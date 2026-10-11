  </div><!-- /.admin-content -->
</main><!-- /.admin-main -->

<?php
/*
 * نوار تب پایین — چهار کار پرکاربرد + منو، در فاصلهٔ انگشت شست.
 * روی دسکتاپ با CSS نمایش داده نمی‌شود؛ اینجا هم فقط برای کارکنان ساخته
 * می‌شود که واقعاً دسترسیِ مربوطه را دارند (تب خالی نمی‌گذاریم).
 */
$jhdAdminTabs = [];
$jhdAdminTabs[] = [
    'label' => 'داشبورد', 'icon' => 'bi-speedometer2', 'href' => adminDashboardUrl(),
    'active' => !empty($adminDashboardActive),
];
$jhdAdminTabs[] = [
    'label' => 'محتوا', 'icon' => 'bi-journal-text', 'href' => adminUrl('content'),
    'active' => !empty($adminPostsActive) || !empty($adminNewsActive) || !empty($adminArticlesActive),
    'can' => 'content',
];
if (jhd_can('content')) {
    $jhdAdminTabs[] = [
        'label' => 'افزودن', 'icon' => 'bi-plus-lg', 'href' => adminUrl('content/create'),
        'active' => false, 'highlight' => true,
    ];
}
$jhdAdminTabs[] = [
    'label' => 'رسانه', 'icon' => 'bi-images', 'href' => adminUrl('uploads'),
    'active' => !empty($adminUploadsActive) || !empty($adminVideosActive) || !empty($adminAudiosActive),
    'can' => 'media',
];
if (!empty($adminUnreadMessages)) {
    $jhdAdminTabs[] = [
        'label' => 'پیام‌ها', 'icon' => 'bi-envelope', 'href' => adminUrl('messages'),
        'active' => !empty($adminMessagesActive), 'count' => (int)$adminUnreadMessages, 'can' => 'messages',
    ];
}
?>
<nav class="admin-tabbar" aria-label="دسترسی سریع پنل مدیریت">
    <?php foreach ($jhdAdminTabs as $tab): ?>
    <?php if (!empty($tab['can']) && !jhd_can($tab['can'])) continue; ?>
    <a class="<?= !empty($tab['active']) ? 'is-active' : '' ?><?= !empty($tab['highlight']) ? ' admin-tabbar__add' : '' ?>"
       href="<?= sanitize($tab['href']) ?>"<?= !empty($tab['active']) ? ' aria-current="page"' : '' ?>>
        <i class="bi <?= sanitize($tab['icon']) ?>" aria-hidden="true"></i>
        <span><?= sanitize($tab['label']) ?><?php if (isset($tab['count'])): ?>
            <b class="admin-tabbar__badge"><?= number_format((int)$tab['count']) ?></b><?php endif; ?></span>
    </a>
    <?php endforeach; ?>
    <button type="button" id="adminTabMenu" class="admin-tabbar__menu" data-admin-drawer="adminSidebar" aria-controls="adminSidebar" aria-expanded="false" aria-label="منوی کامل پنل">
        <i class="bi bi-grid" aria-hidden="true"></i>
        <span>منو</span>
    </button>
</nav>

<script src="<?= asset('vendor/bootstrap.bundle.min.js') ?>"></script>
<script>
// برچسب ستون را روی هر سلول می‌نویسد تا جدول روی گوشی «کارت» شود:
// `.admin-table td::before { content: attr(data-label) }` در assets/css/admin.css.
// تنها مرجع، خودِ <th> است؛ پس هیچ قالب فهرستی نیاز به ویرایش ندارد.
document.querySelectorAll('table.admin-table').forEach(function (table) {
    var heads = Array.prototype.map.call(table.querySelectorAll('thead th'), function (th) {
        return (th.getAttribute('data-label') || th.textContent || '').trim().replace(/\s+/g, ' ');
    });
    table.querySelectorAll('tbody tr').forEach(function (row) {
        Array.prototype.forEach.call(row.children, function (cell, index) {
            if (cell.tagName !== 'TD' || cell.hasAttribute('data-label')) return;
            if (!heads[index]) return;
            cell.setAttribute('data-label', heads[index]);
        });
    });
});

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
