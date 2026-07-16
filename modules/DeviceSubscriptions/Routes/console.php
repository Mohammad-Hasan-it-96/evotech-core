<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Send subscription-expiry reminders once a day (constitution §3 — scheduler).
 * Replaces the legacy public send_plan_notifications HTTP endpoint.
 */
Schedule::command('device-subscriptions:sweep-expiry')->daily();
