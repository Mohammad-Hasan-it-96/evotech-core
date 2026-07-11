<?php

namespace Modules\Licenses\Application\DTO;

use Modules\Licenses\Domain\Models\License;
use Modules\Licenses\Domain\Models\LicenseActivation;
use Modules\Licenses\Http\Resources\ProductLicenseResource;

/**
 * The outcome of a product-facing license operation (self-activation or online
 * validation): the license and, when relevant, the activation the request
 * concerns. Rendered by {@see ProductLicenseResource}.
 */
final readonly class LicenseValidationResult
{
    public function __construct(
        public License $license,
        public ?LicenseActivation $activation = null,
    ) {}
}
