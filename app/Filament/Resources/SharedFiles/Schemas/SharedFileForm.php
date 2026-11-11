<?php

namespace App\Filament\Resources\SharedFiles\Schemas;

use App\Models\Team;
use App\Support\SharedFileUploadRule;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SharedFileForm
{
    public static function configure(Schema $schema): Schema
    {
        $churchId = auth()->user()?->church_id;

        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(150),
                Textarea::make('description')
                    ->rows(3)
                    ->columnSpanFull(),
                FileUpload::make('path')
                    ->label(__('File'))
                    ->disk('public')
                    ->directory('churches/'.($churchId ?? '0').'/files')
                    ->visibility('public')
                    ->required()
                    ->storeFileNamesIn('original_name')
                    ->rule(fn (): \Closure => SharedFileUploadRule::make())
                    ->columnSpanFull()
                    ->helperText(__('PDF, Office docs, audio, images — whatever your team needs to share.')),
                Select::make('team_ids')
                    ->label(__('Share with teams'))
                    ->multiple()
                    ->options(fn (): array => Team::query()
                        ->where('church_id', $churchId)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->columnSpanFull()
                    ->helperText(__('Optional. Team members see these files on the team page.')),
            ]);
    }
}
