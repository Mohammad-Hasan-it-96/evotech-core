<?php

namespace Modules\Notifications\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Modules\Companies\Domain\Models\Company;
use Modules\Notifications\Application\Notifications\InvoicePaidNotification;
use Modules\Payments\Application\Services\PaymentService;
use Modules\Payments\Domain\Enums\PaymentMethod;
use Modules\Payments\Domain\Models\Invoice;
use Modules\Users\Domain\Models\User;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private function seedNotifications(User $user, int $count = 1): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $user->notify(new InvoicePaidNotification("uuid-{$i}", 'INV-00000'.$i, '50.00', 'USD'));
        }
    }

    private function firstNotificationId(): string
    {
        $id = $this->getJson('/api/v1/notifications')->json('data.0.id');

        return is_string($id) ? $id : '';
    }

    // --- Event-driven dispatch ---

    public function test_settling_an_invoice_notifies_the_companys_users(): void
    {
        Notification::fake();
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $invoice = Invoice::factory()->create(['company_id' => $company->id]);

        app(PaymentService::class)->recordPayment($invoice, PaymentMethod::BankTransfer, 'TXN-1');

        Notification::assertSentTo($user, InvoicePaidNotification::class);
    }

    public function test_nothing_is_sent_when_the_company_has_no_users(): void
    {
        Notification::fake();
        $company = Company::factory()->create();
        $invoice = Invoice::factory()->create(['company_id' => $company->id]);

        app(PaymentService::class)->recordPayment($invoice, PaymentMethod::Cash, null);

        Notification::assertNothingSent();
    }

    // --- The dashboard "bell" API ---

    public function test_listing_requires_authentication(): void
    {
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
    }

    public function test_a_user_can_list_their_notifications(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $this->seedNotifications($user, 2);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.type', 'invoice.paid')
            ->assertJsonPath('data.0.read', false);
    }

    public function test_unread_count(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $this->seedNotifications($user, 3);

        $this->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread', 3);
    }

    public function test_a_user_can_mark_one_notification_read(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $this->seedNotifications($user, 2);

        $id = $this->firstNotificationId();
        $this->postJson("/api/v1/notifications/{$id}/read")->assertNoContent();

        $this->getJson('/api/v1/notifications/unread-count')
            ->assertJsonPath('data.unread', 1);
    }

    public function test_a_user_can_mark_all_read(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $this->seedNotifications($user, 3);

        $this->postJson('/api/v1/notifications/read-all')->assertNoContent();

        $this->getJson('/api/v1/notifications/unread-count')
            ->assertJsonPath('data.unread', 0);
    }

    public function test_a_user_only_sees_their_own_notifications(): void
    {
        $owner = User::factory()->create();
        $this->seedNotifications($owner, 2);

        $other = User::factory()->create();
        Sanctum::actingAs($other, ['*']);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
