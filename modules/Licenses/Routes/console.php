<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Sweep expired licenses once a day (constitution §3 — scheduler).
 */
Schedule::command('licenses:expire')->daily();
