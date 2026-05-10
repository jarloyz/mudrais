<?php

namespace App\Domains\Shared\Traits;

use App\Models\Optimizable;
use Illuminate\Database\Eloquent\Relations\MorphOne;

trait HasOptimizedText
{
    /**
     * Get the optimizable text associated with the model.
     */
    public function optimizable(): MorphOne
    {
        // Optimizable model doesn't exist yet, we'll create it or use a query builder here,
        // but creating an Optimizable model is better for Eloquent.
        return $this->morphOne(Optimizable::class, 'optimizable');
    }

    /**
     * Retrieve the optimized text.
     */
    public function getOptimizedText(): ?string
    {
        return $this->optimizable?->optimized_text;
    }

    /**
     * Save the optimized text.
     */
    public function saveOptimizedText(string $text): void
    {
        $this->optimizable()->updateOrCreate(
            [
                'optimizable_type' => self::class,
                'optimizable_id' => $this->getKey(),
            ],
            ['optimized_text' => $text]
        );
    }
}
