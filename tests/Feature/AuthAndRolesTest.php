<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuthAndRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_admin_can_login_and_create_dynamic_role(): void
    {
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);

        $login->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['access_token', 'token_type', 'expires_in', 'user'],
            ]);

        $token = $login->json('data.access_token');

        $createRole = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/roles', [
                'name' => 'editor',
                'permissions' => [
                    'users.view',
                    'roles.view',
                    'permissions.view',
                    'settings.view',
                ],
            ]);

        $createRole->assertCreated()
            ->assertJsonPath('data.name', 'editor')
            ->assertJsonCount(4, 'data.permissions');

        $catalog = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/permissions/catalog');

        $catalog->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['users', 'roles', 'permissions', 'settings']]);
    }

    public function test_protected_super_admin_role_cannot_be_deleted(): void
    {
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);

        $token = $login->json('data.access_token');

        $roleId = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/roles?search=super-admin')
            ->json('data.0.id');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/roles/{$roleId}")
            ->assertStatus(422);
    }

    public function test_user_can_update_profile_and_password(): void
    {
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);

        $token = $login->json('data.access_token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/auth/profile', [
                'name' => 'Admin Actualizado',
                'phone' => '3001234567',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Admin Actualizado')
            ->assertJsonPath('data.phone', '3001234567');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/auth/profile', [
                'current_password' => 'password',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'password123',
        ])->assertOk();
    }

    public function test_user_can_upload_and_remove_avatar(): void
    {
        Storage::fake('public');

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);

        $token = $login->json('data.access_token');
        $file = UploadedFile::fake()->image('avatar.jpg', 400, 400);

        $upload = $this->withHeader('Authorization', "Bearer {$token}")
            ->post('/api/v1/auth/avatar', [
                'avatar' => $file,
            ], [
                'Accept' => 'application/json',
            ]);

        $upload->assertOk()
            ->assertJsonPath('success', true);

        $avatarPath = $upload->json('data.avatar');
        Storage::disk('public')->assertExists($avatarPath);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/v1/auth/avatar')
            ->assertOk()
            ->assertJsonPath('data.avatar', null);

        Storage::disk('public')->assertMissing($avatarPath);
    }

    public function test_admin_can_create_user(): void
    {
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);

        $token = $login->json('data.access_token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/users', [
                'name' => 'Usuario Demo',
                'email' => 'user@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
                'roles' => ['admin'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'user@example.com');
    }
}
