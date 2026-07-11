<?php

namespace Modules\Licenses\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Licenses\Application\Services\LicenseService;
use Modules\Licenses\Domain\Models\License;
use Modules\Licenses\Domain\Models\LicenseActivation;
use Modules\Licenses\Http\Concerns\ResolvesActor;
use Modules\Licenses\Http\Requests\ActivateLicenseRequest;
use Modules\Licenses\Http\Resources\LicenseActivationResource;
use Modules\Subscriptions\Domain\Enums\IdentifierType;

/**
 * Admin management of a license's device/domain activations. Product-facing
 * self-activation (behind per-product auth) is a later Phase 4 step.
 */
final class LicenseActivationController extends ApiController
{
    use ResolvesActor;

    public function __construct(private readonly LicenseService $licenses) {}

    public function index(License $license): AnonymousResourceCollection
    {
        return LicenseActivationResource::collection(
            $license->activations()->latest('activated_at')->get()
        );
    }

    public function store(ActivateLicenseRequest $request, License $license): JsonResponse
    {
        $activation = $this->licenses->activate(
            $license,
            IdentifierType::from((string) $request->string('identifier_type')),
            (string) $request->string('identifier'),
            $request->filled('name') ? (string) $request->string('name') : null,
            $this->actorId($request),
        );

        return LicenseActivationResource::make($activation)
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Request $request, License $license, LicenseActivation $activation): JsonResponse
    {
        $this->licenses->deactivate($activation, $this->actorId($request));

        return $this->noContent();
    }
}
