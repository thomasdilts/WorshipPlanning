<?php

namespace App\Filament\Resources\Events\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class EventInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name'),
                TextEntry::make('description')
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextEntry::make('starts_at')
                    ->label(__('Starts'))
                    ->dateTime('D M j, Y H:i'),
            ]);
    }
}
