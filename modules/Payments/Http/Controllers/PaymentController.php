<?php

namespace Modules\Payments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Payments\Application\Services\PaymentService;
use Modules\Payments\Domain\Enums\PaymentMethod;
use Modules\Payments\Domain\Models\Invoice;
use Modules\Payments\Http\Concerns\ResolvesActor;
use Modules\Payments\Http\Requests\RecordPaymentRequest;
use Modules\Payments\Http\Resources\PaymentResource;

/**
 * Records a payment against an invoice, settling it. This increment collects the
 * full amount through the manual/offline gateway (ADR 0006).
 */
final class PaymentController extends ApiController
{
    use ResolvesActor;

    public function __construct(private readonly PaymentService $payments) {}

    public function store(RecordPaymentRequest $request, Invoice $invoice): JsonResponse
    {
        $payment = $this->payments->recordPayment(
            $invoice,
            PaymentMethod::from((string) $request->string('method')),
            $request->filled('reference') ? (string) $request->string('reference') : null,
            $this->actorId($request),
        );

        return PaymentResource::make($payment)
            ->response()
            ->setStatusCode(201);
    }
}
