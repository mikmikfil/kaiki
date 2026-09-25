{{--
    {@see \App\Filament\Forms\DepartureTimes}: the times as chips, sorted, each
    with its own ×, and «+ Ώρα» opening a picker under the row: the hours, then
    the minutes in fives — two taps, no typing (Mike, 25/9: «θέλω timepicker,
    όχι όπως είναι»; it was a text box that wanted «14:30» typed).

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
            hour: null,
            hours: Array.from({ length: 24 }, (_, i) => String(i).padStart(2, '0')),
            minutes: Array.from({ length: 12 }, (_, i) => String(i * 5).padStart(2, '0')),
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
                this.hour = null;
                this.error = '';
            },
            close() {
                this.adding = false;
                this.hour = null;
                this.error = '';
            },
            pick(minute) {
                if (this.hour === null) return;
                const time = this.hour + ':' + minute;
                if (this.times.includes(time)) { this.error = this.t.duplicate.replace('__TIME__', time); return; }
                this.state = [...this.times, time].sort();
                this.close();
            },
            hasHour(hour) {
                return this.times.some((time) => time.startsWith(hour + ':'));
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

            <button type="button" class="ka-time-add" x-show="! adding" x-on:click="open()">+ {{ __('availability.schedule_rule.form.start_times.chip') }}</button>
        </div>

        <div class="ka-pick" x-show="adding" x-cloak x-on:keydown.escape.prevent="close()"
             role="group" aria-label="{{ __('availability.schedule_rule.form.start_times.new') }}">
            <p class="ka-pick-label">{{ __('availability.schedule_rule.form.start_times.hour') }}</p>
            <div class="ka-pick-grid is-hours">
                <template x-for="h in hours" :key="h">
                    <button type="button" x-text="h" x-on:click="hour = h"
                            :class="{ 'is-on': hour === h, 'is-used': hasHour(h) }"
                            :aria-pressed="hour === h ? 'true' : 'false'"></button>
                </template>
            </div>
            <p class="ka-pick-label">{{ __('availability.schedule_rule.form.start_times.minutes') }}</p>
            <div class="ka-pick-grid is-minutes">
                <template x-for="m in minutes" :key="m">
                    <button type="button" x-on:click="pick(m)" :disabled="hour === null"
                            x-text="(hour ?? '--') + ':' + m"
                            :class="{ 'is-used': hour !== null && times.includes(hour + ':' + m) }"></button>
                </template>
            </div>
            <div class="ka-pick-foot">
                <button type="button" class="ka-pick-cancel" x-on:click="close()">{{ __('availability.schedule_rule.form.start_times.cancel') }}</button>
            </div>
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
        .ka-pick {
            display: grid; gap: .5rem; padding: .75rem;
            border: 1px solid rgb(var(--gray-200)); border-radius: .625rem; background: #fff;
            box-shadow: 0 8px 24px rgba(11, 39, 64, .08);
        }
        .ka-pick-label { margin: 0; font-size: .8125rem; font-weight: 600; color: rgb(var(--gray-600)); }
        .ka-pick-grid { display: grid; gap: .35rem; }
        .ka-pick-grid.is-hours { grid-template-columns: repeat(8, minmax(0, 1fr)); }
        .ka-pick-grid.is-minutes { grid-template-columns: repeat(6, minmax(0, 1fr)); }
        .ka-pick-grid button {
            min-block-size: 2.25rem; border-radius: .5rem;
            border: 1px solid rgb(var(--gray-200)); background: #fff;
            font-weight: 600; font-variant-numeric: tabular-nums; color: #0F2E57;
        }
        .ka-pick-grid button:hover:not(:disabled) { border-color: #1E5AA8; background: #F5F9FE; }
        .ka-pick-grid button.is-used { background: #EAF1FA; }
        .ka-pick-grid button.is-on, .ka-pick-grid button.is-on:hover { background: #1E5AA8; border-color: #1E5AA8; color: #fff; }
        .ka-pick-grid button:disabled { color: rgb(var(--gray-400)); cursor: default; }
        .ka-pick-grid button:focus-visible { outline: 2px solid #1E5AA8; outline-offset: 1px; }
        .ka-pick-foot { display: flex; justify-content: flex-end; }
        .ka-pick-cancel { padding: .3rem .8rem; border-radius: 999px; color: rgb(var(--gray-600)); font-weight: 600; }
        .ka-pick-cancel:hover { background: rgb(var(--gray-100)); }
        @media (max-width: 30rem) {
            .ka-pick-grid.is-hours { grid-template-columns: repeat(6, minmax(0, 1fr)); }
            .ka-pick-grid.is-minutes { grid-template-columns: repeat(4, minmax(0, 1fr)); }
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
            .ka-time-add, .ka-pick-cancel { min-block-size: 2.5rem; }
            .ka-pick-grid button { min-block-size: 2.75rem; }
        }
    </style>
@endonce
