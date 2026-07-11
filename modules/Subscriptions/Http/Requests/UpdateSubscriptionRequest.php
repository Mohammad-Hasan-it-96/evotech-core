<?php

namespace Modules\Subscriptions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Subscriptions\Application\DTOs\UpdateSubscriptionData;
use Modules\Subscriptions\Domain\Enums\IdentifierType;
use Modules\Subscriptions\Domain\Enums\SubscriptionStatus;

class UpdateSubscriptionRequest extends FormRequest
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
            'identifier_type' => ['sometimes', 'nullable', Rule::enum(IdentifierType::class)],
            'identifier_value' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(SubscriptionStatus::class)],
            'auto_renew' => ['sometimes', 'boolean'],
        ];
    }

    public function toData(): UpdateSubscriptionData
    {
        return new UpdateSubscriptionData(
            identifierType: $this->enum('identifier_type', IdentifierType::class),
            identifierValue: $this->filled('identifier_value') ? (string) $this->string('identifier_value') : null,
            status: $this->enum('status', SubscriptionStatus::class),
            autoRenew: $this->has('auto_renew') ? $this->boolean('auto_renew') : null,
        );
    }
}
