<?php

namespace Modules\Subscriptions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Subscriptions\Application\DTOs\CreateSubscriptionData;
use Modules\Subscriptions\Domain\Enums\IdentifierType;

class StoreSubscriptionRequest extends FormRequest
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
            'company' => ['required', 'string', 'exists:companies,uuid'],
            'plan' => ['required', 'string', 'exists:plans,uuid'],
            'identifier_type' => ['nullable', Rule::enum(IdentifierType::class)],
            'identifier_value' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['nullable', 'date'],
            'auto_renew' => ['sometimes', 'boolean'],
        ];
    }

    public function toData(): CreateSubscriptionData
    {
        return new CreateSubscriptionData(
            companyUuid: (string) $this->string('company'),
            planUuid: (string) $this->string('plan'),
            identifierType: $this->enum('identifier_type', IdentifierType::class),
            identifierValue: $this->filled('identifier_value') ? (string) $this->string('identifier_value') : null,
            startsAt: $this->date('starts_at'),
            autoRenew: $this->has('auto_renew') ? $this->boolean('auto_renew') : true,
        );
    }
}
