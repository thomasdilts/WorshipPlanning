<?php

namespace App\Filament\Resources\AutomaticMessages\Schemas;

use App\Enums\MessageTemplatePurpose;
use App\Filament\Forms\MessageTemplateFields;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class AutomaticMessageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(100),
                TextInput::make('hours_before')
                    ->label(__('Hours before the service starts'))
                    ->integer()
                    ->minValue(1)
                    ->maxValue(24 * 60)
                    ->required()
                    ->live(onBlur: true)
                    ->helperText(fn (Get $get): string => self::hoursHelp($get('hours_before'))),
                ...MessageTemplateFields::components(MessageTemplatePurpose::Service),
                Toggle::make('is_active')
                    ->label(__('Active'))
                    ->helperText(__('Inactive automatic messages are never sent.'))
                    ->default(true),
            ]);
    }

    protected static function hoursHelp(mixed $hours): string
    {
        if (! is_numeric($hours) || (int) $hours < 1) {
            return __('For example 72 for three days before. Checked every hour.');
        }

        return __('Sent about :time before each service. Checked every hour.', ['time' => self::duration((int) $hours)]);
    }

    public static function duration(int $hours): string
    {
        $days = intdiv($hours, 24);
        $rest = $hours % 24;

        return match (true) {
            $days === 0 => trans_choice(':count hour|:count hours', $hours),
            $rest === 0 => trans_choice(':count day|:count days', $days),
            default => trans_choice(':count day|:count days', $days).' '.trans_choice(':count hour|:count hours', $rest),
        };
    }
}
