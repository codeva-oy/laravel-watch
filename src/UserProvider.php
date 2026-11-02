<?php

namespace Codeva\Watch;

use Codeva\Watch\Types\Str;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Throwable;

use function call_user_func;
use function is_null;

/**
 * @internal
 */
final class UserProvider
{
    private ?object $rememberedUser = null;

    /**
     * @var (callable(): (null|(callable(Authenticatable): array{id: mixed, name?: mixed, username?: mixed})))
     */
    public $userDetailsResolverResolver;

    /**
     * @var array{id: mixed, name?: mixed, username?: mixed}
     */
    private ?array $resolvedDetails;

    /**
     * @var (callable(callable(AuthManager): mixed): mixed)
     */
    private $withAuth;

    /**
     * @var (callable(): (callable(Throwable, bool): void))
     */
    private $reportResolver;

    /**
     * @var (callable(): list<string>)
     */
    private $guardsResolver;

    private bool $alreadyReportedResolvingUserIdException = false;

    /**
     * @param  null|(callable(): list<string>)  $guardsResolver
     */
    public function __construct(
        callable $withAuth,
        callable $userDetailsResolverResolver,
        callable $reportResolver,
        ?callable $guardsResolver = null,
    ) {
        $this->withAuth = $withAuth;
        $this->userDetailsResolverResolver = $userDetailsResolverResolver;
        $this->reportResolver = $reportResolver;
        $this->guardsResolver = $guardsResolver ?? static fn (): array => [];
    }

    /**
     * @return string|LazyValue<string>
     */
    public function id(): LazyValue|string
    {
        return $this->withAuth(function ($auth) {
            if (! $auth->hasResolvedGuards()) {
                return $this->lazyUserId();
            }

            if ($user = $this->authenticatedUser($auth)) {
                return $this->userId($user);
            }

            if ($this->rememberedUser) {
                return $this->userId($this->rememberedUser);
            }

            return $this->lazyUserId();
        });
    }

    /**
     * @return LazyValue<string>
     */
    private function lazyUserId(): LazyValue
    {
        return new LazyValue(function () {
            return $this->resolvedUserId();
        });
    }

    public function resolvedUserId(): string
    {
        return $this->withAuth(function ($auth) {
            if (! $auth->hasResolvedGuards()) {
                return Compatibility::getUserIdFromContext();
            }

            if ($user = $this->authenticatedUser($auth)) {
                return $this->userId($user);
            }

            if ($this->rememberedUser) {
                return $this->userId($this->rememberedUser);
            }

            return Compatibility::getUserIdFromContext();
        });
    }

    /**
     * Find the user authenticated on any guard, not just the default one.
     *
     * A guard only becomes the default one when the `auth` middleware runs, so
     * token flows such as Sanctum's frequently leave the user on a non-default
     * guard. Guards are inspected with `hasUser()`, which reads the already
     * resolved user and never triggers authentication of its own.
     *
     * @param  AuthManager  $auth
     */
    private function authenticatedUser($auth): ?object
    {
        try {
            if ($auth->hasUser()) {
                return $auth->user();
            }
        } catch (Throwable) {
            // The default guard's driver may not be resolvable; keep looking.
        }

        foreach (call_user_func($this->guardsResolver) as $name) {
            try {
                $guard = $auth->guard($name);

                if ($guard->hasUser()) {
                    return $guard->user();
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    private function userId(object $user): string
    {
        try {
            return Str::tinyText((string) ($this->resolvedDetails($user)['id'] ?? ''));
        } catch (Throwable $e) {
            $this->reportResolvingUserIdException($e);

            return '';
        }
    }

    /**
     * @return array{ id: mixed, name?: mixed, username?: mixed }|null
     */
    public function details(): ?array
    {
        $user = $this->withAuth(fn ($auth) => $auth->hasResolvedGuards()
            ? $this->authenticatedUser($auth) ?? $this->rememberedUser
            : $this->rememberedUser);

        return $this->resolvedDetails($user);
    }

    /**
     * @return array{ id: mixed, name?: mixed, username?: mixed }|null
     */
    private function resolvedDetails(?object $user): ?array
    {
        if ($user === null) {
            return null;
        }

        if (isset($this->resolvedDetails)) {
            return $this->resolvedDetails;
        }

        try {
            $id = $this->identifierFor($user);
        } catch (Throwable $e) {
            $this->reportResolvingUserIdException($e);

            return null;
        }

        $resolver = call_user_func($this->userDetailsResolverResolver);

        if (! is_null($resolver) && $user instanceof Authenticatable) {
            return $this->resolvedDetails = [
                'id' => $id,
                ...$resolver($user),
            ];
        }

        return $this->resolvedDetails = [
            'id' => $id,
            'name' => $user->name ?? '',
            'username' => $user->email ?? '',
        ];
    }

    private function identifierFor(object $user): mixed
    {
        if ($user instanceof Authenticatable) {
            return $user->getAuthIdentifier();
        }

        if ($user instanceof Model) {
            return $user->getKey();
        }

        return $user->id ?? null;
    }

    public function remember(object $user): void
    {
        $this->rememberedUser = $user;
    }

    public function flush(): void
    {
        $this->rememberedUser = null;
        $this->resolvedDetails = null;
        $this->alreadyReportedResolvingUserIdException = false;
    }

    private function reportResolvingUserIdException(Throwable $e): void
    {
        if ($this->alreadyReportedResolvingUserIdException) {
            return;
        }

        $this->alreadyReportedResolvingUserIdException = true;

        $report = call_user_func($this->reportResolver);

        $report($e, true);
    }

    /**
     * @template TValue
     *
     * @param  callable(AuthManager): TValue  $callback
     * @return TValue
     */
    private function withAuth(callable $callback): mixed
    {
        return ($this->withAuth)($callback);
    }
}
