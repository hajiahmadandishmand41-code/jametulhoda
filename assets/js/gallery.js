/**
 * gallery.js — لایت‌باکس گالری تصاویر مطالب (بدون وابستگی بیرونی)
 *
 * هر بخش دارای `data-jhd-gallery` است و داده‌های تصاویرش در یک
 * <script type="application/json" data-jhd-gallery-data="…"> کنار آن قرار دارد.
 * رفتار: باز/بسته کردن، تصویر بعدی و قبلی، کیبورد (Esc، ←، →)، سوایپ لمسی،
 * بندانگشتی‌ها، شمارهٔ تصویر و دانلود. رابط کاربری کاملاً RTL است.
 */
(function () {
    'use strict';

    var PERSIAN = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    function fa(value) { return String(value).replace(/[0-9]/g, function (d) { return PERSIAN[+d]; }); }

    document.querySelectorAll('[data-jhd-gallery]').forEach(function (gallery) {
        var uid = gallery.getAttribute('data-jhd-gallery');
        var dataNode = document.querySelector('script[data-jhd-gallery-data="' + uid + '"]');
        if (!dataNode) return;
        var items = [];
        try { items = JSON.parse(dataNode.textContent || '[]'); } catch (error) { return; }
        if (!items.length) return;

        var lightbox = gallery.querySelector('[data-jhd-lightbox]');
        var image = lightbox.querySelector('[data-jhd-lightbox-image]');
        var caption = lightbox.querySelector('[data-jhd-lightbox-caption]');
        var counter = lightbox.querySelector('[data-jhd-lightbox-counter]');
        var download = lightbox.querySelector('[data-jhd-lightbox-download]');
        var thumbs = lightbox.querySelector('[data-jhd-lightbox-thumbs]');
        var current = 0;
        var lastFocused = null;

        items.forEach(function (item, index) {
            var thumb = document.createElement('button');
            thumb.type = 'button';
            thumb.className = 'jhd-lightbox__thumb';
            thumb.setAttribute('aria-label', 'تصویر ' + fa(index + 1));
            thumb.innerHTML = '<img src="' + item.src + '" alt="">';
            thumb.addEventListener('click', function () { show(index); });
            thumbs.appendChild(thumb);
        });

        function show(index) {
            current = (index + items.length) % items.length;
            var item = items[current];
            image.src = item.src;
            image.alt = item.alt || '';
            caption.textContent = item.alt || '';
            caption.style.display = item.alt ? 'block' : 'none';
            counter.textContent = fa(current + 1) + ' / ' + fa(items.length);
            if (download) download.href = item.src;
            Array.prototype.forEach.call(thumbs.children, function (thumb, i) {
                thumb.classList.toggle('is-active', i === current);
                if (i === current) thumb.scrollIntoView({ block: 'nearest', inline: 'center' });
            });
        }

        function open(index) {
            lastFocused = document.activeElement;
            lightbox.hidden = false;
            document.body.style.overflow = 'hidden';
            show(index);
            var closeButton = lightbox.querySelector('[data-jhd-lightbox-close]');
            if (closeButton) closeButton.focus();
        }

        function close() {
            lightbox.hidden = true;
            document.body.style.overflow = '';
            if (lastFocused && lastFocused.focus) lastFocused.focus();
        }

        gallery.querySelectorAll('.jhd-gallery__cell').forEach(function (cell) {
            cell.addEventListener('click', function () {
                open(parseInt(cell.getAttribute('data-index'), 10) || 0);
            });
        });

        lightbox.querySelectorAll('[data-jhd-lightbox-close]').forEach(function (node) {
            node.addEventListener('click', close);
        });
        lightbox.querySelector('[data-jhd-lightbox-prev]').addEventListener('click', function () { show(current - 1); });
        lightbox.querySelector('[data-jhd-lightbox-next]').addEventListener('click', function () { show(current + 1); });

        document.addEventListener('keydown', function (event) {
            if (lightbox.hidden) return;
            if (event.key === 'Escape') { close(); }
            else if (event.key === 'ArrowLeft') { show(current + 1); }   // RTL: چپ = بعدی
            else if (event.key === 'ArrowRight') { show(current - 1); } // RTL: راست = قبلی
        });

        // سوایپ موبایل
        var startX = 0;
        var startY = 0;
        var figure = lightbox.querySelector('.jhd-lightbox__figure');
        figure.addEventListener('touchstart', function (event) {
            var touch = event.changedTouches[0];
            startX = touch.clientX;
            startY = touch.clientY;
        }, { passive: true });
        figure.addEventListener('touchend', function (event) {
            var touch = event.changedTouches[0];
            var dx = touch.clientX - startX;
            var dy = touch.clientY - startY;
            if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) {
                if (dx < 0) show(current + 1); else show(current - 1);
            } else if (dy > 90 && Math.abs(dy) > Math.abs(dx)) {
                close();
            }
        }, { passive: true });
    });
})();
