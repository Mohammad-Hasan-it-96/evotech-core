<?php

namespace Modules\Downloads\Domain\Enums;

/**
 * The release channel an artifact is published on. Products subscribe to a
 * channel and self-update from its latest published release (ADR 0008).
 */
enum ReleaseChannel: string
{
    case Stable = 'stable';
    case Beta = 'beta';
    case Alpha = 'alpha';
}
