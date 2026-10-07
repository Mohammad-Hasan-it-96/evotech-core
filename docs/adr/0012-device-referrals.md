# ADR 0012 — Device-app referrals («ادعُ محلاً واحصل على شهر مجاني»)

- **Status:** Accepted
- **Date:** 2026-10-07
- **Deciders:** Founder/CTO
- **Supersedes / superseded by:** —
- **Related:** ADR 0010 (DeviceSubscriptions), ADR 0011 (multi-device businesses); Accounting-Book
  `docs/design/growth-statement-link-and-referral.md` (T3.3, decision D14).

## Context

دفتر حسابات (`daftar_hesabat`) grows shop to shop: a grocer tells the pharmacy next door. The owner
wants to reward that: invite a shop, and when it subscribes, get a free month of Pro.

The subscriber here is a **device** (ADR 0010), and device ids cost nothing to fabricate: any phone
or emulator mints new ones, and every new device gets the app's trial. Anything granted on install or
on trial can therefore be farmed without limit.

## Decision

1. **Opt-in per app.** `device_apps.referral_reward_days` (0 = off, the default). Fawateer and
   SmartAgent stay at 0, so their wire responses and behaviour are unchanged. Daftar is 30.
2. **Each device gets a code.**
   - `device_subscriptions.referral_code`: 6 characters from `ABCDEFGHJKLMNPQRSTUVWXYZ23456789`
     (no `0/O/1/I`, readable over the phone), unique per app.
   - Minted lazily the first time `check_device`/`create_device` answers an enabled app.
   - Never minted for the shared fallback id.
3. **Attribution is set once.**
   - `create_device` accepts an optional `referral_code` and records `referred_by_id`. This only
     happens while the device has no referrer **and has never been activated** (`plan_id` null), so
     an existing customer can't be re-attributed.
   - It is ignored silently (never a 422; the shipped clients must not fail registration over it)
     when the code is unknown in this app, is the device's own, or the device is the fallback bucket.
   - Accepted during the trial, not only at first registration: the app registers silently before the
     user ever sees the invite field.
4. **The reward triggers on the referred device's first paid activation**, inside
   `DeviceSubscriptionService::activate()`, so both admin activation paths get it. An activation is
   operator-confirmed and paid for, so it can't be faked cheaply.
   - `device_referral_rewards` has `referred_id` UNIQUE: one reward per referred device, ever.
     Renewals never pay out again, and a race can't pay out twice.
   - The referrer's **effective** subscription source (its business when linked, ADR 0011, otherwise
     the device) gets `expires_at = max(now, expires_at) + days` and `is_verified = true`. A lapsed or
     Free referrer gets `days` of Pro starting now.
   - A lifetime referrer (`expires_at` null) is recorded with 0 days, since nothing can be added.
   - **Cap:** 12 rewards with days > 0 per referrer per rolling 365 days
     (`device-subscriptions.referrals.max_rewards_per_year`). Over the cap, the referral is still
     recorded, with 0 days.
   - Audited (`device_referral.rewarded`). If the referrer has a push token, it is told via a
     `new_plan_activated` push (which the clients already handle as "re-check now").
5. **Wire (additive).** For enabled apps only, `check_device` and `create_device` add
   `referral_code` (string|null) and `referral_rewards` (count of rewards with days > 0). Disabled
   apps' responses are byte-identical to before.
6. **Staff API.**
   - `DeviceSubscriptionResource` gains `referral_code`, `referred_by` (`{id, full_name}` when
     loaded) and `referral_rewards_count` (when counted).
   - `device-apps` exposes `referral_reward_days` and lets it be edited (0–365).

## Consequences

- A referral is worth something only once real money moves, so farming needs paying customers.
- Codes are guessable in principle (32⁶ ≈ 1.07 billion). Guessing one buys nothing: it can only
  attribute **your own** future paid activation to a stranger.
- Deleting a device from the console keeps its reward rows (the foreign keys null on delete), so the
  record that a month was granted survives. A deleted referred device that re-registers is a new row
  and could earn its referrer a reward again. This is acceptable (the same caveat as trial
  eligibility, ADR 0010), because it still requires a second payment.
