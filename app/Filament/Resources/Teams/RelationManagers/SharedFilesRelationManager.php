<?php

namespace App\Filament\Resources\Teams\RelationManagers;

use App\Core\Modules\ChurchModuleManager;
use App\Models\SharedFile;
use App\Models\Team;
use App\Policies\SharedFilePolicy;
use App\Support\SharedFileUploadRule;
use App\Support\UserAvatar;
use Filament\Actions\Action;
use Filament\Actions\DetachAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class SharedFilesRelationManager extends RelationManager
{
    protected static string $relationship = 'sharedFiles';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Files');
    }

    public static function canViewForRecord($ownerRecord, string $pageClass): bool
    {
        if (! $ownerRecord instanceof Team) {
            return false;
        }

        $user = auth()->user();

        if ($user?->church === null) {
            return false;
        }

        if (! app(ChurchModuleManager::class)->isEnabled($user->church, 'file_sharing')) {
            return false;
        }

        if (! app(ChurchModuleManager::class)->isEnabled($user->church, 'teams')) {
            return false;
        }

        return $user->can('view', $ownerRecord)
            && ($user->can('file_sharing.view') || $user->can('file_sharing.manage'));
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function isReadOnly(): bool
    {
        $team = $this->getOwnerRecord();

        return ! ($team instanceof Team && $this->canManage($team));
    }

    public function table(Table $table): Table
    {
        /** @var Team $team */
        $team = $this->getOwnerRecord();
        $canManage = $this->canManage($team);
        $churchId = $team->church_id;

        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('original_name')
                    ->label(__('Filename'))
                    ->toggleable()
                    ->placeholder('—'),
                TextColumn::make('size')
                    ->label(__('Size'))
                    ->state(fn (SharedFile $record): string => $record->humanSize()),
                TextColumn::make('uploadedBy.display_name')
                    ->disabledClick()
                    ->label(__('Uploaded by'))
                    ->html()
                    ->formatStateUsing(fn ($state, SharedFile $record) => $record->uploadedBy
                        ? UserAvatar::html($record->uploadedBy)
                        : '—')
                    ->toggleable(),
            ])
            ->headerActions([
                Action::make('uploadFile')
                    ->label(__('Upload file'))
                    ->icon(Heroicon::OutlinedArrowUpTray)
                    ->visible($canManage)
                    ->modalHeading(__('Upload file for this team'))
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(150),
                        Textarea::make('description')
                            ->rows(2),
                        FileUpload::make('path')
                            ->label(__('File'))
                            ->disk('public')
                            ->directory('churches/'.$churchId.'/files')
                            ->visibility('public')
                            ->required()
                            ->storeFileNamesIn('original_name')
                            ->rule(fn (): \Closure => SharedFileUploadRule::make()),
                    ])
                    ->action(function (array $data) use ($team): void {
                        $user = auth()->user();
                        abort_unless($user !== null, 403);

                        $path = is_array($data['path'] ?? null)
                            ? ($data['path'][0] ?? null)
                            : ($data['path'] ?? null);

                        abort_unless(filled($path), 422);

                        $file = SharedFile::query()->create([
                            'church_id' => $team->church_id,
                            'uploaded_by_user_id' => $user->id,
                            'name' => $data['name'],
                            'description' => $data['description'] ?? null,
                            'path' => $path,
                            'original_name' => $data['original_name'] ?? null,
                            'mime' => Storage::disk('public')->mimeType($path),
                            'size' => Storage::disk('public')->size($path),
                        ]);

                        $team->sharedFiles()->attach($file->id);
                    }),
            ])
            ->recordActions([
                Action::make('download')
                    ->label(__('Download'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->url(fn (SharedFile $record): ?string => $record->url())
                    ->openUrlInNewTab()
                    ->visible(fn (SharedFile $record): bool => filled($record->url())),
                DetachAction::make()
                    ->label(__('Remove from team'))
                    ->visible($canManage),
            ])
            ->emptyStateHeading(__('No files shared with this team'))
            ->emptyStateDescription(__('Upload charts, run sheets, or notes for the team.'))
            ->emptyStateActions([
                Action::make('uploadFile')
                    ->label(__('Upload file'))
                    ->icon(Heroicon::OutlinedArrowUpTray)
                    ->visible($canManage)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(150),
                        Textarea::make('description')
                            ->rows(2),
                        FileUpload::make('path')
                            ->label(__('File'))
                            ->disk('public')
                            ->directory('churches/'.$churchId.'/files')
                            ->visibility('public')
                            ->required()
                            ->storeFileNamesIn('original_name')
                            ->rule(fn (): \Closure => SharedFileUploadRule::make()),
                    ])
                    ->action(function (array $data) use ($team): void {
                        $user = auth()->user();
                        abort_unless($user !== null, 403);

                        $path = is_array($data['path'] ?? null)
                            ? ($data['path'][0] ?? null)
                            : ($data['path'] ?? null);

                        abort_unless(filled($path), 422);

                        $file = SharedFile::query()->create([
                            'church_id' => $team->church_id,
                            'uploaded_by_user_id' => $user->id,
                            'name' => $data['name'],
                            'description' => $data['description'] ?? null,
                            'path' => $path,
                            'original_name' => $data['original_name'] ?? null,
                            'mime' => Storage::disk('public')->mimeType($path),
                            'size' => Storage::disk('public')->size($path),
                        ]);

                        $team->sharedFiles()->attach($file->id);
                    }),
            ]);
    }

    protected function canManage(Team $team): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        return app(SharedFilePolicy::class)->manageForTeam($user, $team);
    }
}
