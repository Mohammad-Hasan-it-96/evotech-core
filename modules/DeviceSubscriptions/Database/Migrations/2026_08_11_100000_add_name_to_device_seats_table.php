<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A nullable, owner-editable display name for a seat (ADR 0011, seat-name
 * addendum). The device registry screen needs one human discriminator beyond
 * "seen minutes ago" so an owner can tell two identical tills apart before
 * revoking one. Additive and nullable: existing seats stay unnamed and keep
 * working, the client renders a last-seen fallback for null, and nothing in the
 * shipped legacy shim is touched.
 *
 * 40 chars, multibyte — Arabic labels ("الكاشير") are the common case. Not
 * unique: two phones may honestly carry the same name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_seats', function (Blueprint $table): void {
            $table->string('name', 40)->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('device_seats', function (Blueprint $table): void {
            $table->dropColumn('name');
        });
    }
};
