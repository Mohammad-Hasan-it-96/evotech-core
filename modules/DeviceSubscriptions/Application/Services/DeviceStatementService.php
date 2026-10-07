<?php

namespace Modules\DeviceSubscriptions\Application\Services;

use Illuminate\Support\Carbon;
use Modules\DeviceSubscriptions\Domain\Models\DeviceStatement;
use Modules\DeviceSubscriptions\Domain\Models\DeviceSubscription;

/**
 * Public read-only statement links (ADR 0013).
 *
 * The data is a third party's (the debtor never agreed to anything), so this class
 * is deliberately narrow: it stores only whitelisted keys, never the plaintext
 * token, and deletes rather than hides.
 */
final class DeviceStatementService
{
    public function enabled(string $appName): bool
    {
        $apps = config('device-subscriptions.statements.apps', []);

        if (! is_array($apps)) {
            return false;
        }

        foreach ($apps as $app) {
            if (is_string($app) && strcasecmp($app, $appName) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Store a snapshot and mint its link.
     *
     * @param  array<string, mixed>  $statement  already validated by the controller
     * @return array{token: string, url: string, expires_at: Carbon}
     */
    public function create(DeviceSubscription $device, array $statement): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expiresAt = Carbon::now()->addDays($this->ttlDays());

        DeviceStatement::create([
            'device_subscription_id' => $device->id,
            'app_name' => (string) $device->app_name,
            'token_hash' => DeviceStatement::hashToken($token),
            'payload' => self::whitelist($statement),
            'expires_at' => $expiresAt,
        ]);

        return [
            'token' => $token,
            'url' => rtrim($this->urlBase(), '/').'/'.$token,
            'expires_at' => $expiresAt,
        ];
    }

    /** Delete a link, but only for the device that created it. */
    public function revoke(DeviceSubscription $device, string $token): bool
    {
        return DeviceStatement::query()
            ->where('token_hash', DeviceStatement::hashToken($token))
            ->where('device_subscription_id', $device->id)
            ->toBase()
            ->delete() > 0;
    }

    /** The live (unexpired, unrevoked) statement behind a token, or null. */
    public function find(string $token): ?DeviceStatement
    {
        return DeviceStatement::query()
            ->live()
            ->where('token_hash', DeviceStatement::hashToken($token))
            ->first();
    }

    /** Delete every expired statement. Returns how many rows went. */
    public function prune(): int
    {
        return DeviceStatement::query()->where('expires_at', '<=', Carbon::now())->toBase()->delete();
    }

    private function ttlDays(): int
    {
        $days = config('device-subscriptions.statements.ttl_days', 30);

        return is_numeric($days) ? max(1, (int) $days) : 30;
    }

    private function urlBase(): string
    {
        $base = config('device-subscriptions.statements.url_base');

        return is_string($base) && $base !== '' ? $base : 'https://evotech-sys.com/ar/s';
    }

    /**
     * Rebuild the snapshot from the known keys only, so nothing a client adds — a
     * phone number, a device id — is ever stored.
     *
     * @param  array<string, mixed>  $statement
     * @return array<string, mixed>
     */
    private static function whitelist(array $statement): array
    {
        $entries = [];
        $raw = $statement['entries'] ?? [];

        foreach (is_array($raw) ? $raw : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $note = $entry['note'] ?? null;

            $entries[] = [
                'date' => self::text($entry['date'] ?? ''),
                'direction' => ($entry['direction'] ?? null) === 'paid' ? 'paid' : 'due',
                'amount' => self::number($entry['amount'] ?? 0),
                'note' => is_string($note) && trim($note) !== '' ? trim($note) : null,
            ];
        }

        $shop = $statement['shop_name'] ?? null;

        return [
            'shop_name' => is_string($shop) && trim($shop) !== '' ? trim($shop) : null,
            'customer_name' => self::text($statement['customer_name'] ?? ''),
            'currency' => self::text($statement['currency'] ?? ''),
            'balance' => self::number($statement['balance'] ?? 0),
            'total_due' => self::number($statement['total_due'] ?? 0),
            'total_paid' => self::number($statement['total_paid'] ?? 0),
            'generated_at' => self::text($statement['generated_at'] ?? ''),
            'entries' => $entries,
        ];
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function number(mixed $value): float
    {
        return is_numeric($value) ? round((float) $value, 2) : 0.0;
    }
}
