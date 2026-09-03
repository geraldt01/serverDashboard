<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_update_a_users_name_email_and_role(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['name' => 'Old Name', 'email' => 'old@example.com', 'role' => 'viewer']);

        $response = $this->actingAs($admin)->put(route('users.update', $user), [
            'name' => 'New Name',
            'email' => 'new@example.com',
            'role' => 'admin',
        ]);

        $response->assertRedirect(route('users.index'));
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'New Name',
            'email' => 'new@example.com',
            'role' => 'admin',
        ]);
    }

    public function test_a_users_password_is_unchanged_when_left_blank(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'viewer']);
        $originalPassword = $user->password;

        $response = $this->actingAs($admin)->put(route('users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame($originalPassword, $user->fresh()->password);
    }

    public function test_a_users_password_can_be_changed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'viewer']);
        $originalPassword = $user->password;

        $response = $this->actingAs($admin)->put(route('users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'password' => 'a-new-secure-password',
            'password_confirmation' => 'a-new-secure-password',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertNotSame($originalPassword, $user->fresh()->password);
    }

    public function test_a_viewer_cannot_update_users(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $user = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)->put(route('users.update', $user), [
            'name' => 'New Name',
            'email' => 'new@example.com',
            'role' => 'admin',
        ])->assertForbidden();
    }

    public function test_email_must_be_unique_when_updating_a_user(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $existing = User::factory()->create(['email' => 'taken@example.com']);
        $user = User::factory()->create(['email' => 'user@example.com']);

        $this->actingAs($admin)->put(route('users.update', $user), [
            'name' => $user->name,
            'email' => 'taken@example.com',
            'role' => 'viewer',
        ])->assertSessionHasErrors('email');
    }
}
