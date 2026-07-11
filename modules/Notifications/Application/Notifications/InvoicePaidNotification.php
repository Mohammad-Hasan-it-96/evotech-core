<?php

namespace Modules\Notifications\Application\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your payment was received." Sent to the billed company's users when an invoice
 * is settled. Carries only scalars (not the Invoice model) so it serializes
 * cleanly on the queue and keeps the Notifications module decoupled from Payments'
 * models — it reacts to the InvoicePaid event, it does not own the invoice.
 */
final class InvoicePaidNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $invoiceUuid,
        private readonly string $number,
        private readonly string $amount,
        private readonly string $currency,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'invoice.paid',
            'title' => __('Payment received'),
            'invoice' => [
                'id' => $this->invoiceUuid,
                'number' => $this->number,
                'amount' => $this->amount,
                'currency' => $this->currency,
            ],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Payment received — invoice :number', ['number' => $this->number]))
            ->line(__('We received your payment of :amount :currency for invoice :number.', [
                'amount' => $this->amount,
                'currency' => $this->currency,
                'number' => $this->number,
            ]))
            ->line(__('Thank you.'));
    }
}
