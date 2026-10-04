/* Supabase direct/resumable upload bridge for the PHP admin forms. */
(function () {
  'use strict';

  var DIRECT_THRESHOLD = 3.5 * 1024 * 1024;
  var CHUNK_SIZE = 6 * 1024 * 1024;
  var csrfSelector = 'input[type="hidden"][name="csrf_token"], input[type="hidden"][name="_token"]';

  function csrfValue(formData) {
    var input = document.querySelector(csrfSelector);
    if (input && input.value) return input.value;
    if (formData) {
      var found = '';
      formData.forEach(function (value, key) {
        if (!found && typeof value === 'string' && /token|csrf/i.test(key) && value.length >= 20) found = value;
      });
      return found;
    }
    return '';
  }

  function kindForInput(input, file) {
    var s = ((input && (input.name + ' ' + input.accept)) || '').toLowerCase();
    var ext = (file.name.split('.').pop() || '').toLowerCase();
    if (s.indexOf('image') >= 0 || ['jpg','jpeg','png','gif','webp'].indexOf(ext) >= 0) return 'image';
    if (s.indexOf('audio') >= 0 || ['mp3','ogg','wav','m4a'].indexOf(ext) >= 0) return 'audio';
    if (s.indexOf('video') >= 0 || ['mp4','webm','mov','mkv'].indexOf(ext) >= 0) return 'video';
    if (s.indexOf('word') >= 0 || ['doc','docx'].indexOf(ext) >= 0) return 'word';
    return 'pdf';
  }

  function absolutePath(path) {
    return path.replace(/^\/+/, '');
  }

  async function sign(file, kind) {
    var body = new URLSearchParams();
    body.set('action', 'sign');
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
    return data;
  }

  function b64(s) {
    return btoa(unescape(encodeURIComponent(s)));
  }

  async function tusUpload(file, grant) {
    var meta = [
      'bucketName ' + b64(grant.bucket),
      'objectName ' + b64(absolutePath(grant.path)),
      'contentType ' + b64(file.type || 'application/octet-stream')
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
      throw new Error(msg || 'شروع آپلود resumable ناموفق بود.');
    }
    var location = create.headers.get('Location');
    if (!location) throw new Error('Storage نشانی ادامهٔ آپلود را برنگرداند.');

    var offset = 0;
    while (offset < file.size) {
      var end = Math.min(offset + CHUNK_SIZE, file.size);
      var chunk = file.slice(offset, end);
      var patch = await fetch(location, {
        method: 'PATCH',
        headers: {
          'Tus-Resumable': '1.0.0',
          'Upload-Offset': String(offset),
          'Content-Type': 'application/offset+octet-stream'
        },
        body: chunk
      });

      if (!patch.ok) {
        // Re-read the server offset so a transient network failure can resume
        // without retransmitting the already accepted bytes.
        var head = await fetch(location, {
          method: 'HEAD',
          headers: {'Tus-Resumable': '1.0.0'}
        }).catch(function(){ return null; });
        if (head && head.ok) {
          var serverOffset = parseInt(head.headers.get('Upload-Offset') || '', 10);
          if (Number.isFinite(serverOffset) && serverOffset >= offset && serverOffset <= file.size) {
            offset = serverOffset;
            continue;
          }
        }
        throw new Error('ادامهٔ آپلود فایل در Storage ناموفق بود.');
      }
      var next = parseInt(patch.headers.get('Upload-Offset') || '', 10);
      if (!Number.isFinite(next) || next <= offset) throw new Error('Storage آفست معتبر برنگرداند.');
      offset = next;
    }
  }

  async function uploadFile(file, kind, status) {
    status.textContent = 'در حال آپلود مستقیم «' + file.name + '»…';
    var grant = await sign(file, kind);
    await tusUpload(file, grant);
    var verifyBody = new URLSearchParams();
    verifyBody.set('action', 'finalize');
    verifyBody.set('path', grant.path);
    verifyBody.set('size', String(file.size));
    var verify = await fetch('/admin/storage/direct', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
      body: verifyBody.toString()
    });
    var result = await verify.json().catch(function(){ return {}; });
    if (!verify.ok || !result.ok) throw new Error(result.error || 'تأیید فایل مستقیم ناموفق بود.');
    return grant.path;
  }

  function addHidden(form, field, value) {
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'jhd_direct[' + field + '][]';
    input.value = value;
    form.appendChild(input);
  }

  async function prepareForm(form, submitter, status) {
    var inputs = Array.prototype.slice.call(form.querySelectorAll('input[type="file"]'));
    for (var i = 0; i < inputs.length; i++) {
      var input = inputs[i];
      var files = Array.prototype.slice.call(input.files || []);
      for (var j = 0; j < files.length; j++) {
        var file = files[j];
        if (file.size <= DIRECT_THRESHOLD) continue;
        var kind = kindForInput(input, file);
        var path = await uploadFile(file, kind, status);
        addHidden(form, input.name.replace(/\[\]$/, ''), path);
      }
      // Keep small files for the original PHP multipart path; remove only
      // files already copied directly to Supabase.
      var keep = files.filter(function (f) { return f.size <= DIRECT_THRESHOLD; });
      if (keep.length !== files.length) {
        try {
          var dt = new DataTransfer();
          keep.forEach(function (f) { dt.items.add(f); });
          input.files = dt.files;
        } catch (_) {
          input.value = '';
        }
      }
    }
  }

  function formHandler(event) {
    var form = event.target.closest ? event.target.closest('form[enctype="multipart/form-data"]') : null;
    if (!form || form.dataset.jhdDirectBusy === '1' || form.dataset.jhdDirectReady === '1') return;
    var hasLarge = false;
    form.querySelectorAll('input[type="file"]').forEach(function (input) {
      Array.prototype.forEach.call(input.files || [], function (file) {
        if (file.size > DIRECT_THRESHOLD) hasLarge = true;
      });
    });
    if (!hasLarge) return;

    event.preventDefault();
    form.dataset.jhdDirectBusy = '1';
    var submitter = event.submitter;
    var status = form.querySelector('[data-jhd-direct-status]');
    if (!status) {
      status = document.createElement('div');
      status.setAttribute('data-jhd-direct-status', '');
      status.setAttribute('role', 'status');
      status.setAttribute('aria-live', 'polite');
      status.className = 'alert alert-info py-2 mt-3';
      form.appendChild(status);
    }

    prepareForm(form, submitter, status).then(function () {
      status.textContent = 'آپلود مستقیم کامل شد؛ در حال ذخیرهٔ اطلاعات…';
      form.dataset.jhdDirectReady = '1';
      form.dataset.jhdDirectBusy = '0';
      if (submitter && typeof form.requestSubmit === 'function') form.requestSubmit(submitter);
      else form.submit();
    }).catch(function (err) {
      form.dataset.jhdDirectBusy = '0';
      status.className = 'alert alert-danger py-2 mt-3';
      status.textContent = err && err.message ? err.message : 'آپلود مستقیم ناموفق بود.';
    });
  }

  // AJAX endpoints that send FormData (gallery/media management): lift large
  // File objects out of the request body and replace them with jhd_direct keys.
  var nativeFetch = window.fetch.bind(window);
  window.fetch = async function (input, init) {
    var body = init && init.body;
    if (!(body instanceof FormData)) return nativeFetch(input, init);

    var originalEntries = [];
    var hasLarge = false;
    body.forEach(function (value, key) {
      originalEntries.push([key, value]);
      if (value instanceof File && value.size > DIRECT_THRESHOLD) hasLarge = true;
    });
    if (!hasLarge) return nativeFetch(input, init);

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
      if (!(value instanceof File) || value.size <= DIRECT_THRESHOLD) {
        formData.append(key, value);
        continue;
      }
      var kind = (value.type || '').indexOf('image/') === 0 ? 'image'
        : (value.type || '').indexOf('audio/') === 0 ? 'audio'
        : (value.type || '').indexOf('video/') === 0 ? 'video'
        : /\.(doc|docx)$/i.test(value.name) ? 'word' : 'pdf';
      var grant = await signWithCsrf(value, kind, csrf);
      status.textContent = 'در حال آپلود مستقیم «' + value.name + '»…';
      await tusUpload(value, grant);
      var verifyBody = new URLSearchParams();
      verifyBody.set('action', 'finalize'); verifyBody.set('path', grant.path); verifyBody.set('size', String(value.size));
      var verify = await nativeFetch('/admin/storage/direct', {
        method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
        body: verifyBody.toString()
      });
      var verified = await verify.json().catch(function(){ return {}; });
      if (!verify.ok || !verified.ok) throw new Error(verified.error || 'تأیید فایل مستقیم ناموفق بود.');
      formData.append('jhd_direct[' + key.replace(/\[\]$/, '') + '][]', grant.path);
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
    body.set('action','sign'); body.set('name',file.name); body.set('size',String(file.size));
    body.set('mime',file.type || 'application/octet-stream'); body.set('kind',kind);
    if (csrf) body.set('csrf_token', csrf);
    var r = await nativeFetch('/admin/storage/direct', {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()});
    var data = await r.json().catch(function(){ return {}; });
    if (!r.ok || !data.ok) throw new Error(data.error || 'مجوز آپلود مستقیم صادر نشد.');
    return data;
  }

  document.addEventListener('submit', formHandler, true);
})();