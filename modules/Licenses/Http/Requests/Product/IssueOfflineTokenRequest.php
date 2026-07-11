<?php

namespace Modules\Licenses\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A product requesting a signed offline token for one of its already-activated
 * devices (ADR 0005).
 */
class IssueOfflineTokenRequest extends FormRequest
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
