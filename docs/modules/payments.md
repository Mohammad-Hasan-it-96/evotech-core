# Module: Payments

Billing — turns an active **Subscription** period into an **Invoice** and records how it was **paid** ([ADR 0006](../adr/0006-billing-invoices-and-payment-gateway.md)). Admin/staff-managed. This is a **composition module** — it references the [Companies](companies.md) and [Subscriptions](subscriptions.md) modules and reacts to their events (an accepted, acyclic dependency; `Payments → {Companies, Subscriptions} → Core`).

The platform rule: **subscription = the agreement, invoice = the bill, payment = the receipt.** Activating (or renewing) a subscription auto-issues an invoice for that period; a payment settles it.

## Endpoints (all `auth:sanctum`, under `/api/v1`)

| Method | Path | Name | Description |
|---|---|---|---|
| GET | `/invoices` | `invoices.index` | Paginated list, each with company + subscription + product and `payments_count`. |
| POST | `/invoices` | `invoices.store` | Manually issue for a subscription — body: `subscription` (uuid). Idempotent per period. |
| GET | `/invoices/{invoice}` | `invoices.show` | Show by uuid, including its payments. |
| POST | `/invoices/{invoice}/payments` | `invoices.payments.store` | Record a full payment (settles the invoice) — body: `method` (`manual`/`bank_transfer`/`cash`; **not** `card`), `reference?`. `201`. |
| POST | `/invoices/{invoice}/void` | `invoices.void` | Void an open (unpaid) invoice. |
| POST | `/invoices/{invoice}/payment-intent` | `invoices.payment-intent` | **Stripe** ([ADR 0009](../adr/0009-stripe-live-gateway.md)) — start a card payment; returns `client_secret` + `publishable_key`. Requires `PAYMENTS_GATEWAY=stripe`. `201`. |

Plus one **unauthenticated** Stripe endpoint (trust is the HMAC signature, not a session):

| Method | Path | Name | Description |
|---|---|---|---|
| POST | `/api/v1/stripe/webhook` | `stripe.webhook` | Verifies the `Stripe-Signature` and settles the invoice on `payment_intent.succeeded`. |

Invoices are also **auto-issued** (no endpoint) when a subscription is activated or renewed.

## Gateways ([ADR 0009](../adr/0009-stripe-live-gateway.md))

The active gateway is chosen by `config('payments.gateway')` — both behind the one `PaymentGateway` contract:

- **`manual`** (default) — records offline/reconciled receipts (bank transfer, cash) synchronously.
- **`stripe`** — live card payments. Because Stripe settles **asynchronously**, its `collect()` is intentionally unsupported (the manual settle endpoint refuses while Stripe is active); money moves via the **PaymentIntent → webhook** flow above. Built **SDK-less** over Stripe's REST API (Laravel HTTP client) with **native HMAC-SHA256 webhook verification** — no new dependency.

**Webhook security & integrity:** signing-secret required, constant-time signature compare, timestamp tolerance (replay protection, default 300s), an **amount-equality guard** (`422` on mismatch), and **idempotent** settlement (re-delivered events settle at most once). Minor-unit conversion is string math — no floats.

**Config / env:** `PAYMENTS_GATEWAY`, `STRIPE_SECRET`, `STRIPE_KEY`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_WEBHOOK_TOLERANCE` (see `modules/Payments/Config/payments.php`).

> **Not live yet:** enabling `stripe` in production needs real credentials + the webhook-security review named in ADR 0009. Today it's exercised via faked HTTP + signed test payloads.

## Domain & lifecycle

| Class | Notes |
|---|---|
| `Domain\Models\Invoice` | `HasUuid`. `belongsTo` Company + Subscription (nullable — outlives a deleted subscription); `hasMany` payments + events. Financial record — **no soft delete, never edited after issue**. `isOpen()`. |
| `Domain\Models\Payment` | `HasUuid`. A receipt against an invoice (`amount`, `currency`, `method`, `gateway`, `reference`, `paid_at`). |
| `Domain\Models\PaymentEvent` | Append-only ledger (constitution §5) — `const UPDATED_AT = null`; never updated/deleted. |
| `Domain\Enums\InvoiceStatus` | `open` / `paid` / `void`. Only `open` may be paid or voided. |
| `Domain\Enums\PaymentMethod` | `manual` / `bank_transfer` / `cash` / `card` (`card` is Stripe-only, ADR 0009). |
| `Domain\Enums\PaymentEventType` | `issued` / `paid` / `voided`. |
| `Domain\Contracts\PaymentGateway` | Collection seam. Impls `Infrastructure\Gateways\{ManualPaymentGateway, StripePaymentGateway}`; the active one is bound by `config('payments.gateway')`. |
| `Infrastructure\Stripe\{StripeClient, StripeWebhookVerifier, StripePayload}` | SDK-less REST client, native HMAC webhook verifier, and a type-safe view over decoded Stripe JSON (ADR 0009). |
| `Application\Services\PaymentService` | `paginate` / `issueForSubscription` (idempotent per period) / `recordPayment` (manual, through the gateway) / `settleFromWebhook` (idempotent, system-actor) / `void`. The **transaction boundary**; every change appends to the ledger. |
| `Application\Support\InvoiceNumberGenerator` | Sequential `INV-000001` numbers; the unique `number` index is the integrity backstop. |
| `Application\Listeners\IssueInvoiceOnActivation` | Listens for `Subscriptions\...\SubscriptionActivated` → `issueForSubscription` (skips price 0). |
| `Domain\Events\InvoicePaid` | Emitted on settlement — for future Notifications/Reports; no consumer yet. |

### Billing rules
- **One invoice per period:** issuance is idempotent per `(subscription, period_start)`; re-issuing a billed period returns the existing invoice. Renewal bills a new period.
- **Full settlement only (this increment):** a payment settles the invoice in full via the manual gateway; partial payments and refunds are deferred (additive later).
- **Immutable & audited:** invoices are never edited/deleted; `open → paid | void` transitions each append to `payment_events`. Money is `decimal(10,2)` handled as strings — no float math.

### Deferred
- **Stripe go-live:** real credentials + production webhook-security review before `PAYMENTS_GATEWAY=stripe` (ADR 0009).
- **More Stripe events** (`payment_intent.payment_failed`, refunds, disputes), stored-customer/off-session charges, and zero-decimal-currency support.
- Partial payments, refunds, dunning, PDF invoices.

## Tests

- `InvoiceLifecycleTest` — auto-issue on subscription activation, renewal bills a second period, free plan raises none, per-period idempotency, manual issuance, settlement (payment row + ledger `paid` + `InvoicePaid` event), paid/void guards, void, and the enriched show payload.
- `StripeGatewayTest` — gateway selection by config, PaymentIntent creation (faked HTTP; correct minor-unit amount/currency), manual settle refused while Stripe is active, `card` rejected on the manual endpoint, and the webhook surface: verified settlement, bad-signature `400`, stale-timestamp replay `400`, amount-mismatch `422`, idempotent re-delivery, and unhandled-event acknowledgement.
