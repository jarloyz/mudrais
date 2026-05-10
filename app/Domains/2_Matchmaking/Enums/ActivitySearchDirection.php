<?php

namespace App\Domains\Matchmaking\Enums;

enum ActivitySearchDirection: string
{
    case OUTBOUND = 'outbound'; // jugadores encuentran la actividad (default)
    case INBOUND  = 'inbound';  // la actividad busca activamente jugadores
    case BOTH     = 'both';     // bidireccional
}
