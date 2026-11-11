<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div class="wp-start-time" x-data="{
        state: $wire.$entangle(@js($getStatePath())),
        open: false,
        above: false,
        showMenu(focus = false) {
            const rect = this.$el.getBoundingClientRect();
            this.above = window.innerHeight - rect.bottom < 260 && rect.top > 260;
            this.open = true;
            const position = () => {
                if (!this.open) return;
                if (!this.$refs.list.clientHeight) {
                    requestAnimationFrame(position);
                    return;
                }
                const options = Array.from(this.$refs.list.children);
                const [hour, minute] = (this.state || '11:00').split(':').map(Number);
                const index = Math.max(0, Math.min(options.length - 1, Math.round((hour * 60 + minute) / 15)));
                const selected = options.find(option => option.dataset.time === this.state) || options[index];
                this.$refs.list.scrollTop = selected.offsetTop - (this.$refs.list.clientHeight - selected.clientHeight) / 2;
                if (focus) selected.focus({ preventScroll: true });
            };
            this.$nextTick(() => requestAnimationFrame(position));
        },
        choose(value) {
            this.state = value;
            this.open = false;
            this.$refs.input.focus({ preventScroll: true });
        },
        move(event, direction) {
            const next = direction > 0 ? event.target.nextElementSibling : event.target.previousElementSibling;
            next?.focus({ preventScroll: true });
            next?.scrollIntoView({ block: 'nearest' });
        }
    }"
        x-on:click.outside="open = false"
        x-on:focusout="if (!$el.contains($event.relatedTarget)) open = false"
        x-on:keydown.escape.prevent.stop="open = false; $refs.input.focus()">
        <x-filament::input.wrapper :valid="!$errors->has($getStatePath())">
            <input type="time" step="60" id="{{ $getId() }}" x-ref="input" x-model="state"
                class="fi-input wp-start-time-input" @required($isRequired()) @disabled($isDisabled())
                aria-controls="{{ $getId() }}-options" aria-haspopup="listbox" x-bind:aria-expanded="open"
                x-on:click="showMenu()" x-on:keydown.arrow-down.prevent="showMenu(true)" />
            <button type="button" class="wp-start-time-toggle" @disabled($isDisabled())
                aria-label="{{ __('Choose start time') }}" aria-haspopup="listbox"
                aria-controls="{{ $getId() }}-options" x-bind:aria-expanded="open"
                x-on:click="open ? open = false : showMenu(true)" x-on:keydown.arrow-down.prevent="showMenu(true)">
                <x-filament::icon icon="heroicon-m-chevron-down" />
            </button>
        </x-filament::input.wrapper>
        <div x-cloak x-show="open" x-ref="list" role="listbox" id="{{ $getId() }}-options"
            aria-label="{{ __('Start time') }}" class="wp-start-time-options" x-bind:class="{ 'wp-start-time-options-above': above }"
            x-on:keydown.arrow-down.prevent="move($event, 1)" x-on:keydown.arrow-up.prevent="move($event, -1)"
            x-on:keydown.home.prevent="$refs.list.firstElementChild.focus()"
            x-on:keydown.end.prevent="$refs.list.lastElementChild.focus()">
            @foreach (range(0, 1425, 15) as $minutes)
                @php($time = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60))
                <button type="button" role="option" tabindex="-1" data-time="{{ $time }}"
                    x-bind:aria-selected="state === @js($time)" x-on:click="choose(@js($time))">
                    {{ \Illuminate\Support\Carbon::createFromTime(intdiv($minutes, 60), $minutes % 60)->isoFormat('LT') }}
                </button>
            @endforeach
        </div>
    </div>
</x-dynamic-component>
