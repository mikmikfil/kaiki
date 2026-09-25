(function () {
  var mk = document.getElementById('mk');
  var bar = document.getElementById('mk-bar');
  if (!mk || !bar) return;
  function barH() { document.documentElement.style.setProperty('--mk-bar', bar.offsetHeight + 'px'); }
  barH(); window.addEventListener('resize', barH);
  function hosts(on) {
    document.querySelectorAll('[data-kaiki-widget]').forEach(function (h) { h.classList.toggle('m-new', on); });
  }
  hosts(mk.classList.contains('m-new'));
  function wire(attr, apply) {
    var btns = document.querySelectorAll('[' + attr + ']');
    btns.forEach(function (b) {
      b.addEventListener('click', function () {
        apply(b.getAttribute(attr));
        btns.forEach(function (o) { o.setAttribute('aria-pressed', o === b ? 'true' : 'false'); });
      });
    });
  }
  wire('data-set', function (v) { mk.classList.toggle('m-new', v === 'new'); hosts(v === 'new'); });
  wire('data-font', function (v) { mk.classList.toggle('f-inter', v === 'inter'); });
  wire('data-marks', function (v) { mk.classList.toggle('mk-marks', v === 'on'); });
  // A mockup: links and forms go nowhere.
  mk.addEventListener('click', function (e) {
    var a = e.target.closest('a[href="#"], button[type="submit"]');
    if (a) e.preventDefault();
  });
  mk.addEventListener('submit', function (e) { e.preventDefault(); });
})();
