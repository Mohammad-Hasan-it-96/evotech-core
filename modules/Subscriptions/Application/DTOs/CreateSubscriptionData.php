<?php

namespace Modules\Subscriptions\Application\DTOs;

use Illuminate\Support\Carbon;
use Modules\Subscriptions\Domain\Enums\IdentifierType;

final readonly class CreateSubscriptionData
{
    public function __construct(
        public string $companyUuid,
        public string $planUuid,
        public ?IdentifierType $identifierType,
        public ?string $identifierValue,
        public ?Carbon $startsAt,
        public bool $autoRenew,
    ) {}
}
