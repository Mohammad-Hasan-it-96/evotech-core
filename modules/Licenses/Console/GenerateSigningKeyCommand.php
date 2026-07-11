<?php

namespace Modules\Licenses\Console;

use Illuminate\Console\Command;

/**
 * Generates an Ed25519 keypair for signing offline license tokens (ADR 0005) and
 * prints the env lines to configure it. Keys are managed secrets (§6.10) — store
 * the private key only in the environment's secret store, never in the repo.
 */
class GenerateSigningKeyCommand extends Command
{
    protected $signature = 'licenses:keygen {--kid= : Key id (kid) to stamp on issued tokens}';

    protected $description = 'Generate an Ed25519 keypair for signing offline license tokens.';

    public function handle(): int
    {
        $keypair = sodium_crypto_sign_keypair();
        $secret = base64_encode(sodium_crypto_sign_secretkey($keypair));
        $public = base64_encode(sodium_crypto_sign_publickey($keypair));

        $kidOption = $this->option('kid');
        $kid = is_string($kidOption) && $kidOption !== '' ? $kidOption : 'evo-license-key-1';

        $this->info('Ed25519 signing keypair generated. Add these to the environment (keep the private key secret):');
        $this->newLine();
        $this->line('LICENSE_TOKEN_KEY_ID='.$kid);
        $this->line('LICENSE_TOKEN_PRIVATE_KEY='.$secret);
        $this->line('LICENSE_TOKEN_PUBLIC_KEY='.$public);
        $this->newLine();
        $this->warn('The private key never leaves the platform. Devices verify with the public key (GET /api/v1/product/keys).');

        return self::SUCCESS;
    }
}
