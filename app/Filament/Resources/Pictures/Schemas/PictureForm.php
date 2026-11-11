<?php

namespace App\Filament\Resources\Pictures\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PictureForm
{
    public static function configure(Schema $schema): Schema
    {
        $churchId = auth()->user()?->church_id;

        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(100),
                Textarea::make('description')
                    ->rows(3)
                    ->columnSpanFull(),
                FileUpload::make('path')
                    ->label(__('Image'))
                    ->image()
                    ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp', 'image/gif'])
                    ->disk('public')
                    ->directory('churches/'.($churchId ?? '0').'/pictures')
                    ->visibility('public')
                    ->imageEditor()
                    ->required()
                    ->columnSpanFull()
                    ->helperText(__('Stored in the church picture library for reuse on service-order tasks.')),
            ]);
    }
}
