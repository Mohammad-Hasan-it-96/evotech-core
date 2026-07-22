<?php

namespace Modules\DeviceSubscriptions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Send a custom notification to a single device — the dry run an operator does on
 * their own phone before broadcasting. The device is chosen from the console's
 * device list, so it arrives as that row's uuid.
 */
class SendTestNotificationRequest extends FormRequest
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
            'device' => ['required', 'string', 'exists:device_subscriptions,uuid'],
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:1000'],
        ];
    }
}
