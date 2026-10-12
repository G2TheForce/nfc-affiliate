<?php

namespace Tests\Feature\Security;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Malformed input gets a clean refusal, never a server error or a loophole. */
class InputHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_array_values_where_strings_are_expected_are_refused_cleanly(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => ['a@b.c'], 'password' => 'x'])->assertUnprocessable();
        $this->get('/ref/NOSUCHCODE?to[]=x')->assertRedirect();
        $this->getJson('/api/v1/store/orders/01ARZ3NDEKTSV4RRFFQ69G5FAV/payment-status?access_token[]=x')->assertNotFound();
    }

    public function test_a_coupon_cannot_become_a_percentage_over_100_by_changing_only_its_type(): void
    {
        $admin = $this->userWithRole(Role::SuperAdmin);
        $id = $this->actingAs($admin)->postJson('/api/v1/admin/coupons', [
            'code' => 'FIXED500', 'discount_type' => 'fixed', 'discount_value' => 50000, 'is_active' => true,
        ])->assertCreated()->json('data.id');

        $this->actingAs($admin)->patchJson("/api/v1/admin/coupons/{$id}", ['discount_type' => 'percent'])
            ->assertUnprocessable()->assertJsonValidationErrors('discount_value');
    }

    public function test_one_account_is_locked_after_many_failures_even_from_many_addresses(): void
    {
        User::factory()->create(['email' => 'target@example.com']);
        for ($i = 0; $i < 30; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "198.51.100.{$i}"])
                ->postJson('/api/v1/auth/login', ['email' => 'target@example.com', 'password' => 'wrong-password'])->assertUnprocessable();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
            ->postJson('/api/v1/auth/login', ['email' => 'target@example.com', 'password' => 'wrong-password'])->assertTooManyRequests();
    }

    public function test_every_api_route_has_a_general_rate_limit(): void
    {
        $this->actingAs($this->userWithRole(Role::SuperAdmin))->getJson('/api/v1/admin/orders')->assertOk()->assertHeader('X-RateLimit-Limit', '120');
    }
}
