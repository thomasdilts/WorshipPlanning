@if ($hasPagination)
    @php
        $isRtl = __('filament-panels::layout.direction') === 'rtl';
        $pageName = $records->getPageName();
        $pageOptions = $table->getPaginationPageOptions();
    @endphp

    <nav class="wp-mobile-pagination" aria-label="{{ __('filament::components/pagination.label') }}">
        <div class="wp-mobile-pagination-pages">
            <x-filament::icon-button
                color="gray"
                :icon="$isRtl ? 'heroicon-m-chevron-right' : 'heroicon-m-chevron-left'"
                :label="__('filament::components/pagination.actions.previous.label')"
                :disabled="$records->onFirstPage()"
                :wire:click="'previousPage(\'' . $pageName . '\')'"
                wire:loading.attr="disabled"
            />
            <span class="wp-mobile-pagination-current" aria-current="page" aria-live="polite">
                {{ \Illuminate\Support\Number::format($records->currentPage()) }}
            </span>
            <x-filament::icon-button
                color="gray"
                :icon="$isRtl ? 'heroicon-m-chevron-left' : 'heroicon-m-chevron-right'"
                :label="__('filament::components/pagination.actions.next.label')"
                :disabled="! $records->hasMorePages()"
                :wire:click="'nextPage(\'' . $pageName . '\')'"
                wire:loading.attr="disabled"
            />
        </div>

        @if (count($pageOptions) > 1)
            <label class="wp-mobile-pagination-size">
                <span class="fi-sr-only">{{ __('filament::components/pagination.fields.records_per_page.label') }}</span>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="tableRecordsPerPage">
                        @foreach ($pageOptions as $option)
                            <option value="{{ $option }}">{{ $option === 'all' ? __('filament::components/pagination.fields.records_per_page.options.all') : $option }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
        @endif
    </nav>
@endif
