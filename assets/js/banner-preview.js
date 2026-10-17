/* Local, unpublished preview. No file or form data is sent to a server. */
(function () {
  'use strict';
  var form = document.querySelector('[data-banner-form]');
  var preview = document.querySelector('[data-banner-preview]');
  if (!form || !preview) return;
  var objectUrl = '';
  function update() {
    preview.querySelector('h2').textContent = form.elements.title.value;
    preview.querySelector('p').textContent = form.elements.description.value;
    var image = preview.querySelector('img');
    var file = form.elements.image.files[0];
    if (objectUrl) URL.revokeObjectURL(objectUrl);
    objectUrl = '';
    if (file && /^image\//.test(file.type)) objectUrl = URL.createObjectURL(file);
    image.src = objectUrl || image.dataset.current || '';
    image.hidden = !image.getAttribute('src');
  }
  form.addEventListener('input', update);
  form.addEventListener('change', update);
  update();
})();
