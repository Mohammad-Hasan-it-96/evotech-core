<?php

namespace Modules\DeviceSubscriptions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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

            /*
             * Remote config — what the app fetches at startup.
             *
             * Both shipped apps compare this component-wise as integers, NOT as
             * semver: `int.tryParse(part) ?? 0`. So "1.2.0-beta" compares that
             * component as 0 and an update can become permanently invisible with no
             * error anywhere. Digits and dots only.
             */
            'latest_version' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^\d+(\.\d+)*$/'],

            'api_base_url' => ['sometimes', 'nullable', 'string', 'max:255', 'url'],

            /*
             * ABI => APK URL. Keys are matched *exactly* against the ABI the device
             * reports, so an unrecognised key is not a validation nicety — it is an
             * update the device can never find. `default` is included because
             * SmartAgent falls back to it for any ABI it cannot classify.
             */
            'downloads' => ['sometimes', 'nullable', 'array'],
            'downloads.*' => ['required', 'string', 'url', 'max:500'],

            'update_notes' => ['sometimes', 'nullable', 'array', 'max:20'],
            // Strings only: a nested value reaches the app as "Instance of ..."
            // once its parser calls toString, and a bare string is dropped.
            'update_notes.*' => ['required', 'string', 'max:200'],

            'support_email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'support_whatsapp' => ['sometimes', 'nullable', 'string', 'max:30'],
            'support_telegram' => ['sometimes', 'nullable', 'string', 'max:150'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'latest_version.regex' => 'Use digits and dots only (e.g. 1.2.0). The apps compare versions numerically, so a suffix like "-beta" is read as 0 and would hide the update.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $downloads = $this->input('downloads');

            if (! is_array($downloads)) {
                return;
            }

            foreach (array_keys($downloads) as $abi) {
                if (! in_array($abi, self::DOWNLOAD_KEYS, true)) {
                    $validator->errors()->add(
                        'downloads',
                        "\"{$abi}\" is not an ABI the apps look for. Use one of: ".implode(', ', self::DOWNLOAD_KEYS).'.',
                    );
                }
            }
        });
    }

    /**
     * The only keys either app ever reads. Fawateer looks up the device's reported
     * ABIs in order; SmartAgent normalises everything to arm64-v8a / armeabi-v7a
     * and falls back to `default`, so x86 devices reach an APK only through that.
     */
    private const DOWNLOAD_KEYS = ['arm64-v8a', 'armeabi-v7a', 'x86_64', 'x86', 'default'];
}
