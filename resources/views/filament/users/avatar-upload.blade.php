@php
    $picture = $field->currentPicture();
    $isDisabled = $field->isDisabled();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        class="wp-avatar-field"
        wire:ignore
        x-data="avatarEditor(@js([
            'statePath' => $getStatePath(),
            'componentKey' => $field->getKey(),
            'key' => $picture['key'],
            'url' => $picture['url'],
            'edge' => \App\Filament\Forms\AvatarUpload::UPLOAD_EDGE,
            'disabled' => $isDisabled,
            'messages' => [
                'confirmDelete' => __('Are you sure you want to delete this profile picture?'),
                'unreadable' => __('This picture can\'t be opened. Please choose a JPG or PNG picture.'),
                'uploadFailed' => __('The picture could not be uploaded. Please try again.'),
            ],
        ]))"
    >
        <div class="wp-avatar-field-row">
            <button
                type="button"
                class="wp-avatar-field-preview"
                x-bind:class="{ 'wp-avatar-field-preview-drop': dropping }"
                x-on:click="choose()"
                x-on:dragover.prevent="dropping = ! disabled"
                x-on:dragleave="dropping = false"
                x-on:drop.prevent="dropping = false; load($event.dataTransfer.files[0])"
                @disabled($isDisabled)
                aria-label="{{ __('Choose picture') }}"
            >
                <img x-show="previewUrl" x-bind:src="previewUrl" alt="" @if (! $picture['url']) x-cloak @endif @if ($picture['url']) src="{{ $picture['url'] }}" @endif>
                <span class="wp-avatar-field-empty" x-show="! previewUrl" @if ($picture['url']) x-cloak @endif>
                    {{ \Filament\Support\generate_icon_html(\Filament\Support\Icons\Heroicon::OutlinedUser, size: \Filament\Support\Enums\IconSize::TwoExtraLarge) }}
                </span>
                <span class="wp-avatar-field-busy" x-show="busy" x-cloak>
                    {{ \Filament\Support\generate_loading_indicator_html(size: \Filament\Support\Enums\IconSize::Large) }}
                </span>
            </button>

            @unless ($isDisabled)
                <div class="wp-avatar-field-actions">
                    <x-filament::button type="button" icon="heroicon-m-photo" x-on:click="choose()" x-bind:disabled="busy">
                        <span x-show="! previewUrl" @if ($picture['url']) x-cloak @endif>{{ __('Choose picture') }}</span>
                        <span x-show="previewUrl" @if (! $picture['url']) x-cloak @endif>{{ __('Change picture') }}</span>
                    </x-filament::button>
                    <x-filament::button type="button" color="gray" icon="heroicon-m-pencil-square" x-show="previewUrl" x-on:click="editCurrent()" x-bind:disabled="busy" :x-cloak="! $picture['url']">
                        {{ __('Edit picture') }}
                    </x-filament::button>
                    <x-filament::button type="button" color="danger" outlined icon="heroicon-m-trash" x-show="previewUrl" x-on:click="remove()" x-bind:disabled="busy" :x-cloak="! $picture['url']">
                        {{ __('Delete picture') }}
                    </x-filament::button>
                </div>
            @endunless
        </div>

        @unless ($isDisabled)
            <p class="wp-avatar-field-hint" x-show="! changed">{{ __('You can also drag a picture onto the square.') }}</p>
            <p class="wp-avatar-field-notice" x-show="changed" x-cloak>{{ __('Remember to save to keep the change.') }}</p>
            <p class="wp-avatar-field-error" role="alert" x-show="error" x-text="error" x-cloak></p>

            <input type="file" accept="image/*" hidden x-ref="input" x-on:change="load($event.target.files[0]); $event.target.value = ''">

            <template x-teleport="body">
                <div
                    class="wp-avatar-editor"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="{{ $getId() }}-editor-heading"
                    x-show="open"
                    x-cloak
                    x-trap.noscroll="open"
                    x-on:keydown.escape.prevent.stop="cancel()"
                >
                    <div class="wp-avatar-editor-backdrop" aria-hidden="true" x-on:click="cancel()"></div>

                    <div class="wp-avatar-editor-window">
                        <h2 class="wp-avatar-editor-heading" id="{{ $getId() }}-editor-heading">{{ __('Edit picture') }}</h2>
                        <p class="wp-avatar-editor-help">{{ __('Drag the picture to move it. Use the slider to make it bigger or smaller.') }}</p>

                        <div class="wp-avatar-editor-row">
                            <div
                                class="wp-avatar-editor-stage"
                                tabindex="0"
                                x-ref="stage"
                                x-bind:class="{ 'wp-avatar-editor-stage-dragging': dragging }"
                                aria-label="{{ __('Picture area. Drag or use the arrow keys to move it, plus and minus to zoom.') }}"
                                x-on:pointerdown="pointerDown($event)"
                                x-on:pointermove="pointerMove($event)"
                                x-on:pointerup="pointerUp($event)"
                                x-on:pointercancel="pointerUp($event)"
                                x-on:wheel.prevent="wheel($event)"
                                x-on:keydown="key($event)"
                            >
                                <img x-ref="image" alt="" draggable="false">
                                <div class="wp-avatar-editor-frame" aria-hidden="true"></div>
                            </div>

                            <div class="wp-avatar-editor-side">
                                <span class="wp-avatar-editor-label">{{ __('Preview') }}</span>
                                <canvas x-ref="preview" width="{{ \App\Filament\Forms\AvatarUpload::UPLOAD_EDGE }}" height="{{ \App\Filament\Forms\AvatarUpload::UPLOAD_EDGE }}"></canvas>
                            </div>
                        </div>

                        <div class="wp-avatar-editor-zoom">
                            <button type="button" class="wp-avatar-editor-zoom-step" x-on:click="zoomBy(1.15)" aria-label="{{ __('Zoom out') }}">−</button>
                            <input type="range" min="0" max="1000" x-ref="zoom" x-on:input="zoomSlider($event.target.value)" aria-label="{{ __('Zoom') }}">
                            <button type="button" class="wp-avatar-editor-zoom-step" x-on:click="zoomBy(1 / 1.15)" aria-label="{{ __('Zoom in') }}">+</button>
                            <x-filament::button type="button" color="gray" size="sm" icon="heroicon-m-arrow-path" x-on:click="reset()">
                                {{ __('Start over') }}
                            </x-filament::button>
                        </div>

                        <div class="wp-avatar-editor-footer">
                            <x-filament::button type="button" color="gray" x-on:click="cancel()">
                                {{ __('Cancel') }}
                            </x-filament::button>
                            <x-filament::button type="button" icon="heroicon-m-check" x-on:click="apply()">
                                {{ __('Use this picture') }}
                            </x-filament::button>
                        </div>
                    </div>
                </div>
            </template>
        @endunless
    </div>
</x-dynamic-component>
