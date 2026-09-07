<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * `php artisan widget:publish` — the built bundle into the versioned path
 * ADR-0011 fixes (WGT-4).
 *
 * ## A path with a version in it, and an alias that never changes
 *
 * ADR-0011 Option A: `/widget/v1.4.2/kaiki-widget.js` is immutable and cached
 * for a year; `/widget/kaiki-widget.js` is what an **operator embeds** and is
 * repointed on release with a short cache life.
 *
 * The reason is the embed snippet. It lives in a thousand WordPress themes, in
 * page builders, in copy-pasted support emails — an operator does not have a
 * deployment, and a snippet that had to change on release would mean a release
 * nobody receives. So the version moves and the snippet does not.
 *
 * ## Copying rather than symlinking
 *
 * A symlink is one Windows checkout and one `rsync` flag away from being a
 * broken file, and this runs in a release pipeline. Two copies of a 60 KB file
 * is not a cost worth being clever about.
 *
 * ## The manifest is for humans and for the release gates
 *
 * `manifest.json` records which version the alias currently points at, so
 * `GET /widget/manifest.json` answers "what are operators actually running" —
 * a question that otherwise needs a deploy log.
 */
class PublishWidgetCommand extends Command
{
    /**
     * `--as` rather than `--version`: Laravel reserves `--version` on every
     * command, and registering it is a fatal error rather than a warning.
     */
    protected $signature = 'widget:publish
        {--as= : Override the version read from packages/widget/package.json}
        {--no-alias : Publish the versioned path without repointing the alias}';

    protected $description = 'Copy the built widget into public/widget as a versioned file and repoint the alias (WGT-4, ADR-0011).';

    public function handle(): int
    {
        $source = base_path('packages/widget/dist/kaiki-widget.js');

        if (! File::exists($source)) {
            $this->error('No bundle at packages/widget/dist/kaiki-widget.js — run `npm run widget:build` first.');

            return self::FAILURE;
        }

        $version = $this->version();
        $target = public_path("widget/{$version}");

        File::ensureDirectoryExists($target);
        File::copy($source, "{$target}/kaiki-widget.js");

        $this->info("Published {$version} — " . $this->size($source));

        if ($this->option('no-alias')) {
            // The release workflow publishes the version first and repoints the
            // alias only after its three gates have passed. This flag is how
            // those two steps stay separable.
            $this->line('Alias left where it was.');

            return self::SUCCESS;
        }

        File::ensureDirectoryExists(public_path('widget'));
        File::copy($source, public_path('widget/kaiki-widget.js'));

        File::put(
            public_path('widget/manifest.json'),
            json_encode([
                'version' => $version,
                'published_at' => now()->toIso8601String(),
                'bytes' => File::size($source),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );

        $this->info("Alias repointed to {$version}.");

        return self::SUCCESS;
    }

    private function version(): string
    {
        $override = $this->option('as');

        if (is_string($override) && $override !== '') {
            return $this->normalise($override);
        }

        /** @var array{version?: string} $package */
        $package = json_decode(
            (string) File::get(base_path('packages/widget/package.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return $this->normalise($package['version'] ?? '0.0.0');
    }

    /** `v1.4.2`, and nothing that could escape the directory it names. */
    private function normalise(string $version): string
    {
        $clean = preg_replace('/[^0-9A-Za-z.\-]/', '', ltrim($version, 'v')) ?? '';

        return 'v' . ($clean === '' ? '0.0.0' : $clean);
    }

    private function size(string $path): string
    {
        return number_format(File::size($path) / 1024, 1) . ' KB raw';
    }
}
