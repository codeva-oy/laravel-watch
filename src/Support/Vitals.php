<?php

namespace Codeva\Watch\Support;

use function config;
use function e;
use function is_string;
use function max;
use function min;
use function preg_match;
use function rtrim;

/**
 * The script tag behind @watchVitals: Watch's Web Vitals script with this
 * project's public key. Nothing when the key or server is not configured, so
 * the directive can stay in a layout that also runs locally.
 */
final class Vitals
{
    public static function tag(): string
    {
        $key = config('watch.vitals.key');
        $server = rtrim((string) config('watch.server_url'), '/');

        if (! config('watch.enabled') || ! is_string($key) || ! preg_match('/^wv_[a-z0-9]{24}$/', $key) || $server === '') {
            return '';
        }

        $sample = max(0.0, min(1.0, (float) config('watch.vitals.sample', 1.0)));

        return '<script src="'.e($server).'/vitals.js" data-key="'.e($key).'"'.($sample < 1.0 ? ' data-sample="'.e((string) $sample).'"' : '').' defer></script>';
    }
}
