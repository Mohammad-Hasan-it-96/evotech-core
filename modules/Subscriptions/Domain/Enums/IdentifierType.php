<?php

namespace Modules\Subscriptions\Domain\Enums;

/**
 * How a subscription's product instance is identified — a web domain or a
 * mobile/device id (the "domain or mobile id" from the dashboard requirements).
 */
enum IdentifierType: string
{
    case Domain = 'domain';
    case Device = 'device';
}
