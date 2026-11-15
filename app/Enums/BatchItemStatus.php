<?php

namespace App\Enums;

enum BatchItemStatus: string
{
    case Generated = 'generated';
    case AlreadyBilled = 'already_billed';
    case Skipped = 'skipped';
    case NeedsReview = 'needs_review';
    case Failed = 'failed';
}
