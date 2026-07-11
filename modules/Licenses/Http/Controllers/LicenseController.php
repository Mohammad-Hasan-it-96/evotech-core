<?php

namespace Modules\Licenses\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Licenses\Application\Services\LicenseService;
use Modules\Licenses\Domain\Models\License;
use Modules\Licenses\Http\Concerns\ResolvesActor;
use Modules\Licenses\Http\Requests\IssueLicenseRequest;
use Modules\Licenses\Http\Resources\LicenseResource;
use Modules\Subscriptions\Domain\Models\Subscription;

final class LicenseController extends ApiController
{
    use ResolvesActor;

    private const WITH = ['company', 'subscription.plan.product'];

    public function __construct(private readonly LicenseService $licenses) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(max($request->integer('per_page', 15), 1), 100);

        return LicenseResource::collection($this->licenses->paginate($perPage));
    }

    public function store(IssueLicenseRequest $request): JsonResponse
    {
        $subscription = Subscription::query()
            ->where('uuid', (string) $request->string('subscription'))
            ->firstOrFail();

        $maxActivations = $request->filled('max_activations') ? $request->integer('max_activations') : null;

        $license = $this->licenses->issueForSubscription($subscription, $this->actorId($request), $maxActivations);

        return $this->present($license)
            ->response()
            ->setStatusCode(201);
    }

    public function show(License $license): LicenseResource
    {
        return LicenseResource::make(
            $license->load([...self::WITH, 'activations'])->loadCount('activeActivations')
        );
    }

    public function suspend(Request $request, License $license): LicenseResource
    {
        return $this->present($this->licenses->suspend($license, $this->actorId($request)));
    }

    public function reactivate(Request $request, License $license): LicenseResource
    {
        return $this->present($this->licenses->reactivate($license, $this->actorId($request)));
    }

    public function revoke(Request $request, License $license): LicenseResource
    {
        return $this->present($this->licenses->revoke($license, $this->actorId($request)));
    }

    private function present(License $license): LicenseResource
    {
        return LicenseResource::make($license->load(self::WITH)->loadCount('activeActivations'));
    }
}
