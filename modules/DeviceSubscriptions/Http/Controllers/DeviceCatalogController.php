<?php

namespace Modules\DeviceSubscriptions\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Core\Http\Controllers\ApiController;
use Modules\DeviceSubscriptions\Application\Services\DeviceCatalogService;
use Modules\DeviceSubscriptions\Domain\Models\DeviceApp;
use Modules\DeviceSubscriptions\Domain\Models\DevicePlan;
use Modules\DeviceSubscriptions\Http\Requests\StoreDevicePlanRequest;
use Modules\DeviceSubscriptions\Http\Requests\UpdateDeviceAppRequest;
use Modules\DeviceSubscriptions\Http\Requests\UpdateDevicePlanRequest;
use Modules\DeviceSubscriptions\Http\Resources\DeviceAppResource;
use Modules\DeviceSubscriptions\Http\Resources\DevicePlanResource;
use Modules\Products\Domain\Models\Product;

/**
 * Staff CRUD for the consumer-app catalog (auth:sanctum) — what the dashboard's
 * plans editor calls.
 *
 * Distinct from DeviceAdminController::plansV1, which serves the *legacy-shaped*
 * plan list for the activate dialog and must keep matching what the device sees.
 * This controller is the admin view: it includes disabled plans, exposes both the
 * uuid and the plan key, and is the only place either can be written.
 */
final class DeviceCatalogController extends ApiController
{
    public function __construct(private readonly DeviceCatalogService $catalog) {}

    // --- Apps -----------------------------------------------------------------

    public function apps(): AnonymousResourceCollection
    {
        return DeviceAppResource::collection(
            DeviceApp::query()->withCount('plans')->orderBy('name')->get(),
        );
    }

    public function updateApp(UpdateDeviceAppRequest $request, DeviceApp $deviceApp): DeviceAppResource
    {
        $attributes = [];

        foreach (['label', 'trial_days', 'uses_shared_plans'] as $field) {
            if ($request->has($field)) {
                $attributes[$field] = $request->validated($field);
            }
        }

        if ($request->has('product')) {
            $slug = $request->input('product');

            $attributes['product_id'] = $slug === null
                ? null
                : Product::query()->where('slug', $slug)->value('id');
        }

        return DeviceAppResource::make($this->catalog->updateApp($deviceApp, $attributes));
    }

    // --- Plans ----------------------------------------------------------------

    /**
     * The catalog for one scope. `?app=<uuid>` lists that app's own plans; omitting
     * it lists the shared catalog.
     *
     * Disabled plans are included — this is the editor, and a disabled plan is
     * exactly the thing an operator needs to see in order to re-enable it.
     */
    public function plans(Request $request): AnonymousResourceCollection
    {
        $appUuid = $request->query('app');

        $query = DevicePlan::query()->with('app');

        if (is_string($appUuid) && $appUuid !== '') {
            $app = DeviceApp::query()->where('uuid', $appUuid)->firstOrFail();
            $query->where('device_app_id', $app->id);
        } else {
            $query->whereNull('device_app_id');
        }

        return DevicePlanResource::collection(
            $query->orderBy('sort_order')->orderBy('id')->get(),
        );
    }

    public function storePlan(StoreDevicePlanRequest $request): DevicePlanResource|JsonResponse
    {
        $appUuid = $request->input('app');

        $app = is_string($appUuid) && $appUuid !== ''
            ? DeviceApp::query()->where('uuid', $appUuid)->firstOrFail()
            : null;

        /*
         * Scope uniqueness, enforced here because the table's unique index cannot
         * see it for shared plans: `device_app_id` is NULL there, and SQL treats
         * NULLs as distinct, so two shared plans could both be 'yearly'. Which one
         * a renewal resolved to would then be arbitrary.
         */
        $duplicate = DevicePlan::query()
            ->where('plan_key', $request->string('key')->value())
            ->when(
                $app === null,
                fn ($query) => $query->whereNull('device_app_id'),
                fn ($query) => $query->where('device_app_id', $app?->id),
            )
            ->exists();

        if ($duplicate) {
            return response()->json([
                'message' => $app === null
                    ? 'A shared plan with this key already exists.'
                    : "The app {$app->name} already has a plan with this key.",
            ], 422);
        }

        $plan = $this->catalog->createPlan([
            'device_app_id' => $app?->id,
            'plan_key' => $request->string('key')->value(),
            'title' => $request->string('title')->value(),
            'description' => $request->input('description'),
            'duration_months' => $request->integer('duration_months'),
            'price' => $request->input('price'),
            'price_after_discount' => $request->input('price_after_discount'),
            'enabled' => $request->boolean('enabled', true),
            'recommended' => $request->boolean('recommended', false),
            'sort_order' => $request->integer('sort_order'),
        ]);

        return DevicePlanResource::make($plan);
    }

    public function updatePlan(UpdateDevicePlanRequest $request, DevicePlan $devicePlan): DevicePlanResource
    {
        return DevicePlanResource::make(
            $this->catalog->updatePlan($devicePlan, $request->validated()),
        );
    }

    /**
     * Deleting a plan that devices hold is refused outright — no `force` escape
     * hatch, unlike deleting a device.
     *
     * The difference is that a forced device delete has a visible, explainable
     * consequence the operator is warned about, while this one is silent and
     * deferred: nothing breaks today, and then a renewal weeks later grants a
     * 0-month term to someone who has just paid. Disabling the plan achieves the
     * operator's actual goal — off the store, still resolvable — so there is no
     * legitimate case for the destructive version.
     */
    public function destroyPlan(DevicePlan $devicePlan): JsonResponse
    {
        $holders = $this->catalog->subscriberCount($devicePlan);

        if ($holders > 0) {
            return response()->json([
                'message' => "{$holders} device(s) are subscribed on this plan, and deleting it would break their renewal. Disable it instead — it stays hidden from the app but keeps working for existing subscribers.",
            ], 422);
        }

        $this->catalog->deletePlan($devicePlan);

        return response()->json(status: 204);
    }
}
