<?php

declare(strict_types=1);

use App\Models\PlatformAnnouncement;

// Run through `php artisan tinker --execute="require '…/capture-announce.php';"`
// by capture.mjs, for the one screenshot of the operator's announcement banner.
// A file rather than inline code, because the message is Greek and a Windows
// command line mangles anything outside its code page. Prints the new id so the
// capture can delete it the moment the shot is taken — nobody should be left
// looking at a maintenance notice that is not real.

$announcement = new PlatformAnnouncement;
$announcement->setTranslations('message', [
    'el' => 'Συντήρηση της πλατφόρμας την Κυριακή 14 Σεπτεμβρίου, 02:00–03:00. Οι σελίδες κράτησης θα λειτουργούν κανονικά.',
    'en' => 'Platform maintenance on Sunday 14 September, 02:00–03:00. Booking pages keep working.',
]);
$announcement->severity = 'info';
$announcement->is_active = true;
$announcement->save();

echo 'ANNOUNCEMENT_ID=' . $announcement->getKey();
