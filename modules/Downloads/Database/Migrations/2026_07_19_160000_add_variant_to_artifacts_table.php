<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets one release carry several builds of the same platform — Android's
 * `arm64-v8a` and `armeabi-v7a` being the case that forced it.
 *
 * `artifacts` was unique on (release, platform), so a release held exactly one
 * Android build. That was fine while the answer was a universal APK, and became
 * the blocker the moment per-ABI builds were wanted: the second upload silently
 * *replaced* the first rather than sitting alongside it.
 *
 * `variant` is NOT NULL with an empty-string default rather than nullable, and
 * that is deliberate: SQL treats NULLs as distinct, so a nullable column would let
 * two rows both claim the universal slot and the unique index would not see it.
 * Empty string means "universal — installs anywhere", which is exactly what every
 * existing row is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artifacts', function (Blueprint $table): void {
            $table->string('variant', 32)->default('')->after('platform');
        });

        /*
         * Add the new index BEFORE dropping the old one, and not for tidiness.
         *
         * MySQL requires an index whose leftmost column is the foreign key, and
         * `artifacts_release_id_platform_unique` was serving that role for
         * `release_id`. Dropping it first leaves the FK unindexed and MySQL
         * refuses outright:
         *
         *   1553 Cannot drop index '…': needed in a foreign key constraint
         *
         * The new index also starts with `release_id`, so once it exists the old
         * one is free to go. SQLite does not care either way — which is why this
         * only showed up on the MySQL half of CI.
         */
        Schema::table('artifacts', function (Blueprint $table): void {
            $table->unique(['release_id', 'platform', 'variant']);
        });

        Schema::table('artifacts', function (Blueprint $table): void {
            $table->dropUnique(['release_id', 'platform']);
        });
    }

    public function down(): void
    {
        // Same ordering constraint in reverse: the FK needs an index throughout.
        //
        // This fails if any release actually holds two variants of one platform —
        // correctly, since the old shape cannot represent them. Drop the extra
        // artifacts first if a rollback is ever genuinely needed.
        Schema::table('artifacts', function (Blueprint $table): void {
            $table->unique(['release_id', 'platform']);
        });

        Schema::table('artifacts', function (Blueprint $table): void {
            $table->dropUnique(['release_id', 'platform', 'variant']);
        });

        Schema::table('artifacts', function (Blueprint $table): void {
            $table->dropColumn('variant');
        });
    }
};
