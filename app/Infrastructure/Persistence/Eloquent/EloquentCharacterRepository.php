<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Contracts\CharacterRepository;
use App\Domain\Catalog\Avatar;
use App\Domain\Catalog\AvatarBullet;
use App\Domain\Catalog\AvatarTrait;
use App\Infrastructure\Persistence\Eloquent\Models\CharacterRecord;
use Illuminate\Support\Facades\DB;

class EloquentCharacterRepository implements CharacterRepository
{
    public function save(Avatar $avatar): void
    {
        DB::transaction(function () use ($avatar): void {
            CharacterRecord::query()->updateOrCreate(
                ['id' => $avatar->id],
                [
                    'vault_id' => $avatar->vaultId,
                    'name' => $avatar->name,
                    'public_facade' => $this->serializeTraits($avatar->traits),
                ]
            );
        });
    }

    public function findById(string $id): ?Avatar
    {
        $record = CharacterRecord::query()->find($id);

        if (! $record) {
            return null;
        }

        return new Avatar(
            id: $record->id,
            vaultId: $record->vault_id,
            name: $record->name,
            traits: $this->deserializeTraits($record->public_facade),
        );
    }

    /**
     * Serialize domain traits to a JSON string stored in public_facade.
     *
     * @param array<int, AvatarTrait> $traits
     */
    private function serializeTraits(array $traits): ?string
    {
        if (empty($traits)) {
            return null;
        }

        return json_encode(
            array_map(
                fn (AvatarTrait $t): array => [
                    'key' => $t->key,
                    'title' => $t->title,
                    'sort_order' => $t->sortOrder,
                    'context_id' => $t->contextId,
                    'bullets' => array_map(
                        fn (AvatarBullet $b): array => [
                            'body' => $b->body,
                            'section' => $b->section,
                            'sort_order' => $b->sortOrder,
                            'legacy_bullet_id' => $b->legacyBulletId,
                            'parent_legacy_bullet_id' => $b->parentLegacyBulletId,
                        ],
                        $t->bullets,
                    ),
                ],
                $traits,
            ),
            JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * Deserialize the public_facade JSON back to CharacterTrait domain objects.
     *
     * @return array<int, AvatarTrait>
     */
    private function deserializeTraits(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $data = json_decode($json, true);

        if (! is_array($data)) {
            return [];
        }

        return array_values(array_map(
            fn (array $t): AvatarTrait => new AvatarTrait(
                key: (string) ($t['key'] ?? ''),
                title: (string) ($t['title'] ?? ''),
                sortOrder: (int) ($t['sort_order'] ?? 0),
                contextId: isset($t['context_id']) ? (string) $t['context_id'] : null,
                bullets: array_values(array_map(
                    fn (array $b): AvatarBullet => new AvatarBullet(
                        body: (string) ($b['body'] ?? ''),
                        section: isset($b['section']) ? (string) $b['section'] : null,
                        sortOrder: (int) ($b['sort_order'] ?? 0),
                        legacyBulletId: isset($b['legacy_bullet_id']) ? (int) $b['legacy_bullet_id'] : null,
                        parentLegacyBulletId: isset($b['parent_legacy_bullet_id']) ? (int) $b['parent_legacy_bullet_id'] : null,
                    ),
                    is_array($t['bullets'] ?? null) ? $t['bullets'] : [],
                )),
            ),
            $data,
        ));
    }
}
