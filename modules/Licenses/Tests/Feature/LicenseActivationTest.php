<?php

namespace Modules\Licenses\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Licenses\Domain\Models\License;
use Modules\Licenses\Domain\Models\LicenseActivation;
use Modules\Users\Domain\Models\User;
use Tests\TestCase;

class LicenseActivationTest extends TestCase
{
    use RefreshDatabase;

    private function actAsStaff(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
    }

    public function test_activations_endpoint_requires_authentication(): void
    {
        $license = License::factory()->create();

        $this->getJson("/api/v1/licenses/{$license->uuid}/activations")->assertUnauthorized();
    }

    public function test_staff_can_activate_a_license_for_a_device(): void
    {
        $this->actAsStaff();
        $license = License::factory()->create(['max_activations' => 2]);

        $this->postJson("/api/v1/licenses/{$license->uuid}/activations", [
            'identifier_type' => 'device',
            'identifier' => 'device-abc-123',
            'name' => 'Front counter POS',
        ])
            ->assertCreated()
            ->assertJsonPath('data.identifier', 'device-abc-123')
            ->assertJsonPath('data.identifier_type', 'device')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('license_activations', [
            'license_id' => $license->id,
            'identifier' => 'device-abc-123',
            'revoked_at' => null,
        ]);
        $this->assertDatabaseHas('license_events', [
            'license_id' => $license->id,
            'event_type' => 'activated',
            'actor_type' => 'user',
        ]);
    }

    public function test_activating_the_same_identifier_is_idempotent(): void
    {
        $this->actAsStaff();
        $license = License::factory()->create(['max_activations' => 1]);

        $payload = ['identifier_type' => 'domain', 'identifier' => 'shop.example.com'];

        $this->postJson("/api/v1/licenses/{$license->uuid}/activations", $payload)->assertCreated();
        $this->postJson("/api/v1/licenses/{$license->uuid}/activations", $payload)->assertCreated();

        // Still exactly one slot occupied — the second call refreshed the row.
        $this->assertSame(1, $license->activeActivations()->count());
    }

    public function test_activation_limit_is_enforced(): void
    {
        $this->actAsStaff();
        $license = License::factory()->create(['max_activations' => 1]);

        $this->postJson("/api/v1/licenses/{$license->uuid}/activations", [
            'identifier_type' => 'domain', 'identifier' => 'a.example.com',
        ])->assertCreated();

        $this->postJson("/api/v1/licenses/{$license->uuid}/activations", [
            'identifier_type' => 'domain', 'identifier' => 'b.example.com',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_deactivating_frees_a_slot(): void
    {
        $this->actAsStaff();
        $license = License::factory()->create(['max_activations' => 1]);
        $activation = LicenseActivation::factory()->create(['license_id' => $license->id]);

        $this->deleteJson("/api/v1/licenses/{$license->uuid}/activations/{$activation->uuid}")
            ->assertNoContent();

        $this->assertNotNull($activation->refresh()->revoked_at);
        $this->assertDatabaseHas('license_events', [
            'license_id' => $license->id,
            'event_type' => 'deactivated',
        ]);

        // The freed slot can be reclaimed by a new identifier.
        $this->postJson("/api/v1/licenses/{$license->uuid}/activations", [
            'identifier_type' => 'domain', 'identifier' => 'fresh.example.com',
        ])->assertCreated();
    }

    public function test_reactivating_a_deactivated_identifier_reuses_the_row(): void
    {
        $this->actAsStaff();
        $license = License::factory()->create(['max_activations' => 1]);
        $activation = LicenseActivation::factory()->revoked()->create([
            'license_id' => $license->id,
            'identifier' => 'returning.example.com',
        ]);

        $this->postJson("/api/v1/licenses/{$license->uuid}/activations", [
            'identifier_type' => 'domain', 'identifier' => 'returning.example.com',
        ])
            ->assertCreated()
            ->assertJsonPath('data.id', $activation->uuid)
            ->assertJsonPath('data.is_active', true);

        $this->assertSame(1, $license->activations()->count());
    }

    public function test_cannot_activate_a_non_active_license(): void
    {
        $this->actAsStaff();
        $license = License::factory()->revoked()->create();

        $this->postJson("/api/v1/licenses/{$license->uuid}/activations", [
            'identifier_type' => 'domain', 'identifier' => 'x.example.com',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_activation_of_one_license_is_not_reachable_via_another(): void
    {
        $this->actAsStaff();
        $licenseA = License::factory()->create();
        $licenseB = License::factory()->create();
        $activation = LicenseActivation::factory()->create(['license_id' => $licenseA->id]);

        // Scoped bindings: activation belongs to A, not B.
        $this->deleteJson("/api/v1/licenses/{$licenseB->uuid}/activations/{$activation->uuid}")
            ->assertNotFound();
    }

    public function test_license_show_reports_activation_usage(): void
    {
        $this->actAsStaff();
        $license = License::factory()->create(['max_activations' => 3]);
        LicenseActivation::factory()->count(2)->create(['license_id' => $license->id]);
        LicenseActivation::factory()->revoked()->create(['license_id' => $license->id]);

        $this->getJson("/api/v1/licenses/{$license->uuid}")
            ->assertOk()
            ->assertJsonPath('data.max_activations', 3)
            ->assertJsonPath('data.activations_used', 2);
    }
}
