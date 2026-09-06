<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Hosted\Support\BlockSettings;
use App\Enums\HomeBlockType;
use App\Models\HomePageBlock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HomePageBlock>
 *
 * The default is a `story` block, because it is the only type that exercises
 * both translatable columns and the prose escaping — a factory whose default
 * was `trips` would let a test about text pass without any text in it.
 *
 * Every state fills `settings` from {@see BlockSettings::defaults()} rather
 * than from a literal, so a setting added later reaches existing tests instead
 * of leaving them asserting against a shape that no longer exists.
 */
class HomePageBlockFactory extends Factory
{
    protected $model = HomePageBlock::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'type' => HomeBlockType::Story,
            'sort_order' => 0,
            'is_visible' => true,
            'heading' => [
                'el' => 'Η ιστορία μας',
                'en' => 'Our story',
            ],
            'body' => [
                'el' => "Ταξιδεύουμε στον Σαρωνικό από το 1998.\n\nΤο σκάφος το έφτιαξε ο παππούς μου στη Σύρο.",
                'en' => "We have sailed the Saronic gulf since 1998.\n\nMy grandfather built the boat on Syros.",
            ],
            'settings' => BlockSettings::defaults(HomeBlockType::Story),
        ];
    }

    public function ofType(HomeBlockType $type): self
    {
        return $this->state(fn (): array => [
            'type' => $type,
            'settings' => BlockSettings::defaults($type),
        ]);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function withSettings(array $settings): self
    {
        return $this->state(fn (array $attributes): array => [
            'settings' => [...(array) ($attributes['settings'] ?? []), ...$settings],
        ]);
    }

    public function hidden(): self
    {
        return $this->state(fn (): array => ['is_visible' => false]);
    }

    public function at(int $position): self
    {
        return $this->state(fn (): array => ['sort_order' => $position]);
    }

    /**
     * Prose in both locales, for a test that cares what the text is.
     */
    public function saying(string $greek, string $english): self
    {
        return $this->state(fn (): array => [
            'body' => ['el' => $greek, 'en' => $english],
        ]);
    }
}
