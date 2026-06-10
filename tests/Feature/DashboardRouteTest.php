<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardRouteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ログイン済みユーザーでも未使用の /dashboard へアクセスできないことを確認する。
     */
    public function test_authenticated_user_cannot_access_dashboard_route(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/dashboard');

        $response->assertNotFound();
    }
}
