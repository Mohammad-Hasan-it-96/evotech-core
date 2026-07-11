<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Config;
use Modules\Products\Database\Seeders\ProductCatalogSeeder;
use Modules\Users\Domain\Models\User;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with reference (production-safe) data.
     * Fake/demo data belongs in a separate demo seeder (constitution §5).
     */
    public function run(): void
    {
        $this->call([
            ProductCatalogSeeder::class,
        ]);

        $this->seedAdminUser();
    }

    /**
     * Bootstrap staff admin (company-less). Idempotent — keyed by email, so
     * re-seeding never duplicates. Credentials come from env; the defaults are
     * for local dev only — set ADMIN_EMAIL / ADMIN_PASSWORD in real environments.
     */
    private function seedAdminUser(): void
    {
        $email = Config::string('app.admin_email', 'admin@evotech.local');

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => Config::string('app.admin_name', 'EVOTECH Admin'),
                // Cast 'hashed' on the User model hashes this automatically.
                'password' => Config::string('app.admin_password', 'password'),
                'email_verified_at' => now(),
            ],
        );
    }
}
