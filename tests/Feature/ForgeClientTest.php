<?php

use App\Services\Forge\ForgeApiException;
use App\Services\Forge\ForgeClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    Http::preventStrayRequests();
});

function forgeClient(): ForgeClient
{
    return new ForgeClient(token: 'secret-token', baseUrl: 'https://forge.test/api', maxAttempts: 3);
}

test('it sends the bearer token and json headers', function () {
    Http::fake(['https://forge.test/api/orgs*' => Http::response(['data' => []])]);

    forgeClient()->organizations();

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer secret-token')
        && $request->hasHeader('Accept', 'application/json'));
});

test('it follows cursor pagination until the cursor is empty', function () {
    Http::fake(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return match ($query['page']['cursor'] ?? null) {
            null => Http::response(['data' => [['id' => 1]], 'meta' => ['next_cursor' => 'page-two']]),
            'page-two' => Http::response(['data' => [['id' => 2]], 'meta' => ['next_cursor' => null]]),
        };
    });

    $items = forgeClient()->organizations();

    expect($items)->toBe([['id' => 1], ['id' => 2]]);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'page%5Bsize%5D=100'));
});

test('it throws when a paginated request is not successful', function () {
    Http::fake(['https://forge.test/api/orgs*' => Http::response(['message' => 'Forbidden'], 403)]);

    forgeClient()->organizations();
})->throws(ForgeApiException::class, 'GET /orgs failed (403): Forbidden');

test('it waits for the rate limit window before retrying a 429', function () {
    Http::fake([
        'https://forge.test/api/orgs*' => Http::sequence()
            ->push(['message' => 'Too many'], 429, ['x-ratelimit-reset' => (string) (time() + 7)])
            ->push(['data' => []]),
    ]);

    forgeClient()->organizations();

    Http::assertSentCount(2);
    Sleep::assertSlept(fn ($duration) => $duration->totalSeconds >= 7 && $duration->totalSeconds <= 9);
});

test('it retries server errors and connection failures with a backoff', function () {
    $attempt = 0;

    Http::fake(function () use (&$attempt) {
        $attempt++;

        return match ($attempt) {
            1 => throw new ConnectionException('timed out'),
            2 => Http::response('bad gateway', 502),
            default => Http::response(['data' => [['id' => 1]]]),
        };
    });

    $items = forgeClient()->organizations();

    expect($items)->toBe([['id' => 1]]);
    Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 5);
    Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 20);
});

test('it gives up after the configured number of attempts', function () {
    Http::fake(['https://forge.test/api/orgs*' => Http::response('down', 503)]);

    forgeClient()->organizations();
})->throws(ForgeApiException::class, 'gave up after 3 attempts');

test('updating a php version accepts 200, 202 and 204', function (int $status) {
    Http::fake(['https://forge.test/api/orgs/acme/servers/1/php/versions/9' => Http::response('', $status)]);

    forgeClient()->updatePhpVersion('acme', 1, 9);

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT');
})->with([200, 202, 204]);

test('updating a php version throws on a rejected request', function () {
    Http::fake(['https://forge.test/api/orgs/acme/servers/1/php/versions/9' => Http::response(['message' => 'Nope'], 422)]);

    forgeClient()->updatePhpVersion('acme', 1, 9);
})->throws(ForgeApiException::class, 'PUT /orgs/acme/servers/1/php/versions/9 failed (422): Nope');

test('fetching a php version returns its attributes', function () {
    Http::fake([
        'https://forge.test/api/orgs/acme/servers/1/php/versions/9' => Http::response([
            'data' => ['id' => 9, 'attributes' => ['status' => 'installed', 'version' => '8.3']],
        ]),
    ]);

    expect(forgeClient()->phpVersion('acme', 1, 9))->toBe(['status' => 'installed', 'version' => '8.3']);
});

test('creating a recipe sends the name, user and script as json and returns the id', function () {
    Http::fake(['https://forge.test/api/orgs/acme/recipes' => Http::response(['data' => ['id' => '55']])]);

    expect(forgeClient()->createRecipe('acme', 'Audit', 'root', 'echo hi'))->toBe('55');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->isJson()
        && $request->data() === ['name' => 'Audit', 'user' => 'root', 'script' => 'echo hi']);
});

test('running a recipe sends the server ids', function () {
    Http::fake(['https://forge.test/api/orgs/acme/recipes/55/runs' => Http::response('', 202)]);

    forgeClient()->runRecipe('acme', '55', [1, 2]);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->data() === ['servers' => [1, 2]]);
});

test('deleting a recipe that is already gone is not an error', function () {
    Http::fake(['https://forge.test/api/orgs/acme/recipes/55' => Http::response(['message' => 'Not found'], 404)]);

    forgeClient()->deleteRecipe('acme', '55');

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE');
});

test('a get request sends no body', function () {
    Http::fake(['https://forge.test/api/orgs*' => Http::response(['data' => []])]);

    forgeClient()->organizations();

    Http::assertSent(fn (Request $request) => $request->body() === '');
});

test('creating a recipe with a team sends the team id', function () {
    Http::fake(['https://forge.test/api/orgs/acme/recipes' => Http::response(['data' => ['id' => '55']], 201)]);

    expect(forgeClient()->createRecipe('acme', 'Audit', 'root', 'echo hi', teamId: 12))->toBe('55');

    Http::assertSent(fn (Request $request) => $request['team_id'] === 12);
});

test('a recipe is readable only when Forge returns it', function (int $status, bool $readable) {
    Http::fake(['https://forge.test/api/orgs/acme/recipes/55' => Http::response(['message' => 'x'], $status)]);

    expect(forgeClient()->canReadRecipe('acme', '55'))->toBe($readable);
})->with([
    'visible' => [200, true],
    'hidden' => [403, false],
    'gone' => [404, false],
]);
