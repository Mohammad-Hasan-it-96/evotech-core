<?php

namespace Modules\Payments\Application\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Payments\Application\Support\InvoiceNumberGenerator;
use Modules\Payments\Domain\Contracts\GatewayPaymentResult;
use Modules\Payments\Domain\Contracts\PaymentGateway;
use Modules\Payments\Domain\Enums\InvoiceStatus;
use Modules\Payments\Domain\Enums\PaymentEventType;
use Modules\Payments\Domain\Enums\PaymentMethod;
use Modules\Payments\Domain\Events\InvoicePaid;
use Modules\Payments\Domain\Models\Invoice;
use Modules\Payments\Domain\Models\Payment;
use Modules\Payments\Domain\Models\PaymentEvent;
use Modules\Subscriptions\Domain\Models\Subscription;

/**
 * Billing use-cases (ADR 0006): issue an invoice for a subscription period,
 * collect a payment through the configured gateway, and void. The service is the
 * transaction boundary; every state change appends to the immutable
 * `payment_events` ledger.
 */
final class PaymentService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly InvoiceNumberGenerator $numbers,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Invoice>
     */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        return Invoice::query()
            ->with(['company', 'subscription.plan.product'])
            ->withCount('payments')
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Issue an invoice for a subscription's current billing period. Idempotent per
     * period: a period already invoiced returns its existing invoice rather than
     * billing twice.
     */
    public function issueForSubscription(Subscription $subscription, ?string $actorId = null): Invoice
    {
        $subscription->loadMissing('plan');

        $days = $subscription->plan->billing_period->days();
        $periodEnd = $subscription->ends_at;
        $periodStart = ($days !== null && $periodEnd !== null)
            ? $periodEnd->copy()->subDays($days)
            : $subscription->starts_at;

        $existing = Invoice::query()
            ->where('subscription_id', $subscription->id)
            ->where('period_start', $periodStart)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($subscription, $periodStart, $periodEnd, $actorId): Invoice {
            $invoice = Invoice::create([
                'number' => $this->numbers->next(),
                'company_id' => $subscription->company_id,
                'subscription_id' => $subscription->id,
                'status' => InvoiceStatus::Open,
                'amount' => $subscription->price,
                'currency' => $subscription->currency,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'issued_at' => Carbon::now(),
            ]);

            $this->record($invoice, PaymentEventType::Issued, $actorId, [
                'amount' => $invoice->amount,
                'currency' => $invoice->currency,
            ]);

            return $invoice;
        });
    }

    /**
     * Collect a full payment for an open invoice through the gateway and settle it.
     *
     * @throws ValidationException when the invoice is not open or collection fails
     */
    public function recordPayment(
        Invoice $invoice,
        PaymentMethod $method,
        ?string $reference = null,
        ?string $actorId = null,
    ): Payment {
        if (! $invoice->isOpen()) {
            throw ValidationException::withMessages([
                'invoice' => __('Only an open invoice can be paid.'),
            ]);
        }

        return DB::transaction(function () use ($invoice, $method, $reference, $actorId): Payment {
            $result = $this->gateway->collect($invoice, $method, $reference);

            if (! $result->succeeded) {
                throw ValidationException::withMessages([
                    'invoice' => __('The payment could not be collected.'),
                ]);
            }

            return $this->persistSettlement($invoice, $result, $this->gateway->identifier(), $actorId);
        });
    }

    /**
     * Settle an invoice from an already-collected gateway result — e.g. a verified
     * Stripe `payment_intent.succeeded` webhook, where Stripe has captured the money
     * before we hear about it (ADR 0009). System-actor, no `collect()` call.
     *
     * Idempotent: a re-delivered event for an already-settled invoice records
     * nothing and returns the existing payment (matched by gateway reference).
     *
     * @param  array<string, mixed>  $meta
     */
    public function settleFromWebhook(
        Invoice $invoice,
        GatewayPaymentResult $result,
        string $gateway,
        array $meta = [],
    ): Payment {
        return DB::transaction(function () use ($invoice, $result, $gateway, $meta): Payment {
            $fresh = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if (! $fresh->isOpen()) {
                return $fresh->payments()
                    ->where('reference', $result->reference)
                    ->firstOrFail();
            }

            return $this->persistSettlement($fresh, $result, $gateway, null, $meta);
        });
    }

    /**
     * Record the receipt, flip the invoice to paid, append the ledger entry, and
     * announce it — the shared tail of every settlement path.
     *
     * @param  array<string, mixed>  $meta
     */
    private function persistSettlement(
        Invoice $invoice,
        GatewayPaymentResult $result,
        string $gateway,
        ?string $actorId,
        array $meta = [],
    ): Payment {
        $payment = $invoice->payments()->create([
            'amount' => $result->amount,
            'currency' => $result->currency,
            'method' => $result->method,
            'gateway' => $gateway,
            'reference' => $result->reference,
            'paid_at' => Carbon::now(),
            'meta' => $meta === [] ? null : $meta,
        ]);

        $invoice->forceFill([
            'status' => InvoiceStatus::Paid,
            'paid_at' => Carbon::now(),
        ])->save();

        $this->record($invoice, PaymentEventType::Paid, $actorId, [
            'payment' => $payment->uuid,
            'method' => $result->method->value,
            'reference' => $result->reference,
        ]);

        InvoicePaid::dispatch($invoice, $payment);

        return $payment;
    }

    /** Void an open (unpaid) invoice. */
    public function void(Invoice $invoice, ?string $actorId = null): Invoice
    {
        if (! $invoice->isOpen()) {
            throw ValidationException::withMessages([
                'invoice' => __('Only an open invoice can be voided.'),
            ]);
        }

        $invoice->forceFill([
            'status' => InvoiceStatus::Void,
            'voided_at' => Carbon::now(),
        ])->save();

        $this->record($invoice, PaymentEventType::Voided, $actorId);

        return $invoice;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(Invoice $invoice, PaymentEventType $type, ?string $actorId, array $context = []): void
    {
        PaymentEvent::create([
            'invoice_id' => $invoice->id,
            'event_type' => $type,
            'actor_type' => $actorId !== null ? 'user' : 'system',
            'actor_id' => $actorId,
            'context' => $context === [] ? null : $context,
        ]);
    }
}
