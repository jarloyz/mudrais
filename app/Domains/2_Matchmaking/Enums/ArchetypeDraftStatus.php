<?php

namespace App\Domains\Matchmaking\Enums;

enum ArchetypeDraftStatus: string
{
    case PENDING      = 'PENDING';
    case PROCESSING   = 'PROCESSING';
    case ERROR        = 'ERROR';
    case NEEDS_REVIEW = 'NEEDS_REVIEW';
    case APPROVED     = 'APPROVED';
    case REJECTED     = 'REJECTED';
}
