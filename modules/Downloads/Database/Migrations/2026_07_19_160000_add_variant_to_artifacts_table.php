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

        Schema::table('artifacts', function (Blueprint $table): void {
            // Two steps: the column has to exist before an index can name it.
            $table->dropUnique(['release_id', 'platform']);
            $table->unique(['release_id', 'platform', 'variant']);
        });
    }

    public function down(): void
    {
        Schema::table('artifacts', function (Blueprint $table): void {
            $table->dropUnique(['release_id', 'platform', 'variant']);
        });

        Schema::table('artifacts', function (Blueprint $table): void {
            $table->dropColumn('variant');
            $table->unique(['release_id', 'platform']);
        });
    }
};
