<?php

declare(strict_types=1);

/**
 * Build `email-buttons.html`: every link in every guest email, and where it goes.
 *
 * Run it with `php docs/mockups/email-buttons.php`, after
 * {@see refresh-emails.php} has put today's renders in `all-emails.html` —
 * this reads the links out of those renders rather than out of the Blade
 * templates, so what the page lists is what a guest would actually be able to
 * tap, with the real tokens of the demo booking in it.
 *
 * That is also why the links on the page are **live**: they point at the local
 * server the gallery was rendered against, so the page is a way to walk the
 * whole product from the guest's side rather than a table about it.
 */

require __DIR__ . '/../../vendor/autoload.php';

$gallery = (string) file_get_contents(__DIR__ . '/all-emails.html');

if (! preg_match('/const DATA = (\{.*?\});\n/s', $gallery, $match)) {
    fwrite(STDERR, "The gallery no longer carries a `const DATA` blob.\n");
    exit(1);
}

/** @var array{groups: list<array{name: string, items: list<array<string, mixed>>}>} $data */
$data = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);

/**
 * What each destination is, in the guest's words and in the router's.
 *
 * Keyed by the kind this script derives from the URL. `route` is the Laravel
 * route name, which is the one string that survives a change of host, port or
 * domain — the URL on the page is today's, the route is the answer.
 */
const DESTINATIONS = [
    'booking' => [
        'name' => 'Η σελίδα της κράτησης',
        'route' => 'guest.booking · /b/{token}',
        'what' => 'Ό,τι μπορεί να κάνει ο επισκέπτης μόνος του: στοιχεία του ταξιδιού, πληρωμή υπολοίπου, αλλαγή τηλεφώνου ή email, ακύρωση με ό,τι του επιστρέφεται, επιλογή μετά από ακύρωση λόγω καιρού, και το εισιτήριο. Χωρίς λογαριασμό και χωρίς κωδικό — ο σύνδεσμος είναι το διαπιστευτήριο.',
    ],
    'calendar_ics' => [
        'name' => 'Το ταξίδι ως αρχείο ημερολογίου',
        'route' => 'guest.booking.calendar · /b/{token}/calendar.ics',
        'what' => 'Κατεβάζει ένα .ics για Apple, Outlook και κινητό, με ώρα προσέλευσης (όχι αναχώρησης), σημείο συνάντησης και υπενθύμιση την προηγούμενη μέρα. Σερβίρεται από τη διαδρομή, όχι από το συνημμένο, ώστε να δίνει πάντα τη σημερινή ώρα.',
    ],
    'calendar_google' => [
        'name' => 'Google Ημερολόγιο',
        'route' => 'calendar.google.com (εκτός Kaiki)',
        'what' => 'Ανοίγει έτοιμη καταχώρηση στο ημερολόγιο του επισκέπτη. Δεν μεταφέρει τον κωδικό της κράτησης — εκείνος θα αποθηκευόταν στη Google και θα ταξίδευε μαζί με το γεγονός αν το μοιραζόταν κάπου.',
    ],
    'ticket' => [
        'name' => 'Το εισιτήριο σε PDF',
        'route' => 'guest.ticket · /b/{token}/ticket',
        'what' => 'Το εισιτήριο με το QR, όπως το σαρώνει το πλήρωμα. Το αρχείο ζει σε ιδιωτικό δίσκο και αυτή η διαδρομή είναι ο μόνος δρόμος προς αυτό.',
    ],
    'details' => [
        'name' => 'Στοιχεία επιβατών',
        'route' => 'guest.details · /g/{token}',
        'what' => 'Η φόρμα με ονοματεπώνυμο, ημερομηνία γέννησης, εθνικότητα και έγγραφο για κάθε επιβάτη — και η ίδια σελίδα δέχεται το ναυλοσύμφωνο, όταν η κράτηση είναι ναύλωση ολόκληρου σκάφους.',
    ],
    'voucher' => [
        'name' => 'Το κουπόνι',
        'route' => 'guest.voucher · /v/{code}',
        'what' => 'Το υπόλοιπο του κουπονιού, πότε λήγει και πώς εξαργυρώνεται στην επόμενη κράτηση.',
    ],
    'quote' => [
        'name' => 'Η προσφορά',
        'route' => 'guest.quote · /q/{token}',
        'what' => 'Η προσφορά για εκδρομή κατόπιν ζήτησης, με αποδοχή, απόρριψη ή αίτημα για νέα.',
    ],
    'map' => [
        'name' => 'Ο χάρτης του σημείου συνάντησης',
        'route' => 'google.com/maps (εκτός Kaiki)',
        'what' => 'Ανοίγει τον χάρτη στις συντεταγμένες του λιμανιού — όχι στη διεύθυνσή του, που σε μια προβλήτα είναι συχνά η διεύθυνση του διπλανού κτιρίου.',
    ],
    'phone' => [
        'name' => 'Το τηλέφωνο του διοργανωτή',
        'route' => 'tel: (το κινητό καλεί)',
        'what' => 'Από τα στοιχεία επικοινωνίας του διοργανωτή. Σε υπολογιστή δεν κάνει τίποτα ορατό.',
    ],
    'email' => [
        'name' => 'Το email του διοργανωτή',
        'route' => 'mailto: (ανοίγει το πρόγραμμα email)',
        'what' => 'Από τα στοιχεία επικοινωνίας του διοργανωτή.',
    ],
    'review' => [
        'name' => 'Η σελίδα κριτικών στη Google',
        'route' => 'ο σύνδεσμος του διοργανωτή (εκτός Kaiki)',
        'what' => 'Ο σύνδεσμος κριτικών που έχει καταχωρήσει ο διοργανωτής στις ρυθμίσεις του. Χωρίς αυτόν, το email δεν φεύγει καθόλου.',
    ],
    'password' => [
        'name' => 'Ορισμός κωδικού στον πίνακα',
        'route' => '/app/password-reset/reset',
        'what' => 'Η μόνη διεύθυνση αυτής της λίστας που δεν πάει σε επισκέπτη αλλά σε άνθρωπο της ομάδας. Ο σύνδεσμος λήγει.',
    ],
];

/** Which destination a URL belongs to. */
function destinationOf(string $url): string
{
    return match (true) {
        str_contains($url, '/calendar.ics') => 'calendar_ics',
        str_contains($url, 'calendar.google.com') => 'calendar_google',
        str_contains($url, '/ticket') => 'ticket',
        (bool) preg_match('#/b/[^/?"]+$#', $url) => 'booking',
        (bool) preg_match('#/g/[^/?"]+#', $url) => 'details',
        (bool) preg_match('#/v/[^/?"]+#', $url) => 'voucher',
        (bool) preg_match('#/q/[^/?"]+#', $url) => 'quote',
        str_contains($url, 'google.com/maps') => 'map',
        str_starts_with($url, 'tel:') => 'phone',
        str_starts_with($url, 'mailto:') => 'email',
        str_contains($url, 'password-reset') => 'password',
        default => 'review',
    };
}

$emails = [];
$counts = [];

foreach ($data['groups'] as $group) {
    foreach ($group['items'] as $item) {
        $html = (string) $item['html'];
        $links = [];

        if (preg_match_all('/<a\s([^>]*)href="([^"]+)"([^>]*)>(.*?)<\/a>/is', $html, $all, PREG_SET_ORDER)) {
            foreach ($all as $a) {
                $attributes = $a[1] . $a[3];
                $url = html_entity_decode($a[2], ENT_QUOTES);
                $label = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($a[4]), ENT_QUOTES)));
                $kind = destinationOf($url);

                $links[] = [
                    'label' => $label,
                    'url' => $url,
                    'kind' => $kind,
                    // The one big coloured button of the message, as opposed to
                    // a link inside a paragraph: the template draws it as an
                    // inline-block with its own background.
                    'primary' => str_contains($attributes, 'display:inline-block'),
                ];

                $counts[$kind] = ($counts[$kind] ?? 0) + 1;
            }
        }

        $emails[] = [
            'group' => $group['name'],
            'key' => $item['key'],
            'name' => $item['name'],
            'when' => $item['when'],
            'links' => $links,
        ];
    }
}

$totalLinks = array_sum($counts);
$host = null;

foreach ($emails as $email) {
    foreach ($email['links'] as $link) {
        if (preg_match('#^(https?://[^/]+)/[bgvq]/#', $link['url'], $m)) {
            $host = $m[1];
            break 2;
        }
    }
}

$e = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

/** An example URL per destination, so the summary table is clickable too. */
$examples = [];

foreach ($emails as $email) {
    foreach ($email['links'] as $link) {
        $examples[$link['kind']] ??= $link['url'];
    }
}

ob_start();
?>
<title>Πού πάει κάθε κουμπί</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Commissioner:wght@500;600;700&family=Noto+Sans:wght@400;500;600&family=Noto+Sans+Mono:wght@400;500&display=swap">
<style>
  :root {
    --ground: #F2F5F9;
    --surface: #FFFFFF;
    --raise: #FBFCFE;
    --ink: #13213A;
    --ink-soft: #52627A;
    --ink-faint: #7F8DA3;
    --rule: #DCE3EE;
    --rule-soft: #EAEFF6;
    --accent: #0F2E57;
    --accent-soft: #E3EBF6;
    --live: #0E7C5A;
    --live-soft: #DCF3E4;
    --away: #9A5B0B;
    --away-soft: #FDF1DC;
    color-scheme: light;
  }

  @media (prefers-color-scheme: dark) {
    :root:not([data-theme="light"]) {
      --ground: #0C1522; --surface: #132033; --raise: #16243A; --ink: #E6ECF5;
      --ink-soft: #A9B6C9; --ink-faint: #7C8AA0; --rule: #25354C; --rule-soft: #1C2B40;
      --accent: #9DBBE6; --accent-soft: #1C2E47; --live: #7FD69E; --live-soft: #173323;
      --away: #F0B45A; --away-soft: #3A2A12; color-scheme: dark;
    }
  }

  :root[data-theme="dark"] {
    --ground: #0C1522; --surface: #132033; --raise: #16243A; --ink: #E6ECF5;
    --ink-soft: #A9B6C9; --ink-faint: #7C8AA0; --rule: #25354C; --rule-soft: #1C2B40;
    --accent: #9DBBE6; --accent-soft: #1C2E47; --live: #7FD69E; --live-soft: #173323;
    --away: #F0B45A; --away-soft: #3A2A12; color-scheme: dark;
  }

  * { box-sizing: border-box; }

  body {
    margin: 0;
    padding-inline: 16px;
    background: var(--ground);
    color: var(--ink);
    font-family: "Noto Sans", system-ui, sans-serif;
    font-size: 15px;
    line-height: 1.55;
  }

  .shell { max-width: 62rem; margin: 0 auto; padding-block: 32px 72px; display: grid; gap: 28px; }

  header.top { display: grid; gap: 10px; border-bottom: 2px solid var(--ink); padding-bottom: 18px; }

  .eyebrow {
    font-family: "Noto Sans Mono", ui-monospace, monospace;
    font-size: .72rem; letter-spacing: .12em; text-transform: uppercase;
    color: var(--ink-faint); margin: 0;
  }

  h1 {
    font-family: Commissioner, "Noto Sans", sans-serif;
    font-weight: 700; font-size: clamp(1.7rem, 4vw, 2.4rem); letter-spacing: -.015em;
    margin: 0; text-wrap: balance;
  }

  .lede { margin: 0; color: var(--ink-soft); max-width: 62ch; }

  .facts { display: flex; flex-wrap: wrap; gap: 8px; }

  .fact {
    background: var(--surface); border: 1px solid var(--rule);
    padding: 6px 12px; font-size: .88rem; color: var(--ink-soft);
  }

  .fact b {
    font-family: Commissioner, sans-serif; color: var(--ink);
    font-size: 1.02rem; margin-right: 5px; font-variant-numeric: tabular-nums;
  }

  .server {
    border: 1px solid var(--away); border-left-width: 4px;
    background: var(--away-soft); color: var(--ink);
    padding: 14px 16px; display: grid; gap: 6px; font-size: .92rem;
  }

  .server strong { font-weight: 600; }
  .server code { font-family: "Noto Sans Mono", ui-monospace, monospace; font-size: .85em; }

  h2 {
    font-family: Commissioner, sans-serif; font-weight: 600;
    font-size: 1.22rem; letter-spacing: -.01em; margin: 0;
  }

  .section-note { margin: 6px 0 0; color: var(--ink-soft); font-size: .93rem; max-width: 62ch; }

  .dests { display: grid; gap: 1px; background: var(--rule); border: 1px solid var(--rule); }

  .dest {
    background: var(--surface); padding: 14px 16px;
    display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 4px 16px; align-items: start;
  }

  .dest h3 { font-family: Commissioner, sans-serif; font-size: 1rem; font-weight: 600; margin: 0; }
  .dest .route {
    grid-column: 1 / -1;
    font-family: "Noto Sans Mono", ui-monospace, monospace;
    font-size: .78rem; color: var(--accent); word-break: break-word;
  }
  .dest p { grid-column: 1 / -1; margin: 2px 0 0; color: var(--ink-soft); font-size: .9rem; }

  .used {
    font-family: "Noto Sans Mono", ui-monospace, monospace;
    font-size: .75rem; color: var(--ink-faint); white-space: nowrap; padding-top: 3px;
  }

  .mail {
    background: var(--surface); border: 1px solid var(--rule);
    display: grid; scroll-margin-top: 16px;
  }

  .mail-head { padding: 14px 16px 12px; display: grid; gap: 4px; border-bottom: 1px solid var(--rule-soft); }
  .mail-head h3 { font-family: Commissioner, sans-serif; font-size: 1.05rem; font-weight: 600; margin: 0; }
  .mail-head .when { margin: 0; color: var(--ink-soft); font-size: .88rem; }
  .mail-head .group {
    font-family: "Noto Sans Mono", ui-monospace, monospace;
    font-size: .7rem; letter-spacing: .08em; text-transform: uppercase; color: var(--ink-faint);
  }

  .links { display: grid; }

  .link {
    display: grid; grid-template-columns: minmax(0, 1fr) auto;
    gap: 4px 14px; align-items: center;
    padding: 12px 16px; border-top: 1px solid var(--rule-soft);
  }

  .link:first-child { border-top: 0; }

  .label { font-weight: 600; display: flex; flex-wrap: wrap; align-items: baseline; gap: 8px; }

  .chip {
    font-family: "Noto Sans Mono", ui-monospace, monospace;
    font-size: .66rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase;
    padding: 2px 7px; background: var(--accent-soft); color: var(--accent); white-space: nowrap;
  }

  .chip.away { background: var(--away-soft); color: var(--away); }

  .goes { grid-column: 1 / -1; margin: 0; color: var(--ink-soft); font-size: .89rem; }
  .goes b { color: var(--ink); font-weight: 600; }

  .url {
    grid-column: 1 / -1;
    font-family: "Noto Sans Mono", ui-monospace, monospace;
    font-size: .76rem; color: var(--ink-faint); word-break: break-all;
  }

  .open {
    justify-self: end; align-self: start;
    font-family: "Noto Sans Mono", ui-monospace, monospace;
    font-size: .78rem; font-weight: 500; letter-spacing: .04em;
    text-decoration: none; white-space: nowrap;
    padding: 7px 12px; border: 1px solid var(--live); color: var(--live); background: var(--live-soft);
  }

  .open:hover, .open:focus-visible { background: var(--live); color: var(--surface); outline: none; }
  .open:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }

  .missing { display: grid; gap: 1px; background: var(--rule); border: 1px solid var(--rule); }
  .missing .dest { background: var(--raise); }

  a { color: var(--accent); }

  @media (max-width: 34rem) {
    .link, .dest { grid-template-columns: minmax(0, 1fr); }
    .open { justify-self: start; }
    .used { padding-top: 0; }
  }
</style>

<div class="shell">
  <header class="top">
    <p class="eyebrow">Kaiki · τα email του επισκέπτη</p>
    <h1>Πού πάει κάθε κουμπί</h1>
    <p class="lede">
      Κάθε σύνδεσμος που υπάρχει σήμερα μέσα στα <?= count($emails) ?> email, βγαλμένος από τα ίδια τα
      αποδομένα μηνύματα και όχι από τον κώδικα — με τους πραγματικούς κωδικούς της κράτησης
      επίδειξης. <strong>Τα κουμπιά είναι ζωντανά:</strong> πατήστε «Άνοιγμα» και θα δείτε ακριβώς ό,τι
      βλέπει ο επισκέπτης.
    </p>
    <div class="facts">
      <span class="fact"><b><?= count($emails) ?></b>email</span>
      <span class="fact"><b><?= $totalLinks ?></b>σύνδεσμοι</span>
      <span class="fact"><b><?= count($counts) ?></b>διαφορετικοί προορισμοί</span>
    </div>
  </header>

  <section class="server">
    <p style="margin:0"><strong>Οι σύνδεσμοι δείχνουν στον τοπικό server</strong>
      <code><?= $e($host) ?></code> — δουλεύουν όσο τρέχει το Kaiki σε αυτό το μηχάνημα. Στην
      παραγωγή η διεύθυνση αλλάζει· η διαδρομή δίπλα σε κάθε προορισμό είναι αυτή που μένει ίδια.</p>
    <p style="margin:0">Ο σύνδεσμος <em>είναι</em> το διαπιστευτήριο: όποιος τον έχει βλέπει την κράτηση.
      Γι&rsquo; αυτό κανένα από αυτά τα κουμπιά δεν ζητά κωδικό, και γι&rsquo; αυτό ο κωδικός δεν
      περνά ποτέ στη Google.</p>
  </section>

  <section>
    <h2>Οι προορισμοί</h2>
    <p class="section-note">Έντεκα σελίδες και αρχεία, τίποτε άλλο. Κάθε email δείχνει σε κάποια από αυτά.</p>
    <div class="dests" style="margin-top:14px">
      <?php foreach (DESTINATIONS as $kind => $destination): ?>
        <?php if (! isset($counts[$kind])) { continue; } ?>
        <div class="dest">
          <h3><?= $e($destination['name']) ?></h3>
          <span class="used"><?= (int) $counts[$kind] ?>× σε email</span>
          <span class="route"><?= $e($destination['route']) ?></span>
          <p><?= $e($destination['what']) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section>
    <h2>Email προς email</h2>
    <p class="section-note">Με τη σειρά της έκθεσης των email. Το «κύριο κουμπί» είναι το μεγάλο μπλε
      κουμπί του μηνύματος· τα υπόλοιπα είναι σύνδεσμοι μέσα στο κείμενο.</p>
  </section>

  <?php foreach ($emails as $email): ?>
    <article class="mail" id="m-<?= $e($email['key']) ?>">
      <div class="mail-head">
        <span class="group"><?= $e($email['group']) ?></span>
        <h3><?= $e($email['name']) ?></h3>
        <p class="when"><?= $e($email['when']) ?></p>
      </div>
      <div class="links">
        <?php if ($email['links'] === []): ?>
          <div class="link"><p class="goes" style="grid-column:1/-1">Κανένα κουμπί — το μήνυμα λέει μόνο τι έγινε.</p></div>
        <?php endif; ?>
        <?php foreach ($email['links'] as $link): ?>
          <?php $destination = DESTINATIONS[$link['kind']]; ?>
          <div class="link">
            <span class="label">
              <?= $e($link['label']) ?>
              <span class="chip<?= $link['primary'] ? '' : ' away' ?>"><?= $link['primary'] ? 'κύριο κουμπί' : 'σύνδεσμος' ?></span>
            </span>
            <a class="open" href="<?= $e($link['url']) ?>" target="_blank" rel="noreferrer noopener">Άνοιγμα ↗</a>
            <p class="goes">Πάει στο <b><?= $e($destination['name']) ?></b> — <?= $e($destination['route']) ?></p>
            <span class="url"><?= $e($link['url']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </article>
  <?php endforeach; ?>

  <section>
    <h2>Δύο κουμπιά που δεν φαίνονται εδώ</h2>
    <p class="section-note">Υπάρχουν στο πρότυπο και εμφανίζονται μόνο όταν ισχύει η προϋπόθεσή τους.
      Η κράτηση επίδειξης δεν τα ενεργοποιεί, γι&rsquo; αυτό λείπουν από τη λίστα πιο πάνω.</p>
    <div class="missing" style="margin-top:14px">
      <div class="dest">
        <h3>«Άνοιγμα εισιτηρίου»</h3>
        <span class="used">όταν υπάρχει QR</span>
        <span class="route">guest.ticket · /b/{token}/ticket</span>
        <p>Μπαίνει στην κάρτα του εισιτηρίου μόνο όταν η πλατφόρμα έχει ανοίξει την επιβίβαση με QR για
          τον διοργανωτή (/admin → Επεξεργασία διοργανωτή). Κλειστό, το email δείχνει τα στοιχεία της
          κράτησης χωρίς κουμπί.</p>
      </div>
      <div class="dest">
        <h3>«Δείτε όλη την πολιτική»</h3>
        <span class="used">όταν υπάρχει πολιτική</span>
        <span class="route">guest.booking · /b/{token}</span>
        <p>Κάτω από τη μονόγραμμη περίληψη της ακύρωσης, όταν η κράτηση έχει παγωμένη πολιτική. Οδηγεί
          στη σελίδα της κράτησης, όπου είναι γραμμένα όλα τα κλιμάκια.</p>
      </div>
    </div>
  </section>
</div>
<?php
$page = (string) ob_get_clean();

file_put_contents(__DIR__ . '/email-buttons.html', $page);

echo count($emails) . " emails, {$totalLinks} links, " . count($counts) . " destinations → docs/mockups/email-buttons.html\n";
