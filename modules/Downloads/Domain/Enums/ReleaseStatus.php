<?php

namespace Modules\Downloads\Domain\Enums;

/**
 * Lifecycle state of a release. Only Published releases are visible to products
 * and downloadable; Draft is staff-only editing; Archived is retired.
 */
enum ReleaseStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    /** Whether the release is downloadable by products/end-users. */
    public function isDownloadable(): bool
    {
        return $this === self::Published;
    }
}
