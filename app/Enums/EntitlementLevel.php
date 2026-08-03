<?php

namespace App\Enums;

enum EntitlementLevel: string
{
    case FullAccess = 'full_access';
    /** The separately proofread redacted document, served in place of the full one. */
    case RedactedAccess = 'redacted_access';
    case PreviewOnly = 'preview_only';
    case Denied = 'denied';
    case MeteredExhausted = 'metered_exhausted';
}
