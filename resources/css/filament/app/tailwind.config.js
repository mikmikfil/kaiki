import preset from '../../../../vendor/filament/filament/tailwind.config.preset'

/*
 * What Tailwind scans for the panel theme. Every file that can put a class on
 * a panel page: our Filament classes and views (both panels), the panel's
 * providers (render hooks), and Filament's own views.
 */
export default {
    presets: [preset],
    content: [
        './app/Filament/**/*.php',
        './app/Providers/Filament/**/*.php',
        './resources/views/filament/**/*.blade.php',
        './vendor/filament/**/*.blade.php',
    ],
}
