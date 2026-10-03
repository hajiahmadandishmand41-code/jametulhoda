/**
 * upload-optimize.js — کوچک‌سازی تصویر پیش از ارسال (سمت مرورگر)
 * ───────────────────────────────────────────────────────────────────────────
 * چرا لازم است:
 *   • میزبان Production این پروژه (runtime PHP روی Vercel) افزونهٔ GD را ندارد،
 *     بنابراین کوچک‌سازی سمت سرور انجام نمی‌شود.
 *   • عکس دوربین تلفن معمولاً ۳ تا ۱۰ مگابایت است؛ از سقف ۴.۵ مگابایتی بدنهٔ
 *     درخواست روی Vercel هم عبور می‌کند و آپلود با خطای مبهم شکست می‌خورد.
 *   • کارت‌های سایت هیچ‌وقت تصویری بزرگ‌تر از ~۱۶۰۰ پیکسل نشان نمی‌دهند.
 *
 * رفتار: فقط روی فیلدهای فایلِ تصویری در پنل مدیریت اجرا می‌شود؛ تصویر را تا
 * سقف تعیین‌شده کوچک و دوباره کدگذاری می‌کند (WebP در صورت پشتیبانی) و همان
 * فیلد را با نسخهٔ کوچک‌تر جایگزین می‌کند. اگر هر مرحله‌ای ناموفق باشد، فایل
 * اصلی دست‌نخورده باقی می‌ماند — این اسکریپت یک بهبود تدریجی است، نه پیش‌شرط.
 *
 * امنیت: نوع واقعی فایل همچنان در سرور با finfo و getimagesize بررسی می‌شود؛
 * این اسکریپت فقط اندازه را کم می‌کند و هیچ نقشی در اعتبارسنجی ندارد.
 */
(function () {
  'use strict';

  var MAX_EDGE = 1600;            // بزرگ‌ترین ضلعِ ذخیره‌شده (هم‌راستا با IMAGE_MAX_EDGE سرور)
  var MIN_BYTES = 260 * 1024;     // فایل‌های کوچک‌تر از این دست‌نخورده می‌مانند
  var TARGET_BYTES = 1400 * 1024; // هدفِ حجم، پایین‌تر از سقف ۴.۵MB پلتفرم
  var JPEG_QUALITY = 0.84;
  var WEBP_QUALITY = 0.82;

  if (typeof window === 'undefined' || typeof document === 'undefined') return;

  function supportsCanvas() {
    return typeof document.createElement('canvas').getContext === 'function';
  }

  function canWriteWebp() {
    try {
      var canvas = document.createElement('canvas');
      canvas.width = 1;
      canvas.height = 1;
      return canvas.toDataURL('image/webp').indexOf('data:image/webp') === 0;
    } catch (e) {
      return false;
    }
  }

  function isImageFile(file) {
    return !!file && typeof file.type === 'string' && file.type.indexOf('image/') === 0
      && file.type.indexOf('image/svg') !== 0;
  }

  function needsWork(file) {
    return file.size > MIN_BYTES || file.size > TARGET_BYTES;
  }

  function loadImage(file) {
    return new Promise(function (resolve, reject) {
      var url = URL.createObjectURL(file);
      var image = new Image();
      image.onload = function () {
        URL.revokeObjectURL(url);
        resolve(image);
      };
      image.onerror = function () {
        URL.revokeObjectURL(url);
        reject(new Error('decode failed'));
      };
      image.src = url;
    });
  }

  function toBlob(canvas, type, quality) {
    return new Promise(function (resolve) {
      if (typeof canvas.toBlob !== 'function') { resolve(null); return; }
      canvas.toBlob(function (blob) { resolve(blob); }, type, quality);
    });
  }

  function newName(original, extension) {
    var base = String(original || 'image').replace(/\.[^.]+$/, '');
    return base + '.' + extension;
  }

  /**
   * یک تصویر را کوچک می‌کند. در صورت بروز هر خطا، همان فایل اصلی برگردانده
   * می‌شود تا فرآیند آپلود هرگز به‌خاطر این بهینه‌سازی متوقف نشود.
   */
  async function optimizeImage(file) {
    if (!isImageFile(file) || !needsWork(file) || !supportsCanvas()) return file;
    var image;
    try {
      image = await loadImage(file);
    } catch (e) {
      return file;
    }
    var width = image.naturalWidth || image.width;
    var height = image.naturalHeight || image.height;
    if (!width || !height) return file;

    var scale = Math.min(1, MAX_EDGE / Math.max(width, height));
    // Start from the pixel limit; if the result is still heavy, keep shrinking.
    var attempts = [scale, scale * 0.75, scale * 0.55];
    var webp = canWriteWebp();
    var alphaRisk = file.type === 'image/png' || file.type === 'image/gif' || file.type === 'image/webp';
    if (!webp && alphaRisk) return file; // JPEG would drop transparency.

    var best = null;
    for (var i = 0; i < attempts.length; i++) {
      var factor = attempts[i];
      var canvas = document.createElement('canvas');
      canvas.width = Math.max(1, Math.round(width * factor));
      canvas.height = Math.max(1, Math.round(height * factor));
      var context = canvas.getContext('2d');
      if (!context) return file;
      context.imageSmoothingEnabled = true;
      context.imageSmoothingQuality = 'high';
      try {
        context.drawImage(image, 0, 0, canvas.width, canvas.height);
      } catch (e) {
        return file;
      }
      var type = webp ? 'image/webp' : 'image/jpeg';
      var quality = webp ? WEBP_QUALITY : JPEG_QUALITY;
      var blob = await toBlob(canvas, type, quality);
      if (!blob) return file;
      best = blob;
      if (blob.size <= TARGET_BYTES) break;
    }
    if (!best || best.size >= file.size) return file; // never make it bigger
    try {
      return new File([best], newName(file.name, webp ? 'webp' : 'jpg'), {
        type: best.type,
        lastModified: Date.now()
      });
    } catch (e) {
      return file;
    }
  }

  function replaceFiles(input, files) {
    try {
      var transfer = new DataTransfer();
      files.forEach(function (file) { transfer.items.add(file); });
      input.files = transfer.files;
      return true;
    } catch (e) {
      return false;
    }
  }

  function imageInputsOf(form) {
    var list = [];
    var inputs = form.querySelectorAll('input[type="file"]');
    for (var i = 0; i < inputs.length; i++) {
      var input = inputs[i];
      var accept = String(input.getAttribute('accept') || '');
      if (accept.indexOf('image') !== -1 || (input.name || '').indexOf('image') !== -1
        || (input.name || '').indexOf('cover') !== -1 || (input.name || '').indexOf('avatar') !== -1) {
        list.push(input);
      }
    }
    return list;
  }

  async function optimizeForm(form) {
    var inputs = imageInputsOf(form);
    var touched = [];
    for (var i = 0; i < inputs.length; i++) {
      var input = inputs[i];
      if (!input.files || !input.files.length) continue;
      var originals = Array.prototype.slice.call(input.files);
      var optimized = [];
      for (var j = 0; j < originals.length; j++) {
        optimized.push(await optimizeImage(originals[j]));
      }
      if (optimized.some(function (file, index) { return file !== originals[index]; })) {
        touched.push(input);
        replaceFiles(input, optimized);
      }
    }
    return touched;
  }

  function notify(input, message) {
    input.setAttribute('data-upload-optimize', message);
    // The preview handlers in the admin forms read File objects, so refresh them.
    try {
      input.dispatchEvent(new Event('change', { bubbles: true }));
    } catch (e) { /* ignore */ }
  }

  var busy = false;
  document.addEventListener('submit', function (event) {
    if (busy) return;
    var form = event.target;
    if (!form || form.tagName !== 'FORM' || form.dataset.uploadOptimized === 'done') return;
    if (!form.querySelector('input[type="file"]')) return;
    // Keep the button the editor pressed: some forms branch on its name/value.
    var submitter = event.submitter || null;
    event.preventDefault();
    busy = true;
    form.setAttribute('data-upload-optimizing', '1');
    var resume = function () {
      busy = false;
      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit(submitter || undefined);
      } else if (typeof form.submit === 'function') {
        form.submit();
      }
    };
    optimizeForm(form).then(function (touched) {
      form.setAttribute('data-upload-optimized', 'done');
      form.removeAttribute('data-upload-optimizing');
      busy = false;
      touched.forEach(function (input) { notify(input, 'optimized'); });
      resume();
    }).catch(function () {
      form.removeAttribute('data-upload-optimizing');
      resume();
    });
  }, true);

  window.jhdOptimizeUploads = optimizeForm;
})();
