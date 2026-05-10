<?php

namespace App\Domains\Matchmaking\Enums;

enum ActivityStatus: string
{
    case PENDING     = 'draft';
    case RECRUITING  = 'ready';
    case IN_PROGRESS = 'in_progress';
    case ACTIVE      = 'active';
    case CLOSED      = 'closed';
    case ARCHIVED    = 'archived';

    public function label(): string
    {
        return match($this) {
            self::PENDING     => 'Pendiente',
            self::RECRUITING  => 'Reclutando',
            self::IN_PROGRESS => 'En Progreso',
            self::ACTIVE      => 'Activa',
            self::CLOSED      => 'Cerrada',
            self::ARCHIVED    => 'Archivada',
        };
    }

    /** Devuelve si la actividad acepta nuevos miembros. */
    public function isOpen(): bool
    {
        return $this === self::RECRUITING;
    }

    public static function options(): array
    {
        return array_column(
            array_map(fn(self $case) => ['value' => $case->value, 'label' => $case->label()], self::cases()),
            'label',
            'value'
        );
    }
}
