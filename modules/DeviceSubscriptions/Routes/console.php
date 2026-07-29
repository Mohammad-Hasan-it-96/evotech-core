<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Send subscription-expiry reminders once a day (constitution §3 — scheduler).
 * Replaces the legacy public send_plan_notifications HTTP endpoint.
 */
Schedule::command('device-subscriptions:sweep-expiry')->daily();

/*
 * Prune the multi-device sync change log to the retention window once a day
 * (ADR 0011, Decision 14). Bounds the append-only oplog's growth; a device
 * offline longer than the window re-bootstraps from a snapshot.
 */
Schedule::command('device-subscriptions:prune-sync-changes')->daily();
