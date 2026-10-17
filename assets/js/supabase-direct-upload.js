/* Supabase direct/resumable upload bridge for the PHP admin forms. */
(function () {
  'use strict';

  // Leave enough room for the multipart boundary and ordinary form fields on Vercel.
  var DIRECT_THRESHOLD = 3.5 * 1024 * 1024;
  var CHUNK_SIZE = 6 * 1024 * 1024;
  var verifiedFiles = new WeakMap();
  var csrfSelector = 'input[type="hidden"][name="csrf_token"], input[type="hidden"][name="_token"]';

  function csrfValue(formData) {
    if (formData) {
      var found = '';
      formData.forEach(function (value, key) {
        if (!found && typeof value === 'string' && /token|csrf/i.test(key) && value.length >= 20) found = value;
      });
      if (found) return found;
    }
    var input = document.querySelector(csrfSelector);
    return input && input.value ? input.value : '';
  }

  function kindForInput(input, file) {
    var s = ((input && (input.name + ' ' + input.accept)) || '').toLowerCase();
    var ext = (file.name.split('.').pop() || '').toLowerCase();
    if (['jpg','jpeg','png','gif','webp'].indexOf(ext) >= 0) return 'image';
    if (['mp3','ogg','wav','m4a'].indexOf(ext) >= 0) return 'audio';
    if (['mp4','webm','mov','mkv'].indexOf(ext) >= 0) return 'video';
    if (['doc','docx'].indexOf(ext) >= 0) return 'word';
    return 'pdf';
  }

  function activeFiles(form) {
    var entries = [];
    var total = 0;
    form.querySelectorAll('input[type="file"]').forEach(function (input) {
      if (input.disabled || !input.name) return;
      Array.prototype.forEach.call(input.files || [], function (file) {
        if (!file || !file.name || file.size < 1) return;
        entries.push({ input: input, file: file });
        total += file.size;
      });
    });
    return { entries: entries, total: total };
  }

  function absolutePath(path) {
    return String(path || '').replace(/^\/+/, '');
  }

  async function sign(file, kind, csrf) {
    var body = new URLSearchParams();
    body.set('action', 'sign');
    var token = csrf || csrfValue();
    if (token) body.set('csrf_token', token);
    body.set('name', file.name);
    body.set('size', String(file.size));
    body.set('mime', file.type || 'application/octet-stream');
    body.set('kind', kind);
    var r = await fetch('/admin/storage/direct', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
      body: body.toString()
    });
    var data = await r.json().catch(function(){ return {}; });
    if (!r.ok || !data.ok) throw new Error(data.error || 'دریافت مجوز آپلود مستقیم ناموفق بود.');
    if (!data.token || !data.path || !data.bucket) {
      throw new Error('مجوز Storage ناقص است؛ فایل ارسال نشد.');
    }
    if (!data.tus_endpoint && !data.signed_url) {
      throw new Error('مجوز Storage ناقص است؛ فایل ارسال نشد.');
    }
    return data;
  }

  function b64(s) {
    return btoa(unescape(encodeURIComponent(s)));
  }

  /**
   * One-shot signed upload: the browser hands the whole file to Storage in a
   * single PUT against the signed URL the server issued.
   *
   * This is the documented fallback for the resumable route. It is also the
   * only path that is safe on Vercel for a large file, because the bytes go
   * straight from the browser to Storage and never touch the function's
   * request body.
   */
  async function signedPutUpload(file, grant, status) {
    if (!grant.signed_url) throw new Error('نشانی آپلود امضاشده در دسترس نیست.');
    if (status) status.textContent = 'در حال آپلود مستقیم «' + file.name + '»…';
    var put = await fetch(grant.signed_url, {
      method: 'PUT',
      headers: {
        'Content-Type': grant.mime || file.type || 'application/octet-stream',
        'x-upsert': 'false',
        'cache-control': 'max-age=3600'
      },
      body: file
    });
    if (!put.ok) {
      var body = await put.text().catch(function(){ return ''; });
      throw new Error(storageErrorMessage(put.status, body) || 'آپلود امضاشدهٔ فایل ناموفق بود.');
    }
    if (status) status.textContent = 'آپلود مستقیم «' + file.name + '» کامل شد.';
  }

  /** Turn a Storage answer into a message an editor can act on. */
  function storageErrorMessage(status, body) {
    var text = String(body || '');
    if (/invalid compact jws/i.test(text)) {
      return 'Storage توکن آپلود امضاشده را نپذیرفت (Invalid Compact JWS). تنظیمات Supabase پروژه را بررسی کنید.';
    }
    if (status === 0) return '';
    if (!text) return '';
    try {
      var parsed = JSON.parse(text);
      if (parsed && (parsed.message || parsed.error)) {
        return String(parsed.message || parsed.error);
      }
    } catch (_) {
      /* not JSON — show the raw text */
    }
    return text.length > 300 ? text.slice(0, 300) : text;
  }

  async function tusUpload(file, grant, status) {
    var meta = [
      'bucketName ' + b64(grant.bucket),
      'objectName ' + b64(absolutePath(grant.path)),
      'contentType ' + b64(grant.mime || file.type || 'application/octet-stream')
    ].join(',');
    var create = await fetch(grant.tus_endpoint, {
      method: 'POST',
      headers: {
        'Tus-Resumable': '1.0.0',
        'Upload-Length': String(file.size),
        'Upload-Metadata': meta,
        'x-signature': grant.token,
        'x-upsert': 'false'
      }
    });
    if (!create.ok) {
      var msg = await create.text().catch(function(){ return ''; });
      var detail = storageErrorMessage(create.status, msg);
      var failure = new Error(detail || 'شروع آپلود resumable ناموفق بود.');
      failure.fallbackAllowed = [404,405,501].indexOf(create.status) >= 0;
      throw failure;
    }
    var location = create.headers.get('Location');
    if (!location) throw new Error('Storage نشانی ادامهٔ آپلود را برنگرداند.');
    location = new URL(location, grant.tus_endpoint).toString();
    if (new URL(location).origin !== new URL(grant.tus_endpoint).origin) throw new Error('مبدأ ادامهٔ آپلود معتبر نیست.');
    grant.tusCreated = true;

    var offset = 0;
    var retries = 0;
    while (offset < file.size) {
      var end = Math.min(offset + CHUNK_SIZE, file.size);
      var chunk = file.slice(offset, end);
      var patch = await fetch(location, {
        method: 'PATCH',
        headers: {
          'Tus-Resumable': '1.0.0',
          'Upload-Offset': String(offset),
          'Content-Type': 'application/offset+octet-stream',
          // The signed token authorises every request of this upload, not
          // only its creation; without it the chunk is rejected.
          'x-signature': grant.token
        },
        body: chunk
      }).catch(function(){ return {ok:false,status:0,text:async function(){return '';}}; });

      if (!patch.ok) {
        // Ask the TUS server how many bytes it accepted before retrying.
        var head = await fetch(location, {
          method: 'HEAD',
          headers: {'Tus-Resumable': '1.0.0', 'x-signature': grant.token}
        }).catch(function(){ return null; });
        if (head && head.ok) {
          var serverOffset = parseInt(head.headers.get('Upload-Offset') || '', 10);
          if (Number.isFinite(serverOffset) && serverOffset > offset && serverOffset <= file.size) {
            offset = serverOffset;
            retries = 0;
            continue;
          }
          if (serverOffset === offset && retries < 3) {
            retries++;
            await new Promise(function(resolve){ setTimeout(resolve, retries * 500); });
            continue;
          }
        }
        var patchBody = await patch.text().catch(function(){ return ''; });
        throw new Error(storageErrorMessage(patch.status, patchBody) || 'ادامهٔ آپلود فایل در Storage ناموفق بود.');
      }
      var next = parseInt(patch.headers.get('Upload-Offset') || '', 10);
      if (!Number.isFinite(next) || next <= offset || next > file.size) {
        throw new Error('Storage آفست معتبر برنگرداند.');
      }
      offset = next;
      retries = 0;
      if (status) {
        var percent = file.size > 0 ? Math.min(100, Math.round((offset / file.size) * 100)) : 100;
        status.textContent = 'در حال آپلود مستقیم «' + file.name + '» — ' + percent + '%';
      }
    }
    if (offset !== file.size) throw new Error('آپلود فایل در Storage کامل نشد.');
  }

  /**
   * Upload one file to Storage, resumable first and signed PUT second.
   *
   * The resumable route is preferred because it survives a dropped connection;
   * the signed PUT is the documented fallback for a Storage deployment whose
   * resumable route is unavailable. Both paths keep the file bytes out of the
   * Vercel function, so neither can trip the 4.5 MB request-body limit.
   */
  async function uploadToStorage(file, grant, status) {
    try {
      await tusUpload(file, grant, status);
      return;
    } catch (error) {
      var message = error && error.message ? error.message : '';
      // Only a protocol/endpoint level failure justifies the fallback; a
      // rejected file (wrong type, quota, existing object) must not be
      // uploaded a second time.
      var resumableBroken = error.fallbackAllowed || (error instanceof TypeError && !grant.tusCreated);
      if (!resumableBroken || !grant.signed_url) throw error;
      if (status) status.textContent = 'آپلود resumable در دسترس نبود؛ آپلود امضاشدهٔ مستقیم…';
      await signedPutUpload(file, grant, status);
    }
  }

  async function finalizeUpload(grant, fileSize, csrf, useNativeFetch) {
    var verifyBody = new URLSearchParams();
    verifyBody.set('action', 'finalize');
    var token = csrf || csrfValue();
    if (token) verifyBody.set('csrf_token', token);
    verifyBody.set('path', grant.path);
    verifyBody.set('size', String(fileSize));
    var send = useNativeFetch || window.fetch;
    var verify = await send('/admin/storage/direct', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
      body: verifyBody.toString()
    });
    var result = await verify.json().catch(function(){ return {}; });
    if (!verify.ok || !result.ok) throw new Error(result.error || 'تأیید فایل مستقیم ناموفق بود.');
    if (Number(result.size) !== fileSize) throw new Error('حجم تأییدشدهٔ Storage با فایل اصلی برابر نیست.');
    return grant.path;
  }

  function addHidden(form, field, value) {
    var name = 'jhd_direct[' + field + '][]';
    var existing = Array.prototype.slice.call(form.querySelectorAll('input[type="hidden"]'));
    if (existing.some(function(item){ return item.name === name && item.value === value; })) return;
    var input = document.createElement('input');
    input.dataset.jhdDirectReference = field;
    input.type = 'hidden';
    input.name = 'jhd_direct[' + field + '][]';
    input.value = value;
    form.appendChild(input);
  }

  function replaceSelectedFiles(input, keep, original) {
    if (keep.length === original.length) return;
    var assigned = false;
    try {
      if (typeof DataTransfer === 'function') {
        var dt = new DataTransfer();
        keep.forEach(function (file) { dt.items.add(file); });
        input.files = dt.files;
        var actual = Array.prototype.slice.call(input.files || []);
        assigned = actual.length === keep.length && actual.every(function (file, index) {
          return file === keep[index];
        });
      }
    } catch (_) {
      assigned = false;
    }

    if (!assigned) {
      // Older mobile browsers may not permit replacing FileList. Clearing is
      // safe for large-file privacy, but do not silently drop selected small files.
      input.value = '';
      var afterClear = Array.prototype.slice.call(input.files || []);
      if (afterClear.some(function (file) { return file.size > DIRECT_THRESHOLD; })) {
        throw new Error('مرورگر نتوانست فایل بزرگ را از فرم جدا کند؛ فرم ارسال نشد.');
      }
      if (keep.length > 0) {
        throw new Error('آپلود مستقیم انجام شد، اما مرورگر قدیمی اجازهٔ نگه‌داشتن فایل‌های کوچک را نداد. فایل‌های کوچک را جداگانه دوباره انتخاب کنید.');
      }
    }

    var remaining = Array.prototype.slice.call(input.files || []);
    if (remaining.some(function (file) { return file.size > DIRECT_THRESHOLD; })) {
      input.value = '';
      remaining = Array.prototype.slice.call(input.files || []);
      if (remaining.some(function (file) { return file.size > DIRECT_THRESHOLD; })) {
        throw new Error('فایل اصلی هنوز به فرم متصل است؛ برای جلوگیری از خطای 413 ارسال متوقف شد.');
      }
      if (keep.length > 0) {
        throw new Error('مرورگر نتوانست فایل‌های کوچک را نگه دارد؛ آن‌ها را جداگانه دوباره انتخاب کنید.');
      }
    }
    if (assigned) {
      var finalFiles = Array.prototype.slice.call(input.files || []);
      if (finalFiles.length !== keep.length || !finalFiles.every(function(file,index){return file === keep[index];})) {
        input.value = '';
        throw new Error('فهرست فایل‌های فرم پس از آپلود مستقیم قابل تأیید نیست؛ ارسال متوقف شد.');
      }
    }
  }

  function visibleStatus(form) {
    var status = document.querySelector('[data-jhd-direct-status-global]');
    if (!status) {
      status = document.createElement('div');
      status.setAttribute('data-jhd-direct-status-global', '');
      status.setAttribute('role', 'status');
      status.setAttribute('aria-live', 'polite');
      status.className = 'alert alert-info shadow';
      status.style.position = 'fixed';
      status.style.top = '12px';
      status.style.left = '12px';
      status.style.right = '12px';
      status.style.maxWidth = '760px';
      status.style.margin = '0 auto';
      status.style.zIndex = '2147483647';
      status.style.whiteSpace = 'normal';
      status.style.overflowWrap = 'anywhere';
      document.body.appendChild(status);
    }
    if (form) status.dataset.formId = form.id || '';
    return status;
  }

  function setStatus(status, message, failed) {
    status.className = failed ? 'alert alert-danger shadow' : 'alert alert-info shadow';
    status.textContent = message;
    status.setAttribute('role', failed ? 'alert' : 'status');
    status.style.display = 'block';
  }

  function setSubmitControlsDisabled(controls, disabled) {
    controls.forEach(function (item) {
      item.control.disabled = disabled ? true : item.wasDisabled;
    });
  }

  function formHandler(event) {
    var target = event.target;
    var form = target && target.closest ? target.closest('form') : null;
    if (!form) return;

    // A second submit while TUS is still uploading must never fall through to
    // the browser's native multipart request.
    if (form.dataset.jhdDirectBusy === '1') {
      event.preventDefault();
      return;
    }
    if (form.dataset.jhdDirectReady === '1') return;

    var selected = activeFiles(form);
    // Moving the complete selection when its combined size crosses the safe
    // threshold also protects multi-file forms from aggregate 413 responses.
    if (selected.total <= DIRECT_THRESHOLD) return;

    event.preventDefault();
    form.dataset.jhdDirectBusy = '1';
    var submitter = event.submitter || null;
    var status = visibleStatus(form);
    var submitControls = Array.prototype.slice.call(form.querySelectorAll('button[type="submit"], input[type="submit"], button:not([type])'))
      .map(function (control) { return { control: control, wasDisabled: control.disabled }; });
    setSubmitControlsDisabled(submitControls, true);
    setStatus(status, 'آپلود مستقیم آغاز شد؛ این صفحه را نبندید. فایل‌ها به Storage منتقل می‌شوند، نه به Vercel.', false);

    (async function () {
      // Snapshot every File before touching FileList; when the aggregate is
      // above threshold all named files are moved, not merely the largest one.
      var directAll = selected.total > DIRECT_THRESHOLD;
      var inputs = Array.prototype.slice.call(form.querySelectorAll('input[type="file"]'));
      for (var i = 0; i < inputs.length; i++) {
        var input = inputs[i];
        if (input.disabled || !input.name) continue;
        var files = Array.prototype.slice.call(input.files || []);
        var directFiles = files.filter(function(file) {
          return file && file.name && file.size > 0 && (directAll || file.size > DIRECT_THRESHOLD);
        });
        for (var j = 0; j < directFiles.length; j++) {
          var file = directFiles[j];
          var kind = kindForInput(input, file);
          status.textContent = 'در حال آپلود مستقیم «' + file.name + '»…';
          var path = verifiedFiles.get(file);
          if (!path) {
            var csrf = csrfValue(new FormData(form));
            var grant = await sign(file, kind, csrf);
            await uploadToStorage(file, grant, status);
            path = await finalizeUpload(grant, file.size, csrf, false);
            verifiedFiles.set(file, path);
          }
          addHidden(form, input.name.replace(/\[\]$/, ''), path);
        }
        var keep = files.filter(function(file) { return directFiles.indexOf(file) === -1; });
        if (keep.length !== files.length) {
          replaceSelectedFiles(input, keep, files);
          // A cleared required file input fails native constraint validation even
          // though its upload now lives in jhd_direct. Mark that requirement as
          // satisfied by the verified staging reference.
          if (directFiles.length > 0 && keep.length === 0 && input.required) {
            input.required = false;
            input.dataset.jhdDirectSatisfiedRequired = '1';
          }
        }
      }

      var remaining = activeFiles(form);
      if (remaining.total > DIRECT_THRESHOLD
          || remaining.entries.some(function(entry){ return entry.file.size > DIRECT_THRESHOLD; })) {
        throw new Error('تأیید نهایی انجام نشد: فایل بزرگ هنوز در payload فرم باقی مانده است.');
      }
    })().then(function () {
      var remaining = activeFiles(form);
      if (remaining.total > DIRECT_THRESHOLD
          || remaining.entries.some(function(entry){ return entry.file.size > DIRECT_THRESHOLD; })) {
        throw new Error('بررسی نهایی نشان داد که فایل بزرگ هنوز در فرم باقی است؛ برای جلوگیری از خطای 413 ارسال نشد.');
      }
      setSubmitControlsDisabled(submitControls, false);
      form.dataset.jhdDirectReady = '1';
      form.dataset.jhdDirectBusy = '0';

      // Native requestSubmit() obeys browser validation. Report invalid fields
      // explicitly; otherwise a cleared required file input looks like a dead button.
      if (!form.checkValidity()) {
        setStatus(status, 'آپلود فایل‌ها کامل شد، اما یکی از فیلدهای اجباری فرم تکمیل نیست. فیلد مشخص‌شده را بررسی کنید.', true);
        if (typeof form.reportValidity === 'function') form.reportValidity();
        form.dataset.jhdDirectReady = '0';
        return;
      }

      setStatus(status, 'آپلود کامل شد؛ در حال ذخیرهٔ مطلب…', false);
      try {
        if (typeof form.requestSubmit === 'function') {
          if (submitter && submitter.form === form && !submitter.disabled) form.requestSubmit(submitter);
          else form.requestSubmit();
        } else {
          form.submit();
        }
      } catch (submitError) {
        form.dataset.jhdDirectReady = '0';
        setStatus(status, 'فایل‌ها آپلود شدند، اما ارسال فرم انجام نشد: ' + (submitError && submitError.message ? submitError.message : 'خطای مرورگر'), true);
      }
    }).catch(function (err) {
      form.dataset.jhdDirectBusy = '0';
      setSubmitControlsDisabled(submitControls, false);
      var message = err instanceof TypeError
        ? 'ارتباط با Storage برقرار نشد یا قطع شد (شبکه/CORS). ذخیرهٔ مطلب انجام نشد؛ اطلاعات فرم حفظ است. دوباره تلاش کنید.'
        : (err && err.message ? err.message : 'آپلود مستقیم ناموفق بود؛ فرم ارسال نشد.');
      setStatus(status, message, true);
    });
  }

  // AJAX endpoints (gallery/media management): move the selected batch to TUS
  // when its combined size exceeds the same safe threshold.
  var nativeFetch = window.fetch.bind(window);
  window.fetch = async function (input, init) {
    var body = init && init.body;
    var targetUrl = new URL(typeof input === 'string' || input instanceof URL ? input : input.url, window.location.href);
    if (targetUrl.origin !== window.location.origin || !targetUrl.pathname.startsWith('/admin/')) return nativeFetch(input, init);
    if (!(body instanceof FormData)) return nativeFetch(input, init);

    var originalEntries = [];
    var fileBytes = 0;
    body.forEach(function (value, key) {
      originalEntries.push([key, value]);
      if (value instanceof File && value.name && value.size > 0) fileBytes += value.size;
    });
    if (fileBytes <= DIRECT_THRESHOLD) return nativeFetch(input, init);

    var csrf = csrfValue(body);
    var formData = new FormData();
    var status = document.querySelector('[data-jhd-direct-status-global]');
    if (!status) {
      status = document.createElement('div');
      status.setAttribute('data-jhd-direct-status-global', '');
      status.setAttribute('role', 'status');
      status.className = 'alert alert-info position-fixed bottom-0 start-0 m-3 shadow';
      status.style.zIndex = '2000';
      document.body.appendChild(status);
    }

    for (var i = 0; i < originalEntries.length; i++) {
      var key = originalEntries[i][0], value = originalEntries[i][1];
      if (!(value instanceof File) || !value.name || value.size < 1) {
        formData.append(key, value);
        continue;
      }
      var kind = (value.type || '').indexOf('image/') === 0 ? 'image'
        : (value.type || '').indexOf('audio/') === 0 ? 'audio'
        : (value.type || '').indexOf('video/') === 0 ? 'video'
        : /\.(doc|docx)$/i.test(value.name) ? 'word' : 'pdf';
      status.textContent = 'در حال آپلود مستقیم «' + value.name + '»…';
      var grant = await signWithCsrf(value, kind, csrf);
      await uploadToStorage(value, grant, status);
      await finalizeUpload(grant, value.size, csrf, nativeFetch);
      var field = key.replace(/\[(?:\d*)\]$/, '');
      formData.append('jhd_direct[' + field + '][]', grant.path);
    }

    var remainingBytes = 0;
    formData.forEach(function(value) {
      if (value instanceof File && value.name && value.size > 0) remainingBytes += value.size;
    });
    if (remainingBytes > DIRECT_THRESHOLD) {
      throw new Error('یک فایل بزرگ در درخواست AJAX باقی مانده است؛ ارسال متوقف شد.');
    }

    var nextInit = Object.assign({}, init, {body: formData});
    if (nextInit.headers) {
      var h = new Headers(nextInit.headers);
      h.delete('Content-Type');
      nextInit.headers = h;
    }
    return nativeFetch(input, nextInit);
  };

  async function signWithCsrf(file, kind, csrf) {
    var body = new URLSearchParams();
    body.set('action', 'sign');
    body.set('name', file.name);
    body.set('size', String(file.size));
    body.set('mime', file.type || 'application/octet-stream');
    body.set('kind', kind);
    if (csrf) body.set('csrf_token', csrf);
    var r = await nativeFetch('/admin/storage/direct', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
      body: body.toString()
    });
    var data = await r.json().catch(function(){ return {}; });
    if (!r.ok || !data.ok) throw new Error(data.error || 'مجوز آپلود مستقیم صادر نشد.');
    if (!data.token || !data.path || !data.bucket) {
      throw new Error('مجوز Storage ناقص است؛ فایل ارسال نشد.');
    }
    if (!data.tus_endpoint && !data.signed_url) {
      throw new Error('مجوز Storage ناقص است؛ فایل ارسال نشد.');
    }
    return data;
  }

  document.addEventListener('change', function(event) {
    var input = event.target;
    if (!input || input.type !== 'file' || !input.form) return;
    var form = input.form;
    form.dataset.jhdDirectReady = '0';
    var field = input.name.replace(/\[\]$/, '');
    form.querySelectorAll('[data-jhd-direct-reference]').forEach(function(item) {
      if (item.dataset.jhdDirectReference === field) item.remove();
    });
    if (input.dataset.jhdDirectSatisfiedRequired === '1') {
      input.required = true;
      delete input.dataset.jhdDirectSatisfiedRequired;
    }
  }, true);
  document.addEventListener('submit', formHandler, true);
})();