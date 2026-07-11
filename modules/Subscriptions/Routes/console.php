<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Sweep expired subscriptions once a day (constitution §3 — scheduler).
 */
Schedule::command('subscriptions:expire')->daily();
