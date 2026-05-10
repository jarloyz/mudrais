<?php

namespace App\Filament\Resources\ArchetypeDrafts\Pages;

use App\Application\Services\ArchetypeDraftApprovalService;
use App\Domains\Matchmaking\Enums\ArchetypeDraftStatus;
use App\Filament\Resources\ArchetypeDrafts\ArchetypeDraftResource;
use App\Jobs\GenerateArchetypeTagProposalsJob;
use App\Jobs\ProcessArchetypeDraftJob;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Log;
use Filament\Schemas\Schema;

class ViewArchetypeDraft extends ViewRecord
{
    protected static string $resource = ArchetypeDraftResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Entrada original')
                    ->collapsed()
                    ->schema([
                        TextEntry::make('input_name'),
                        TextEntry::make('input_text'),
                    ]),

                Section::make('Resultado del pipeline')
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('name_es'),
                            TextEntry::make('name_en'),
                            TextEntry::make('slug'),
                        ]),
                        TextEntry::make('optimized_text_en')->columnSpanFull(),
                        TextEntry::make('semantic_tag_query')
                            ->label('Query taxonómica (semantic_tag_query)')
                            ->columnSpanFull()
                            ->placeholder('—')
                            ->helperText('Cadena generada por el optimizer para buscar tags en Qdrant.'),
                    ]),

                Section::make('Tags sugeridos por Qdrant')
                    ->schema([
                        RepeatableEntry::make('suggested_tags')
                            ->hiddenLabel()
                            ->schema([
                                TextEntry::make('source')
                                    ->badge()
                                    ->color(fn ($state) => $state === 'qdrant' ? 'info' : 'primary'),
                                TextEntry::make('name'),
                                TextEntry::make('slug'),
                                TextEntry::make('score')
                                    ->numeric(4)
                                    ->badge()
                                    ->color(fn ($state) => match (true) {
                                        $state >= 0.88 => 'success',
                                        $state >= 0.70 => 'warning',
                                        default        => 'danger',
                                    }),
                            ])
                            ->columns(4),
                    ]),

                Section::make('Estado')
                    ->schema([
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (ArchetypeDraftStatus $state) => match ($state) {
                                ArchetypeDraftStatus::PENDING      => 'gray',
                                ArchetypeDraftStatus::PROCESSING   => 'warning',
                                ArchetypeDraftStatus::NEEDS_REVIEW => 'primary',
                                ArchetypeDraftStatus::APPROVED     => 'success',
                                ArchetypeDraftStatus::REJECTED     => 'danger',
                                ArchetypeDraftStatus::ERROR        => 'danger',
                                default                            => 'gray',
                            }),
                        TextEntry::make('processing_error')
                            ->visible(fn ($record) => filled($record->processing_error)),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reintentar')
                ->label('Editar y Reintentar')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn () => in_array($this->record->status, [
                    ArchetypeDraftStatus::ERROR,
                    ArchetypeDraftStatus::PROCESSING,
                ]))
                ->form([
                    TextInput::make('input_name')
                        ->label('Nombre de entrada')
                        ->default(fn () => $this->record->input_name)
                        ->required()
                        ->maxLength(255),
                    Textarea::make('input_text')
                        ->label('Texto de entrada')
                        ->default(fn () => $this->record->input_text)
                        ->required()
                        ->rows(6),
                ])
                ->modalHeading('Editar y reintentar procesamiento')
                ->modalDescription('Corrige el input y el job se volverá a ejecutar desde cero.')
                ->modalSubmitActionLabel('Reintentar')
                ->action(function (array $data) {
                    Log::info('[ViewArchetypeDraft] Reintentando draft tras error', [
                        'draft_id' => $this->record->id,
                        'old_status' => $this->record->status->value,
                    ]);

                    $this->record->update([
                        'input_name'        => $data['input_name'],
                        'input_text'        => $data['input_text'],
                        'status'            => ArchetypeDraftStatus::PENDING->value,
                        'processing_error'  => null,
                        'name_es'           => null,
                        'name_en'           => null,
                        'slug'              => null,
                        'optimized_text_en' => null,
                        'semantic_tag_query'=> null,
                        'style_vector'      => null,
                        'suggested_tags'    => null,
                    ]);

                    ProcessArchetypeDraftJob::dispatch($this->record->id);

                    Notification::make()
                        ->title('Reintentando procesamiento')
                        ->body('El draft fue reseteado y el job fue enviado a la cola.')
                        ->success()
                        ->send();

                    return redirect(static::getResource()::getUrl('view', ['record' => $this->record]));
                }),

            Action::make('proponer_tags')
                ->label('Proponer Tags con IA')
                ->requiresConfirmation()
                ->visible(fn () => $this->record->status === ArchetypeDraftStatus::NEEDS_REVIEW)
                ->action(function () {
                    GenerateArchetypeTagProposalsJob::dispatch($this->record->id);
                    Notification::make()->title('Job disparado')->success()->send();

                    return redirect(static::getResource()::getUrl('view', ['record' => $this->record]));
                }),

            Action::make('aprobar')
                ->label('Aprobar')
                ->color('success')
                ->visible(fn () => $this->record->status === ArchetypeDraftStatus::NEEDS_REVIEW)
                ->modalHeading('Confirmar aprobación')
                ->modalSubmitActionLabel('Aprobar')
                ->form([
                    Placeholder::make('optimized_text_en_display')
                        ->label('Texto optimizado')
                        ->content(fn () => $this->record->optimized_text_en ?? '—'),
                    Placeholder::make('semantic_tag_query_display')
                        ->label('Query taxonómica (semantic_tag_query)')
                        ->content(fn () => $this->record->semantic_tag_query ?? '—')
                        ->helperText('Cadena usada para buscar tags en Qdrant.'),
                ])
                ->action(function (ArchetypeDraftApprovalService $service) {
                    $archetype = $service->approve($this->record, auth()->id());
                    Notification::make()->title('Aprobado')->success()->send();

                    return redirect('/app/archetypes/' . $archetype->id . '/edit');
                }),
            Action::make('rechazar')
                ->label('Rechazar')
                ->form([
                    Textarea::make('note')
                        ->label('Nota de rechazo (opcional)'),
                ])
                ->visible(fn () => $this->record->status === ArchetypeDraftStatus::NEEDS_REVIEW)
                ->color('danger')
                ->action(function (array $data) {
                    $this->record->update([
                        'status' => ArchetypeDraftStatus::REJECTED->value,
                        'processing_error' => $data['note'] ?? 'Rechazado manualmente.',
                    ]);
                    Notification::make()->title('Rechazado')->danger()->send();

                    return redirect(static::getResource()::getUrl('index'));
                }),
        ];
    }
}
