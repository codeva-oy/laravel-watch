<?php

namespace Codeva\Watch\Http\Middleware;

use Closure;
use Codeva\Watch\Core;
use Codeva\Watch\Facades\Watch;
use Codeva\Watch\State\RequestState;
use Illuminate\Http\Request;
use Throwable;

final class Sample
{
    /**
     * @param  Core<RequestState>  $watch
     */
    public function __construct(private Core $watch)
    {
        //
    }

    public static function rate(float $rate): string
    {
        $rate = (string) $rate;

        if ($rate === '0') {
            $rate = '0.0';
        }

        return self::class.':'.$rate;
    }

    public static function always(): string
    {
        return self::class.':1.0';
    }

    public static function never(): string
    {
        return self::class.':0.0';
    }

    public function handle(Request $request, Closure $next, float $rate): mixed
    {
        try {
            $this->watch->sample($rate);
        } catch (Throwable $e) {
            Watch::unrecoverableExceptionOccurred($e);
        }

        return $next($request);
    }
}
