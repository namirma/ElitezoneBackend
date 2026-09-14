<?php

namespace Tests\Feature\Audit;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Regression tests for the critical-audit fixes.
 */
#[Group('audit-fixes')]
class CriticalFixesTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * C3 — the product "availability" filter previously queried a nonexistent
     * column `stock` (real column is `stock_quantity`) and threw
     * "Unknown column 'stock'" → HTTP 500 on every in_stock/out_of_stock request.
     */
    public function test_product_availability_filter_does_not_crash(): void
    {
        $this->getJson('/api/products?availability=in_stock')->assertStatus(200);
        $this->getJson('/api/products?availability=out_of_stock')->assertStatus(200);
    }

    /**
     * C5 — every error response must carry the documented {status,message,data}
     * contract. `data` is added additively (alongside the legacy `errors`) so
     * existing clients keep working.
     */
    public function test_error_envelope_includes_data_key(): void
    {
        // /authenticate/logout is behind auth:sanctum; with no token it returns a
        // 401 through the API exception handler.
        $response = $this->postJson('/api/authenticate/logout');

        $response->assertStatus(401);
        $response->assertJsonStructure(['status', 'message', 'data', 'errors']);
    }

    /**
     * C4 — tickets.status enum must cover the full helpdesk state machine
     * (Ticket::STATUSES), not just the original pending/answered/closed.
     */
    public function test_tickets_status_enum_supports_full_state_machine(): void
    {
        $column = DB::selectOne("SHOW COLUMNS FROM tickets WHERE Field = 'status'");

        $this->assertNotNull($column, 'tickets.status column not found');

        foreach (['open', 'in_progress', 'waiting_customer', 'waiting_supplier', 'resolved'] as $status) {
            $this->assertStringContainsString(
                $status,
                $column->Type,
                "tickets.status enum is missing '{$status}'"
            );
        }
    }

    /**
     * Login CORS fix — the HTTP staging origin must be allowlisted so the
     * staging login preflight is not blocked by CORS.
     */
    public function test_staging_http_origin_is_allowed_for_cors(): void
    {
        $this->assertContains('http://staging.elitezone.me', config('cors.allowed_origins'));
    }

    /**
     * Login CORS fix — an OPTIONS preflight must be answered directly with 2xx
     * and the CORS headers, never falling through to auth/redirect (which
     * triggers "Redirect is not allowed for a preflight request").
     *
     * Previously this drove `App\Http\Middleware\Cors` directly. That class is
     * gone: it carried a second, HARDCODED origin allowlist and wrote
     * Access-Control-Allow-Origin unconditionally, which overrode config/cors.php
     * and re-allowed http://localhost:* with credentials on production. The
     * behaviour it protected is unchanged — it is just enforced by Laravel's
     * global HandleCors now, so the test drives the real HTTP stack instead of a
     * middleware instance. See Security\CorsAllowlistTest.
     */
    public function test_cors_preflight_short_circuits_with_headers(): void
    {
        $response = $this->call(
            'OPTIONS',
            '/api/authenticate/check-user',
            server: [
                'HTTP_ORIGIN' => 'http://staging.elitezone.me',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            ],
        );

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame(
            'http://staging.elitezone.me',
            $response->headers->get('Access-Control-Allow-Origin')
        );
    }
}
