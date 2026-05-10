<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Domains\Matchmaking\Models\Archetype;
use App\Models\CanonicalTag;

try {
    $archetype = Archetype::create([
        'name' => 'Test Archetype ' . time(),
        'qdrant_vector_name' => 'test_vec_' . time()
    ]);
    
    $tag = CanonicalTag::firstOrCreate(['slug' => 'test-tag'], ['name' => 'Test', 'is_active' => true]);
    $archetype->tags()->attach($tag->id, ['tag_context' => 'general']);
    
    echo "SUCCESS: Archetype created with ID: " . $archetype->id . "\n";
    echo "SUCCESS: Tag attached.\n";
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
