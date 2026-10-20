<?php

namespace App\Enums;

enum ProductStatus: string
{
    case Draft = 'draft';
    // Failed quality control and out of revision rounds. Kept out of the
    // vault listing: the point of the QA gate is that this never reads as a
    // product the catalogue could draw on.
    case QaFailed = 'qa_failed';
    case AwaitingProofreading = 'awaiting_proofreading';
    case InProofreading = 'in_proofreading';
    case AwaitingRedaction = 'awaiting_redaction';
    case Rejected = 'rejected';
    case Approved = 'approved';
    case Published = 'published';
}
