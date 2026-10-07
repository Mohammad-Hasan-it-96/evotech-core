# ADR 0013 — Public read-only statement links for device apps

- **Status:** Accepted
- **Date:** 2026-10-07
- **Deciders:** Founder/CTO
- **Supersedes / superseded by:** —
- **Related:** ADR 0010 (DeviceSubscriptions), ADR 0012 (referrals); Accounting-Book
  `docs/design/growth-statement-link-and-referral.md` (T3.2, decisions D11–D13).

## Context

دفتر حسابات keeps a shop's ledger **offline on the phone**. The shop already sends a debtor a text or
PDF statement. The owner wants a third option: a link (`evotech-sys.com/s/<token>`) the debtor opens in
any browser. A link can't be edited, can be checked again later, and carries the growth footer.

This is the first time the platform stores **a third party's financial data**. The debtor is not our
user and never agreed to anything. Every decision below follows from minimizing that.

## Decision

1. **Snapshot, not sync.**
   - The app uploads one frozen statement per share: shop name, customer name, one currency, balance,
     totals, and entries (date, direction, amount, note).
   - The server validates against a closed schema and **stores only those keys**. No phone number, device
     id or any other field can ride along, even if a client sends one.
   - Limits: at most 300 entries, notes of at most 200 characters.
2. **Opt-in per app.** `device-subscriptions.statements.apps`; only `daftar_hesabat` today.
   Owner decision D11: **free**, not Pro, because the page is a growth channel.
3. **Endpoints.**
   - `POST /api/{app}/statements` (`app_name`, `device_id`, `statement`): returns
     `{token, url, expires_at}`.
     - Only a registered device of an enabled app can create links; the fallback bucket can't.
     - Throttled per device and per IP (`statement-create`).
   - `DELETE /api/{app}/statements/{token}` (`app_name`, `device_id`): revokes. It hard-deletes the row,
     and only the creating device can do it. Otherwise it returns 404.
   - `GET /api/v1/statements/{token}`: the public JSON the website renders.
     - Returns 404 for unknown, expired and revoked links alike, so nothing can be probed.
     - Throttled per IP (`statement-read`).
4. **Token.** 32 random bytes, base64url (43 characters), stored as its **SHA-256 hash**. A database
   leak doesn't yield working links, and the plaintext exists only in the create response.
5. **Lifetime.** 30 days (`statements.ttl_days`, owner decision D12).
   `device-subscriptions:prune-statements` runs daily and **deletes** expired rows. Expiry removes the
   data; it doesn't just hide the link. Deleting a device cascades to its links.
6. **The web page** (`evotech-web`, `/[locale]/s/[token]`) is rendered on each request, with no cache:
   - `noindex, nofollow`;
   - `Referrer-Policy: no-referrer`;
   - `Cache-Control: private, no-store`.

## Consequences

- The platform now holds debtor names and amounts for up to 30 days, but only for shares the shop owner
  explicitly made. The app's privacy policy (all three copies) says so.
- Anyone holding a link can read that one statement until it expires or is revoked. This is the
  intended trust model of a share link. The 256-bit token makes guessing infeasible.
- A device id is not a secret (the app shows it). Someone holding another shop's id could create
  junk links under it, but they can't read or revoke that shop's existing links, because they
  don't have the tokens. Throttling bounds the junk.
