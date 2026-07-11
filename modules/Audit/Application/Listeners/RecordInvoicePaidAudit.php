<?php

namespace Modules\Audit\Application\Listeners;

use Modules\Core\Domain\Contracts\AuditLogger;
use Modules\Payments\Domain\Events\InvoicePaid;

/**
 * Audits invoice settlement (§6.14 — "payment"). Runs synchronously so the
 * request's actor/IP are captured.
 */
final class RecordInvoicePaidAudit
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(InvoicePaid $event): void
    {
        $this->audit->log('invoice.paid', 'invoice', $event->invoice->uuid, [
            'number' => $event->invoice->number,
            'amount' => $event->invoice->amount,
            'currency' => $event->invoice->currency,
            'payment' => $event->payment->uuid,
        ]);
    }
}
