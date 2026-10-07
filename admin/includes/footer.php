  </div><!-- /.admin-content -->
</main><!-- /.admin-main -->

<script src="<?= asset('vendor/bootstrap.bundle.min.js') ?>"></script>
<script>
// Auto dismiss alerts
document.querySelectorAll('.alert-auto-dismiss').forEach(function(el) {
    setTimeout(function() {
        el.style.transition = 'opacity .5s ease';
        el.style.opacity = '0';
        setTimeout(function() { el.remove(); }, 500);
    }, 4000);
});

// Confirm delete
document.querySelectorAll('[data-confirm]').forEach(function(el) {
    el.addEventListener('click', function(e) {
        if (!confirm(this.dataset.confirm || 'آیا اطمینان دارید؟')) e.preventDefault();
    });
});

// Search is progressive enhancement: every topic is rendered server-side;
// filtering only narrows the on-screen checklist and never truncates records.
document.querySelectorAll('[data-topic-filter]').forEach(function (input) {
    var list = document.getElementById(input.dataset.topicTarget || '');
    if (!list) return;
    var apply = function () {
        var needle = input.value.trim().toLocaleLowerCase();
        list.querySelectorAll('label.form-check').forEach(function (label) {
            var selected = !!label.querySelector('input:checked');
            label.hidden = !!needle && !selected && !label.textContent.toLocaleLowerCase().includes(needle);
        });
    };
    input.addEventListener('input', apply);
    apply();
});

document.querySelectorAll('[data-admin-table-filter]').forEach(function (input) {
    var table = document.getElementById(input.getAttribute('data-admin-table-filter') || '');
    if (!table) return;
    input.addEventListener('input', function () {
        var needle = input.value.trim().toLocaleLowerCase();
        table.querySelectorAll('tbody tr').forEach(function (tr) {
            tr.hidden = !!needle && !tr.textContent.toLocaleLowerCase().includes(needle);
        });
    });
});

document.querySelectorAll('[data-admin-list-filter]').forEach(function (input) {
    var list = document.getElementById(input.getAttribute('data-admin-list-filter') || '');
    if (!list) return;
    input.addEventListener('input', function () {
        var needle = input.value.trim().toLocaleLowerCase();
        list.querySelectorAll('.admin-msg-card').forEach(function (card) {
            card.hidden = !!needle && !card.textContent.toLocaleLowerCase().includes(needle);
        });
    });
});

(function () {
    var nameInput = document.getElementById('topicNameInput');
    var preview = document.getElementById('topicPreviewName');
    if (!nameInput || !preview) return;
    nameInput.addEventListener('input', function () {
        preview.textContent = nameInput.value.trim() || 'نام موضوع';
    });
})();

// Preview image before upload
function previewImg(input, previewId) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            var prev = document.getElementById(previewId);
            if (prev) { prev.src = e.target.result; prev.style.display = 'block'; }
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// Client-side guard mirrors the application limits. Large files are not sent
// through the Vercel PHP function; supabase-direct-upload.js moves them to
// Supabase Storage via TUS before the form/AJAX request is submitted.
document.querySelectorAll('input[type="file"]').forEach(function (input) {
    input.addEventListener('change', function () {
        var videoLimit = <?= (int)MAX_VIDEO_SIZE ?>;
        var otherLimit = <?= (int)MAX_FILE_SIZE ?>;
        var accept = (input.getAttribute('accept') || '').toLowerCase();
        var name = (input.getAttribute('name') || '').toLowerCase();
        var isVideo = accept.indexOf('video') >= 0 || /video/.test(name);
        var limit = isVideo ? videoLimit : otherLimit;
        for (var i = 0; i < (this.files || []).length; i++) {
            if (this.files[i].size > limit) {
                var mb = Math.round(limit / 1024 / 1024);
                alert('فایل «' + this.files[i].name + '» از سقف ' + mb + 'MB بیشتر است.');
                this.value = '';
                return;
            }
        }
    });
});
</script>
</body>
</html>
