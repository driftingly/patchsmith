<?php

namespace App\Services\Forge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Thin client for the Forge API that paces requests under the rate limit
 * and retries network errors, 429s and 5xx responses.
 */
class ForgeClient
{
    private float $lastRequestAt = 0.0;

    public function __construct(
        #[\SensitiveParameter] private readonly string $token,
        private readonly string $baseUrl = 'https://forge.laravel.com/api',
        private readonly float $secondsBetweenRequests = 1.05,
        private readonly int $maxAttempts = 5,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function organizations(): array
    {
        return $this->paginate('/orgs');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function servers(string $organization): array
    {
        return $this->paginate("/orgs/{$organization}/servers");
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function teams(string $organization): array
    {
        return $this->paginate("/orgs/{$organization}/teams");
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function archivedServers(string $organization): array
    {
        return $this->paginate("/orgs/{$organization}/servers/archives");
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function phpVersions(string $organization, int|string $serverId): array
    {
        return $this->paginate("/orgs/{$organization}/servers/{$serverId}/php/versions");
    }

    /**
     * Fetch the current attributes of a single PHP version.
     *
     * @return array<string, mixed>
     */
    public function phpVersion(string $organization, int|string $serverId, int|string $versionId): array
    {
        $response = $this->request('GET', "/orgs/{$organization}/servers/{$serverId}/php/versions/{$versionId}");

        return $response->json('data.attributes') ?? [];
    }

    /**
     * Ask Forge to patch a PHP version to its latest patch release.
     *
     * @throws ForgeApiException when Forge rejects the request
     */
    public function updatePhpVersion(string $organization, int|string $serverId, int|string $versionId): void
    {
        $path = "/orgs/{$organization}/servers/{$serverId}/php/versions/{$versionId}";
        $response = $this->request('PUT', $path);

        if (! in_array($response->status(), [200, 202, 204], true)) {
            throw ForgeApiException::fromResponse('PUT', $path, $response);
        }
    }

    /**
     * Create a recipe and return its ID.
     *
     * @throws ForgeApiException
     */
    public function createRecipe(string $organization, string $name, string $user, string $script, ?int $teamId = null): string
    {
        $path = "/orgs/{$organization}/recipes";
        $body = ['name' => $name, 'user' => $user, 'script' => $script];

        if ($teamId !== null) {
            $body['team_id'] = $teamId;
        }

        $response = $this->request('POST', $path, body: $body);

        if (! in_array($response->status(), [200, 201], true)) {
            throw ForgeApiException::fromResponse('POST', $path, $response);
        }

        return (string) $response->json('data.id');
    }

    /**
     * Whether this token can read the recipe. Forge can create a recipe that
     * its creator is not allowed to see, run or delete.
     *
     * @throws ForgeApiException when every attempt failed
     */
    public function canReadRecipe(string $organization, int|string $recipeId): bool
    {
        return $this->request('GET', "/orgs/{$organization}/recipes/{$recipeId}")->status() === 200;
    }

    /**
     * Start a recipe on the given servers. Forge runs it in the background.
     *
     * @param  list<int>  $serverIds
     *
     * @throws ForgeApiException
     */
    public function runRecipe(string $organization, int|string $recipeId, array $serverIds): void
    {
        $path = "/orgs/{$organization}/recipes/{$recipeId}/runs";
        $response = $this->request('POST', $path, body: ['servers' => $serverIds]);

        if (! in_array($response->status(), [200, 202, 204], true)) {
            throw ForgeApiException::fromResponse('POST', $path, $response);
        }
    }

    /**
     * Every run of a recipe, one entry per server it ran on.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws ForgeApiException
     */
    public function recipeRuns(string $organization, int|string $recipeId): array
    {
        return $this->paginate("/orgs/{$organization}/recipes/{$recipeId}/runs");
    }

    /**
     * @throws ForgeApiException
     */
    public function deleteRecipe(string $organization, int|string $recipeId): void
    {
        $path = "/orgs/{$organization}/recipes/{$recipeId}";
        $response = $this->request('DELETE', $path);

        if (! in_array($response->status(), [200, 202, 204, 404], true)) {
            throw ForgeApiException::fromResponse('DELETE', $path, $response);
        }
    }

    /**
     * Walk every page of a cursor paginated collection.
     *
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     *
     * @throws ForgeApiException
     */
    public function paginate(string $path, array $query = []): array
    {
        $items = [];
        $cursor = null;

        do {
            $pageQuery = $query + ['page[size]' => 100];

            if ($cursor !== null) {
                $pageQuery['page[cursor]'] = $cursor;
            }

            $response = $this->request('GET', $path, $pageQuery);

            if ($response->status() !== 200) {
                throw ForgeApiException::fromResponse('GET', $path, $response);
            }

            $items = array_merge($items, $response->json('data') ?? []);
            $cursor = $response->json('meta.next_cursor');
        } while ($cursor !== null);

        return $items;
    }

    /**
     * Send one request, retrying transient failures and honouring rate limits.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     *
     * @throws ForgeApiException when every attempt failed
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null): Response
    {
        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $this->throttle();

            try {
                $response = Http::baseUrl($this->baseUrl)
                    ->withToken($this->token)
                    ->acceptJson()
                    ->asJson()
                    ->timeout(60)
                    ->send($method, ltrim($path, '/'), array_filter(['query' => $query, 'json' => $body], fn ($value) => $value !== null));
            } catch (ConnectionException) {
                Sleep::for(5 * $attempt)->seconds();

                continue;
            }

            if ($response->status() === 429) {
                Sleep::for($this->secondsUntilRateLimitResets($response))->seconds();

                continue;
            }

            if ($response->serverError()) {
                Sleep::for(10 * $attempt)->seconds();

                continue;
            }

            return $response;
        }

        throw ForgeApiException::retriesExhausted($method, $path, $this->maxAttempts);
    }

    /**
     * Stay under the per-minute request limit by spacing requests out.
     */
    private function throttle(): void
    {
        $elapsed = microtime(true) - $this->lastRequestAt;

        if ($elapsed < $this->secondsBetweenRequests) {
            Sleep::for((int) (($this->secondsBetweenRequests - $elapsed) * 1_000_000))->microseconds();
        }

        $this->lastRequestAt = microtime(true);
    }

    private function secondsUntilRateLimitResets(Response $response): int
    {
        $resetAt = (int) $response->header('x-ratelimit-reset');
        $seconds = $resetAt > time() ? $resetAt - time() + 1 : 30;

        return min($seconds, 120);
    }
}
