<?php

namespace Modules\Licenses\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A product checking a license online. An optional `identifier` lets the platform
 * record the device/domain checking in (heartbeat) during validation.
 */
class ValidateProductLicenseRequest extends FormRequest
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
            'key' => ['required', 'string', 'max:255'],
            'identifier' => ['nullable', 'string', 'max:255'],
        ];
    }
}
