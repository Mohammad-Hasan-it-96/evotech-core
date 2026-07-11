<?php

namespace Modules\Customers\Domain\Enums;

enum CustomerStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
