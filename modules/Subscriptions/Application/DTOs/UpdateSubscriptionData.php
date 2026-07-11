<?php

namespace Modules\Subscriptions\Application\DTOs;

use Modules\Subscriptions\Domain\Enums\IdentifierType;
use Modules\Subscriptions\Domain\Enums\SubscriptionStatus;

/**
 * Partial update — a null field means "leave unchanged".
 */
final readonly class UpdateSubscriptionData
{
    public function __construct(
        public ?IdentifierType $identifierType,
        public ?string $identifierValue,
        public ?SubscriptionStatus $status,
        public ?bool $autoRenew,
    ) {}
}
