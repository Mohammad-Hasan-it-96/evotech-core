<?php

namespace Modules\Subscriptions\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Subscriptions\Application\Services\SubscriptionService;
use Modules\Subscriptions\Domain\Models\Subscription;
use Modules\Subscriptions\Http\Requests\StoreSubscriptionRequest;
use Modules\Subscriptions\Http\Requests\UpdateSubscriptionRequest;
use Modules\Subscriptions\Http\Resources\SubscriptionResource;

final class SubscriptionController extends ApiController
{
    private const WITH = ['company', 'plan.product'];

    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(max($request->integer('per_page', 15), 1), 100);

        return SubscriptionResource::collection($this->subscriptions->paginate($perPage));
    }

    public function store(StoreSubscriptionRequest $request): JsonResponse
    {
        $subscription = $this->subscriptions->create($request->toData());

        return SubscriptionResource::make($subscription->load(self::WITH))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Subscription $subscription): SubscriptionResource
    {
        return SubscriptionResource::make($subscription->load(self::WITH));
    }

    public function update(UpdateSubscriptionRequest $request, Subscription $subscription): SubscriptionResource
    {
        $updated = $this->subscriptions->update($subscription, $request->toData());

        return SubscriptionResource::make($updated->load(self::WITH));
    }

    public function destroy(Subscription $subscription): JsonResponse
    {
        $this->subscriptions->delete($subscription);

        return $this->noContent();
    }

    public function renew(Subscription $subscription): SubscriptionResource
    {
        return SubscriptionResource::make(
            $this->subscriptions->renew($subscription)->load(self::WITH)
        );
    }

    public function cancel(Subscription $subscription): SubscriptionResource
    {
        return SubscriptionResource::make(
            $this->subscriptions->cancel($subscription)->load(self::WITH)
        );
    }
}
