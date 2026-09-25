/*
 * Kaiki — customer deck with motion (2026-09-23).
 *
 *   node docs/deck/motion/build.cjs      (needs pptxgenjs, sharp, react, react-dom, react-icons)
 *   python docs/deck/motion/animate.py  (adds transitions, build-ins and the drifting waves)
 *
 * The screenshots come from docs/manual/img, re-shot from the live app on the
 * 23rd. The waves are the same drawing as public/images/waves.svg — five lines
 * of linked arcs — at deck scale. Every shape that should build in is named
 * `anim-<order>-…`; every wave layer `wave-<dir>-…`; animate.py reads the names.
 */
const path = require('path');
const fs = require('fs');
const pptxgen = require('pptxgenjs');
const sharp = require('sharp');
const React = require('react');
const { renderToStaticMarkup } = require('react-dom/server');
const Lu = require('react-icons/lu');

const ROOT = path.resolve(__dirname, '..', '..', '..');
const IMG = path.join(ROOT, 'docs', 'manual', 'img');
const OUT_DIR = path.join(__dirname, 'build');
const OUT = process.env.DECK_OUT || path.join(OUT_DIR, 'Kaiki-parousiasi.pptx');
fs.mkdirSync(OUT_DIR, { recursive: true });

const W = 13.333;
const H = 7.5;
const C = {
  navy: '0F2E57',
  deep: '0A2240',
  blue: '1C4378',
  mid: '23497D',
  sky: '3D7BD9',
  pale: 'CFE0F7',
  mist: 'EDF3FA',
  paper: 'F7FAFD',
  white: 'FFFFFF',
  ink: '101828',
  soft: '47526B',
  faint: '7B8BA1',
  sun: 'EE8A3C',
};
const HEAD = 'Calibri';
const BODY = 'Calibri';

/* ---------- artwork ---------- */

// The waves.svg drawing, tiled: each line is a run of quadratic arcs.
function waveSvg({ width, height, stroke, strength }) {
  const tile = 1300;
  const lines = [
    { y: 0.88, amp: 0.1, op: 0.9, shift: 0 },
    { y: 0.7, amp: 0.09, op: 0.65, shift: 0 },
    { y: 0.52, amp: 0.08, op: 0.45, shift: -tile / 8 },
    { y: 0.34, amp: 0.07, op: 0.28, shift: 0 },
    { y: 0.17, amp: 0.06, op: 0.15, shift: -tile / 8 },
  ];
  const paths = lines.map(({ y, amp, op, shift }) => {
    const yy = y * height;
    const a = amp * height;
    let d = `M${shift} ${yy} Q${shift + tile / 8} ${yy - a} ${shift + tile / 4} ${yy}`;
    for (let x = shift + tile / 4; x < width + tile; x += tile / 4) d += ` T${x + tile / 4} ${yy}`;
    return `<path d="${d}" opacity="${(op * strength).toFixed(3)}"/>`;
  });
  return `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${width} ${height}"><g fill="none" stroke="#${stroke}" stroke-width="3.2" stroke-linecap="round">${paths.join('')}</g></svg>`;
}

async function png(svg) {
  const buf = await sharp(Buffer.from(svg)).png().toBuffer();
  return 'image/png;base64,' + buf.toString('base64');
}

async function icon(name, color, size = 256) {
  const svg = renderToStaticMarkup(React.createElement(Lu[name], { color: '#' + color, size, strokeWidth: 1.75 }));
  return png(svg);
}

async function shot(file, crop) {
  let img = sharp(path.join(IMG, file));
  if (crop) img = img.extract(crop);
  const buf = await img.jpeg({ quality: 86 }).toBuffer();
  return 'image/jpeg;base64,' + buf.toString('base64');
}

/* ---------- helpers ---------- */

let seq = 0;
const nm = (order, what) => `anim-${String(order).padStart(2, '0')}-${what}-${++seq}`;

function waves(slide, art, { dark }) {
  // Two layers: the back one fainter and drifting the other way, as on the panel.
  slide.addImage({ data: art.back, x: -W / 2, y: H - 2.55, w: W * 2, h: 2.55, objectName: `wave-right-back-${++seq}`, transparency: dark ? 30 : 10 });
  slide.addImage({ data: art.front, x: 0, y: H - 2.0, w: W * 2, h: 2.0, objectName: `wave-left-front-${++seq}` });
}

function text(slide, str, opts) {
  slide.addText(str, { isTextBox: true, margin: 0, fontFace: BODY, color: C.ink, ...opts });
}

function eyebrow(slide, str, { x = 0.7, y = 0.62, dark = false, order = 1 } = {}) {
  text(slide, str, { x, y, w: 8, h: 0.35, fontSize: 13, bold: true, color: dark ? C.pale : C.sky, charSpacing: 1, objectName: nm(order, 'eyebrow') });
}

function title(slide, str, { x = 0.7, y = 0.98, w = 11.9, h = 0.95, dark = false, size = 36, order = 1 } = {}) {
  text(slide, str, { x, y, w, h, fontFace: HEAD, fontSize: size, bold: true, color: dark ? C.white : C.navy, valign: 'top', objectName: nm(order, 'title') });
}

function frame(slide, data, { x, y, w, h, order, phone = false }) {
  // A white rounded mount with a soft shadow; the screenshot sits inside it.
  const pad = phone ? 0.09 : 0.07;
  slide.addShape('roundRect', {
    x, y, w, h, rectRadius: phone ? 0.28 : 0.12,
    fill: { color: C.white }, line: { color: C.pale, width: 0.75 },
    shadow: { type: 'outer', color: '0A2240', opacity: 0.22, blur: 18, offset: 6, angle: 90 },
    objectName: nm(order, 'mount'),
  });
  slide.addImage({ data, x: x + pad, y: y + pad, w: w - 2 * pad, h: h - 2 * pad, objectName: nm(order, 'shot') });
}

function points(slide, items, { x, y, w, order, dark = false, gap = 0.78 }) {
  items.forEach(([head, body], i) => {
    const yy = y + i * gap;
    slide.addShape('ellipse', { x, y: yy + 0.07, w: 0.16, h: 0.16, fill: { color: C.sun }, line: { color: C.sun }, objectName: nm(order + i, 'dot') });
    text(slide, [
      { text: head, options: { bold: true, color: dark ? C.white : C.navy, breakLine: true } },
      { text: body, options: { color: dark ? C.pale : C.soft } },
    ], { x: x + 0.35, y: yy, w: w - 0.35, h: gap - 0.05, fontSize: 15, valign: 'top', paraSpaceAfter: 2, objectName: nm(order + i, 'point') });
  });
}

function card(slide, { x, y, w, h, ico, head, body, order, dark = false }) {
  slide.addShape('roundRect', {
    x, y, w, h, rectRadius: 0.14,
    fill: { color: dark ? C.blue : C.white }, line: { color: dark ? C.mid : C.pale, width: 0.75 },
    shadow: dark ? undefined : { type: 'outer', color: '0A2240', opacity: 0.1, blur: 12, offset: 3, angle: 90 },
    objectName: nm(order, 'card'),
  });
  slide.addShape('ellipse', { x: x + 0.3, y: y + 0.3, w: 0.62, h: 0.62, fill: { color: dark ? C.mid : C.mist }, line: { color: dark ? C.mid : C.mist }, objectName: nm(order, 'iconbg') });
  slide.addImage({ data: ico, x: x + 0.43, y: y + 0.43, w: 0.36, h: 0.36, objectName: nm(order, 'icon') });
  text(slide, head, { x: x + 0.3, y: y + 1.1, w: w - 0.6, h: 0.62, fontFace: HEAD, fontSize: 16.5, bold: true, color: dark ? C.white : C.navy, valign: 'top', objectName: nm(order, 'cardhead') });
  text(slide, body, { x: x + 0.3, y: y + 1.78, w: w - 0.6, h: h - 1.95, fontSize: 13.5, color: dark ? C.pale : C.soft, valign: 'top', objectName: nm(order, 'cardbody') });
}

function pageNo(slide, n, dark) {
  text(slide, String(n).padStart(2, '0'), { x: W - 1.2, y: 0.62, w: 0.5, h: 0.3, fontSize: 11, align: 'right', color: dark ? C.faint : C.faint });
}

/* ---------- deck ---------- */

async function main() {
  const art = {
    darkFront: await png(waveSvg({ width: 4000, height: 600, stroke: 'FFFFFF', strength: 0.42 })),
    darkBack: await png(waveSvg({ width: 4000, height: 760, stroke: '8FB2E6', strength: 0.35 })),
    lightFront: await png(waveSvg({ width: 4000, height: 600, stroke: '9DB8DD', strength: 0.75 })),
    lightBack: await png(waveSvg({ width: 4000, height: 760, stroke: 'C4D3E8', strength: 0.7 })),
  };
  const dark = { front: art.darkFront, back: art.darkBack };
  const light = { front: art.lightFront, back: art.lightBack };
  const ic = async (n, c) => icon(n, c);

  const pres = new pptxgen();
  pres.layout = 'LAYOUT_WIDE';
  pres.title = 'Kaiki — παρουσίαση';
  pres.company = 'Kaiki';

  const mk = (isDark) => {
    const s = pres.addSlide();
    s.background = { color: isDark ? C.navy : C.paper };
    waves(s, isDark ? dark : light, { dark: isDark });
    return s;
  };

  let n = 0;

  /* 1 — cover */
  {
    const s = mk(true); n++;
    text(s, 'Μηχανή κρατήσεων για ελληνικά σκάφη', { x: 0.9, y: 1.35, w: 9, h: 0.4, fontSize: 16, bold: true, color: C.pale, charSpacing: 1, objectName: nm(1, 'kicker') });
    text(s, 'Kaiki', { x: 0.85, y: 1.8, w: 9, h: 1.7, fontFace: HEAD, fontSize: 110, bold: true, color: C.white, objectName: nm(2, 'wordmark') });
    text(s, 'Οι κρατήσεις σας online, το πρόγραμμα σε μία οθόνη,\nτα χαρτιά στη σειρά.', { x: 0.9, y: 3.6, w: 9.5, h: 1.1, fontSize: 24, color: C.white, fontFace: 'Cambria', italic: true, objectName: nm(3, 'tagline') });
    ['Ελληνικά & Αγγλικά', 'Δουλεύει σε κινητό', 'Χωρίς εγκατάσταση'].forEach((t, i) => {
      s.addShape('roundRect', { x: 0.9 + i * 2.55, y: 4.95, w: 2.35, h: 0.5, rectRadius: 0.25, fill: { color: C.blue }, line: { color: C.mid }, objectName: nm(4 + i, 'chip') });
      text(s, t, { x: 0.9 + i * 2.55, y: 4.95, w: 2.35, h: 0.5, fontSize: 13, color: C.white, align: 'center', valign: 'middle', objectName: nm(4 + i, 'chiptext') });
    });
    s.addNotes('Kaiki: σύστημα κρατήσεων φτιαγμένο για σκάφη — όχι για ξενοδοχεία ή εστιατόρια.');
  }

  /* 2 — the problem */
  {
    const s = mk(false); n++;
    eyebrow(s, 'Πού πάει ο χρόνος σήμερα');
    title(s, 'Οι κρατήσεις σας ζουν σε τέσσερα μέρη ταυτόχρονα');
    const cards = [
      ['LuPhone', 'Τηλέφωνο & WhatsApp', 'Κάθε διαθεσιμότητα ρωτιέται και απαντιέται με το χέρι, συχνά εκτός ωραρίου.'],
      ['LuSheet', 'Ένα πρόχειρο αρχείο', 'Το πρόγραμμα ζει σε ένα Excel που ξέρει μόνο ένας, σε έναν υπολογιστή.'],
      ['LuMessagesSquare', 'Ό,τι θυμάται ο καθένας', 'Ποιος πλήρωσε, ποιος χρωστάει, ποιος ακύρωσε: μια αναζήτηση σε μηνύματα.'],
      ['LuAnchor', 'Και το λιμεναρχείο', 'Καταστάσεις επιβατών και παραστατικά, από την αρχή κάθε φορά.'],
    ];
    for (let i = 0; i < 4; i++) {
      const [ico, head, body] = cards[i];
      card(s, { x: 0.7 + i * 3.03, y: 2.25, w: 2.8, h: 3.1, ico: await ic(ico, C.navy), head, body, order: 2 + i });
    }
    pageNo(s, n);
  }

  /* 3 — what it is */
  {
    const s = mk(true); n++;
    eyebrow(s, 'Τι είναι το Kaiki', { dark: true });
    title(s, 'Ένα σύστημα, φτιαγμένο για σκάφη', { dark: true, size: 40 });
    const cards = [
      ['LuGlobe', 'Ο επισκέπτης κλείνει μόνος του', '24 ώρες το 24ωρο, από τη δική σας σελίδα ή από την ιστοσελίδα που ήδη έχετε.'],
      ['LuLayoutDashboard', 'Εσείς βλέπετε ένα πρόγραμμα', 'Σκάφη, αναχωρήσεις, επιβάτες, εισπράξεις — σε μία οθόνη, πάντα ενημερωμένα.'],
      ['LuFileCheck', 'Τα χαρτιά βγαίνουν μόνα τους', 'Αποδείξεις, τιμολόγια, καταστάσεις επιβατών, εισιτήρια με QR.'],
    ];
    for (let i = 0; i < 3; i++) {
      const [ico, head, body] = cards[i];
      card(s, { x: 0.7 + i * 4.05, y: 2.4, w: 3.8, h: 2.6, ico: await ic(ico, C.white), head, body, order: 2 + i, dark: true });
    }
    pageNo(s, n, true);
  }

  /* 4 — the site */
  {
    const s = mk(false); n++;
    eyebrow(s, 'Η ιστοσελίδα σας');
    title(s, 'Μια σελίδα που απαντά\nπριν σας ρωτήσουν', { w: 5.2, h: 1.6, size: 34 });
    points(s, [
      ['Έτοιμη από την πρώτη μέρα', 'Με τα χρώματα και το λογότυπό σας, σε Ελληνικά και Αγγλικά.'],
      ['Φωτογραφίες, χάρτης, ερωτήσεις', 'Και το κουτί κράτησης δίπλα στον τίτλο.'],
      ['Φτιαγμένη για τη Google', 'Τιμές και αναχωρήσεις στα αποτελέσματα αναζήτησης.'],
    ], { x: 0.7, y: 2.9, w: 4.9, order: 2, gap: 0.95 });
    frame(s, await shot('guest-trip.jpg'), { x: 6.1, y: 0.75, w: 6.55, h: 4.55, order: 2 });
    pageNo(s, n);
  }

  /* 5 — booking in three steps */
  {
    const s = mk(false); n++;
    eyebrow(s, 'Η κράτηση');
    title(s, 'Τρία βήματα, και ο επισκέπτης έχει εισιτήριο');
    const steps = [
      ['1', 'Ημερομηνία και άτομα', 'Βλέπει μόνο ό,τι όντως μπορεί να κλείσει, με την τιμή της παρέας του.'],
      ['2', 'Τα στοιχεία του', 'Σε μια καθαρή σελίδα ολοκλήρωσης. Η θέση κρατιέται όσο συμπληρώνει.'],
      ['3', 'Πληρωμή', 'Με κάρτα μέσω Viva. Τα χρήματα πάνε στον δικό σας λογαριασμό.'],
    ];
    steps.forEach(([k, head, body], i) => {
      const y = 2.3 + i * 1.28;
      s.addShape('ellipse', { x: 0.7, y, w: 0.72, h: 0.72, fill: { color: C.navy }, line: { color: C.navy }, objectName: nm(2 + i, 'num') });
      text(s, k, { x: 0.7, y, w: 0.72, h: 0.72, fontSize: 24, bold: true, color: C.white, align: 'center', valign: 'middle', fontFace: HEAD, objectName: nm(2 + i, 'numtext') });
      text(s, [
        { text: head, options: { bold: true, color: C.navy, fontSize: 18, breakLine: true } },
        { text: body, options: { color: C.soft, fontSize: 14 } },
      ], { x: 1.65, y: y - 0.02, w: 4.2, h: 1.1, valign: 'top', objectName: nm(2 + i, 'steptext') });
    });
    frame(s, await shot('guest-booking.jpg'), { x: 6.3, y: 1.95, w: 6.35, h: 4.37, order: 5 });
    text(s, 'Και μετά: εισιτήριο με QR, και «Προσθήκη στο ημερολόγιο».', { x: 6.3, y: 6.45, w: 6.35, h: 0.35, fontSize: 12.5, italic: true, color: C.soft, objectName: nm(6, 'caption') });
    pageNo(s, n);
  }

  /* 6 — the home screen */
  {
    const s = mk(false); n++;
    eyebrow(s, 'Ο πίνακάς σας');
    title(s, 'Το πρωί, μία οθόνη\nσας λέει τι τρέχει', { w: 4.6, h: 1.6, size: 34 });
    points(s, [
      ['Η μέρα ανά σκάφος', 'Ποιο φεύγει, πότε, με πόσους.'],
      ['Η επόμενη αναχώρηση', 'Με ένα κουμπί για σάρωση εισιτηρίων.'],
      ['Χρειάζεται προσοχή', 'Ό,τι θέλει απόφαση δική σας, με κουμπί που πάει εκεί.'],
    ], { x: 0.7, y: 2.9, w: 4.4, order: 2, gap: 0.95 });
    frame(s, await shot('dashboard.jpg'), { x: 5.45, y: 0.75, w: 7.2, h: 5.0, order: 2 });
    pageNo(s, n);
  }

  /* 7 — new trip in five steps */
  {
    const s = mk(false); n++;
    eyebrow(s, 'Νέα εκδρομή');
    title(s, 'Από την ιδέα ως τη δημοσίευση, σε πέντε βήματα');
    const steps = ['Βασικά', 'Πότε φεύγει', 'Τιμές', 'Όροι', 'Σελίδα'];
    steps.forEach((t, i) => {
      const x = 0.7 + i * 2.43;
      s.addShape('roundRect', { x, y: 2.1, w: 2.2, h: 0.62, rectRadius: 0.31, fill: { color: i === 4 ? C.navy : C.white }, line: { color: i === 4 ? C.navy : C.pale }, objectName: nm(2 + i, 'step') });
      text(s, `${i + 1}  ${t}`, { x, y: 2.1, w: 2.2, h: 0.62, fontSize: 15, bold: true, color: i === 4 ? C.white : C.navy, align: 'center', valign: 'middle', objectName: nm(2 + i, 'steptext') });
    });
    frame(s, await shot('trip-new-basics.jpg', { left: 0, top: 0, width: 1920, height: 1080 }), { x: 2.55, y: 3.0, w: 8.2, h: 3.95, order: 7 });
    pageNo(s, n);
  }

  /* 8 — prices */
  {
    const s = mk(false); n++;
    eyebrow(s, 'Τιμές');
    title(s, 'Οι τιμές σας όπως\nτις έχετε στο μυαλό σας', { w: 5, h: 1.6, size: 34 });
    points(s, [
      ['Σε ευρώ, ηλικία × περίοδο', 'Άλλη τιμή τον Ιούλιο, άλλη τον Οκτώβριο· ενήλικας, παιδί, βρέφος.'],
      ['Πρόσθετα και κουπόνια', 'Φαγητό, εξοπλισμός, μεταφορά· κωδικοί έκπτωσης με όριο.'],
      ['Πολιτικές ακύρωσης', 'Με κλιμάκια και αυτόματο υπολογισμό επιστροφής.'],
    ], { x: 0.7, y: 2.9, w: 4.8, order: 2, gap: 0.95 });
    frame(s, await shot('trip-tab-prices.jpg'), { x: 5.85, y: 0.75, w: 6.8, h: 4.72, order: 2 });
    pageNo(s, n);
  }

  /* 9 — on the quay */
  {
    const s = mk(true); n++;
    eyebrow(s, 'Στην προβλήτα', { dark: true });
    title(s, 'Επιβίβαση από το κινητό,\nκαι χωρίς σήμα', { dark: true, w: 6.5, h: 1.6, size: 36 });
    points(s, [
      ['Σάρωση ή ένα πάτημα ανά όνομα', 'Χωρίς γραμμή, οι σαρώσεις περιμένουν στο κινητό και φεύγουν μόλις επανέλθει.'],
      ['Το πλήρωμα βλέπει την εκδρομή', 'Επιβίβαση, σκάφος, σημείο συνάντησης — όχι τιμές.'],
      ['Κατάσταση επιβατών', 'Για το λιμεναρχείο, με τα βρέφη μέσα.'],
    ], { x: 0.7, y: 2.9, w: 6.2, order: 2, dark: true, gap: 0.95 });
    frame(s, await shot('crew-boarding-phone.jpg', { left: 0, top: 0, width: 1170, height: 1560 }), { x: 8.35, y: 0.8, w: 3.9, h: 5.2, order: 5, phone: true });
    pageNo(s, n, true);
  }

  /* 10 — money, in figures */
  {
    const s = mk(true); n++;
    eyebrow(s, 'Τα χρήματα', { dark: true });
    title(s, 'Τα χρήματα πάνε κατευθείαν σε εσάς', { dark: true });
    const stats = [
      ['100%', 'των πληρωμών στον δικό σας λογαριασμό Viva — δεν περνούν ποτέ από την πλατφόρμα'],
      ['24/7', 'κρατήσεις, και όταν εσείς είστε στη θάλασσα'],
      ['0', 'διπλοκρατήσεις: η θέση κλειδώνει όσο πληρώνει ο επισκέπτης'],
    ];
    stats.forEach(([big, small], i) => {
      const x = 0.7 + i * 4.05;
      text(s, big, { x, y: 2.55, w: 3.8, h: 1.25, fontFace: HEAD, fontSize: 72, bold: true, color: C.sun, objectName: nm(2 + i, 'stat') });
      text(s, small, { x, y: 3.85, w: 3.5, h: 1.1, fontSize: 16, color: C.pale, valign: 'top', objectName: nm(2 + i, 'statlabel') });
    });
    pageNo(s, n, true);
  }

  /* 11 — Greek paperwork */
  {
    const s = mk(false); n++;
    eyebrow(s, 'Ελληνικά χαρτιά');
    title(s, 'Παραστατικά στη σειρά, χωρίς να τα σκέφτεστε');
    const items = [
      ['LuReceipt', 'Απόδειξη ή τιμολόγιο', 'Το αποφασίζουν τα στοιχεία του πελάτη, όχι εσείς σε κάθε κράτηση.'],
      ['LuListOrdered', 'Αρίθμηση που δεν σπάει', 'Δύο παραστατικά δεν παίρνουν ποτέ τον ίδιο αριθμό.'],
      ['LuUndo2', 'Πιστωτικά και επιστροφές', 'Και οι μερικές, που είναι ο κανόνας στις ακυρώσεις.'],
      ['LuLandmark', 'Έτοιμο για myDATA', 'Ο μηχανισμός είναι χτισμένος· ανοίγει με τα δικά σας διαπιστευτήρια.'],
      ['LuFileSpreadsheet', 'Για τον λογιστή', 'Κρατήσεις και επιβάτες σε αρχείο Excel.'],
      ['LuShieldCheck', 'Προσωπικά δεδομένα', 'Οι αριθμοί διαβατηρίου σβήνονται μόνοι τους στην ώρα τους.'],
    ];
    for (let i = 0; i < 6; i++) {
      const [ico, head, body] = items[i];
      const col = i % 3, row = Math.floor(i / 3);
      const x = 0.7 + col * 4.05, y = 2.1 + row * 1.55;
      s.addShape('ellipse', { x, y, w: 0.62, h: 0.62, fill: { color: C.navy }, line: { color: C.navy }, objectName: nm(2 + i, 'ibg') });
      s.addImage({ data: await ic(ico, C.white), x: x + 0.14, y: y + 0.14, w: 0.34, h: 0.34, objectName: nm(2 + i, 'ico') });
      text(s, [
        { text: head, options: { bold: true, color: C.navy, fontSize: 16, breakLine: true } },
        { text: body, options: { color: C.soft, fontSize: 13 } },
      ], { x: x + 0.85, y: y - 0.03, w: 3.0, h: 1.3, valign: 'top', objectName: nm(2 + i, 'itext') });
    }
    pageNo(s, n);
  }

  /* 12 — statistics */
  {
    const s = mk(false); n++;
    eyebrow(s, 'Στατιστικά');
    title(s, 'Πώς πήγε ο μήνας,\nχωρίς λογιστικό φύλλο', { w: 4.7, h: 1.6, size: 34 });
    points(s, [
      ['Έσοδα, κρατήσεις, πληρότητα', 'Για όποιο διάστημα διαλέξετε.'],
      ['Ποιες μέρες ταξιδεύουν', 'Και πόσο νωρίς κλείνουν — πότε αξίζει η διαφήμιση.'],
      ['Τα πιο άδεια δρομολόγια', 'Αυτό πάνω στο οποίο μπορείτε να κάνετε κάτι.'],
    ], { x: 0.7, y: 2.9, w: 4.5, order: 2, gap: 0.95 });
    frame(s, await shot('analytics-charts.jpg', { left: 480, top: 90, width: 1440, height: 950 }), { x: 5.55, y: 0.75, w: 7.1, h: 4.68, order: 2 });
    pageNo(s, n);
  }

  /* 13 — weather */
  {
    const s = mk(false); n++;
    eyebrow(s, 'Όταν κάτι αλλάζει');
    title(s, 'Ο καιρός χαλάει. Το σύστημα σας βοηθάει να το πείτε.');
    const cards = [
      ['LuWind', 'Πριν ακυρώσετε, βλέπετε', 'Ποιες αναχωρήσεις επηρεάζονται, τι είναι κλεισμένο, ποιος παίρνει τι πίσω.'],
      ['LuSend', 'Μία κίνηση, μία φορά', 'Φεύγουν μαζί ειδοποιήσεις και επιστροφές — όχι δεκαπέντε τηλέφωνα.'],
      ['LuBellRing', 'Και οι υπόλοιπες ειδοποιήσεις', 'Επιβεβαίωση, υπενθύμιση, εισιτήριο. Ποτέ μέσα στη νύχτα.'],
    ];
    for (let i = 0; i < 3; i++) {
      const [ico, head, body] = cards[i];
      card(s, { x: 0.7 + i * 4.05, y: 2.35, w: 3.8, h: 2.7, ico: await ic(ico, C.navy), head, body, order: 2 + i });
    }
    pageNo(s, n);
  }

  /* 14 — existing website */
  {
    const s = mk(true); n++;
    eyebrow(s, 'Αν έχετε ήδη ιστοσελίδα', { dark: true });
    title(s, 'Η κράτηση μπαίνει μέσα στη δική σας σελίδα', { dark: true });
    const cards = [
      ['LuPuzzle', 'Πρόσθετο WordPress', 'Με shortcode, block ή Elementor. Οι εκδρομές γίνονται σελίδες που βρίσκει η Google.'],
      ['LuLayers', 'Τέσσερα κομμάτια', 'Κράτηση, λίστα εκδρομών, ημερολόγιο, φόρμα ερώτησης — όπου θέλετε.'],
      ['LuFeather', 'Ελαφρύ', 'Λίγα KB. Κρατάει τα χρώματά σας και δεν καθυστερεί τη σελίδα.'],
    ];
    for (let i = 0; i < 3; i++) {
      const [ico, head, body] = cards[i];
      card(s, { x: 0.7 + i * 4.05, y: 2.35, w: 3.8, h: 2.7, ico: await ic(ico, C.white), head, body, order: 2 + i, dark: true });
    }
    pageNo(s, n, true);
  }

  /* 15 — getting started */
  {
    const s = mk(false); n++;
    eyebrow(s, 'Ξεκίνημα');
    title(s, 'Στήνεται σε ένα απόγευμα', { w: 5, h: 1.0, size: 34 });
    points(s, [
      ['Ένας οδηγός σας πάει βήμα-βήμα', 'Στοιχεία επιχείρησης, εμφάνιση, ΦΠΑ, πολιτική ακύρωσης.'],
      ['Ό,τι δεν θέλετε τώρα, το αφήνετε', 'Τίποτα δεν σας κλειδώνει.'],
      ['Εγχειρίδιο στα Ελληνικά', 'Με πραγματικές οθόνες, σε PDF.'],
    ], { x: 0.7, y: 2.3, w: 4.8, order: 2, gap: 0.95 });
    frame(s, await shot('setup-guide.jpg'), { x: 5.85, y: 0.75, w: 6.8, h: 4.72, order: 2 });
    pageNo(s, n);
  }

  /* 16 — ready and coming */
  {
    const s = mk(false); n++;
    eyebrow(s, 'Με ειλικρίνεια');
    title(s, 'Τι είναι έτοιμο σήμερα, και τι έρχεται');
    const col = (x, head, colour, items, order) => {
      s.addShape('roundRect', { x, y: 2.1, w: 5.8, h: 2.75, rectRadius: 0.14, fill: { color: C.white }, line: { color: C.pale }, shadow: { type: 'outer', color: '0A2240', opacity: 0.08, blur: 10, offset: 3, angle: 90 }, objectName: nm(order, 'col') });
      text(s, head, { x: x + 0.35, y: 2.35, w: 5.1, h: 0.45, fontSize: 18, bold: true, color: colour, fontFace: HEAD, objectName: nm(order, 'colhead') });
      text(s, items.map((t, i) => ({ text: t, options: { bullet: { indent: 18 }, breakLine: i < items.length - 1 } })), { x: x + 0.35, y: 2.9, w: 5.1, h: 1.8, fontSize: 15, color: C.soft, valign: 'top', paraSpaceAfter: 6, objectName: nm(order, 'colbody') });
    };
    col(0.7, 'Έτοιμο', C.navy, ['Κρατήσεις, τιμές, διαθεσιμότητα', 'Η ιστοσελίδα σας και το πρόσθετο WordPress', 'Πίνακας, ημερολόγιο, επιβίβαση, στατιστικά', 'Παραστατικά, πιστωτικά, εξαγωγές'], 2);
    col(6.85, 'Έρχεται', C.sun, ['myDATA: ανοίγει με τα διαπιστευτήριά σας', 'Ναυλοσύμφωνο: περιμένει νομική επιβεβαίωση', 'GetYourGuide και Viator', 'Λογαριασμοί για πρακτορεία'], 3);
    pageNo(s, n);
  }

  /* 17 — close */
  {
    const s = mk(true); n++;
    text(s, 'Να το δούμε πάνω στα δικά σας σκάφη;', { x: 0.9, y: 1.7, w: 11.5, h: 1.2, fontFace: HEAD, fontSize: 44, bold: true, color: C.white, objectName: nm(1, 'close') });
    text(s, 'Στήνουμε έναν δοκιμαστικό λογαριασμό με τις δικές σας εκδρομές και τις δικές σας τιμές,\nκαι τον δοκιμάζετε όσο θέλετε πριν την πρώτη πραγματική κράτηση.', { x: 0.9, y: 3.05, w: 11.2, h: 1.1, fontSize: 19, color: C.pale, objectName: nm(2, 'closebody') });
    text(s, 'Kaiki', { x: 0.9, y: 4.5, w: 4, h: 0.8, fontFace: HEAD, fontSize: 34, bold: true, color: C.white, objectName: nm(3, 'mark') });
  }

  await pres.writeFile({ fileName: OUT });
  console.log('wrote', OUT, 'slides:', n);
}

main().catch((e) => { console.error(e); process.exit(1); });
