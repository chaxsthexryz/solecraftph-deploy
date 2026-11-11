<footer>
  <div class="wrap">
    <div class="foot__grid">
      <div>
        <div class="foot__brand">SOLE<span>CRAFT</span>PH</div>
        <p>Authentic footwear for the way Filipinos move — sourced right, priced fair, and always in stock when it matters.</p>
      </div>
      <div class="foot__col">
        <h3>Shop</h3>
        <a href="<?= BASE_PATH ?>/index.php?category=<?= urlencode('Athletic & Performance Footwear') ?>">Athletic & Performance</a>
        <a href="<?= BASE_PATH ?>/index.php?category=<?= urlencode('Casual & Lifestyle Footwear') ?>">Casual & Lifestyle</a>
        <a href="<?= BASE_PATH ?>/index.php?category=<?= urlencode('Formal & Dress Footwear') ?>">Formal & Dress</a>
      </div>
      <div class="foot__col">
        <h3>Company</h3>
        <a href="<?= BASE_PATH ?>/page.php?slug=about">About Us</a>
        <a href="<?= BASE_PATH ?>/page.php?slug=faq">FAQs</a>
        <a href="<?= BASE_PATH ?>/page.php?slug=privacy">Privacy Policy</a>
        <a href="<?= BASE_PATH ?>/page.php?slug=terms">Terms &amp; Conditions</a>
        <a href="<?= BASE_PATH ?>/page.php?slug=returns">Returns &amp; Refunds</a>
        <a href="<?= BASE_PATH ?>/developers.php">The Developers</a>
      </div>
      <div class="foot__col">
        <h3>Help</h3>
        <a href="<?= BASE_PATH ?>/cart.php">Cart</a>
        <a href="<?= BASE_PATH ?>/contact.php">Contact Support</a>
      </div>
    </div>
    <div class="wrap foot__bottom" style="padding-left:0;padding-right:0;">
      <span>&copy; <?= date('Y') ?> SoleCraftPH. All rights reserved.</span>
      <span class="mono">Made in the Philippines</span>
    </div>
  </div>
</footer>

<script>
/* "Add to Cart" without leaving the page: intercept the form, post it
   in the background, show a toast, update the nav cart count. */
(function () {
  function showToast(message, ok) {
    if (!message) return;
    var toast = document.createElement('div');
    toast.className = 'toast' + (ok === false ? ' toast--error' : '');
    toast.textContent = message;
    document.body.appendChild(toast);
    requestAnimationFrame(function () { toast.classList.add('is-visible'); });
    setTimeout(function () {
      toast.classList.remove('is-visible');
      setTimeout(function () { toast.remove(); }, 300);
    }, 4000);
  }

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement)) return;

    var actionField = form.querySelector('input[name="action"]');
    if (!actionField || actionField.value !== 'add') return;
    if (!/\/cart\.php(\?.*)?$/.test(form.getAttribute('action') || '')) return;

    e.preventDefault();

    var btn = form.querySelector('button[type="submit"]');
    var originalLabel = btn ? btn.textContent : '';
    if (btn) { btn.disabled = true; btn.textContent = 'Adding…'; }

    fetch(form.getAttribute('action'), {
      method: 'POST',
      body: new FormData(form),
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        showToast(data.message, data.ok);
        var countEl = document.getElementById('navCartCount');
        if (countEl && typeof data.cart_count !== 'undefined') {
          countEl.textContent = data.cart_count;
        }
      })
      .catch(function () {
        showToast('Something went wrong. Please try again.', false);
      })
      .finally(function () {
        if (btn) { btn.disabled = false; btn.textContent = originalLabel; }
      });
  });
})();
</script>

<!-- Help assistant (shop only; the admin site has its own footer). -->
<button type="button" class="ast-fab" id="astFab" aria-expanded="false" aria-controls="astPanel">
  <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
  <span>Ask SoleCraft</span>
</button>
<section class="ast-panel" id="astPanel" role="dialog" aria-label="SoleCraft assistant" hidden>
  <header class="ast-head">
    <div><b>SoleCraft Assistant</b><span>Shoes, sizes, shipping, orders</span></div>
    <button type="button" class="ast-x" id="astClose" aria-label="Close assistant">&times;</button>
  </header>
  <div class="ast-log" id="astLog" aria-live="polite"></div>
  <form class="ast-form" id="astForm">
    <label for="astInput" class="sr-only">Message</label>
    <input id="astInput" type="text" maxlength="300" autocomplete="off" placeholder="e.g. running shoes under 3000">
    <button type="submit" class="btn btn--solid btn--sm">Send</button>
  </form>
</section>
<script>
(function () {
  var fab = document.getElementById('astFab'), panel = document.getElementById('astPanel');
  var log = document.getElementById('astLog'), form = document.getElementById('astForm'), input = document.getElementById('astInput');
  var api = '<?= BASE_PATH ?>/api/assistant.php', started = false, busy = false;

  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
  function scroll() { log.scrollTop = log.scrollHeight; }

  function render(d) {
    var b = el('div', 'ast-msg ast-msg--bot');
    b.appendChild(el('p', null, d.reply || 'Sorry, something went wrong.'));
    (d.items || []).forEach(function (it) {
      var a = el('a', 'ast-item'); a.href = it.url;
      if (it.image) { var img = el('img'); img.src = it.image; img.alt = ''; img.loading = 'lazy'; a.appendChild(img); }
      var t = el('span'); t.appendChild(el('b', null, it.name)); t.appendChild(el('em', null, it.price)); a.appendChild(t);
      b.appendChild(a);
    });
    if ((d.links || []).length) {
      var l = el('div', 'ast-links');
      d.links.forEach(function (x) { var a = el('a', null, x[0] + ' \u2192'); a.href = x[1]; l.appendChild(a); });
      b.appendChild(l);
    }
    log.appendChild(b);
    if ((d.chips || []).length) {
      var c = el('div', 'ast-chips');
      d.chips.forEach(function (t) { var k = el('button', null, t); k.type = 'button'; k.onclick = function () { send(t); }; c.appendChild(k); });
      log.appendChild(c);
    }
    scroll();
  }

  function send(text) {
    text = (text || '').trim();
    if (busy) return;
    if (text) log.appendChild(el('div', 'ast-msg ast-msg--me', text));
    busy = true;
    var typing = el('div', 'ast-msg ast-msg--bot ast-typing', '\u2026'); log.appendChild(typing); scroll();
    fetch(api, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ message: text }) })
      .then(function (r) { return r.json(); })
      .then(function (d) { typing.remove(); render(d); })
      .catch(function () { typing.remove(); render({ reply: 'I could not connect. Check your internet and try again.' }); })
      .finally(function () { busy = false; });
  }

  function open(on) {
    panel.hidden = !on; fab.setAttribute('aria-expanded', on ? 'true' : 'false');
    if (on) { if (!started) { started = true; send(''); } input.focus(); } else { fab.focus(); }
  }
  fab.addEventListener('click', function () { open(panel.hidden); });
  document.getElementById('astClose').addEventListener('click', function () { open(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hidden) open(false); });
  form.addEventListener('submit', function (e) { e.preventDefault(); var t = input.value; input.value = ''; if (t.trim()) send(t); });
})();
</script>

</body>
</html>
