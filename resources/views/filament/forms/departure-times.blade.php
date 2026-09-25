{{--
    {@see \App\Filament\Forms\DepartureTimes}: the times as chips, sorted, each
    with its own ×, and «+ Ώρα» opening one small input in the same row.

    The line underneath counts the ticked days of the same schedule, so the
    operator reads what the choice means («15 αναχωρήσεις τη βδομάδα») before
    saving it. It reads those days from Livewire's own state; nothing is sent
    to the server until the form is.
--}}
@php
    $statePath = $getStatePath();
    $messages = [
        'invalid' => __('availability.schedule_rule.form.start_times.invalid'),
        'duplicate' => __('availability.schedule_rule.form.start_times.duplicate', ['time' => '__TIME__']),
        'none' => __('availability.schedule_rule.form.start_times.none'),
        'no_days' => __('availability.schedule_rule.form.start_times.no_days'),
        'summary' => __('availability.schedule_rule.form.start_times.summary'),
        'times_one' => __('availability.schedule_rule.form.start_times.times_one'),
        'times_many' => __('availability.schedule_rule.form.start_times.times_many'),
        'days_one' => __('availability.schedule_rule.form.start_times.days_one'),
        'days_many' => __('availability.schedule_rule.form.start_times.days_many'),
    ];
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        class="ka-times"
        x-data="{
            state: $wire.{{ $applyStateBindingModifiers("\$entangle('{$statePath}')") }},
            daysPath: @js($getDaysStatePath()),
            t: @js($messages),
            adding: false,
            draft: '',
            error: '',
            get times() {
                return [...new Set((Array.isArray(this.state) ? this.state : Object.values(this.state ?? {}))
                    .map((item) => this.parse(item && typeof item === 'object' ? item.time : item))
                    .filter(Boolean))].sort();
            },
            get dayCount() {
                const days = $wire.$get(this.daysPath);

                return Array.isArray(days) ? days.length : Object.values(days ?? {}).length;
            },
            get summary() {
                if (this.times.length === 0) return this.t.none;
                if (this.dayCount === 0) return this.t.no_days;

                return this.t.summary
                    .replace(':count', this.times.length * this.dayCount)
                    .replace(':times', (this.times.length === 1 ? this.t.times_one : this.t.times_many).replace(':n', this.times.length))
                    .replace(':days', (this.dayCount === 1 ? this.t.days_one : this.t.days_many).replace(':n', this.dayCount));
            },
            parse(value) {
                if (value === null || value === undefined) return null;
                value = String(value).trim();
                let h, m, match;
                if ((match = value.match(/(?:^|\s)(\d{1,2}):(\d{2})(?::\d{2})?$/))) {
                    h = +match[1]; m = +match[2];
                } else if (/^\d{1,4}$/.test(value)) {
                    const d = value.length <= 2 ? value.padStart(2, '0') + '00' : value.padStart(4, '0');
                    h = +d.slice(0, 2); m = +d.slice(2);
                } else {
                    return null;
                }
                return h <= 23 && m <= 59 ? String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') : null;
            },
            open() {
                this.adding = true;
                this.error = '';
                this.$nextTick(() => this.$refs.input.focus());
            },
            close() {
                this.adding = false;
                this.draft = '';
                this.error = '';
            },
            add() {
                const time = this.parse(this.draft);
                if (! time) { this.error = this.t.invalid; return; }
                if (this.times.includes(time)) { this.error = this.t.duplicate.replace('__TIME__', time); return; }
                this.state = [...this.times, time].sort();
                this.close();
            },
            remove(time) {
                this.state = this.times.filter((other) => other !== time);
            },
        }"
    >
        <div class="ka-times-row" role="group" aria-label="{{ $getLabel() }}">
            <template x-for="time in times" :key="time">
                <span class="ka-time">
                    <span x-text="time"></span>
                    <button type="button" class="ka-time-x" x-on:click="remove(time)" :aria-label="@js(__('availability.schedule_rule.form.start_times.remove')) + ' ' + time">&times;</button>
                </span>
            </template>

            <span class="ka-time-adding" x-show="adding" x-cloak>
                <input
                    type="text"
                    inputmode="numeric"
                    autocomplete="off"
                    maxlength="5"
                    x-ref="input"
                    x-model="draft"
                    x-on:keydown.enter.prevent="add()"
                    x-on:keydown.escape.prevent="close()"
                    placeholder="{{ __('availability.schedule_rule.form.start_times.placeholder') }}"
                    aria-label="{{ __('availability.schedule_rule.form.start_times.new') }}"
                >
                <button type="button" class="ka-time-ok" x-on:click="add()">{{ __('availability.schedule_rule.form.start_times.add') }}</button>
            </span>

            <button type="button" class="ka-time-add" x-show="! adding" x-on:click="open()">+ {{ __('availability.schedule_rule.form.start_times.chip') }}</button>
        </div>

        <p class="ka-times-error" x-show="error" x-text="error" x-cloak role="alert"></p>
        <p class="ka-times-sum" :class="{ 'is-empty': times.length === 0 || dayCount === 0 }" x-text="summary"></p>
    </div>
</x-dynamic-component>

@once
    <style>
        .ka-times { display: grid; gap: .5rem; }
        .ka-times-row {
            display: flex; flex-wrap: wrap; align-items: center; gap: .5rem;
            min-height: 3.25rem; padding: .5rem;
            border: 1px solid rgb(var(--gray-300)); border-radius: .625rem; background: #fff;
        }
        .ka-time {
            display: inline-flex; align-items: center; gap: .25rem;
            padding: .3rem .35rem .3rem .75rem; border-radius: 999px;
            background: #EAF1FA; color: #0F2E57;
            font-weight: 700; font-variant-numeric: tabular-nums;
        }
        .ka-time-x {
            inline-size: 1.5rem; block-size: 1.5rem; border-radius: 999px;
            display: grid; place-items: center; line-height: 1;
            color: rgb(var(--gray-500)); font-size: 1.1rem;
        }
        .ka-time-x:hover { background: #D6E3F3; color: #B42318; }
        .ka-time-add {
            display: inline-flex; align-items: center; gap: .35rem;
            padding: .3rem .8rem; border-radius: 999px;
            border: 1px dashed rgb(var(--gray-300));
            color: #1E5AA8; font-weight: 600;
        }
        .ka-time-add:hover { border-color: #1E5AA8; background: #F5F9FE; }
        .ka-time-adding { display: inline-flex; align-items: center; gap: .4rem; }
        .ka-time-adding input {
            inline-size: 7.5rem; padding: .3rem .7rem; border-radius: 999px;
            border: 1px solid #1E5AA8; font: inherit; font-variant-numeric: tabular-nums;
        }
        .ka-time-adding input:focus { outline: 2px solid #1E5AA8; outline-offset: 1px; }
        .ka-time-ok {
            padding: .3rem .8rem; border-radius: 999px;
            background: #1E5AA8; color: #fff; font-weight: 600;
        }
        .ka-times-error { margin: 0; font-size: .8125rem; color: #B42318; }
        .ka-times-sum {
            margin: 0; padding: .6rem .75rem; border-radius: .625rem;
            font-size: .875rem; background: #E3F2EA; color: #1F7A4D;
        }
        .ka-times-sum.is-empty { background: rgb(var(--gray-100)); color: rgb(var(--gray-600)); }

        /* A thumb, not a cursor: the 44px the rest of the panel gives a phone. */
        @media (max-width: 1023.98px) {
            .ka-time-x { inline-size: 2.25rem; block-size: 2.25rem; }
            .ka-time-add, .ka-time-ok, .ka-time-adding input { min-block-size: 2.5rem; }
        }
    </style>
@endonce
