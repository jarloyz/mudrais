<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Contracts\EventEngineRepository;
use App\Application\Contracts\StructuredLogger;
use App\Models\ContinuityQuestStatus;
use App\Models\Event;
use App\Models\EventCondition;
use App\Models\EventEffect;
use App\Models\EventRun;
use App\Models\ContinuityStateChange;
use Illuminate\Support\Facades\DB;

class EloquentEventEngineRepository implements EventEngineRepository
{
    public function __construct(
        private readonly ?StructuredLogger $logger = null,
    ) {
    }

    public function evaluateAndApplyTriggers(array $input): array
    {
        $continuityId = trim((string) ($input['continuityId'] ?? ''));
        $sceneId = trim((string) ($input['sceneId'] ?? ''));
        $locationId = trim((string) ($input['locationId'] ?? ''));
        $turnIndex = (int) ($input['turnIndex'] ?? 0);
        $characterIds = collect($input['characterIds'] ?? [])->map(fn ($id) => trim((string) $id))->filter()->values()->all();
        $tags = collect($input['tags'] ?? [])->map(fn ($tag) => mb_strtolower(trim((string) $tag)))->filter()->values()->all();
        $maxCandidates = max(1, (int) ($input['maxCandidates'] ?? 24));
        $maxFired = max(1, (int) ($input['maxFired'] ?? 3));
        $minScore = (int) ($input['minScore'] ?? 10);
        $logger = $this->logger?->withContext([
            'layer' => 'infrastructure',
            'repository' => 'event_engine',
            'continuityId' => $continuityId,
            'sceneId' => $sceneId,
            'turnIndex' => $turnIndex,
        ]);

        $logger?->info('Inicio de evaluacion de eventos', [
            'locationId' => $locationId,
            'characterIds' => $characterIds,
            'tags' => $tags,
            'maxCandidates' => $maxCandidates,
            'maxFired' => $maxFired,
            'minScore' => $minScore,
        ]);

        $stateTexts = ContinuityStateChange::query()
            ->where('continuity_id', $continuityId)
            ->orderByDesc('id')
            ->limit(120)
            ->pluck('change')
            ->map(fn ($text) => mb_strtolower(trim((string) $text)))
            ->filter()
            ->values()
            ->all();
        $questStatuses = ContinuityQuestStatus::query()
            ->with('quest')
            ->where('continuity_id', $continuityId)
            ->where('status', '!=', 'hidden')
            ->get()
            ->mapWithKeys(function (ContinuityQuestStatus $status): array {
                return [
                    (string) $status->quest_id => [[
                        'questId' => (string) $status->quest_id,
                        'title' => trim((string) ($status->quest?->title ?? '')),
                        'status' => trim((string) ($status->status ?? 'active')),
                        'stageNumber' => (int) ($status->current_stage_number ?? 0),
                        'summary' => trim((string) ($status->ai_summary ?? '')),
                    ]],
                ];
            })
            ->all();

        $candidates = Event::query()
            ->where('status', 'active')
            ->where(function ($query) use ($sceneId, $locationId, $characterIds): void {
                $query->where('scene_id', $sceneId);
                if ($locationId !== '') {
                    $query->orWhereHas('locations', fn ($q) => $q->where('locations.id', $locationId));
                }
                if ($characterIds !== []) {
                    $query->orWhereIn('subject_character_id', $characterIds)
                        ->orWhereHas('characters', fn ($q) => $q->whereIn('avatars.id', $characterIds));
                }
            })
            ->with(['locations', 'characters'])
            ->orderByDesc('importance')
            ->limit($maxCandidates)
            ->get();
        $logger?->debug('Candidatos de evento cargados', [
            'candidateCount' => $candidates->count(),
            'questStatusCount' => count($questStatuses),
        ]);

        $evaluated = 0;
        $firedCount = 0;
        $effectsApplied = 0;
        $firedEvents = [];

        DB::transaction(function () use (
            $candidates,
            $continuityId,
            $sceneId,
            $locationId,
            $turnIndex,
            $characterIds,
            $tags,
            $stateTexts,
            $questStatuses,
            $minScore,
            $maxFired,
            &$evaluated,
            &$firedCount,
            &$effectsApplied,
            &$firedEvents
        ): void {
            foreach ($candidates as $event) {
                $evaluated++;

                $conditions = EventCondition::query()
                    ->where('event_id', $event->id)
                    ->where('active', true)
                    ->where(function ($query) use ($continuityId): void {
                        $query->whereNull('continuity_id')->orWhere('continuity_id', $continuityId);
                    })
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get();

                if ($conditions->isEmpty()) {
                    continue;
                }

                $blocked = false;
                $score = ((int) $event->importance) * 5;
                $reasons = [];

                if ($event->last_fired_turn !== null && (int) $event->cooldown_turns > 0) {
                    $diff = $turnIndex - (int) $event->last_fired_turn;
                    if ($diff >= 0 && $diff < (int) $event->cooldown_turns) {
                        $blocked = true;
                        $reasons[] = [
                            'type' => 'cooldown',
                            'cooldown_turns' => (int) $event->cooldown_turns,
                            'last_fired_turn' => (int) $event->last_fired_turn,
                        ];
                    }
                }

                foreach ($conditions as $condition) {
                    $matched = $this->evalCondition($condition, [
                        'sceneId' => mb_strtolower($sceneId),
                        'locationId' => mb_strtolower($locationId),
                        'characterIds' => array_map('mb_strtolower', $characterIds),
                        'tags' => $tags,
                        'stateTexts' => $stateTexts,
                        'questsById' => $questStatuses,
                        'eventQuestId' => trim((string) ($event->quest_id ?? '')),
                    ]);

                    $reasons[] = [
                        'condition_id' => $condition->id,
                        'scope_type' => $condition->scope_type,
                        'operator' => $condition->operator,
                        'value_text' => $condition->value_text,
                        'required' => (bool) $condition->required,
                        'matched' => $matched,
                    ];

                    if ($matched) {
                        $score += (int) $condition->weight;
                    } elseif ($condition->required) {
                        $blocked = true;
                    } else {
                        $score -= max(1, (int) ceil(((int) $condition->weight) / 2));
                    }
                }

                $score = max(0, $score);
                if ($blocked || $score < $minScore || $firedCount >= $maxFired) {
                    EventRun::query()->updateOrCreate(
                        [
                            'event_id' => $event->id,
                            'continuity_id' => $continuityId,
                            'scene_id' => $sceneId,
                            'turn_index' => $turnIndex,
                        ],
                        [
                            'score' => $score,
                            'fired' => false,
                            'reasons_json' => $reasons,
                            'effects_applied' => 0,
                            'created_at' => now(),
                        ]
                    );
                    continue;
                }

                $effects = EventEffect::query()
                    ->where('event_id', $event->id)
                    ->where('active', true)
                    ->where(function ($query) use ($continuityId): void {
                        $query->whereNull('continuity_id')->orWhere('continuity_id', $continuityId);
                    })
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get();

                $appliedForEvent = 0;
                foreach ($effects as $effect) {
                    if (($effect->effect_type ?? 'state_change') === 'quest_status') {
                        $questId = $effect->payload_json['quest_id'] ?? $event->quest_id ?? null;
                        if ($questId) {
                            /** @var \App\Application\UseCases\ApplyQuestProgressDirectiveUseCase $applyQuestUseCase */
                            $applyQuestUseCase = app(\App\Application\UseCases\ApplyQuestProgressDirectiveUseCase::class);
                            $applyQuestUseCase->execute(
                                continuityId: $continuityId,
                                sceneId: $sceneId,
                                directive: [
                                    'matched' => true,
                                    'quest_id' => (string) $questId,
                                    'advance_step' => (bool) ($effect->payload_json['advance_step'] ?? false),
                                    'new_stage_number' => $effect->payload_json['new_stage_number'] ?? null,
                                    'new_status' => trim((string) ($effect->change_text ?? 'active')) ?: 'active',
                                    'ai_summary' => "Evento disparado: {$event->title}",
                                ],
                                turnIndex: $turnIndex,
                            );
                            $appliedForEvent++;
                            $effectsApplied++;
                        }
                        continue;
                    }

                    if (($effect->effect_type ?? 'state_change') !== 'state_change') {
                        continue;
                    }

                    ContinuityStateChange::query()->create([
                        'continuity_id' => $continuityId,
                        'scene_id' => $sceneId,
                        'kind' => trim((string) ($effect->kind ?? 'state')) ?: 'state',
                        'scope_type' => trim((string) ($effect->scope_type ?? 'scene')) ?: 'scene',
                        'scope_id' => ($effect->scope_id ?? null) ? trim((string) $effect->scope_id) : $this->defaultScopeId((string) $effect->scope_type, $sceneId, $locationId),
                        'change' => trim((string) ($effect->change_text ?? '')),
                        'severity' => max(1, min(5, (int) ($effect->severity ?? 1))),
                    ]);
                    $appliedForEvent++;
                    $effectsApplied++;
                }

                $event->update(['last_fired_turn' => $turnIndex]);

                EventRun::query()->updateOrCreate(
                    [
                        'event_id' => $event->id,
                        'continuity_id' => $continuityId,
                        'scene_id' => $sceneId,
                        'turn_index' => $turnIndex,
                    ],
                    [
                        'score' => $score,
                        'fired' => true,
                        'reasons_json' => $reasons,
                        'effects_applied' => $appliedForEvent,
                        'created_at' => now(),
                    ]
                );

                $firedCount++;
                $firedEvents[] = [
                    'eventId' => $event->id,
                    'title' => $event->title,
                    'score' => $score,
                    'effectsApplied' => $appliedForEvent,
                ];
            }
        });

        $logger?->info('Evaluacion de eventos completada', [
            'evaluated' => $evaluated,
            'firedCount' => $firedCount,
            'effectsApplied' => $effectsApplied,
        ]);

        return [
            'evaluated' => $evaluated,
            'firedCount' => $firedCount,
            'effectsApplied' => $effectsApplied,
            'firedEvents' => $firedEvents,
        ];
    }

    /**
     * @param array<string, mixed> $facts
     */
    private function evalCondition(EventCondition $condition, array $facts): bool
    {
        $scope = mb_strtolower(trim((string) $condition->scope_type));
        $operator = mb_strtolower(trim((string) $condition->operator));
        $value = mb_strtolower(trim((string) ($condition->value_text ?? '')));
        $setValues = collect(preg_split('/[,\n]/', (string) ($condition->value_text ?? '')) ?: [])
            ->map(fn ($item) => mb_strtolower(trim((string) $item)))
            ->filter()
            ->values()
            ->all();

        if ($scope === 'scene') {
            return $this->matchScalar((string) ($facts['sceneId'] ?? ''), $operator, $value, $setValues);
        }
        if ($scope === 'location') {
            return $this->matchScalar((string) ($facts['locationId'] ?? ''), $operator, $value, $setValues);
        }
        if ($scope === 'character') {
            return $this->matchCollection($facts['characterIds'] ?? [], $operator, $value, $setValues);
        }
        if ($scope === 'tag') {
            return $this->matchCollection($facts['tags'] ?? [], $operator, $value, $setValues);
        }
        if ($scope === 'state') {
            return $this->matchCollection($facts['stateTexts'] ?? [], $operator, $value, $setValues, true);
        }
        if ($scope === 'quest') {
            return $this->matchQuestCondition(
                questsById: is_array($facts['questsById'] ?? null) ? $facts['questsById'] : [],
                eventQuestId: trim((string) ($facts['eventQuestId'] ?? '')),
                operator: $operator,
                value: $value,
                setValues: $setValues,
            );
        }

        return false;
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $questsById
     * @param array<int, string> $setValues
     */
    private function matchQuestCondition(array $questsById, string $eventQuestId, string $operator, string $value, array $setValues): bool
    {
        $targetQuests = $eventQuestId !== '' && isset($questsById[$eventQuestId])
            ? [$eventQuestId => $questsById[$eventQuestId]]
            : $questsById;

        if ($operator === 'exists') {
            return $targetQuests !== [];
        }
        if ($operator === 'not_exists') {
            return $targetQuests === [];
        }

        $tokens = $operator === 'in' ? $setValues : [$value];

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            if ($this->questTokenMatches($token, $targetQuests, $eventQuestId)) {
                return true;
            }
        }

        if ($operator === 'contains') {
            return collect($targetQuests)->flatten(1)->contains(function (array $quest) use ($value): bool {
                if ($value === '') {
                    return false;
                }

                $haystack = mb_strtolower(implode(' | ', array_filter([
                    (string) ($quest['questId'] ?? ''),
                    (string) ($quest['title'] ?? ''),
                    (string) ($quest['status'] ?? ''),
                    (string) ($quest['stageNumber'] ?? ''),
                    (string) ($quest['summary'] ?? ''),
                ])));

                return str_contains($haystack, $value);
            });
        }

        return false;
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $questsById
     */
    private function questTokenMatches(string $token, array $questsById, string $eventQuestId): bool
    {
        $token = mb_strtolower(trim($token));
        if ($token === '') {
            return false;
        }

        if (preg_match('/^\d+$/', $token) === 1) {
            $questRows = $eventQuestId !== '' ? ($questsById[$eventQuestId] ?? []) : collect($questsById)->flatten(1)->all();

            return collect($questRows)->contains(
                fn (array $quest): bool => (int) ($quest['stageNumber'] ?? -1) === (int) $token
            );
        }

        [$questId, $expected] = array_pad(explode(':', $token, 2), 2, null);
        if ($expected !== null && $questId !== '') {
            $questRows = $questsById[$questId] ?? [];

            return collect($questRows)->contains(fn (array $quest): bool => $this->questRowMatchesExpected($quest, $expected));
        }

        if ($eventQuestId !== '' && ($questsById[$eventQuestId] ?? []) !== []) {
            return collect($questsById[$eventQuestId])->contains(fn (array $quest): bool => $this->questRowMatchesExpected($quest, $token));
        }

        return isset($questsById[$questId]);
    }

    /**
     * @param array<string, mixed> $quest
     */
    private function questRowMatchesExpected(array $quest, string $expected): bool
    {
        $expected = mb_strtolower(trim($expected));

        if ($expected === '') {
            return false;
        }

        if (preg_match('/^\d+$/', $expected) === 1) {
            return (int) ($quest['stageNumber'] ?? -1) === (int) $expected;
        }

        return in_array($expected, [
            mb_strtolower((string) ($quest['status'] ?? '')),
            mb_strtolower((string) ($quest['title'] ?? '')),
            mb_strtolower((string) ($quest['questId'] ?? '')),
        ], true);
    }

    /**
     * @param array<int, string> $setValues
     */
    private function matchScalar(string $current, string $operator, string $value, array $setValues): bool
    {
        if ($operator === 'exists') {
            return $current !== '';
        }
        if ($operator === 'not_exists') {
            return $current === '';
        }
        if ($operator === 'in') {
            return in_array($current, $setValues, true);
        }
        if ($operator === 'contains') {
            return $value !== '' && str_contains($current, $value);
        }

        return $current === $value;
    }

    /**
     * @param array<int, string> $items
     * @param array<int, string> $setValues
     */
    private function matchCollection(array $items, string $operator, string $value, array $setValues, bool $containsText = false): bool
    {
        $normalized = array_map(fn ($item) => mb_strtolower(trim((string) $item)), $items);

        if ($operator === 'exists') {
            return $normalized !== [];
        }
        if ($operator === 'not_exists') {
            return $normalized === [];
        }
        if ($operator === 'contains') {
            return collect($normalized)->contains(fn ($item) => $value !== '' && str_contains($item, $value));
        }
        if ($operator === 'in') {
            return collect($setValues)->contains(function ($candidate) use ($normalized, $containsText): bool {
                return $containsText
                    ? collect($normalized)->contains(fn ($item) => str_contains($item, $candidate))
                    : in_array($candidate, $normalized, true);
            });
        }

        return $containsText
            ? collect($normalized)->contains(fn ($item) => $item === $value)
            : in_array($value, $normalized, true);
    }

    private function defaultScopeId(string $scopeType, string $sceneId, string $locationId): ?string
    {
        $scope = mb_strtolower(trim($scopeType));

        return match ($scope) {
            'scene' => $sceneId,
            'location' => $locationId !== '' ? $locationId : null,
            default => null,
        };
    }
}
