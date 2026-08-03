<?php

namespace App\Enums;

enum ProductStatus: string
{
    case Draft = 'draft';
    case AwaitingProofreading = 'awaiting_proofreading';
    case InProofreading = 'in_proofreading';
    case AwaitingRedaction = 'awaiting_redaction';
    case Rejected = 'rejected';
    case Approved = 'approved';
    case Published = 'published';
}
