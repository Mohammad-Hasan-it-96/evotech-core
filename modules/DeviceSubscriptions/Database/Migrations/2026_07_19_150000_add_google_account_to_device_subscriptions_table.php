<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which Google account holds a device's Drive backups.
 *
 * Support needs it to answer "where are my backups?", and a reinstalled app uses
 * it to remind the user which account to sign back into — the one moment the
 * answer matters and the device can no longer work it out for itself.
 *
 * Nullable because most rows will never have one: it is only known once the user
 * has actually linked Drive, and a user who signs out must be able to clear it.
 * The shipped app already sends the field; it is ignored until this column exists,
 * so deploy order does not matter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_subscriptions', function (Blueprint $table): void {
            $table->string('google_account', 255)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('device_subscriptions', function (Blueprint $table): void {
            $table->dropColumn('google_account');
        });
    }
};
