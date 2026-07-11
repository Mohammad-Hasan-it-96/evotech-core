<?php

namespace Modules\Licenses\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IssueLicenseRequest extends FormRequest
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
            'subscription' => ['required', 'string', 'exists:subscriptions,uuid'],
            'max_activations' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ];
    }
}
