<?php

namespace Modules\DeviceSubscriptions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDeviceAppRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Note what is absent: `name` and `slug`, both immutable.
     *
     * `name` is the literal string a shipped build sends in `app_name`, and every
     * device row is matched on it — renaming it orphans every subscriber of that
     * app at once. `slug` is the base URL those builds are pointed at through their
     * remote-config file; changing it 404s the app until each config is re-edited.
     * Neither is recoverable from the dashboard, so neither is offered there.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'string', 'max:100'],

            // 0 = no trial. SmartAgent is deliberately 0; raising it grants trials
            // to an app whose owner never asked for them.
            'trial_days' => ['sometimes', 'integer', 'min:0', 'max:365'],

            'uses_shared_plans' => ['sometimes', 'boolean'],

            // The Products-module row this app's releases belong to. Null unlinks.
            'product' => ['sometimes', 'nullable', 'string', 'exists:products,slug'],
        ];
    }
}
