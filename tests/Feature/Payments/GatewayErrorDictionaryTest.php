<?php

declare(strict_types=1);

use App\Domain\Payments\Support\GatewayErrorDictionary;
use App\Enums\PaymentGatewayName;
use App\Support\Locale\LocaleResolver;

/*
|--------------------------------------------------------------------------
| PAY-12: every code, in two languages, for two audiences
|--------------------------------------------------------------------------
|
| The requirement is short and the failure it prevents is not: *"raw gateway
| error text is never shown to a guest."* A guest who sees
| `card_declined: insufficient_funds` has been told something about their own
| bank balance by a company they have never heard of, in a language chosen by a
| developer in California, and given nothing they can do about it.
|
| These tests assert the mechanism rather than the wording: that every code
| resolves, in both languages, for both audiences, and that the guest half never
| carries the code.
|
*/

/** @return list<PaymentGatewayName> */
function dictionaryGateways(): array
{
    return [PaymentGatewayName::Stripe, PaymentGatewayName::Viva];
}

it('has both languages and both audiences for every code it knows', function (): void {
    $missing = [];

    foreach (dictionaryGateways() as $gateway) {
        foreach (GatewayErrorDictionary::codesFor($gateway) as $code) {
            $message = GatewayErrorDictionary::describe($gateway, $code);

            foreach (['guestEl', 'guestEn', 'operatorEl', 'operatorEn'] as $field) {
                $value = $message->{$field};

                // A key that failed to resolve comes back *as the key*, which
                // renders as `payments.operator.viva.declined` on an operator's
                // screen — technically a string, and useless. I18N-1 relies on
                // a missing translation looking missing.
                if ($value === '' || str_starts_with($value, 'payments.')) {
                    $missing[] = "{$gateway->value}.{$code}.{$field}";
                }
            }
        }
    }

    expect($missing)->toBe([], "unresolved dictionary strings:\n" . implode("\n", $missing));
})->group('fast', 'i18n');

it('never puts the gateway code in front of a guest', function (): void {
    $leaks = [];

    foreach (dictionaryGateways() as $gateway) {
        foreach (GatewayErrorDictionary::codesFor($gateway) as $code) {
            $message = GatewayErrorDictionary::describe($gateway, $code);

            // PAY-12's whole point. Viva's codes are single digits, so a naive
            // `str_contains` would match any sentence containing "2" — the
            // check is word-bounded for exactly that reason.
            foreach (['guestEl', 'guestEn'] as $field) {
                if (preg_match('/(?<![\w])' . preg_quote($code, '/') . '(?![\w])/u', $message->{$field}) === 1) {
                    $leaks[] = "{$gateway->value}.{$code}.{$field}";
                }
            }
        }
    }

    expect($leaks)->toBe([], "gateway codes visible to a guest:\n" . implode("\n", $leaks));
})->group('fast');

it('gives an operator something different from what it gives a guest', function (): void {
    $identical = [];

    foreach (dictionaryGateways() as $gateway) {
        foreach (GatewayErrorDictionary::codesFor($gateway) as $code) {
            $message = GatewayErrorDictionary::describe($gateway, $code);

            // If the two are the same sentence, one of the two audiences is
            // being served badly — either the guest is being told too much or
            // the operator is being told nothing they can act on.
            if ($message->guestEn === $message->operatorEn) {
                $identical[] = "{$gateway->value}.{$code}";
            }
        }
    }

    expect($identical)->toBe([], "one sentence serving both audiences:\n" . implode("\n", $identical));
})->group('fast');

it('names the gateway in the operator line, because an operator may run both', function (): void {
    // An operator with Viva and Stripe configured reads a failure feed
    // containing both. "The card was declined" without a gateway is a message
    // they cannot trace back to a dashboard.
    foreach (dictionaryGateways() as $gateway) {
        foreach (GatewayErrorDictionary::codesFor($gateway) as $code) {
            $message = GatewayErrorDictionary::describe($gateway, $code);

            expect($message->operatorEn)->toContain($gateway === PaymentGatewayName::Stripe ? 'Stripe' : 'Viva');
        }
    }
})->group('fast');

it('degrades an unmapped code in both directions at once', function (): void {
    $message = GatewayErrorDictionary::describe(PaymentGatewayName::Stripe, 'brand_new_code_2027');

    expect($message->isUnmapped)->toBeTrue()
        // Never nothing, and never the code.
        ->and($message->guestEn)->toBe((string) trans('payments.guest.generic', [], 'en'))
        ->and($message->guestEl)->toBe((string) trans('payments.guest.generic', [], 'el'))
        ->and($message->guestEn)->not->toContain('brand_new_code_2027')
        // The operator gets it, marked unrecognised, in both languages —
        // because they are the only person who can report the gap.
        ->and($message->operatorEn)->toContain('brand_new_code_2027')
        ->and($message->operatorEl)->toContain('brand_new_code_2027')
        ->and($message->code)->toBe('brand_new_code_2027');
})->group('fast');

it('resolves in whatever locale the reader is in, not the request', function (): void {
    // The four sentences are resolved at *description* time in both languages
    // and carried together, rather than rendered in the ambient locale. A guest
    // can hit a refusal mid-locale-switch, and a payment failure stored in a
    // column has to still be readable by an operator whose panel is in the
    // other language.
    foreach (LocaleResolver::installed() as $locale) {
        app()->setLocale($locale);

        $message = GatewayErrorDictionary::describe(PaymentGatewayName::Viva, '2');

        expect($message->forGuest('el'))->toBe($message->guestEl)
            ->and($message->forGuest('en'))->toBe($message->guestEn)
            ->and($message->guestEl)->not->toBe($message->guestEn);
    }
})->group('fast', 'i18n');

it('has no dictionary for the two that never call anything', function (): void {
    // Cash and bank transfer are how a manual booking is recorded as paid
    // (BKG-33). There is no gateway to return an error, so every code is
    // unmapped — which is the honest answer rather than a fabricated table.
    foreach ([PaymentGatewayName::Cash, PaymentGatewayName::BankTransfer] as $gateway) {
        expect(GatewayErrorDictionary::codesFor($gateway))->toBe([])
            ->and(GatewayErrorDictionary::describe($gateway, 'anything')->isUnmapped)->toBeTrue();
    }
})->group('fast');
