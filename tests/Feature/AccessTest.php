<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_must_log_in_and_registration_is_unavailable(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/publications')->assertRedirect('/login');
        $this->get('/register')->assertNotFound();
    }

    public function test_examiner_cannot_open_admin_settings(): void
    {
        $user = User::factory()->create(['role' => 'examiner']);
        $this->actingAs($user)->get('/settings')->assertForbidden();
    }

    public function test_inactive_employee_cannot_log_in(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_admin_creates_an_employee_and_password_is_hashed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/settings/users', [
            'name' => 'Pemeriksa Publikasi', 'email' => 'pemeriksa@example.test',
            'role' => 'examiner', 'password' => 'StrongPassword123!',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $employee = User::where('email', 'pemeriksa@example.test')->firstOrFail();
        $this->assertNotSame('StrongPassword123!', $employee->password);
        $this->assertTrue($employee->is_active);
    }

    public function test_admin_cannot_deactivate_self_with_string_zero(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->put('/settings/users/'.$admin->id, ['name' => $admin->name, 'email' => $admin->email, 'role' => 'admin', 'is_active' => '0'])->assertSessionHasErrors('role');
        $this->assertTrue($admin->fresh()->is_active);
    }
}
