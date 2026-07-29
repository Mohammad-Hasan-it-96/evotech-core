<?php

namespace Modules\DeviceSubscriptions\Application\DTO;

use Modules\DeviceSubscriptions\Domain\Models\DeviceSeat;

/**
 * The output of a device redeeming a join token: its new seat, the one-time
 * plaintext sync token, and the bootstrap seed to catch up with the shop.
 */
final readonly class EnrolledSeat
{
    public function __construct(
        public DeviceSeat $seat,
        public string $plaintext,
        public BootstrapHandoff $bootstrap,
    ) {}
}
