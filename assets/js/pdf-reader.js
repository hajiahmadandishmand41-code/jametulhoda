/**
 * pdf-reader.js — مطالعهٔ آنلاین PDF داخل سایت (موبایل و دسکتاپ)
 * ───────────────────────────────────────────────────────────────────────────
 * هر عنصر [data-jhd-pdf] با اسکرول پیوسته، بزرگ‌نمایی، پرش به صفحه، حالت
 * تمام‌صفحه و دکمهٔ دانلود (در صورت مجاز بودن) به یک خوانندهٔ کامل تبدیل می‌شود.
 *
 * چرا این پیاده‌سازی برای موبایل مناسب است:
 *   • فقط صفحه‌های نزدیک به دید رندر می‌شوند (IntersectionObserver) پس حافظه
 *     و باتری مصرف نمی‌شود و فایل‌های بزرگ هم روان باز می‌شوند.
 *   • مقیاس بر اساس عرض واقعی ستون محاسبه می‌شود ⇒ متن همیشه خوانا و بدون
 *     اسکرول افقی است.
 *   • رندر روی <canvas> انجام می‌شود؛ نه به نمایش‌گر داخلی مرورگر وابسته‌ایم و
 *     نه به iframe (که در موبایل معمولاً فایل را دانلود می‌کند).
 *   • pdf.js به‌صورت محلی از assets/vendor بارگذاری می‌شود؛ هیچ CDN خارجی.
 */
(function () {
  'use strict';

  var MODULE_URL = null;
  var WORKER_URL = null;
  var pdfjsPromise = null;

  function resolveAssets(root) {
    var base = root.getAttribute('data-pdf-lib');
    var worker = root.getAttribute('data-pdf-worker');
    if (base) MODULE_URL = base;
    if (worker) WORKER_URL = worker;
  }

  function loadPdfJs() {
    if (pdfjsPromise) return pdfjsPromise;
    if (!MODULE_URL) return Promise.reject(new Error('pdf.js path missing'));
    pdfjsPromise = import(MODULE_URL).then(function (lib) {
      if (WORKER_URL) lib.GlobalWorkerOptions.workerSrc = WORKER_URL;
      return lib;
    });
    return pdfjsPromise;
  }

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text != null) node.textContent = text;
    return node;
  }

  function faDigits(value) {
    return String(value).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[+d]; });
  }

  function setupReader(root) {
    if (root.dataset.jhdPdfReady === '1') return;
    root.dataset.jhdPdfReady = '1';
    resolveAssets(root);

    var src = root.getAttribute('data-jhd-pdf');
    if (!src) return;

    var viewport = root.querySelector('[data-pdf-viewport]');
    var status = root.querySelector('[data-pdf-status]');
    var pageLabel = root.querySelector('[data-pdf-page]');
    var totalLabel = root.querySelector('[data-pdf-total]');
    var prevBtn = root.querySelector('[data-pdf-prev]');
    var nextBtn = root.querySelector('[data-pdf-next]');
    var zoomInBtn = root.querySelector('[data-pdf-zoom-in]');
    var zoomOutBtn = root.querySelector('[data-pdf-zoom-out]');
    var fitBtn = root.querySelector('[data-pdf-fit]');
    var fullBtn = root.querySelector('[data-pdf-full]');
    if (!viewport) return;

    var doc = null;
    var pages = [];
    var zoom = 1;
    var current = 1;
    var rendering = Object.create(null);

    function say(message, isError) {
      if (!status) return;
      status.textContent = message || '';
      status.hidden = !message;
      status.classList.toggle('is-error', !!isError);
    }

    function baseScale(page) {
      var unscaled = page.getViewport({ scale: 1 });
      var width = viewport.clientWidth - 8;
      if (width < 120) width = 320;
      // سقف مقیاس برای صفحه‌های بسیار عریض در دسکتاپ
      return Math.min(width / unscaled.width, 2.4);
    }

    function renderPage(index) {
      var holder = pages[index - 1];
      if (!holder || holder.dataset.rendered === String(zoom) || rendering[index]) return;
      rendering[index] = true;
      doc.getPage(index).then(function (page) {
        var scale = baseScale(page) * zoom;
        var vp = page.getViewport({ scale: scale });
        var ratio = Math.min(window.devicePixelRatio || 1, 2);
        var canvas = holder.querySelector('canvas');
        canvas.width = Math.floor(vp.width * ratio);
        canvas.height = Math.floor(vp.height * ratio);
        canvas.style.width = Math.floor(vp.width) + 'px';
        canvas.style.height = Math.floor(vp.height) + 'px';
        holder.style.minHeight = '';
        var ctx = canvas.getContext('2d', { alpha: false });
        ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
        return page.render({ canvasContext: ctx, viewport: vp }).promise.then(function () {
          holder.dataset.rendered = String(zoom);
        });
      }).catch(function () {
        holder.dataset.rendered = '';
      }).finally(function () {
        rendering[index] = false;
      });
    }

    var observer = 'IntersectionObserver' in window
      ? new IntersectionObserver(function (entries) {
          entries.forEach(function (entry) {
            var index = Number(entry.target.dataset.page);
            if (entry.isIntersecting) {
              renderPage(index);
              renderPage(index + 1);
              current = index;
              if (pageLabel) pageLabel.textContent = faDigits(current);
            }
          });
        }, { root: viewport, rootMargin: '250px 0px' })
      : null;

    function goTo(index) {
      if (!doc) return;
      index = Math.min(Math.max(1, index), doc.numPages);
      var holder = pages[index - 1];
      if (!holder) return;
      renderPage(index);
      viewport.scrollTo({ top: holder.offsetTop - viewport.offsetTop - 6, behavior: 'smooth' });
      current = index;
      if (pageLabel) pageLabel.textContent = faDigits(current);
    }

    function rerender() {
      pages.forEach(function (holder) { holder.dataset.rendered = ''; });
      renderPage(current);
      renderPage(current + 1);
    }

    function applyZoom(next) {
      zoom = Math.min(Math.max(next, 0.6), 3);
      rerender();
    }

    if (prevBtn) prevBtn.addEventListener('click', function () { goTo(current - 1); });
    if (nextBtn) nextBtn.addEventListener('click', function () { goTo(current + 1); });
    if (zoomInBtn) zoomInBtn.addEventListener('click', function () { applyZoom(zoom + 0.25); });
    if (zoomOutBtn) zoomOutBtn.addEventListener('click', function () { applyZoom(zoom - 0.25); });
    if (fitBtn) fitBtn.addEventListener('click', function () { applyZoom(1); });
    if (fullBtn) {
      fullBtn.addEventListener('click', function () {
        var on = root.classList.toggle('is-fullscreen');
        document.body.classList.toggle('jhd-pdf-locked', on);
        fullBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
        window.setTimeout(rerender, 220);
      });
      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && root.classList.contains('is-fullscreen')) fullBtn.click();
      });
    }

    var resizeTimer = null;
    window.addEventListener('resize', function () {
      window.clearTimeout(resizeTimer);
      resizeTimer = window.setTimeout(rerender, 220);
    });

    say('در حال آماده‌سازی خوانندهٔ PDF…');
    loadPdfJs().then(function (lib) {
      return lib.getDocument({ url: src, withCredentials: false }).promise;
    }).then(function (loaded) {
      doc = loaded;
      if (totalLabel) totalLabel.textContent = faDigits(doc.numPages);
      viewport.innerHTML = '';
      for (var i = 1; i <= doc.numPages; i++) {
        var holder = el('div', 'jhd-pdf-page');
        holder.dataset.page = String(i);
        holder.style.minHeight = '60vh';
        holder.appendChild(el('canvas'));
        holder.appendChild(el('span', 'jhd-pdf-page__num', faDigits(i)));
        viewport.appendChild(holder);
        pages.push(holder);
        if (observer) observer.observe(holder);
      }
      say('');
      renderPage(1);
      renderPage(2);
      if (!observer) {
        viewport.addEventListener('scroll', function () {
          pages.forEach(function (holder, index) {
            var top = holder.offsetTop - viewport.scrollTop - viewport.offsetTop;
            if (top < viewport.clientHeight + 400 && top > -holder.offsetHeight - 400) renderPage(index + 1);
          });
        }, { passive: true });
      }
    }).catch(function () {
      say('این فایل در مرورگر شما قابل نمایش نیست؛ از دکمهٔ دانلود استفاده کنید.', true);
      root.classList.add('is-failed');
    });
  }

  function init() {
    document.querySelectorAll('[data-jhd-pdf]').forEach(function (root) {
      // فقط وقتی خواننده وارد دید شد بارگذاری می‌شود — سرعت بارگذاری صفحه حفظ می‌شود.
      if (!('IntersectionObserver' in window)) { setupReader(root); return; }
      var io = new IntersectionObserver(function (entries, obs) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          obs.disconnect();
          setupReader(root);
        });
      }, { rootMargin: '400px 0px' });
      io.observe(root);
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
