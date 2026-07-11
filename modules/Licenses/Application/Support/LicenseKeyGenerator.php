<?php

namespace Modules\Licenses\Application\Support;

use Modules\Licenses\Domain\Models\License;

/**
 * Generates unique, human-transcribable license keys of the form
 * `EVO-XXXX-XXXX-XXXX-XXXX`. Ambiguous characters (0/O, 1/I) are excluded so
 * keys can be read aloud and typed by support staff and customers.
 */
final class LicenseKeyGenerator
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const GROUPS = 4;

    private const GROUP_LENGTH = 4;

    public function generate(): string
    {
        do {
            $key = $this->build();
        } while (License::withTrashed()->where('key', $key)->exists());

        return $key;
    }

    private function build(): string
    {
        $configured = config('licenses.key_prefix', 'EVO');
        $prefix = strtoupper(is_string($configured) ? $configured : 'EVO');
        $groups = [];

        for ($g = 0; $g < self::GROUPS; $g++) {
            $chars = '';
            for ($i = 0; $i < self::GROUP_LENGTH; $i++) {
                $chars .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $groups[] = $chars;
        }

        return $prefix.'-'.implode('-', $groups);
    }
}
