<?php

namespace Modules\DeviceSubscriptions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Broadcast a custom notification to an app's devices. `app` is the app name the
 * devices carry (validated against the configured catalog, so it always maps to a
 * Firebase project); `active_only` narrows the audience to live subscribers.
 */
class BroadcastNotificationRequest extends FormRequest
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
            'app' => ['required', 'string', 'exists:device_apps,name'],
            'active_only' => ['sometimes', 'boolean'],
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:1000'],
        ];
    }
}
