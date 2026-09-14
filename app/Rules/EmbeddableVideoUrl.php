<?php

declare(strict_types=1);

namespace App\Rules;

use App\Domain\Hosted\Support\VideoEmbed;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A link the hero can actually frame — YouTube or Vimeo, and nothing else.
 *
 * ## Why a rule rather than letting the page ignore it
 *
 * {@see VideoEmbed::parse()} already returns null for a link it cannot use, and
 * the hero already falls back to its photograph. That is the right behaviour on
 * the public page and the wrong one in the editor: an operator who pastes a
 * Facebook video, saves, and sees their photograph has been told nothing. The
 * message names the two providers, because "invalid" would send them to check
 * their typing on a link that is perfectly well typed.
 *
 * The two lists cannot drift: what the rule accepts is what the page can embed,
 * because both ask the same parser.
 */
final class EmbeddableVideoUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // An empty field is not a bad link. `nullable` on the field is what
        // says whether it may be empty; this rule only judges what is in it.
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return;
        }

        if (! is_string($value) || VideoEmbed::parse($value) === null) {
            $fail('home_page.validation.video_url')->translate();
        }
    }
}
