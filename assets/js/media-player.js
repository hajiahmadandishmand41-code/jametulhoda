/**
 * media-player.js — پلیر حرفه‌ای صوت/ویدیو با Playlist، Prev/Next و پخش خودکار بعدی
 */
(function () {
  'use strict';

  function initPlayer(mediaEl, playlistEl, opts) {
    if (!mediaEl) return;
    opts = opts || {};
    var items = playlistEl ? Array.prototype.slice.call(playlistEl.querySelectorAll('.playlist-item')) : [];
    var current = 0;
    var counterEl = opts.counterEl || null;
    var downloadEl = opts.downloadEl || null;

    function loadIndex(idx, autoplay) {
      if (!items.length) return;
      if (idx < 0) idx = items.length - 1;
      if (idx >= items.length) idx = 0;
      current = idx;
      var src = items[idx].getAttribute('data-src');
      var source = mediaEl.querySelector('source');
      if (source) source.setAttribute('src', src); else mediaEl.setAttribute('src', src);
      mediaEl.setAttribute('src', src);
      mediaEl.load();
      items.forEach(function (b, i) { b.classList.toggle('active', i === idx); });
      if (counterEl) counterEl.textContent = (idx + 1) + ' / ' + items.length;
      if (downloadEl) downloadEl.setAttribute('href', src);
      if (autoplay) {
        var p = mediaEl.play();
        if (p && p.catch) p.catch(function(){});
      }
    }

    // اولین بار: از data-src منبع اصلی را بارگذاری کن (Lazy)
    var firstSource = mediaEl.querySelector('source');
    if (firstSource && !firstSource.getAttribute('src')) {
      var initialSrc = firstSource.getAttribute('data-src') || '';
      if (initialSrc) {
        firstSource.setAttribute('src', initialSrc);
        mediaEl.setAttribute('src', initialSrc);
      }
    }

    if (items.length) {
      items.forEach(function (btn, i) {
        btn.addEventListener('click', function () { loadIndex(i, true); });
      });
      // پخش خودکار بعدی
      mediaEl.addEventListener('ended', function () {
        if (current < items.length - 1) loadIndex(current + 1, true);
      });
    }

    if (opts.prevBtn) opts.prevBtn.addEventListener('click', function () { loadIndex(current - 1, true); });
    if (opts.nextBtn) opts.nextBtn.addEventListener('click', function () { loadIndex(current + 1, true); });
  }

  document.addEventListener('DOMContentLoaded', function () {
    initPlayer(
      document.getElementById('mainAudio'),
      document.getElementById('audioPlaylist'),
      {
        prevBtn: document.getElementById('audioPrevBtn'),
        nextBtn: document.getElementById('audioNextBtn'),
        counterEl: document.getElementById('audioCounter'),
        downloadEl: document.getElementById('audioDownload'),
      }
    );
    initPlayer(
      document.getElementById('mainVideo'),
      document.getElementById('videoPlaylist'),
      {
        prevBtn: document.getElementById('videoPrevBtn'),
        nextBtn: document.getElementById('videoNextBtn'),
        counterEl: document.getElementById('videoCounter'),
      }
    );

    // Lazy-load سراسری برای تصاویر بدون loading="lazy"
    document.querySelectorAll('img:not([loading])').forEach(function (img) {
      img.setAttribute('loading', 'lazy');
      img.setAttribute('decoding', 'async');
    });
  });
})();
