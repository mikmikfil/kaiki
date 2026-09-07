<?php

declare(strict_types=1);

use App\Enums\HomeBlockType;
use App\Models\Faq;
use App\Models\HomePageBlock;

use function Pest\Laravel\get;

use Tests\Support\Hosted\HostedRequest;
use Tests\Support\Hosted\OperatorPage;

/*
|--------------------------------------------------------------------------
| #103: the FAQPage block, asserted by parsing it
|--------------------------------------------------------------------------
|
| A JSON-LD block that does not parse is discarded by Google **silently**. So a
| test that greps for `FAQPage` would pass on markup that achieves exactly
| nothing, which is worse than no test — it would report the feature working on
| the day it stopped.
|
| Every assertion here therefore goes through `json_decode` on the actual
| contents of the actual script element, and asserts on the decoded structure.
|
*/

/**
 * The decoded JSON-LD of a hosted page, or null when the page carries none.
 *
 * @return array<string, mixed>|null
 */
function faqSchemaOn(string $path): ?array
{
    $body = (string) get(HostedRequest::url($path))->getContent();

    if (preg_match('#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', $body, $matches) !== 1) {
        return null;
    }

    $decoded = json_decode($matches[1], true);

    return is_array($decoded) ? $decoded : null;
}

it('emits a FAQPage document that parses and holds every rendered question', function (): void {
    $tenant = OperatorPage::operator('seo-faq');

    OperatorPage::as($tenant, function (): void {
        Faq::factory()->at(0)->asking('Πού συναντιόμαστε;', 'Where do we meet?')->create();
        Faq::factory()->at(1)->asking('Τι γίνεται αν βρέξει;', 'What if it rains?')->create();
        Faq::factory()->at(2)->unpublished()->asking('Κρυφή;', 'Hidden?')->create();

        HomePageBlock::factory()->ofType(HomeBlockType::Faq)->create();
    });

    $schema = faqSchemaOn('/seo-faq?lang=en');

    expect($schema)->not->toBeNull()
        ->and($schema['@context'] ?? null)->toBe('https://schema.org')
        ->and($schema['@type'] ?? null)->toBe('FAQPage');

    /** @var list<array<string, mixed>> $questions */
    $questions = $schema['mainEntity'] ?? [];

    // Every question that is on the page, and nothing that is not. An
    // unpublished entry appearing in the structured data would put an answer
    // the operator withdrew into a search result.
    expect(array_column($questions, 'name'))->toBe(['Where do we meet?', 'What if it rains?']);

    foreach ($questions as $question) {
        $answer = $question['acceptedAnswer'] ?? [];

        expect($question['@type'] ?? null)->toBe('Question')
            ->and(is_array($answer) ? $answer['@type'] ?? null : null)->toBe('Answer');

        // An answer that is present but empty is a `Question` Google discards,
        // so the emptiness is asserted rather than the key.
        expect(is_array($answer) && is_string($answer['text'] ?? null) ? trim($answer['text']) : '')
            ->not->toBe('');
    }
})->group('fast');

it('describes the page in the language the page is written in', function (): void {
    $tenant = OperatorPage::operator('seo-locale');

    OperatorPage::as($tenant, function (): void {
        Faq::factory()->asking('Πού συναντιόμαστε;', 'Where do we meet?')->create();
        HomePageBlock::factory()->ofType(HomeBlockType::Faq)->create();
    });

    // A Greek page describing itself to a search engine in English is a page
    // that ranks for the wrong query.
    expect(faqSchemaOn('/seo-locale?lang=el')['mainEntity'][0]['name'] ?? null)
        ->toBe('Πού συναντιόμαστε;')
        ->and(faqSchemaOn('/seo-locale?lang=en')['mainEntity'][0]['name'] ?? null)
        ->toBe('Where do we meet?');
})->group('fast');

it('cannot be closed early by an operator who pastes a closing script tag', function (): void {
    $tenant = OperatorPage::operator('seo-escape');

    OperatorPage::as($tenant, function (): void {
        Faq::factory()
            ->asking(
                'Ερώτηση;',
                'A question?',
                'Απάντηση </script><script>alert(1)</script>',
                'Answer </script><script>alert(1)</script>',
            )
            ->create();

        HomePageBlock::factory()->ofType(HomeBlockType::Faq)->create();
    });

    $schema = faqSchemaOn('/seo-escape?lang=en');

    // The block still parses — which it would not if the operator's text had
    // closed the element — and the text comes back as the operator typed it,
    // because `<` means `<` to a JSON parser and nothing at all to an HTML
    // one.
    expect($schema)->not->toBeNull()
        ->and($schema['mainEntity'][0]['acceptedAnswer']['text'] ?? null)
        ->toBe('Answer </script><script>alert(1)</script>');
})->group('fast');

it('carries the nonce, without which the browser drops it unread', function (): void {
    $tenant = OperatorPage::operator('seo-nonce');

    OperatorPage::as($tenant, function (): void {
        Faq::factory()->asking('Ερώτηση;', 'A question?')->create();
        HomePageBlock::factory()->ofType(HomeBlockType::Faq)->create();
    });

    $page = HostedRequest::headers('seo-nonce');

    // HOS-8's policy has no `unsafe-inline`, and `script-src` applies to a
    // script element whatever its type. An un-nonced JSON-LD block is dropped
    // silently, which is the same outcome as never writing one — so the nonce
    // in the element must be the nonce in the header.
    expect($page['csp'])->toContain("'nonce-")
        ->and(preg_match('#<script type="application/ld\+json" nonce="([^"]+)"#', $page['body'], $matches))->toBe(1);

    expect($page['csp'])->toContain("'nonce-{$matches[1]}'");
})->group('fast');

it('writes no FAQPage block on a page with no FAQ section', function (): void {
    $tenant = OperatorPage::operator('seo-none');

    // The default page: no FAQ block, and therefore no structured data about
    // questions nobody asked. A `FAQPage` with an empty `mainEntity` is a
    // structured-data error rather than an empty section.
    expect(faqSchemaOn('/seo-none'))->toBeNull();
})->group('fast');
