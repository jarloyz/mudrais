<?php

namespace App\Domains\Narrative\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoreEntry extends Model
{
    use HasFactory, \App\Traits\HasUuidV7;

    protected $table = 'lore_entries';

    protected $fillable = [
        'vault_id',
        'entity_id',
        'content',
        'metadata',
        'lineage_id',
        'version_start',
        'version_end',
    ];

    protected $casts = [
        'metadata'     => 'array',
        'version_start'=> 'integer',
        'version_end'  => 'integer',
    ];

    /**
     * Cierra esta versión del lore marcando version_end.
     */
    public function closeVersion(int $endVersion): bool
    {
        return (bool) $this->update(['version_end' => $endVersion]);
    }

    /**
     * Indica si esta entrada está vigente para la versión dada.
     */
    public function isValidAtVersion(int $version): bool
    {
        if ($this->version_start > $version) {
            return false;
        }

        return $this->version_end === null || $this->version_end >= $version;
    }
}
