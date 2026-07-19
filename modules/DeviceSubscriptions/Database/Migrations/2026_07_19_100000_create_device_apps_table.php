<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the per-app settings out of config/device-subscriptions.php so pricing and
 * trial terms can change from the dashboard instead of a deploy.
 *
 * `name` is the identity the shipped apps actually send in `app_name` ('Fawateer',
 * 'SmartAgent') — matched case-insensitively at lookup, so it is the real join key
 * and not decoration. It stays a string rather than becoming an id because the
 * builds in customers' hands send it verbatim and cannot be updated remotely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_apps', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            // The literal app_name a device sends. Unique so two rows cannot both
            // claim a name that lookups resolve case-insensitively.
            $table->string('name', 50)->unique();

            // URL namespace for /api/{slug}/*.
            $table->string('slug', 50)->unique();

            // Product name used in push copy, so a Fawateer user is never asked to
            // renew "المندوب الذكي".
            $table->string('label', 100);

            // 0 = no trial. SmartAgent is deliberately 0; see the module config.
            $table->unsignedSmallInteger('trial_days')->default(0);

            /*
             * False = this app has its own catalog in device_plans; true = it reads
             * the shared list. Kept as an explicit flag rather than inferred from
             * "has no plan rows", because those two states are different: an app
             * must be able to configure genuinely zero purchasable plans without
             * silently inheriting the shared prices.
             */
            $table->boolean('uses_shared_plans')->default(true);

            /*
             * The Products-module row this app's releases belong to. The two naming
             * schemes diverged early (this module's 'Fawateer' is the catalog's
             * 'invoices'), so nothing joined them until now. Nullable: an app can be
             * sold before it has a product row, and losing the product must not
             * delete the app's subscribers.
             */
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_apps');
    }
};
