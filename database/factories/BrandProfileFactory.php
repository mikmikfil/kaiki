<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FontSource;
use App\Enums\WidgetTheme;
use App\Models\BrandProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BrandProfile>
 *
 * The definition is the **platform default palette**, not random colours.
 *
 * A factory that invented a hex colour per row would make every contrast
 * assertion in the suite depend on a dice roll: `ContrastChecker` answers a
 * question about two specific colours, and a test that seeds unknown ones can
 * only assert that it returned a number. States below cover the cases a test
 * actually wants — a palette that fails AA, and a fully filled-in brand.
 */
class BrandProfileFactory extends Factory
{
    protected $model = BrandProfile::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return BrandProfile::platformDefaults();
    }

    /**
     * A brand with everything filled in — the shape the API and the hosted page
     * see for an operator who finished the form.
     */
    public function customised(): self
    {
        return $this->state(fn (): array => [
            'color_primary' => '#8B1E3F',
            'color_secondary' => '#123C69',
            'color_accent' => '#EDC7B7',
            'color_background' => '#FFFFFF',
            'color_text' => '#1A1A1A',
            'font_family' => 'Lora',
            'font_source' => FontSource::Google,
            'button_radius_px' => 16,
            'widget_theme' => WidgetTheme::Light,
            'email_footer_text' => [
                'el' => 'Καλό ταξίδι από την ομάδα μας.',
                'en' => 'Fair winds from all of us.',
            ],
            'social_links' => [
                'website' => 'https://example.gr',
                'instagram' => 'https://instagram.com/example',
                'whatsapp' => '+306912345678',
            ],
        ]);
    }

    /**
     * Grey on white: 2.85:1, under both AA thresholds.
     *
     * The numbers are pinned in the test rather than here, so a change to this
     * state that accidentally made it pass would fail loudly instead of turning
     * a contrast test into one that asserts nothing.
     */
    public function lowContrast(): self
    {
        return $this->state(fn (): array => [
            'color_text' => '#9A9A9A',
            'color_background' => '#FFFFFF',
            'color_primary' => '#B8B8B8',
        ]);
    }
}
