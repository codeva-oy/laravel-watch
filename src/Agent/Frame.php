<?php

declare(strict_types=1);

namespace Codeva\Watch\Agent;

use function count;
use function explode;
use function hash_equals;
use function hash_hmac;
use function strlen;

/**
 * Incremental parser for `<length>:v1:<auth>:<body>` frames. Length covers
 * everything after its own colon.
 *
 * @internal
 */
final class Frame
{
    public const string VERSION = 'v1';

    public const int MAX_BYTES = 8_000_000;

    public string $body = '';

    public string $version = '';

    public string $auth = '';

    public ?int $length = null;

    public bool $complete = false;

    public bool $tooLarge = false;

    public function append(string $chunk): void
    {
        $this->body .= $chunk;

        if ($this->length === null) {
            $bits = explode(':', $this->body, 4);

            if (count($bits) !== 4) {
                $this->tooLarge = strlen($this->body) > 256;

                return;
            }

            [$length, $this->version, $this->auth, $this->body] = $bits;
            $this->length = (int) $length;
        }

        $this->tooLarge = $this->length > self::MAX_BYTES;
        $this->complete = $this->length === strlen($this->version) + 1 + strlen($this->auth) + 1 + strlen($this->body);
    }

    public function versionIsValid(): bool
    {
        return $this->version === self::VERSION;
    }

    public function isAuthorised(string $tokenHash, string $token, bool $requireHmac): bool
    {
        if ($requireHmac) {
            return hash_equals(hash_hmac('sha256', $this->body, $token), $this->auth);
        }

        return hash_equals($tokenHash, $this->auth);
    }
}
