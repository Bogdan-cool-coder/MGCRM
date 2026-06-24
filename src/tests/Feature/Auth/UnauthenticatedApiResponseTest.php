<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NEW-4: unauthenticated API requests must return a clean JSON 401, never a 500.
 *
 * Before the fix, the Authenticate middleware tried to redirect guests to the
 * non-existent `login` named route and threw RouteNotFoundException → HTTP 500
 * with a full stack trace (information disclosure). This only surfaced when the
 * request did NOT carry `Accept: application/json` (the SPA path), because
 * expectsJson() short-circuits the redirect. bootstrap/app.php now sets
 * redirectGuestsTo(... null) so the guard always throws AuthenticationException,
 * which renders as 401 JSON via shouldRenderJsonWhen('api/*').
 */
class UnauthenticatedApiResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_json_request_returns_401(): void
    {
        $this->getJson('/api/me')
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_unauthenticated_request_without_json_accept_header_returns_401_not_500(): void
    {
        // The NEW-4 repro: a plain request with no Accept: application/json.
        // Must NOT be a 500 and must NOT leak a stack trace (no `exception`
        // / `trace` keys), regardless of the Accept header.
        $response = $this->get('/api/me');

        $response->assertStatus(401);
        $this->assertArrayNotHasKey('exception', (array) $response->json());
        $this->assertArrayNotHasKey('trace', (array) $response->json());
    }
}
