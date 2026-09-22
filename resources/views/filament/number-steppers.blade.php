{{--
    The other half of {@see \App\Filament\Support\NumberSteppers}: the browser's
    own spinner goes away, and the two buttons that replace it get a size and
    the behaviour behind them.

    Injected from a render hook for the same reason as `touch-targets`: a
    compiled Filament theme is a second Vite entry point and its own build step,
    which is a large change to the asset pipeline to carry a dozen declarations
    and thirty lines of script.
--}}
<style>
    /* The stacked arrows, gone. They are what the − and + replace, and a field
       with both is a field with four ways to add one. */
    .fi-input-wrp input[type="number"]::-webkit-outer-spin-button,
    .fi-input-wrp input[type="number"]::-webkit-inner-spin-button {
        -webkit-appearance: none;
        margin: 0;
    }

    .fi-input-wrp input[type="number"] {
        -moz-appearance: textfield;
        appearance: textfield;
    }

    /* A square the size of a row action, so the two of them and the value read
       as one control rather than as a field with decoration either side. */
    .fi-input-wrp .ka-step {
        block-size: 2rem;
        inline-size: 2rem;
        border-radius: .5rem;
    }

    .fi-input-wrp .ka-step:hover {
        background-color: rgb(var(--gray-100));
    }

    .dark .fi-input-wrp .ka-step:hover {
        background-color: rgb(var(--gray-700));
    }

    /* The value between them, rather than hard against the − on its left.
       A number that is being stepped is read at a glance and compared with the
       one before it, which is easier from the middle than from an edge. */
    .fi-input-wrp:has(.ka-step) input[type="number"] {
        text-align: center;
    }

    /* Below the desktop breakpoint they are what a thumb aims at, so they take
       the 44px `touch-targets` gives every other control on a phone. */
    @media (max-width: 1023.98px) {
        .fi-input-wrp .ka-step {
            block-size: 2.5rem;
            inline-size: 2.5rem;
        }
    }
</style>

<script>
    /**
     * Step the field one of these buttons belongs to.
     *
     * Arithmetic rather than `input.stepUp()`. Filament's `numeric()` sets
     * `step="any"`, and `stepUp()` on an "any" field throws `InvalidStateError`
     * in every browser — so the one call that looks like the right answer is
     * the one that fails on most of the fields in this panel.
     *
     * The `input` event is what tells Livewire: it is the same event typing
     * raises, so a stepped value is committed exactly like a typed one, with
     * the same deferred binding and the same validation.
     */
    window.kaikiStepNumber = function (button, direction) {
        const wrapper = button.closest('.fi-input-wrp');
        const input = wrapper ? wrapper.querySelector('input[type="number"]') : null;

        if (!input || input.disabled || input.readOnly) {
            return;
        }

        // «any» is Filament's default for a numeric field, and it means "no
        // granularity", not "no sensible press". One is the unit of everything
        // this panel counts: people, minutes, hours, percent, days.
        const step = Number(input.step) > 0 ? Number(input.step) : 1;

        const current = Number(input.value);
        const from = input.value === '' || Number.isNaN(current) ? 0 : current;

        let next = from + (direction * step);

        const min = input.min === '' ? null : Number(input.min);
        const max = input.max === '' ? null : Number(input.max);

        if (min !== null && !Number.isNaN(min) && next < min) {
            next = min;
        }

        if (max !== null && !Number.isNaN(max) && next > max) {
            next = max;
        }

        // Floating point: 0.1 + 0.2 in a field whose step is 0.1 must not put
        // «0.30000000000000004» in front of an operator. The step's own
        // precision is the precision of the answer.
        const decimals = (String(step).split('.')[1] || '').length;
        input.value = decimals > 0 ? next.toFixed(decimals) : String(next);

        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    };
</script>
