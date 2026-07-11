<?php

namespace Modules\Notifications\Application\Listeners;

use Illuminate\Support\Facades\Notification;
use Modules\Notifications\Application\Notifications\InvoicePaidNotification;
use Modules\Payments\Domain\Events\InvoicePaid;
use Modules\Users\Domain\Models\User;

/**
 * Notifies the billed company's users when an invoice is settled. Composition
 * consumer — reacts to the Payments `InvoicePaid` event and resolves recipients
 * from Users. If the company has no users, nothing is sent.
 */
final class SendInvoicePaidNotification
{
    public function handle(InvoicePaid $event): void
    {
        $invoice = $event->invoice;

        $recipients = User::query()
            ->where('company_id', $invoice->company_id)
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new InvoicePaidNotification(
            $invoice->uuid,
            $invoice->number,
            $invoice->amount,
            $invoice->currency,
        ));
    }
}
