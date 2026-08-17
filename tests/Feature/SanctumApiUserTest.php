<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SanctumApiUserTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 未認証ユーザーがSanctum認証必須のAPIへアクセスできないことを保証する。
     */
    public function test_guest_cannot_access_api_user(): void
    {
        $this
            ->getJson('/api/user')
            ->assertUnauthorized();
    }

    /**
     * 有効なPersonal Access Tokenでトークン発行ユーザーを取得できることを保証する。
     */
    public function test_user_can_access_api_user_with_valid_bearer_token(): void
    {
        $user = User::factory()->create();
        $plainTextToken = $user->createToken('sanctum-api-user-test')->plainTextToken;

        $this
            ->withToken($plainTextToken)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('id', $user->id);
    }
}
