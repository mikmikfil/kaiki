<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * `#RRGGBB`, and nothing else (`docs/data-model.md` §2.2).
 *
 * The columns are `char(7)`, so anything longer is truncated by the database
 * rather than refused, and the truncation is silent: `#0F62FE99` becomes
 * `#0F62FE` on MySQL and a strict-mode error in some configurations. A rule
 * here means the operator is told, in their own language, instead of finding
 * out from the colour.
 *
 * ## Why not `regex:`
 *
 * Laravel's `regex` rule works, and its message is
 * *"The color primary field format is invalid"* — which names a column, and in
 * a form with five colour fields does not say which spelling is expected. This
 * exists so the message can show the shape (I18N-1, CNV-11).
 *
 * ## Why the short form is refused
 *
 * `#FFF` is valid CSS and every designer types it. It is still refused, because
 * the *column* is seven characters and the widget concatenates these into CSS
 * custom properties without parsing them — accepting a four-character value
 * would mean a stored colour that is fine in a browser and wrong in an email
 * template that pads it. The form's colour picker only ever produces the long
 * form, so this fires for an import or a paste.
 */
final class HexColor implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^#[0-9A-Fa-f]{6}$/', $value) !== 1) {
            $fail('branding.validation.hex_color')->translate();
        }
    }
}
