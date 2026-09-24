<?php

namespace Banimark\Auth;

/** In-memory bag for tests. */
class ArraySession implements SessionBag
{
    public array $data = [];

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function put(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($this->data[$key]);
    }
}
