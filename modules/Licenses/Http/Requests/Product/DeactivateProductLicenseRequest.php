<?php

namespace Modules\Licenses\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A product releasing one of its license's activation slots by identifier.
 */
class DeactivateProductLicenseRequest extends FormRequest
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
            'identifier' => ['required', 'string', 'max:255'],
        ];
    }
}
