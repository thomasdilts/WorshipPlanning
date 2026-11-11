<?php

namespace App\Filament\Forms;

use App\Enums\MessageTemplatePurpose;
use App\Models\MessageTemplate;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Modules\Messaging\Services\TemplateMessages;

/**
 * Choosing the message template every message is sent from, and the text for its {{custom_mess}}.
 */
class MessageTemplateFields
{
    /**
     * @return list<Component>
     */
    public static function components(MessageTemplatePurpose $purpose, bool $singlePreview = false): array
    {
        return [
            Select::make('message_template_id')
                ->label(__('Message template'))
                ->options(fn (): array => app(TemplateMessages::class)->options($purpose))
                ->helperText(__('Only templates made for :purpose are listed.', ['purpose' => mb_strtolower($purpose->label())]))
                ->searchable()
                ->required()
                ->live()
                ->columnSpanFull(),
            ViewField::make('template_preview')
                ->hiddenLabel()
                ->view('filament.messaging.message-preview')
                ->viewData(function (Get $get) use ($singlePreview): array {
                    $template = self::template($get);

                    return [
                        'subject' => null,
                        'html' => $template?->body ?? '',
                        'text' => $template?->text_body,
                        'singlePreview' => $singlePreview,
                    ];
                })
                ->visible(fn (Get $get): bool => self::template($get) !== null)
                ->dehydrated(false)
                ->columnSpanFull(),
            Textarea::make('custom_message')
                ->label(__('Custom message'))
                ->helperText(__('Replaces {{custom_mess}} in the template. WhatsApp shows it on one line.'))
                ->rows(5)
                ->maxLength(1000)
                ->required()
                ->visible(fn (Get $get): bool => app(TemplateMessages::class)->usesCustomMessage(self::template($get)))
                ->columnSpanFull(),
        ];
    }

    protected static function template(Get $get): ?MessageTemplate
    {
        $id = $get('message_template_id');

        return filled($id) ? MessageTemplate::query()->find($id) : null;
    }
}
