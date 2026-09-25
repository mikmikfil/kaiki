<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Models\HomePageBlock;

/**
 * «Σχετικά με εμάς» on `/app` (Mike, 2026-09-24).
 *
 * The home-page editor pointed at the other page: the same sections, the same
 * save, the same rules. What differs is only which rows it reads and writes,
 * and that it opens on a starting layout rather than on the live page — there
 * is no live about page until the first save ({@see HomePage::aboutStarter()}).
 *
 * Reached from «Ρυθμίσεις», beside «Αρχική σελίδα».
 */
class AboutPage extends HomePage
{
    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 89;

    protected static ?string $slug = 'about-page';

    protected static string $pageName = HomePageBlock::PAGE_ABOUT;

    public static function getNavigationLabel(): string
    {
        return __('home_page.about.nav');
    }

    public function getTitle(): string
    {
        return __('home_page.about.title');
    }

    public function getSubheading(): ?string
    {
        return __('home_page.about.subtitle');
    }
}
