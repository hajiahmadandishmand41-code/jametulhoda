/**
 * gallery.js — لایت‌باکس نگارخانه (بدون وابستگی بیرونی)
 * ───────────────────────────────────────────────────────────────────────────
 * هر نگارخانه با `data-jhd-gallery` نشانه‌گذاری می‌شود و دادهٔ تصاویرش در
 * `<script type="application/json" data-jhd-lightbox-data="…">` کنارش قرار دارد.
 *
 * چرا «پورتال»؟
 * لایت‌باکس در HTML درون `.jhd-card-media` (و درون کارت) متولد می‌شود؛ همان‌جا
 * که `overflow: hidden` و روی هاور `transform` وجود دارد. `transform` روی یک
 * جد، `position: fixed` را به همان جد گره می‌زند — پس «پنجرهٔ تمام‌صفحهٔ عکس»
 * داخل یک قاب ۱۶:۱۰ بریده و جابه‌جا نمایش داده می‌شد. راه‌حل: پیش از اولین
 * باز شدن، گره لایت‌باکس به `document.body` منتقل می‌شود (و `inert` روی محتوای
 * صفحه می‌نشیند تا پیمایش با کیبورد پشت پنجره گیر نکند).
 *
 * رفتار: باز/بسته، بعدی/قبلی، کیبورد (Esc، ←، →، Home، End)، سوایپ،
 * بندانگشتی‌ها، شمارهٔ تصویر، دانلود، و پیش‌بارگذاری تصویر بعدی.
 */
(function () {
    'use strict';

    var PERSIAN = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    function fa(value) { return String(value).replace(/[0-9]/g, function (d) { return PERSIAN[+d]; }); }

    var FOCUSABLE = 'button:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])';

    document.querySelectorAll('[data-jhd-gallery]').forEach(function (gallery) {
        var uid = gallery.getAttribute('data-jhd-gallery');
        if (!uid) return;
        var dataNode = document.querySelector('[data-jhd-lightbox-data="' + uid + '"]')
            || document.querySelector('script[data-jhd-gallery-data="' + uid + '"]');
        if (!dataNode) return;
        var items = [];
        try { items = JSON.parse(dataNode.textContent || '[]'); } catch (error) { return; }
        if (!Array.isArray(items) || !items.length) return;

        var lightbox = gallery.querySelector('[data-jhd-lightbox]');
        if (!lightbox) return;
        var image = lightbox.querySelector('[data-jhd-lightbox-image]');
        var caption = lightbox.querySelector('[data-jhd-lightbox-caption]');
        var counter = lightbox.querySelector('[data-jhd-lightbox-counter]');
        var download = lightbox.querySelector('[data-jhd-lightbox-download]');
        var thumbs = lightbox.querySelector('[data-jhd-lightbox-thumbs]');
        var prevBtn = lightbox.querySelector('[data-jhd-lightbox-prev]');
        var nextBtn = lightbox.querySelector('[data-jhd-lightbox-next]');
        var current = 0;
        var lastFocused = null;
        var isPortaled = false;

        if (thumbs) {
            thumbs.textContent = '';
            items.forEach(function (item, index) {
                var thumb = document.createElement('button');
                thumb.type = 'button';
                thumb.className = 'jhd-lightbox__thumb';
                thumb.setAttribute('aria-label', 'تصویر ' + fa(index + 1));
                var thumbImg = document.createElement('img');
                thumbImg.src = item.src;
                thumbImg.alt = '';
                thumbImg.loading = 'lazy';
                thumbImg.decoding = 'async';
                thumb.appendChild(thumbImg);
                thumb.addEventListener('click', function () { show(index); });
                thumbs.appendChild(thumb);
            });
        }

        function preload(index) {
            if (!items.length) return;
            var next = new Image();
            next.src = items[(index + items.length) % items.length].src;
        }

        function show(index) {
            current = (index + items.length) % items.length;
            var item = items[current];
            if (image) {
                image.src = item.src;
                image.alt = item.alt || '';
            }
            if (caption) {
                caption.textContent = item.alt || '';
                caption.style.display = item.alt ? '' : 'none';
            }
            if (counter) counter.textContent = fa(current + 1) + ' / ' + fa(items.length);
            if (download) download.href = item.src;
            if (thumbs) {
                Array.prototype.forEach.call(thumbs.children, function (thumb, i) {
                    var active = i === current;
                    thumb.classList.toggle('is-active', active);
                    if (active && thumb.scrollIntoView) {
                        thumb.scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'smooth' });
                    }
                });
            }
            preload(current + 1);
            preload(current - 1);
        }

        /** گره را از کارت بیرون می‌آورد تا دیگر توسط `overflow`/`transform` بریده نشود. */
        function portal() {
            if (isPortaled || lightbox.parentNode === document.body) { isPortaled = true; return; }
            lightbox.dataset.jhdLightboxHome = '1';
            document.body.appendChild(lightbox);
            isPortaled = true;
        }

        function setBackgroundHidden(state) {
            ['main', '.jhd-header'].forEach(function (selector) {
                document.querySelectorAll(selector).forEach(function (node) {
                    if (state) {
                        if (node.dataset.jhdInertBefore === undefined) {
                            node.dataset.jhdInertBefore = node.getAttribute('aria-hidden') || '';
                        }
                        node.setAttribute('aria-hidden', 'true');
                        try { node.inert = true; } catch (ignored) { /* مرورگرهای کهنه */ }
                    } else {
                        node.removeAttribute('aria-hidden');
                        try { node.inert = false; } catch (ignored) {}
                        if (node.dataset.jhdInertBefore !== undefined) delete node.dataset.jhdInertBefore;
                    }
                });
            });
        }

        function onKeydown(event) {
            if (lightbox.hidden) return;
            if (event.key === 'Escape') { event.preventDefault(); close(); return; }
            if (event.key === 'ArrowLeft') { event.preventDefault(); show(current + 1); return; } // RTL: چپ = بعدی
            if (event.key === 'ArrowRight') { event.preventDefault(); show(current - 1); return; }
            if (event.key === 'Home') { event.preventDefault(); show(0); return; }
            if (event.key === 'End') { event.preventDefault(); show(items.length - 1); return; }
            if (event.key !== 'Tab') return;
            var focusable = Array.prototype.slice.call(lightbox.querySelectorAll(FOCUSABLE))
                .filter(function (el) { return el.getClientRects().length > 0; });
            if (!focusable.length) return;
            var first = focusable[0];
            var last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }

        function open(index) {
            lastFocused = document.activeElement;
            portal();
            document.addEventListener('keydown', onKeydown);
            lightbox.hidden = false;
            document.body.classList.add('jhd-lightbox-open');
            setBackgroundHidden(true);
            show(index);
            var closeButton = lightbox.querySelector('button[data-jhd-lightbox-close]');
            if (closeButton) closeButton.focus();
        }

        function close() {
            lightbox.hidden = true;
            document.removeEventListener('keydown', onKeydown);
            document.body.classList.remove('jhd-lightbox-open');
            setBackgroundHidden(false);
            if (lastFocused && lastFocused.focus) lastFocused.focus();
        }

        gallery.querySelectorAll('.jhd-gallery__cell').forEach(function (cell) {
            cell.addEventListener('click', function (event) {
                // سلول‌ها پیوند به فایل اصلی‌اند؛ با JS همان پیوند به پنجرهٔ
                // تصویر تبدیل می‌شود و مرورگر آدرس را ترک نمی‌کند.
                if (event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0) return;
                event.preventDefault();
                open(parseInt(cell.getAttribute('data-index'), 10) || 0);
            });
        });
        lightbox.querySelectorAll('[data-jhd-lightbox-close]').forEach(function (node) {
            node.addEventListener('click', close);
        });
        if (prevBtn) prevBtn.addEventListener('click', function () { show(current - 1); });
        if (nextBtn) nextBtn.addEventListener('click', function () { show(current + 1); });

        // سوایپ موبایل: چپ/راست برای جابه‌جایی، پایین برای بستن
        var startX = 0;
        var startY = 0;
        var figure = lightbox.querySelector('.jhd-lightbox__figure');
        if (figure) {
            figure.addEventListener('touchstart', function (event) {
                var touch = event.changedTouches[0];
                startX = touch.clientX;
                startY = touch.clientY;
            }, { passive: true });
            figure.addEventListener('touchend', function (event) {
                var touch = event.changedTouches[0];
                var dx = touch.clientX - startX;
                var dy = touch.clientY - startY;
                if (Math.abs(dx) > 45 && Math.abs(dx) > Math.abs(dy)) {
                    if (dx < 0) show(current + 1); else show(current - 1);
                } else if (dy > 90 && Math.abs(dy) > Math.abs(dx)) {
                    close();
                }
            }, { passive: true });
        }
    });
})();
