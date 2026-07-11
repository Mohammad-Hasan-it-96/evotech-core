<?php

namespace Modules\Licenses\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Subscriptions\Domain\Enums\IdentifierType;

/**
 * A product self-activating one of its license's device/domain slots. The product
 * itself is identified by its API key (the `product` guard), not this body.
 */
class ActivateProductLicenseRequest extends FormRequest
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
            'identifier_type' => ['required', Rule::enum(IdentifierType::class)],
            'identifier' => ['required', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
