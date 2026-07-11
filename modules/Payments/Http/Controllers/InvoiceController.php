<?php

namespace Modules\Payments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Payments\Application\Services\PaymentService;
use Modules\Payments\Domain\Models\Invoice;
use Modules\Payments\Http\Concerns\ResolvesActor;
use Modules\Payments\Http\Requests\IssueInvoiceRequest;
use Modules\Payments\Http\Resources\InvoiceResource;
use Modules\Subscriptions\Domain\Models\Subscription;

final class InvoiceController extends ApiController
{
    use ResolvesActor;

    private const WITH = ['company', 'subscription.plan.product'];

    public function __construct(private readonly PaymentService $payments) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(max($request->integer('per_page', 15), 1), 100);

        return InvoiceResource::collection($this->payments->paginate($perPage));
    }

    public function store(IssueInvoiceRequest $request): JsonResponse
    {
        $subscription = Subscription::query()
            ->where('uuid', (string) $request->string('subscription'))
            ->firstOrFail();

        $invoice = $this->payments->issueForSubscription($subscription, $this->actorId($request));

        return $this->present($invoice)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Invoice $invoice): InvoiceResource
    {
        return InvoiceResource::make(
            $invoice->load([...self::WITH, 'payments'])->loadCount('payments')
        );
    }

    public function void(Request $request, Invoice $invoice): InvoiceResource
    {
        return $this->present($this->payments->void($invoice, $this->actorId($request)));
    }

    private function present(Invoice $invoice): InvoiceResource
    {
        return InvoiceResource::make($invoice->load(self::WITH)->loadCount('payments'));
    }
}
