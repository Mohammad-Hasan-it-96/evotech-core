<?php

namespace Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Payments\Domain\Enums\PaymentMethod;

class RecordPaymentRequest extends FormRequest
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
            // `card` is collected asynchronously through Stripe (ADR 0009), not here.
            'method' => ['required', Rule::enum(PaymentMethod::class)->except([PaymentMethod::Card])],
            'reference' => ['nullable', 'string', 'max:255'],
        ];
    }
}
