<?php

namespace Modules\Downloads\Domain\Enums;

/**
 * The target platform an artifact runs on. `Any` is a platform-agnostic
 * artifact (e.g. a cross-platform archive or a firmware image).
 */
enum Platform: string
{
    case Windows = 'windows';
    case Macos = 'macos';
    case Linux = 'linux';
    case Android = 'android';
    case Ios = 'ios';
    case Web = 'web';
    case Any = 'any';
}
