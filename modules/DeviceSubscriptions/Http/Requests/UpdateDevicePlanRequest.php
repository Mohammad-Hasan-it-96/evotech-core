<?php

namespace Modules\DeviceSubscriptions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\DeviceSubscriptions\Domain\Models\DevicePlan;

class UpdateDevicePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Everything is `sometimes` — this is a PATCH.
     *
     * Note what is absent: `key` and `app`. Both are immutable after creation.
     * device_subscriptions rows store the key, and renewals resolve a duration by
     * matching it; re-keying a plan silently turns every holder's next renewal into
     * a 0-month term. Moving a plan between apps has the same effect. Operators who
     * genuinely want a different key create a new plan and disable the old one,
     * which leaves existing holders resolvable.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'duration_months' => ['sometimes', 'integer', 'min:1', 'max:120'],
            'price' => ['sometimes', 'numeric', 'min:0', 'max:99999999'],
            'price_after_discount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'enabled' => ['sometimes', 'boolean'],
            'recommended' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'duration_months.min' => 'A plan must last at least one month, or it expires the moment it is sold.',
        ];
    }

    /**
     * `lte:price` cannot be used here: on a PATCH that sends only the discount,
     * there is no `price` field in the payload to compare against, so the rule
     * would pass vacuously. Compare against the incoming price when present and the
     * stored one otherwise.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $discount = $this->input('price_after_discount');

            if ($discount === null || ! is_numeric($discount)) {
                return;
            }

            $plan = $this->route('devicePlan');

            $price = $this->has('price')
                ? $this->input('price')
                : ($plan instanceof DevicePlan ? $plan->price : null);

            if (is_numeric($price) && (float) $discount > (float) $price) {
                $validator->errors()->add(
                    'price_after_discount',
                    'The discounted price cannot be higher than the price.',
                );
            }
        });
    }
}
