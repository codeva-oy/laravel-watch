<?php

namespace Codeva\Watch\Support;

use function preg_match;
use function str_contains;
use function strtolower;

/**
 * @internal
 */
final class DirectoryListing
{
    public static function isEnabled(string $serverSoftware, ?string $htaccess): bool
    {
        if (! str_contains(strtolower($serverSoftware), 'apache')) {
            return false;
        }

        if ($htaccess === null) {
            return false;
        }

        if (preg_match('/^\s*Options\s+.*\+Indexes\b/im', $htaccess) === 1) {
            return true;
        }

        return preg_match('/^\s*Options\s+.*-Indexes\b/im', $htaccess) !== 1;
    }
}
