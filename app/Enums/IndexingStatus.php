<?php

namespace App\Enums;

enum IndexingStatus: string
{
    case Pending    = 'pending';
    case Processing = 'processing';
    case Indexed    = 'indexed';
    case Failed     = 'failed';
}
