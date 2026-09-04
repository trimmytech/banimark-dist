<?php

namespace Banimark\Auth;

/** Laravel-session-backed bag. Only instantiated inside a Laravel app. */
class LaravelSession implements SessionBag
{
    public function get(string $key): mixed
    {
        return session($key);
    }

    public function put(string $key, mixed $value): void
    {
        session([$key => $value]);
    }

    public function forget(string $key): void
    {
        session()->forget($key);
    }
}
