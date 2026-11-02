<?php

declare(strict_types=1);

namespace Codeva\Watch\Http\Controllers;

use Codeva\Watch\Core;
use Codeva\Watch\Health\HealthRunner;
use Codeva\Watch\State\RequestState;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

use function abort;
use function hash_equals;
use function is_string;

/**
 * A wrong or missing secret looks exactly like a route that does not exist.
 *
 * @internal
 */
final class HealthController
{
    /**
     * @param  Core<RequestState>  $watch
     */
    public function __invoke(Request $request, Repository $config, HealthRunner $runner, Core $watch): JsonResponse
    {
        $secret = $config->get('watch.health_secret');
        $given = $request->header('X-Watch-Health-Secret');

        if (! is_string($secret) || $secret === '' || ! is_string($given) || ! hash_equals($secret, $given)) {
            abort(404);
        }

        // A monitor polling every minute is not traffic worth recording.
        $watch->dontSample();

        return new JsonResponse($watch->ignore($runner->run(...)), headers: ['Cache-Control' => 'no-store']);
    }
}
