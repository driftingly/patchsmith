<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

uses(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Fake the Forge API from a map of "METHOD /path" to a response payload,
 * a response sequence, or a closure receiving the request. Organizations
 * have no archived servers and no teams unless a route says otherwise.
 *
 * @param  array<string, mixed>  $routes
 */
function fakeForgeApi(array $routes): void
{
    Http::fake(function (Request $request) use ($routes) {
        $path = Str::after((string) parse_url($request->url(), PHP_URL_PATH), '/api');
        $key = $request->method().' '.$path;

        if (! array_key_exists($key, $routes) && Str::is(['GET /orgs/*/servers/archives', 'GET /orgs/*/teams'], $key)) {
            return Http::response(forgeCollection([]));
        }

        if (! array_key_exists($key, $routes)) {
            throw new RuntimeException("Unexpected Forge request: {$key}");
        }

        $response = $routes[$key];

        return is_callable($response) ? $response($request) : Http::response($response);
    });
}

/**
 * @param  array<int, array<string, mixed>>  $items
 * @return array{data: array<int, array<string, mixed>>, meta: array{next_cursor: null}}
 */
function forgeCollection(array $items): array
{
    return ['data' => $items, 'meta' => ['next_cursor' => null]];
}

/**
 * @return array<string, mixed>
 */
function forgeOrganization(string $slug): array
{
    return ['id' => $slug, 'attributes' => ['slug' => $slug, 'name' => ucfirst($slug)]];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function forgeServer(int $id, string $name, array $overrides = []): array
{
    return ['id' => $id, 'attributes' => $overrides + [
        'id' => $id,
        'name' => $name,
        'ip_address' => "10.0.0.{$id}",
        'type' => 'app',
        'connection_status' => 'connected',
        'is_ready' => true,
        'revoked' => false,
    ]];
}

/**
 * @return array<string, mixed>
 */
function forgePhpVersion(int $id, string $version, string $status = 'installed', string $updatedAt = '2026-01-01T00:00:00Z'): array
{
    return ['id' => $id, 'attributes' => [
        'version' => $version,
        'status' => $status,
        'binary_name' => 'php'.str_replace('.', '', $version),
        'updated_at' => $updatedAt,
    ]];
}

/**
 * @return array<int, array<string, string>>
 */
function readLog(string $path): array
{
    $rows = array_map(fn (string $line) => str_getcsv($line, escape: ''), array_filter(file($path, FILE_IGNORE_NEW_LINES)));
    $header = array_shift($rows);

    return array_map(fn (array $row) => array_combine($header, $row), $rows);
}
