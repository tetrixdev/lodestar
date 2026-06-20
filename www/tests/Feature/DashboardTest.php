<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboard screen is retired (task #69): its cross-project inbox is folded
 * into the unified board's "needs you" strip, so /dashboard now redirects to the
 * board. The controller/views stay dormant until #70 deletes them. The inbox
 * signals themselves are covered by the board tests (DeliverableFlowTest).
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_redirects_to_board(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('board'));
    }

    public function test_dashboard_requires_auth(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }
}
