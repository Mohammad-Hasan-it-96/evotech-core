<?php

namespace Modules\Licenses\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Subscriptions\Domain\Enums\IdentifierType;

class ActivateLicenseRequest extends FormRequest
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
            'identifier_type' => ['required', Rule::enum(IdentifierType::class)],
            'identifier' => ['required', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
