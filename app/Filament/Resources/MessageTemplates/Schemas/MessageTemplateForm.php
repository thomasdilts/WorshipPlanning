<?php

namespace App\Filament\Resources\MessageTemplates\Schemas;

use App\Core\Modules\ChurchModuleManager;
use App\Enums\MessageTemplatePurpose;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Modules\Messaging\Services\WhatsAppTemplates;
use Modules\Messaging\Support\MessagePlaceholders;
use Modules\Messaging\Support\MessagingAvailability;

class MessageTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(100),
                TextInput::make('key')
                    ->helperText(__('Optional stable key for code (e.g. task_assigned).'))
                    ->maxLength(100),
                Radio::make('purpose')
                    ->label(__('Used for'))
                    ->options(MessageTemplatePurpose::options())
                    ->descriptions(MessageTemplatePurpose::descriptions())
                    ->default(MessageTemplatePurpose::Service->value)
                    ->required()
                    ->columnSpanFull(),
                Section::make(__('Placeholders'))
                    ->description(__('Personalise one template for every recipient.'))
                    ->icon('heroicon-o-information-circle')
                    ->collapsible()
                    ->schema([
                        View::make('filament.messaging.placeholder-help')
                            ->viewData([
                                'placeholders' => MessagePlaceholders::templateKeys(),
                                'linkPlaceholders' => MessagePlaceholders::LINK_KEYS,
                            ]),
                    ])
                    ->columnSpanFull(),
                TextInput::make('subject')
                    ->label(__('Subject'))
                    ->maxLength(255)
                    ->helperText(__('Email subject line. Placeholders work here too.'))
                    ->columnSpanFull(),
                Section::make(__('Email (HTML)'))
                    ->description(__('Write the HTML on the left; the right side shows how the email will look.'))
                    ->schema([
                        Grid::make(['default' => 1, 'lg' => 2])
                            ->schema([
                                CodeEditor::make('body')
                                    ->label(__('HTML'))
                                    ->language(Language::Html)
                                    ->requiredWithout('text_body')
                                    ->live(debounce: 500)
                                    ->validationMessages([
                                        'required_without' => __('Write an HTML or a text message.'),
                                    ]),
                                ViewField::make('html_preview')
                                    ->label(__('Preview'))
                                    ->view('filament.messaging.html-preview-field')
                                    ->viewData(fn (Get $get): array => [
                                        'subject' => $get('subject'),
                                        'html' => $get('body') ?? '',
                                    ])
                                    ->dehydrated(false),
                            ]),
                    ])
                    ->columnSpanFull(),
                Section::make(__('Text message (SMS / WhatsApp)'))
                    ->description(__('Plain text, no formatting. Leave empty to send a plain-text version of the HTML instead.'))
                    ->schema([
                        Textarea::make('text_body')
                            ->label(__('Text'))
                            ->rows(6)
                            ->maxLength(2000)
                            ->requiredWithout('body')
                            ->validationMessages([
                                'required_without' => __('Write an HTML or a text message.'),
                            ])
                            ->helperText(__('SMS max ~2000 characters (iP.1).')),
                    ])
                    ->columnSpanFull(),
                Section::make(__('WhatsApp template'))
                    ->description(__('WhatsApp only delivers templates approved by Meta, so people who prefer WhatsApp get this approved wording instead of the text above.'))
                    ->visible(fn (): bool => self::whatsAppEnabled())
                    ->schema([
                        Select::make('whatsapp_template')
                            ->label(__('Meta template'))
                            ->options(fn (?string $state): array => self::whatsAppTemplateOptions($state))
                            ->searchable()
                            ->required(fn (): bool => self::whatsAppEnabled())
                            ->helperText(fn (): string => self::whatsAppTemplateHelp()),
                    ])
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->label(__('Active'))
                    ->default(true),
            ]);
    }

    protected static function whatsAppEnabled(): bool
    {
        $church = auth()->user()?->church;

        return $church !== null
            && app(ChurchModuleManager::class)->isEnabled($church, MessagingAvailability::MODULE_WHATSAPP);
    }

    protected static function whatsAppTemplateHelp(): string
    {
        $help = __('Variables use the placeholder names, e.g. {{task}}. Link buttons get {{accept_url}} (first) and {{decline_url}} (second), or {{password_url}} in password messages.');
        $church = auth()->user()?->church;

        if ($church === null) {
            return $help;
        }

        $templates = app(WhatsAppTemplates::class);
        $templates->all($church);
        $fetchError = $templates->fetchError($church);

        return $fetchError === null
            ? $help
            : __('WhatsApp templates could not be loaded: :error', ['error' => $fetchError]).' '.$help;
    }

    /**
     * @return array<string, string>
     */
    protected static function whatsAppTemplateOptions(?string $current): array
    {
        $church = auth()->user()?->church;
        $options = $church === null ? [] : app(WhatsAppTemplates::class)->options($church);

        // Keep the saved choice selectable when Meta cannot be reached.
        if (filled($current) && ! array_key_exists($current, $options)) {
            $options[$current] = $current;
        }

        return $options;
    }
}
