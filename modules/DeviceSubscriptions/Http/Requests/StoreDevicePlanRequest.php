<?php

namespace Modules\DeviceSubscriptions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDevicePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Omitted / null = the shared catalog served by the un-namespaced
            // /api/getPlans, which carries no app_name.
            'app' => ['nullable', 'string', 'exists:device_apps,uuid'],

            /*
             * The identifier the app sends back and device rows store. Constrained
             * to a conservative charset because it travels through URLs and JSON
             * written by parsers we cannot update remotely.
             *
             * Uniqueness within the scope is enforced in the controller: the shared
             * scope is `device_app_id IS NULL`, and SQL treats NULLs as distinct,
             * so the table's unique index cannot see a duplicate there.
             */
            'key' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/'],

            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],

            /*
             * Minimum 1. A 0-month plan is not a free tier — activation adds the
             * duration to today, so it sells a subscription that has already
             * expired at the moment it is granted.
             */
            'duration_months' => ['required', 'integer', 'min:1', 'max:120'],

            'price' => ['required', 'numeric', 'min:0', 'max:99999999'],

            // A "discount" above the price would display as an increase in the app.
            'price_after_discount' => ['nullable', 'numeric', 'min:0', 'lte:price'],

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
            'key.regex' => 'The plan key may only contain lowercase letters, numbers and underscores.',
            'duration_months.min' => 'A plan must last at least one month, or it expires the moment it is sold.',
            'price_after_discount.lte' => 'The discounted price cannot be higher than the price.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('key'))) {
            $this->merge(['key' => strtolower(trim($this->input('key')))]);
        }
    }
}
