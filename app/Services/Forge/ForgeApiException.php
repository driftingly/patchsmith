<?php

namespace App\Services\Forge;

use Exception;
use Illuminate\Http\Client\Response;

class ForgeApiException extends Exception
{
    public static function fromResponse(string $method, string $path, Response $response): self
    {
        $detail = $response->json('message') ?? mb_substr($response->body(), 0, 300);

        return new self("{$method} {$path} failed ({$response->status()}): {$detail}");
    }

    public static function retriesExhausted(string $method, string $path, int $attempts): self
    {
        return new self("{$method} {$path} gave up after {$attempts} attempts");
    }
}
