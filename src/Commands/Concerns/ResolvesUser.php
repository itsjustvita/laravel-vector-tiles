<?php

namespace ItsJustVita\VectorTiles\Commands\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

trait ResolvesUser
{
    /**
     * The user given with --user, loaded through the default guard's provider.
     *
     * @throws InvalidArgumentException when the user does not exist
     */
    protected function resolveUser(): ?Authenticatable
    {
        $id = $this->option('user');

        if ($id === null || $id === '') {
            return null;
        }

        $guard = config('auth.defaults.guard');
        $user = Auth::createUserProvider(config("auth.guards.{$guard}.provider"))?->retrieveById($id);

        if ($user === null) {
            throw new InvalidArgumentException("User '{$id}' not found.");
        }

        return $user;
    }
}
