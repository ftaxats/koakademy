<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class TrailObservabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_access_view_trail_gate(): void
    {
        $superAdmin = User::factory()->create([
            'role' => UserRole::SuperAdmin,
        ]);

        $this->assertTrue(Gate::forUser($superAdmin)->allows('viewTrail'));
    }

    public function test_developer_can_access_view_trail_gate(): void
    {
        $developer = User::factory()->create([
            'role' => UserRole::Developer,
        ]);

        $this->assertTrue(Gate::forUser($developer)->allows('viewTrail'));
    }

    public function test_regular_student_or_unauthorized_user_cannot_access_view_trail_gate(): void
    {
        $student = User::factory()->create([
            'role' => UserRole::Student,
        ]);

        $this->assertFalse(Gate::forUser($student)->allows('viewTrail'));
    }

    public function test_trail_dashboard_returns_success_for_authorized_user(): void
    {
        $superAdmin = User::factory()->create([
            'role' => UserRole::SuperAdmin,
        ]);

        $response = $this->actingAs($superAdmin)->get('/trail');

        $response->assertSuccessful();
    }

    public function test_trail_dashboard_denies_unauthorized_user_outside_local(): void
    {
        $student = User::factory()->create([
            'role' => UserRole::Student,
        ]);

        $this->app['env'] = 'production';

        $response = $this->actingAs($student)->get('/trail');

        $response->assertForbidden();
    }

    public function test_trail_dashboard_returns_404_when_disabled(): void
    {
        $superAdmin = User::factory()->create([
            'role' => UserRole::SuperAdmin,
        ]);

        Config::set('trail.dashboard.enabled', false);

        $response = $this->actingAs($superAdmin)->get('/trail');

        $response->assertNotFound();
    }

    public function test_trail_prune_and_sweep_commands_run_cleanly(): void
    {
        $this->artisan('trail:sweep')->assertSuccessful();
        $this->artisan('trail:prune')->assertSuccessful();
    }
}
