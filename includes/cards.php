<?php
/**
 * includes/cards.php — لایهٔ اجزای محتوایی Design System
 * ───────────────────────────────────────────────────────────────────────────
 * همهٔ فهرست‌ها و صفحهٔ اصلی از همین چند تابع استفاده می‌کنند تا یک زبان بصری
 * واحد داشته باشیم: کارت، آیتم فشرده، ردیف سرمقاله‌ای، ردیف رویداد، کاشی
 * موضوع، کاشی کتاب، ردیف درس، کارت رسانه، ردیف صوت، نگارخانه، فایل‌ها،
 * اشتراک‌گذاری و حالت خالی.
 *
 * اصل: «محتوا قهرمان است» — هیچ تصویر جایگزین ساختگی تولید نمی‌کنیم؛ وقتی
 * رسانه‌ای وجود ندارد کارت به شکل متنیِ سرمقاله‌ای نمایش داده می‌شود.
 */

/* ══════════════════════════════════════════════════════════════════════════
 * ۱) هستهٔ Design System — یک کارت واحد برای تمام انواع محتوا
 * ──────────────────────────────────────────────────────────────────────────
 * هر خبر، مقاله، گزارش، پژوهش، اطلاعیه، رویداد، پرسش‌وپاسخ، کتاب، درس،
 * ویدیو، صوت و موضوع در سراسر سایت با همین یک تابع رندر می‌شود؛ بنابراین
 * اندازه، نسبت تصویر، فاصله، تایپوگرافی، دکمه‌ها و رفتار Responsive همه‌جا
 * یکسان است. هر تفاوتی فقط «گونه» (variant) همین کارت است، نه یک طرح تازه.
 * ══════════════════════════════════════════════════════════════════════════ */

/** ستون استاندارد گرید کارت‌ها (یک ستون در موبایل، دو در تبلت، سه در دسکتاپ). */
const JHD_CARD_COL = 'col-12 col-sm-6 col-lg-4';

/** آیکون پیش‌فرض هر نوع محتوا — برای قاب تصویرِ بدون عکس. */
function jhd_type_icon(string $type): string {
    return match ($type) {
        'news'         => 'bi-newspaper',
        'article'      => 'bi-file-earmark-richtext',
        'research'     => 'bi-journal-richtext',
        'report'       => 'bi-card-text',
        'announcement' => 'bi-megaphone',
        'speech'       => 'bi-mic',
        'program'      => 'bi-calendar-event',
        'religious'    => 'bi-moon-stars',
        'event'        => 'bi-calendar-event',
        'qa'           => 'bi-patch-question',
        'book'         => 'bi-book',
        'lesson'       => 'bi-mortarboard',
        'topic'        => 'bi-diagram-3',
        'video'        => 'bi-camera-video',
        'audio'        => 'bi-soundwave',
        'document'     => 'bi-file-earmark-pdf',
        'media'        => 'bi-play-circle',
        default        => 'bi-journal-text',
    };
}

/** کلاس CSS امن برای نوع محتوا. */
function jhd_type_class(string $type): string {
    $clean = preg_replace('/[^a-z]/', '', strtolower($type));
    return $clean !== '' ? $clean : 'post';
}

/**
 * پیش‌بارگذاری موضوعات چند مطلب با یک کوئری (رفع N+1 در فهرست‌های بزرگ).
 * فهرست‌ها پیش از رندر کارت‌ها این تابع را صدا می‌زنند؛ jhd_card_topics()
 * سپس از همان حافظهٔ درخواست می‌خواند و هیچ کوئری تکراری اجرا نمی‌شود.
 */
function jhd_preload_post_topics(array $posts): void {
    jhd_preload_post_images($posts);
    static $loaded = [];
    $ids = [];
    foreach ($posts as $post) {
        if (!is_array($post)) continue;
        $id = (int)($post['id'] ?? 0);
        if ($id > 0 && !isset($loaded[$id])) $ids[$id] = $id;
    }
    if (!$ids) return;
    $cache =& jhd_post_topics_cache();
    try {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = getDB()->prepare(
            "SELECT pt.post_id, t.* FROM post_topics pt JOIN topics t ON t.id = pt.topic_id
             WHERE pt.post_id IN ($marks) ORDER BY t.sort_order ASC, t.id ASC"
        );
        $stmt->execute(array_values($ids));
        foreach ($ids as $id) { $cache[$id] = $cache[$id] ?? []; $loaded[$id] = true; }
        foreach ($stmt->fetchAll() as $row) {
            $pid = (int)$row['post_id'];
            unset($row['post_id']);
            $cache[$pid][] = $row;
        }
    } catch (Throwable) {
        foreach ($ids as $id) { $cache[$id] = $cache[$id] ?? []; $loaded[$id] = true; }
    }
}

/**
 * پیش‌بارگذاری تصاویر گالری مطالب با یک Query مشترک.
 * کارت‌های عمومی در صورت داشتن چند تصویر، یک پیش‌نمایش چندتصویری شبیه فید
 * شبکه‌های اجتماعی می‌گیرند؛ خود تصاویر در لایت‌باکس داخلی باز می‌شوند.
 */
function jhd_preload_post_images(array $posts): void {
    static $loaded = [];
    $ids = [];
    foreach ($posts as $post) {
        if (!is_array($post)) continue;
        $id = (int)($post['id'] ?? 0);
        if ($id > 0 && !isset($loaded[$id])) $ids[$id] = $id;
    }
    if (!$ids) return;

    $cache =& jhd_post_images_cache();
    try {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = getDB()->prepare(
            "SELECT post_id, image_path, alt_text FROM post_images
             WHERE post_id IN ($marks) ORDER BY post_id ASC, id ASC"
        );
        $stmt->execute(array_values($ids));
        foreach ($ids as $id) {
            $cache[$id] = $cache[$id] ?? [];
            $loaded[$id] = true;
        }
        foreach ($stmt->fetchAll() as $row) {
            $pid = (int)$row['post_id'];
            $cache[$pid][] = [
                'path' => (string)($row['image_path'] ?? ''),
                'alt'  => (string)($row['alt_text'] ?? ''),
            ];
        }
    } catch (Throwable) {
        foreach ($ids as $id) {
            $cache[$id] = $cache[$id] ?? [];
            $loaded[$id] = true;
        }
    }
}

/** حافظهٔ تصاویر گالری هر مطلب در طول یک درخواست. */
function &jhd_post_images_cache(): array {
    static $cache = [];
    return $cache;
}

/** تصاویر قابل نمایش در پیش‌نمایش عمومی کارت، حداکثر ۵ تصویر. */
function jhd_card_image_set(array $post): array {
    $id = (int)($post['id'] ?? 0);
    $rows = [];
    if ($id > 0) {
        $cache =& jhd_post_images_cache();
        if (array_key_exists($id, $cache)) {
            $rows = $cache[$id];
        } else {
            try {
                $stmt = getDB()->prepare(
                    'SELECT image_path, alt_text FROM post_images WHERE post_id = ? ORDER BY id ASC'
                );
                $stmt->execute([$id]);
                $rows = array_map(static fn(array $row): array => [
                    'path' => (string)($row['image_path'] ?? ''),
                    'alt'  => (string)($row['alt_text'] ?? ''),
                ], $stmt->fetchAll());
                $cache[$id] = $rows;
            } catch (Throwable) {
                $rows = [];
                $cache[$id] = [];
            }
        }
    }

    $featured = trim((string)($post['featured_image'] ?? ''));
    $hasFeatured = false;
    foreach ($rows as $row) {
        if ($featured !== '' && (string)($row['path'] ?? '') === $featured) {
            $hasFeatured = true;
            break;
        }
    }
    if ($featured !== '' && !$hasFeatured) {
        array_unshift($rows, ['path' => $featured, 'alt' => (string)($post['title'] ?? '')]);
    }

    $out = [];
    $seen = [];
    foreach ($rows as $row) {
        $path = trim((string)($row['path'] ?? ''));
        if ($path === '' || isset($seen[$path])) continue;
        $seen[$path] = true;
        $out[] = ['path' => $path, 'alt' => (string)($row['alt'] ?? $post['title'] ?? '')];
        if (count($out) >= 5) break;
    }
    return $out;
}

/** پیش‌نمایش چندتصویری کارت؛ کلید هر تصویر لایت‌باکس داخلی را باز می‌کند. */
function jhd_card_gallery(array $images, string $title, string $badge = '', bool $eager = false): string {
    if (count($images) < 2) return '';
    $GLOBALS['JHD_NEEDS_GALLERY'] = true;
    $uid = 'jhdcg-' . substr(bin2hex(random_bytes(5)), 0, 10);
    $count = count($images);
    $shown = array_slice($images, 0, 4);
    $extra = max(0, $count - count($shown));

    $cells = '';
    foreach ($shown as $index => $image) {
        $src = imgUrl((string)$image['path']);
        if ($src === '') continue;
        $overlay = ($extra > 0 && $index === count($shown) - 1)
            ? '<span class="jhd-card-gallery__more">+' . jhd_persian_digits($extra) . '</span>'
            : '';
        $cells .= '<button type="button" class="jhd-gallery__cell jhd-card-gallery__cell" data-index="' . $index . '" aria-label="نمایش تصویر ' . jhd_persian_digits($index + 1) . ' از ' . jhd_persian_digits($count) . '">'
            . '<img src="' . sanitize($src) . '" alt="' . sanitize((string)($image['alt'] ?? $title)) . '"'
            . ($eager ? ' loading="eager" fetchpriority="high"' : ' loading="lazy"')
            . ' decoding="async" width="800" height="500">'
            . $overlay . '</button>';
    }
    if ($cells === '') return '';

    $data = [];
    foreach ($images as $image) {
        $src = imgUrl((string)$image['path']);
        if ($src === '') continue;
        $data[] = ['src' => $src, 'alt' => (string)($image['alt'] ?? $title)];
    }

    $html = '<div class="jhd-card-gallery jhd-card-gallery--' . count($shown) . '" data-jhd-gallery="' . $uid . '" aria-label="تصاویر ' . sanitize($title) . '">'
        . '<div class="jhd-card-gallery__grid">' . $cells . '</div>'
        . '<span class="jhd-card-gallery__count"><i class="bi bi-images" aria-hidden="true"></i>' . jhd_persian_digits($count) . ' تصویر</span>'
        . ($badge !== '' ? '<span class="jhd-card-badge">' . sanitize($badge) . '</span>' : '')
        . '<div class="jhd-lightbox" data-jhd-lightbox="' . $uid . '" role="dialog" aria-modal="true" aria-label="' . sanitize($title) . '" hidden>'
        . '<div class="jhd-lightbox__backdrop" data-jhd-lightbox-close></div>'
        . '<div class="jhd-lightbox__frame">'
        . '<div class="jhd-lightbox__top"><span class="jhd-lightbox__counter" data-jhd-lightbox-counter></span><div class="jhd-lightbox__tools">'
        . '<a class="jhd-lightbox__tool" data-jhd-lightbox-download href="' . sanitize($data[0]['src']) . '" download title="دانلود تصویر"><i class="bi bi-download" aria-hidden="true"></i></a>'
        . '<button type="button" class="jhd-lightbox__tool" data-jhd-lightbox-close title="بستن" aria-label="بستن"><i class="bi bi-x-lg" aria-hidden="true"></i></button>'
        . '</div></div>'
        . '<button type="button" class="jhd-lightbox__nav jhd-lightbox__nav--prev" data-jhd-lightbox-prev title="تصویر قبلی" aria-label="تصویر قبلی"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>'
        . '<figure class="jhd-lightbox__figure"><img data-jhd-lightbox-image src="" alt=""><figcaption data-jhd-lightbox-caption></figcaption></figure>'
        . '<button type="button" class="jhd-lightbox__nav jhd-lightbox__nav--next" data-jhd-lightbox-next title="تصویر بعدی" aria-label="تصویر بعدی"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>'
        . '<div class="jhd-lightbox__thumbs" data-jhd-lightbox-thumbs></div>'
        . '</div></div></div>'
        . '<script type="application/json" data-jhd-gallery-data="' . $uid . '">'
        . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)
        . '</script>';
    return $html;
}
    static $cache = [];
    return $cache;
}

function jhd_card_topics(array $post, int $limit = 2): array {
    $id = (int)($post['id'] ?? 0);
    if ($id < 1) return [];
    $cache =& jhd_post_topics_cache();
    if (!array_key_exists($id, $cache)) {
        try {
            $cache[$id] = getTopicsForPost($id);
        } catch (Throwable) {
            $cache[$id] = [];
        }
    }
    return array_slice($cache[$id], 0, $limit);
}

/** برچسب‌های موضوع به‌شکل چیپ کوچک. */
function jhd_topic_chips(array $topics): string {
    $html = '';
    foreach ($topics as $tp) {
        if (!is_array($tp) || empty($tp['name'])) continue;
        $html .= '<a class="jhd-chip" href="' . sanitize(topicUrl($tp)) . '">' . sanitize((string)$tp['name']) . '</a>';
    }
    return $html;
}

/**
 * قاب رسانهٔ کارت — همیشه با نسبت استاندارد.
 * تصویر با object-fit پوشش داده می‌شود تا هیچ‌گاه کشیده یا خراب نشود؛ جلد
 * کتاب با گونهٔ «contain» و پس‌زمینهٔ محو نمایش داده می‌شود تا کامل دیده شود.
 */
function jhd_card_media(array $card, array $opts = []): string {
    $href    = (string)($card['url'] ?? '');
    $title   = (string)($card['title'] ?? '');
    $image   = trim((string)($card['image'] ?? ''));
    $alt     = (string)($card['image_alt'] ?? $title);
    $type    = (string)($card['type'] ?? 'post');
    $badge   = (string)($card['badge'] ?? '');
    $fit     = (string)($opts['media'] ?? $card['media_fit'] ?? 'cover');
    $eager   = !empty($opts['eager']);
    $ratio   = (string)($opts['ratio'] ?? '');
    $icon    = (string)($card['icon'] ?? jhd_type_icon($type));
    $gallery = (array)($card['gallery'] ?? []);

    $classes = ['jhd-card-media'];
    if ($ratio !== '') $classes[] = 'jhd-card-media--' . $ratio;
    if ($fit === 'contain') $classes[] = 'jhd-card-media--contain';

    /*
     * Multi-image public preview: use a social-feed style mosaic instead of
     * forcing every image into a single cropped cover. Compact side cards stay
     * single-image for density.
     */
    if (count($gallery) > 1 && (($opts['variant'] ?? '') !== 'compact')) {
        $galleryHtml = jhd_card_gallery($gallery, $title, $badge, $eager);
        if ($galleryHtml !== '') {
            return $galleryHtml;
        }
    }

    $src = $image !== '' ? imgUrl($image) : '';
    $inner = '';
    if ($src !== '') {
        if ($fit === 'contain') {
            $inner .= '<span class="jhd-card-media__bg" style="background-image:url(\'' . sanitize($src) . '\')" aria-hidden="true"></span>';
        }
        $inner .= '<img src="' . sanitize($src) . '" alt="' . sanitize($alt) . '"'
            . ($eager ? ' loading="eager" fetchpriority="high"' : ' loading="lazy"')
            . ' decoding="async" width="800" height="500">';
    } else {
        $classes[] = 'jhd-card-media--empty';
        $inner .= '<span class="jhd-card-media__plate" aria-hidden="true">'
            . '<i class="bi ' . sanitize($icon) . '"></i>'
            . '<span class="jhd-card-media__mark">۞</span>'
            . '</span>';
    }
    if ($badge !== '') $inner .= '<span class="jhd-card-badge">' . sanitize($badge) . '</span>';
    if (!empty($card['duration'])) $inner .= '<span class="jhd-media-duration">' . sanitize((string)$card['duration']) . '</span>';

    $flags = '';
    if (!empty($card['has_video'])) $flags .= '<span class="jhd-card-flag" title="دارای ویدیو"><i class="bi bi-play-fill" aria-hidden="true"></i></span>';
    if (!empty($card['has_audio'])) $flags .= '<span class="jhd-card-flag" title="دارای صوت"><i class="bi bi-soundwave" aria-hidden="true"></i></span>';
    if (!empty($card['has_pdf']))   $flags .= '<span class="jhd-card-flag" title="دارای PDF"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i></span>';
    if ($flags !== '') $inner .= '<span class="jhd-card-flags">' . $flags . '</span>';
    if (!empty($card['play'])) $inner .= '<span class="jhd-media-play"><span><i class="bi bi-play-fill" aria-hidden="true"></i></span></span>';

    if ($href === '') return '<span class="' . implode(' ', $classes) . '">' . $inner . '</span>';
    return '<a class="' . implode(' ', $classes) . '" href="' . sanitize($href) . '" aria-label="' . sanitize($title) . '" tabindex="-1">' . $inner . '</a>';
}

/** خط متادیتای کارت (چیپ‌ها، نشانه‌ها و تاریخ) — یک الگو برای همهٔ انواع. */
function jhd_card_meta_line(array $card): string {
    $parts = '';
    $chips = (string)($card['chips'] ?? '');
    if ($chips !== '') $parts .= $chips;
    foreach ((array)($card['meta'] ?? []) as $meta) {
        if (is_string($meta)) { $parts .= '<span>' . sanitize($meta) . '</span>'; continue; }
        $text = trim((string)($meta['text'] ?? ''));
        if ($text === '') continue;
        $icon = (string)($meta['icon'] ?? '');
        $parts .= '<span>' . ($icon !== '' ? '<i class="bi ' . sanitize($icon) . '" aria-hidden="true"></i>' : '') . sanitize($text) . '</span>';
    }
    $date = trim((string)($card['date_label'] ?? ''));
    if ($date !== '') {
        $parts .= '<time' . (!empty($card['date_raw']) ? ' datetime="' . sanitize((string)$card['date_raw']) . '"' : '')
            . '><i class="bi bi-calendar3" aria-hidden="true"></i>' . sanitize($date) . '</time>';
    }
    return $parts === '' ? '' : '<div class="jhd-card-meta">' . $parts . '</div>';
}

/**
 * کارت واحد Design System.
 *
 * $card: url, title, summary, image, image_alt, badge, type, icon, chips,
 *        meta[], date_label, date_raw, author, author_icon, cta, duration,
 *        has_video/has_audio/has_pdf, play, media_fit, index, foot_extra
 * $opts: variant (default|featured|compact), col, eager, ratio, media, class
 */
function jhd_card(array $card, array $opts = []): string {
    $variant = (string)($opts['variant'] ?? 'default');
    $type    = (string)($card['type'] ?? 'post');
    $href    = (string)($card['url'] ?? '');
    $title   = (string)($card['title'] ?? '');
    $summary = trim((string)($card['summary'] ?? ''));
    $cta     = (string)($card['cta'] ?? 'ادامه مطلب');
    $author  = trim((string)($card['author'] ?? ''));
    $authorIcon = (string)($card['author_icon'] ?? 'bi-person');
    $index   = isset($card['index']) ? (int)$card['index'] : null;

    $classes = ['jhd-card', 'jhd-card--' . jhd_type_class($type)];
    if ($variant === 'featured') $classes[] = 'jhd-card--featured';
    if ($variant === 'compact')  $classes[] = 'jhd-card--compact';
    if (trim((string)($card['image'] ?? '')) === '') $classes[] = 'jhd-card--noimage';
    if (!empty($opts['class'])) $classes[] = (string)$opts['class'];

    $mediaOpts = $opts;
    if ($variant === 'featured' && empty($opts['ratio'])) $mediaOpts['ratio'] = 'wide';
    if ($variant === 'compact' && empty($opts['ratio'])) $mediaOpts['ratio'] = 'thumb';

    $body  = jhd_card_meta_line($card);
    $body .= '<h3 class="jhd-card-title">'
        . ($href !== '' ? '<a href="' . sanitize($href) . '">' . sanitize($title) . '</a>' : sanitize($title))
        . '</h3>';
    if ($summary !== '' && $variant !== 'compact') {
        $body .= '<p class="jhd-card-summary">' . sanitize($summary) . '</p>';
    }
    if (!empty($card['body_extra'])) $body .= (string)$card['body_extra'];

    $footLeft = $author !== ''
        ? '<span class="jhd-card-author"><i class="bi ' . sanitize($authorIcon) . '" aria-hidden="true"></i>' . sanitize($author) . '</span>'
        : (!empty($card['foot_extra']) ? (string)$card['foot_extra'] : '<span></span>');
    $footRight = $href !== ''
        ? '<a class="btn-read-more" href="' . sanitize($href) . '">' . sanitize($cta) . ' <i class="bi bi-arrow-left" aria-hidden="true"></i></a>'
        : '';
    $body .= '<div class="jhd-card-foot">' . $footLeft . $footRight . '</div>';

    $article = '<article class="' . implode(' ', $classes) . '">'
        . jhd_card_media($card, $mediaOpts)
        . '<div class="jhd-card-body">' . $body . '</div>'
        . ($index !== null ? '<span class="jhd-card-index" aria-hidden="true">' . str_pad((string)$index, 2, '0', STR_PAD_LEFT) . '</span>' : '')
        . '</article>';

    $col = array_key_exists('col', $opts) ? (string)$opts['col'] : JHD_CARD_COL;
    return $col === '' ? $article : '<div class="' . $col . '">' . $article . '</div>';
}

/** باز/بسته کردن گرید استاندارد کارت‌ها (یک فاصله و یک ریتم در کل سایت). */
function jhd_grid_open(string $extra = ''): string {
    return '<div class="row g-3 jhd-card-grid' . ($extra !== '' ? ' ' . $extra : '') . '">';
}
function jhd_grid_close(): string { return '</div>'; }

/** رندر یک فهرست کامل با کارت استاندارد. */
function jhd_card_list(array $items, callable $mapper, array $opts = []): string {
    if (!$items) return '';
    $html = jhd_grid_open((string)($opts['grid_class'] ?? ''));
    foreach ($items as $i => $item) {
        $html .= $mapper($item, $i);
    }
    return $html . jhd_grid_close();
}

/* ══════════════════════════════════════════════════════════════════════════
 * ۲) سازگاری کامل با API قبلی — همهٔ توابع قدیمی روی همان کارت واحد
 * ══════════════════════════════════════════════════════════════════════════ */

/**
 * کارت محتوایی استاندارد (خبر/مقاله/گزارش/پژوهش/اطلاعیه/پرسش‌وپاسخ/…).
 * opts: featured, compact, col, cta, excerpt, topics, url, badge, index, eager
 */
function renderPostCard(array $post, array $opts = []): string {
    $type = (string)($opts['type'] ?? ($post['post_type'] ?? 'post'));
    $variant = !empty($opts['featured']) ? 'featured' : (!empty($opts['compact']) ? 'compact' : 'default');
    $rawDate = (string)($post['published_at'] ?? $post['created_at'] ?? '');
    $topics = $opts['topics'] ?? jhd_card_topics($post, $variant === 'compact' ? 1 : 2);
    $author = trim((string)($post['author_name'] ?? '')) ?: trim((string)($post['speaker'] ?? ''));

    $meta = [];
    $location = trim((string)($post['location'] ?? ''));
    if ($location !== '') $meta[] = ['icon' => 'bi-geo-alt', 'text' => $location];
    $eventTime = trim((string)($post['event_time'] ?? ''));
    if ($eventTime !== '') $meta[] = ['icon' => 'bi-clock', 'text' => $eventTime];
    $field = trim((string)($post['research_field'] ?? ''));
    if ($field !== '') $meta[] = ['icon' => 'bi-journal-check', 'text' => $field];
    foreach ((array)($opts['meta'] ?? []) as $extraMeta) {
        if (is_array($extraMeta) && !empty($extraMeta['text'])) $meta[] = $extraMeta;
    }

    $card = [
        'type'        => $type,
        'url'         => $opts['url'] ?? postUrl($post),
        'title'       => (string)($post['title'] ?? ''),
        'summary'     => excerpt((string)($post['summary'] ?? $post['content'] ?? ''), (int)($opts['excerpt'] ?? ($variant === 'featured' ? 170 : 118))),
        'image'       => (string)($post['featured_image'] ?? ''),
        'image_alt'   => (string)($post['featured_image_alt'] ?? $post['title'] ?? ''),
        'badge'       => (string)($opts['badge'] ?? postTypeLabel($type)),
        'chips'       => jhd_topic_chips($topics),
        'meta'        => $meta,
        'date_label'  => persianDate($rawDate),
        'date_raw'    => $rawDate,
        'author'      => $author,
        'author_icon' => !empty($post['speaker']) ? 'bi-mic' : 'bi-person',
        'cta'         => (string)($opts['cta'] ?? 'ادامه مطلب'),
        'has_video'   => !empty($post['featured_video']) || !empty($post['has_video']) || !empty($opts['has_video']),
        'has_audio'   => !empty($post['has_audio']) || !empty($opts['has_audio']),
        'has_pdf'     => !empty($post['pdf_file']) || !empty($opts['has_pdf']),
        'gallery'     => jhd_card_image_set($post),
    ];
    if (isset($opts['index'])) $card['index'] = (int)$opts['index'];

    return jhd_card($card, [
        'variant' => $variant,
        'col'     => array_key_exists('col', $opts) ? (string)$opts['col'] : ($variant === 'featured' ? 'col-12' : JHD_CARD_COL),
        'eager'   => !empty($opts['eager']),
    ]);
}

/**
 * آیتم فشردهٔ کناری — همان کارت با گونهٔ compact، بنابراین رنگ، تایپوگرافی،
 * دکمه و نسبت تصویر دقیقاً با بقیهٔ سایت یکی است.
 */
function renderMiniItem(array $post, array $opts = []): string {
    $opts['compact'] = true;
    $opts['excerpt'] = $opts['excerpt'] ?? 0;
    $opts['cta'] = $opts['cta'] ?? 'مشاهده';
    if (!array_key_exists('col', $opts)) $opts['col'] = '';
    return renderPostCard($post, $opts);
}

/**
 * ردیف سرمقاله‌ای مقالات/پژوهش.
 * برخلاف کارت‌های عمومی، این نوع برای محتوای متنی فشرده است: عنوان و خلاصه
 * قهرمان‌اند، تصویر فقط یک بندانگشتی کوچک است و کل ردیف به همان URL داخلی
 * canonical وصل می‌شود. این الگو برای صفحهٔ اصلی و آرشیو مقاله/پژوهش خواناتر
 * و از نظر تراکم اطلاعات مناسب‌تر است.
 */
function renderEditorialRow(array $post, array $opts = []): string {
    $href = (string)($opts['url'] ?? postUrl($post));
    $title = (string)($post['title'] ?? '');
    $summary = excerpt(
        (string)($post['summary'] ?? $post['content'] ?? ''),
        (int)($opts['excerpt'] ?? 105)
    );
    $rawDate = (string)($post['published_at'] ?? $post['created_at'] ?? '');
    $topics = $opts['topics'] ?? jhd_card_topics($post, 2);
    $index = isset($opts['index']) ? max(0, (int)$opts['index']) : null;
    $image = trim((string)($post['featured_image'] ?? ''));
    $type = (string)($post['post_type'] ?? 'article');
    $badge = (string)($opts['badge'] ?? postTypeLabel($type));
    $meta = jhd_card_meta_line([
        'chips' => jhd_topic_chips($topics),
        'date_label' => $rawDate !== '' ? persianDate($rawDate) : '',
        'date_raw' => $rawDate,
    ]);

    $thumb = '';
    if ($image !== '') {
        $src = imgUrl($image);
        $thumb = '<a class="jhd-editorial-thumb" href="' . sanitize($href) . '"'
            . ' aria-label="' . sanitize($title) . '">'
            . '<img src="' . sanitize($src) . '" alt="' . sanitize($title) . '"'
            . ' loading="' . (!empty($opts['eager']) ? 'eager' : 'lazy') . '" decoding="async"'
            . ' width="180" height="112"></a>';
    }

    $cta = (string)($opts['cta'] ?? 'مطالعه مقاله');
    $body = '<div class="jhd-editorial-main">'
        . ($meta !== '' ? '<div class="jhd-editorial-meta">' . $meta . '</div>' : '')
        . '<h3 class="jhd-editorial-title">'
        . ($href !== '' ? '<a href="' . sanitize($href) . '">' . sanitize($title) . '</a>' : sanitize($title))
        . '</h3>'
        . ($summary !== '' ? '<p class="jhd-card-summary">' . sanitize($summary) . '</p>' : '')
        . '<a class="jhd-editorial-cta" href="' . sanitize($href) . '">'
        . sanitize($cta) . ' <i class="bi bi-arrow-left" aria-hidden="true"></i></a>'
        . '</div>';

    $number = $index !== null
        ? '<span class="jhd-row-index" aria-hidden="true">' . str_pad((string)$index, 2, '0', STR_PAD_LEFT) . '</span>'
        : '';

    return '<div class="col-12"><article class="jhd-editorial-row">'
        . $thumb . $body
        . '<span class="jhd-editorial-badge">' . sanitize($badge) . '</span>'
        . $number
        . '</article></div>';
}

/** رویداد/برنامه/اطلاعیه — همان کارت، با جعبهٔ تاریخ در متادیتا. */
function renderEventRow(array $post, array $opts = []): string {
    $rawDate = (string)($post['published_at'] ?? $post['created_at'] ?? '');
    $parts = persianDateParts($rawDate);
    $opts['cta'] = $opts['cta'] ?? 'جزییات رویداد';
    $opts['badge'] = $opts['badge'] ?? postTypeLabel((string)($post['post_type'] ?? 'program'));
    $html = renderPostCard($post, $opts);
    if (!$parts) return $html;
    // جعبهٔ تاریخ روی قاب تصویرِ همان کارت می‌نشیند (بدون طرح جداگانه).
    $box = '<span class="jhd-event-date"><strong>' . $parts['day'] . '</strong><span>' . sanitize($parts['month']) . '</span></span>';
    return preg_replace('~(<div class="jhd-card-body">)~', $box . '$1', $html, 1) ?? $html;
}

/** کاشی کتاب — همان کارت با قاب «contain» تا جلد کامل و بدون کشیدگی دیده شود. */
function renderBookCard(array $book, array $opts = []): string {
    $meta = [];
    if (!empty($book['publish_year'])) $meta[] = ['icon' => 'bi-calendar3', 'text' => 'سال ' . (string)$book['publish_year']];
    if (!empty($book['pages'])) $meta[] = ['icon' => 'bi-file-earmark-text', 'text' => number_format((int)$book['pages']) . ' صفحه'];
    $fileType = strtoupper(trim((string)($book['file_type'] ?? '')));
    if ($fileType !== '') $meta[] = ['icon' => 'bi-filetype-pdf', 'text' => $fileType];

    $card = [
        'type'        => 'book',
        'url'         => bookUrl($book),
        'title'       => (string)($book['title'] ?? ''),
        'summary'     => excerpt((string)($book['description'] ?? ''), (int)($opts['excerpt'] ?? 96)),
        'image'       => (string)($book['cover_image'] ?? ''),
        'image_alt'   => 'جلد ' . (string)($book['title'] ?? ''),
        'badge'       => 'کتاب',
        'meta'        => $meta,
        'author'      => trim((string)($book['author'] ?? '')),
        'author_icon' => 'bi-pen',
        'cta'         => (string)($opts['cta'] ?? 'مشاهده کتاب'),
        'media_fit'   => 'contain',
        'has_pdf'     => !empty($book['file_path']) || $fileType === 'PDF',
    ];
    return jhd_card($card, [
        'col'   => array_key_exists('col', $opts) ? (string)$opts['col'] : JHD_CARD_COL,
        'media' => 'contain',
        'eager' => !empty($opts['eager']),
    ]);
}

/** کارت درس — همان کارت استاندارد. */
function renderLessonCard(array $lesson, array $opts = []): string {
    $lessonNo = (int)($lesson['lesson_number'] ?? 0);
    $collection = trim((string)($lesson['collection_title'] ?? $lesson['subject'] ?? ''));
    $meta = [];
    if ($collection !== '') $meta[] = ['icon' => 'bi-collection', 'text' => $collection];
    $level = trim((string)($lesson['level'] ?? ''));
    if ($level !== '') $meta[] = ['icon' => 'bi-bar-chart', 'text' => $level];
    $sessions = (int)($lesson['session_count'] ?? 0);
    if ($sessions > 0) $meta[] = ['icon' => 'bi-list-ol', 'text' => number_format($sessions) . ' جلسه'];

    $card = [
        'type'        => 'lesson',
        'url'         => $opts['url'] ?? lessonUrl($lesson),
        'title'       => (string)($lesson['title'] ?? ''),
        'summary'     => excerpt((string)($lesson['summary'] ?? $lesson['content'] ?? ''), (int)($opts['excerpt'] ?? 96)),
        'image'       => (string)($lesson['featured_image'] ?? ''),
        'badge'       => $lessonNo > 0 ? 'جلسه ' . number_format($lessonNo) : 'درس',
        'meta'        => $meta,
        'author'      => trim((string)($lesson['teacher'] ?? '')) !== '' ? 'استاد: ' . trim((string)$lesson['teacher']) : '',
        'author_icon' => 'bi-person-video3',
        'cta'         => (string)($opts['cta'] ?? 'مشاهده درس'),
        'has_audio'   => !empty($lesson['has_audio']) || !empty($lesson['audio_file']) || !empty($lesson['audio_path']),
        'has_video'   => !empty($lesson['has_video']) || !empty($lesson['video_file']) || !empty($lesson['video_path']),
        'has_pdf'     => !empty($lesson['pdf_file']) || !empty($lesson['pdf_path']) || !empty($lesson['attachment']),
    ];
    return jhd_card($card, [
        'col'   => array_key_exists('col', $opts) ? (string)$opts['col'] : JHD_CARD_COL,
        'eager' => !empty($opts['eager']),
    ]);
}

/** ردیف درس داخل گروه دوره — همان کارت (گونهٔ فشرده در گرید یکسان). */
function renderLessonRow(array $lesson, array $opts = []): string {
    return renderLessonCard($lesson, $opts);
}

/** کاشی موضوع (اطلس) — همان کارت با نشان موضوع و شمارش محتوا. */
function renderTopicCard(array $topic, array $opts = []): string {
    $counts = (array)($opts['counts'] ?? []);
    $meta = [];
    foreach ($counts as $c) {
        if (empty($c['label'])) continue;
        $meta[] = ['icon' => (string)($c['icon'] ?? 'bi-dot'), 'text' => number_format((int)($c['value'] ?? 0)) . ' ' . (string)$c['label']];
    }
    $children = (array)($topic['children'] ?? []);
    $kids = '';
    foreach (array_slice($children, 0, 4) as $ch) {
        if (empty($ch['name'])) continue;
        $kids .= '<a class="jhd-chip jhd-chip--soft" href="' . sanitize(topicUrl($ch)) . '">' . sanitize((string)$ch['name']) . '</a>';
    }
    $card = [
        'type'       => 'topic',
        'url'        => topicUrl($topic),
        'title'      => (string)($topic['name'] ?? ''),
        'summary'    => excerpt((string)($topic['intro'] ?? $topic['description'] ?? ''), (int)($opts['excerpt'] ?? 90)),
        'image'      => (string)($topic['cover_image'] ?? ''),
        'badge'      => 'موضوع',
        'meta'       => $meta,
        'cta'        => (string)($opts['cta'] ?? 'ورود به موضوع'),
        'body_extra' => $kids !== '' ? '<div class="jhd-card-kids">' . $kids . '</div>' : '',
    ];
    return jhd_card($card, [
        'col'   => array_key_exists('col', $opts) ? (string)$opts['col'] : JHD_CARD_COL,
        'eager' => !empty($opts['eager']),
    ]);
}

/** کارت ویدیو/صوت — همان کارت با دکمهٔ پخش روی قاب رسانه. */
function renderMediaCard(array $media, array $opts = []): string {
    $kind = (string)($media['kind'] ?? 'video');
    $id = (int)($media['id'] ?? 0);
    $title = (string)($media['title'] ?? ($media['post_title'] ?? ($kind === 'audio' ? 'صوت' : 'ویدیو')));
    $href = $opts['url'] ?? (
        !empty($media['post_slug'])
            ? postUrl([
                'slug' => (string)$media['post_slug'],
                'post_type' => (string)($media['post_type'] ?? ''),
            ])
            : mediaUrl($kind, $id)
    );
    $parent = trim((string)($media['post_title'] ?? $media['parent_title'] ?? ''));
    $meta = [];
    if ($parent !== '' && $parent !== $title) $meta[] = ['icon' => 'bi-link-45deg', 'text' => $parent];
    $raw = (string)($media['published_at'] ?? $media['created_at'] ?? '');

    $card = [
        'type'        => $kind === 'audio' ? 'audio' : 'video',
        'url'         => $href,
        'title'       => $title,
        'summary'     => excerpt((string)($media['description'] ?? ''), 90),
        'image'       => (string)($media['thumbnail'] ?? $media['poster'] ?? ''),
        'badge'       => $kind === 'audio' ? 'صوت' : 'ویدیو',
        'meta'        => $meta,
        'date_label'  => $raw !== '' ? persianDate($raw) : '',
        'date_raw'    => $raw,
        'author'      => trim((string)($media['speaker'] ?? '')),
        'author_icon' => $kind === 'audio' ? 'bi-mic' : 'bi-camera-reels',
        'cta'         => (string)($opts['cta'] ?? 'پخش و دریافت'),
        'duration'    => trim((string)($media['duration'] ?? '')),
        'play'        => true,
        'body_extra'  => (string)($media['body_extra'] ?? ''),
    ];
    return jhd_card($card, [
        'col'   => array_key_exists('col', $opts) ? (string)$opts['col'] : JHD_CARD_COL,
        'eager' => !empty($opts['eager']),
    ]);
}

/** ردیف صوت — همان کارت رسانه‌ای (یک زبان بصری واحد). */
function renderAudioRow(array $media, array $opts = []): string {
    $media['kind'] = 'audio';
    $opts['cta'] = $opts['cta'] ?? 'گوش دادن';
    return renderMediaCard($media, $opts);
}

/** کارت سند/PDF با پیوند مطالعهٔ آنلاین. */
function renderDocumentCard(array $doc, array $opts = []): string {
    $title = (string)($doc['title'] ?? 'سند');
    $card = [
        'type'        => 'document',
        'url'         => (string)($doc['url'] ?? ''),
        'title'       => $title,
        'summary'     => excerpt((string)($doc['description'] ?? ''), 90),
        'image'       => (string)($doc['thumbnail'] ?? ''),
        'badge'       => strtoupper((string)($doc['ext'] ?? 'PDF')),
        'meta'        => (array)($doc['meta'] ?? []),
        'cta'         => (string)($opts['cta'] ?? 'مطالعه آنلاین'),
        'has_pdf'     => true,
    ];
    return jhd_card($card, ['col' => array_key_exists('col', $opts) ? (string)$opts['col'] : JHD_CARD_COL]);
}

/** حالت خالی: کوچک، آرام و صادق — هرگز تمام‌صفحه نیست. */
function renderEmptyState(string $icon, string $title, string $ctaUrl = '', string $cta = ''): string {
    $btn = ($ctaUrl !== '' && $cta !== '')
        ? '<a class="btn btn-outline-primary btn-sm" href="' . sanitize($ctaUrl) . '">' . sanitize($cta) . '</a>'
        : '';
    return '<div class="jhd-empty-state"><i class="bi ' . sanitize($icon) . '" aria-hidden="true"></i><p>' . sanitize($title) . '</p>' . $btn . '</div>';
}

/* ══════════════════════════════════════════════════════════════════════════
 * خوانندهٔ PDF داخل سایت
 * ──────────────────────────────────────────────────────────────────────────
 * همهٔ PDFها (کتاب، جزوهٔ درس، پیوست مطلب) با همین یک جزء نمایش داده می‌شوند،
 * پس تجربهٔ مطالعه در کل سایت یکسان است: نوار ابزار، اسکرول پیوسته،
 * بزرگ‌نمایی، تمام‌صفحه و — در صورت مجاز بودن — دکمهٔ دانلود در کنار آن.
 * ══════════════════════════════════════════════════════════════════════════ */
function jhd_pdf_reader(string $url, array $opts = []): string {
    $url = trim($url);
    if ($url === '') return '';
    $GLOBALS['JHD_NEEDS_PDF_READER'] = true;

    $title        = (string)($opts['title'] ?? 'مطالعهٔ آنلاین سند');
    $downloadUrl  = (string)($opts['download'] ?? $url);
    $allowDownload = array_key_exists('allow_download', $opts) ? (bool)$opts['allow_download'] : true;
    $note         = (string)($opts['note'] ?? 'برای مطالعه، همین‌جا اسکرول کنید؛ در موبایل هم بدون خروج از سایت خوانا است.');
    $id           = 'jhdpdf' . substr(hash('sha1', $url . $title), 0, 10);

    $lib    = asset('vendor/pdfjs/pdf.min.js');
    $worker = asset('vendor/pdfjs/pdf.worker.min.js');

    $buttons = '<button type="button" class="jhd-pdf__btn" data-pdf-prev title="صفحهٔ قبل" aria-label="صفحهٔ قبل"><i class="bi bi-chevron-up" aria-hidden="true"></i></button>'
        . '<button type="button" class="jhd-pdf__btn" data-pdf-next title="صفحهٔ بعد" aria-label="صفحهٔ بعد"><i class="bi bi-chevron-down" aria-hidden="true"></i></button>'
        . '<span class="jhd-pdf__counter">صفحهٔ <span data-pdf-page>۱</span> از <span data-pdf-total>—</span></span>'
        . '<button type="button" class="jhd-pdf__btn" data-pdf-zoom-out title="کوچک‌نمایی" aria-label="کوچک‌نمایی"><i class="bi bi-zoom-out" aria-hidden="true"></i></button>'
        . '<button type="button" class="jhd-pdf__btn" data-pdf-fit title="اندازهٔ صفحه" aria-label="اندازهٔ صفحه"><i class="bi bi-aspect-ratio" aria-hidden="true"></i></button>'
        . '<button type="button" class="jhd-pdf__btn" data-pdf-zoom-in title="بزرگ‌نمایی" aria-label="بزرگ‌نمایی"><i class="bi bi-zoom-in" aria-hidden="true"></i></button>'
        . '<button type="button" class="jhd-pdf__btn" data-pdf-full aria-pressed="false" title="تمام‌صفحه" aria-label="تمام‌صفحه"><i class="bi bi-arrows-fullscreen" aria-hidden="true"></i></button>';
    if ($allowDownload) {
        $buttons .= '<a class="jhd-pdf__btn jhd-pdf__btn--accent" href="' . sanitize($downloadUrl) . '" download><i class="bi bi-download" aria-hidden="true"></i><span>دانلود</span></a>';
    }

    return '<div class="jhd-pdf" id="' . $id . '" data-jhd-pdf="' . sanitize($url) . '"'
        . ' data-pdf-lib="' . sanitize($lib) . '" data-pdf-worker="' . sanitize($worker) . '">'
        . '<div class="jhd-pdf__bar">'
        . '<span class="jhd-pdf__title"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>' . sanitize($title) . '</span>'
        . $buttons
        . '</div>'
        . '<div class="jhd-pdf__viewport" data-pdf-viewport tabindex="0">'
        . '<p class="jhd-pdf__status" data-pdf-status>در حال آماده‌سازی خوانندهٔ PDF…</p>'
        . '</div>'
        . ($note !== '' ? '<p class="jhd-pdf__note mb-0">' . sanitize($note) . '</p>' : '')
        . '</div>';
}

/** ردیف فایل/دانلود. */
function jhd_file_row(string $label, string $url, string $size = '', string $icon = 'bi-file-earmark', array $opts = []): string {
    $isPdf = (bool)($opts['pdf'] ?? preg_match('~\.pdf($|\?)~i', $url));
    $read = '';
    if ($isPdf && !empty($opts['reader_target'])) {
        $read = '<a class="btn btn-sm btn-primary" href="#' . sanitize((string)$opts['reader_target']) . '"><i class="bi bi-book ms-1"></i>مطالعهٔ آنلاین</a>';
    }
    return '<div class="jhd-file-row"><i class="bi ' . sanitize($icon) . '" aria-hidden="true"></i>'
        . '<span class="file-name">' . sanitize($label) . '</span>'
        . ($size !== '' ? '<span class="file-size">' . sanitize($size) . '</span>' : '')
        . $read
        . '<a class="btn btn-sm btn-outline-primary" href="' . sanitize($url) . '" download><i class="bi bi-download ms-1"></i>دانلود</a>'
        . '</div>';
}

/**
 * نگارخانه تصاویر مطلب — گرید حرفه‌ای + لایت‌باکس (بدون کتابخانهٔ بیرونی).
 *
 * ورودی می‌تواند فهرست رشته‌های مسیر باشد یا آرایه‌هایی با کلیدهای
 * path/alt (جدول post_images). خروجی شامل نشانگر `data-jhd-gallery` است و
 * assets/js/gallery.js رفتار لایت‌باکس را به آن متصل می‌کند: نمایش بزرگ،
 * شمارهٔ تصویر، قبلی/بعدی، کیبورد (Esc و کلیدهای جهت)، سوایپ موبایل،
 * بندانگشتی‌ها و دانلود. اگر تصویری نباشد هیچ فضای خالی تولید نمی‌شود.
 */
function jhd_gallery(array $images, array $opts = []): string {
    $items = [];
    foreach ($images as $image) {
        if (is_array($image)) {
            $path = (string)($image['path'] ?? $image['image_path'] ?? '');
            $alt  = (string)($image['alt'] ?? $image['alt_text'] ?? '');
        } else {
            $path = (string)$image;
            $alt  = '';
        }
        $src = imgUrl($path);
        if ($src === '') continue;
        $items[] = ['src' => $src, 'alt' => $alt];
    }
    if (!$items) return '';

    $title = (string)($opts['title'] ?? 'گالری تصاویر');
    $uid = 'jhdgal-' . substr(bin2hex(random_bytes(4)), 0, 8);
    $html = '<section class="jhd-gallery" data-jhd-gallery="' . $uid . '" aria-label="' . sanitize($title) . '">';
    $html .= '<div class="jhd-gallery__head">'
        . '<h2 class="jhd-gallery__title"><i class="bi bi-images ms-1" aria-hidden="true"></i>' . sanitize($title) . '</h2>'
        . '<span class="jhd-gallery__count">' . jhd_persian_digits(count($items)) . ' تصویر</span>'
        . '</div>';
    $html .= '<div class="jhd-gallery__grid">';
    foreach ($items as $index => $item) {
        $html .= '<button type="button" class="jhd-gallery__cell" data-index="' . $index . '" aria-label="نمایش تصویر ' . jhd_persian_digits($index + 1) . '">'
            . '<img src="' . sanitize($item['src']) . '" alt="' . sanitize($item['alt']) . '" loading="lazy" decoding="async">'
            . '<span class="jhd-gallery__zoom" aria-hidden="true"><i class="bi bi-arrows-angle-expand"></i></span>'
            . '</button>';
    }
    $html .= '</div>';

    // لایت‌باکس: تنها در DOM ساخته می‌شود ولی تا زمانی که باز نشود مخفی است.
    $html .= '<div class="jhd-lightbox" data-jhd-lightbox="' . $uid . '" role="dialog" aria-modal="true" aria-label="' . sanitize($title) . '" hidden>';
    $html .= '<div class="jhd-lightbox__backdrop" data-jhd-lightbox-close></div>';
    $html .= '<div class="jhd-lightbox__frame">';
    $html .= '<div class="jhd-lightbox__top">'
        . '<span class="jhd-lightbox__counter" data-jhd-lightbox-counter></span>'
        . '<div class="jhd-lightbox__tools">'
        . '<a class="jhd-lightbox__tool" data-jhd-lightbox-download href="' . sanitize($items[0]['src']) . '" download title="دانلود تصویر"><i class="bi bi-download" aria-hidden="true"></i></a>'
        . '<button type="button" class="jhd-lightbox__tool" data-jhd-lightbox-close title="بستن" aria-label="بستن"><i class="bi bi-x-lg" aria-hidden="true"></i></button>'
        . '</div></div>';
    $html .= '<button type="button" class="jhd-lightbox__nav jhd-lightbox__nav--prev" data-jhd-lightbox-prev title="تصویر قبلی" aria-label="تصویر قبلی"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>';
    $html .= '<figure class="jhd-lightbox__figure">'
        . '<img data-jhd-lightbox-image src="" alt="">'
        . '<figcaption data-jhd-lightbox-caption></figcaption>'
        . '</figure>';
    $html .= '<button type="button" class="jhd-lightbox__nav jhd-lightbox__nav--next" data-jhd-lightbox-next title="تصویر بعدی" aria-label="تصویر بعدی"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>';
    $html .= '<div class="jhd-lightbox__thumbs" data-jhd-lightbox-thumbs></div>';
    $html .= '</div></div>';
    $html .= '</section>';

    // دادهٔ تصاویر برای اسکریپت (JSON امن درون data attribute)
    $html .= '<script type="application/json" data-jhd-gallery-data="' . $uid . '">'
        . json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)
        . '</script>';
    return $html;
}

/** ارقام فارسی برای شماره‌گذاری گالری. */
function jhd_persian_digits(int $number): string {
    return str_replace(['0','1','2','3','4','5','6','7','8','9'], ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'], (string)$number);
}

/** ردیف اشتراک‌گذاری مطلب. */
function jhd_share_row(string $url, string $title): string {
    $enc = rawurlencode($url);
    $encTitle = rawurlencode($title);
    return '<div class="jhd-share">'
        . '<span class="text-muted"><i class="bi bi-share ms-1"></i>اشتراک‌گذاری:</span>'
        . '<a class="jhd-icon-btn" href="https://t.me/share/url?url=' . $enc . '&text=' . $encTitle . '" target="_blank" rel="noopener noreferrer" aria-label="اشتراک در تلگرام"><i class="bi bi-telegram"></i></a>'
        . '<a class="jhd-icon-btn" href="https://wa.me/?text=' . $enc . '%20' . $encTitle . '" target="_blank" rel="noopener noreferrer" aria-label="اشتراک در واتس‌اپ"><i class="bi bi-whatsapp"></i></a>'
        . '<button type="button" class="jhd-icon-btn" data-copy-link="' . sanitize($url) . '" aria-label="کپی پیوند"><i class="bi bi-link-45deg"></i></button>'
        . '</div>';
}

/** ناوبری مطلب قبلی/بعدی. */
function jhd_post_nav(?array $prev, ?array $next): string {
    if (!$prev && !$next) return '';
    $html = '<nav class="jhd-post-nav" aria-label="مطالب پیوسته">';
    $html .= $next
        ? '<a href="' . sanitize(postUrl($next)) . '"><small>مطلب بعدی</small>' . sanitize((string)$next['title']) . '</a>'
        : '<span></span>';
    $html .= $prev
        ? '<a href="' . sanitize(postUrl($prev)) . '" style="text-align:left"><small>مطلب قبلی</small>' . sanitize((string)$prev['title']) . '</a>'
        : '<span></span>';
    return $html . '</nav>';
}

function jhd_nav_sections(): array {
    return [
        ['route' => 'news', 'label' => 'اخبار', 'icon' => 'bi-newspaper', 'types' => ['news']],
        ['route' => 'articles', 'label' => 'مقالات', 'icon' => 'bi-file-text', 'types' => ['article']],
        ['route' => 'research', 'label' => 'پژوهش', 'icon' => 'bi-journal-richtext', 'types' => ['research']],
        ['route' => 'books', 'label' => 'کتابخانه', 'icon' => 'bi-book', 'types' => []],
        ['route' => 'lessons', 'label' => 'دروس', 'icon' => 'bi-mortarboard', 'types' => []],
        ['route' => 'events', 'label' => 'رویدادها', 'icon' => 'bi-calendar-event', 'types' => ['program', 'religious', 'announcement']],
        ['route' => 'media', 'label' => 'رسانه', 'icon' => 'bi-play-circle', 'types' => []],
        ['route' => 'reports', 'label' => 'گزارش‌ها', 'icon' => 'bi-card-text', 'types' => ['report']],
        ['route' => 'topics', 'label' => 'موضوعات', 'icon' => 'bi-diagram-3', 'types' => []],
    ];
}

function getCategoriesForTypes(array $types): array {
    if (!$types) return [];
    $out = [];
    foreach (getCategories() as $c) {
        $pt = (string)($c['post_type'] ?? 'all');
        if (in_array($pt, $types, true)) $out[] = $c;
    }
    return $out;
}

function jhd_section_children(array $section): array {
    // Public Vercel can intentionally run without the shared-host MySQL database.
    // Navigation must still render; DB-backed submenu data is optional there.
    if (array_key_exists('JHD_PUBLIC_DB_READY', $GLOBALS) && !$GLOBALS['JHD_PUBLIC_DB_READY']) {
        return jhd_static_section_children($section);
    }
    // The very same submenu is built twice on every page — once for the
    // desktop navigation and once for the mobile drawer. Build it once.
    static $memo = [];
    $memoKey = (string)($section['route'] ?? '') . '|' . implode(',', (array)($section['types'] ?? []));
    if (array_key_exists($memoKey, $memo)) return $memo[$memoKey];
    $route = (string)($section['route'] ?? '');
    $children = [];
    foreach (getCategoriesForTypes($section['types'] ?? []) as $c) {
        $children[] = ['label' => (string)$c['name'], 'url' => categoryUrl($c)];
    }
    if ($route === 'media') {
        $children[] = ['label' => 'ویدیو', 'url' => url('videos')];
        $children[] = ['label' => 'صوت', 'url' => url('audios')];
    }
    if ($route === 'events') {
        $children[] = ['label' => 'برنامه‌ها', 'url' => url('programs')];
        $children[] = ['label' => 'فعالیت‌های مذهبی', 'url' => url('religious-activities')];
        $children[] = ['label' => 'اطلاعیه‌ها', 'url' => url('announcements')];
    }
    if ($route === 'lessons') {
        foreach (getLessonCollections(['active' => 1]) as $col) {
            $children[] = ['label' => (string)$col['title'], 'url' => collectionUrl($col)];
        }
    }
    return $memo[$memoKey] = $children;
}

/** Static submenu fallback used when the public shell has no database. */
function jhd_static_section_children(array $section): array {
    $route = (string)($section['route'] ?? '');
    $children = [];
    if ($route === 'media') {
        $children[] = ['label' => 'ویدیو', 'url' => url('videos')];
        $children[] = ['label' => 'صوت', 'url' => url('audios')];
    }
    if ($route === 'events') {
        $children[] = ['label' => 'برنامه‌ها', 'url' => url('programs')];
        $children[] = ['label' => 'فعالیت‌های مذهبی', 'url' => url('religious-activities')];
        $children[] = ['label' => 'اطلاعیه‌ها', 'url' => url('announcements')];
    }
    return $children;
}

/** آیتم ناوبری دسکتاپ (بدون آیکون: ناوبری آرام و حرفه‌ای). */
function jhd_render_desktop_nav_item(array $section, callable $isActiveNav, array $extraActive = []): string {
    $route = (string)$section['route'];
    $label = (string)$section['label'];
    $children = $section['children'] ?? jhd_section_children($section);
    $active = $isActiveNav($route);
    foreach ($extraActive as $r) {
        if ($isActiveNav($r)) $active = true;
    }
    $has = $children ? ' class="jhd-has-sub"' : '';
    $html = '<li' . $has . '>';
    $html .= '<a href="' . sanitize(url($route)) . '" class="jhd-nav-link' . ($active ? ' active' : '') . '"'
        . ($active ? ' aria-current="page"' : '')
        . ($children ? ' aria-haspopup="true"' : '') . '>'
        . sanitize($label) . '</a>';
    if ($children) {
        $html .= '<ul class="jhd-subnav" role="menu">';
        $html .= '<li class="jhd-subnav-all"><a href="' . sanitize(url($route)) . '">همه ' . sanitize($label) . '</a></li>';
        foreach ($children as $ch) {
            $html .= '<li><a href="' . sanitize((string)$ch['url']) . '">' . sanitize((string)$ch['label']) . '</a></li>';
        }
        $html .= '</ul>';
    }
    return $html . '</li>';
}

/** آیتم ناوبری کشوی موبایل. */
function jhd_render_drawer_nav_item(array $section, callable $isActiveNav): string {
    $route = (string)$section['route'];
    $label = (string)$section['label'];
    $icon = (string)($section['icon'] ?? 'bi-dot');
    $children = $section['children'] ?? jhd_section_children($section);
    $active = $isActiveNav($route) ? ' active' : '';
    $href = sanitize(url($route));
    if (!$children) {
        return '<a href="' . $href . '" class="drawer-link' . $active . '"><i class="bi ' . sanitize($icon) . '"></i> ' . sanitize($label) . '</a>';
    }
    $html = '<details class="jhd-acc"' . ($active ? ' open' : '') . '>';
    $html .= '<summary><i class="bi ' . sanitize($icon) . '"></i> ' . sanitize($label) . '</summary>';
    $html .= '<div class="jhd-acc-body">';
    $html .= '<a class="drawer-link' . $active . '" href="' . $href . '">همه ' . sanitize($label) . '</a>';
    foreach ($children as $ch) {
        $html .= '<a class="drawer-link" href="' . sanitize((string)$ch['url']) . '">' . sanitize((string)$ch['label']) . '</a>';
    }
    $html .= '</div></details>';
    return $html;
}

function renderCategoryChips(array $types, string $allUrl, string $allLabel = 'همه'): string {
    $cats = getCategoriesForTypes($types);
    if (!$cats) return '';
    $html = '<nav class="jhd-cat-strip" aria-label="زیربخش‌ها">';
    $html .= '<a class="jhd-chip jhd-chip--all" href="' . sanitize($allUrl) . '">' . sanitize($allLabel) . '</a>';
    foreach ($cats as $c) {
        $html .= '<a class="jhd-chip" href="' . sanitize(categoryUrl($c)) . '">' . sanitize((string)$c['name']) . '</a>';
    }
    return $html . '</nav>';
}

function jhd_render_topic_tree_nav(array $nodes, string $mode = 'desktop'): string {
    $html = '';
    foreach ($nodes as $node) {
        $children = $node['children'] ?? [];
        $name = sanitize((string)$node['name']);
        $href = sanitize(topicUrl($node));
        if ($children) {
            if ($mode === 'drawer') {
                $html .= '<details class="jhd-acc"><summary>' . $name . '</summary><div class="jhd-acc-body">';
                $html .= '<a class="drawer-link" href="' . $href . '">نمای کلی «' . $name . '»</a>';
                $html .= jhd_render_topic_tree_nav($children, 'drawer');
                $html .= '</div></details>';
            } else {
                $html .= '<li class="jhd-has-sub"><a href="' . $href . '">' . $name . '</a><ul class="jhd-subnav jhd-subnav-nested">';
                foreach ($children as $ch) {
                    $html .= '<li><a href="' . sanitize(topicUrl($ch)) . '">' . sanitize((string)$ch['name']) . '</a></li>';
                }
                $html .= '</ul></li>';
            }
        } else {
            $html .= $mode === 'drawer'
                ? '<a class="drawer-link drawer-topic depth-0" href="' . $href . '">' . $name . '</a>'
                : '<li><a href="' . $href . '">' . $name . '</a></li>';
        }
    }
    return $html;
}

/** سرفصل بخش‌ها — یک الگوی واحد برای همهٔ فهرست‌ها و صفحهٔ اصلی. */
function jhd_section_head(array $opts): string {
    $eyebrow = (string)($opts['eyebrow'] ?? '');
    $title   = (string)($opts['title'] ?? '');
    $icon    = (string)($opts['icon'] ?? '');
    $url     = (string)($opts['url'] ?? '');
    $link    = (string)($opts['link'] ?? '');
    $html = '<div class="jhd-section-head">';
    $html .= '<div>';
    if ($eyebrow !== '') $html .= '<span class="jhd-eyebrow">' . sanitize($eyebrow) . '</span>';
    $html .= '<h2>';
    if ($icon !== '') $html .= '<i class="bi ' . sanitize($icon) . '" aria-hidden="true"></i>';
    $html .= sanitize($title) . '</h2>'
        . '<div class="jhd-rule" aria-hidden="true"></div>'
        . '</div>';
    if ($url !== '' && $link !== '') {
        $html .= '<a class="jhd-section-link" href="' . sanitize($url) . '">' . sanitize($link)
              . ' <i class="bi bi-arrow-left" aria-hidden="true"></i></a>';
    }
    return $html . '</div>';
}

/** سرصفحهٔ صفحه‌های داخلی: برچسب، عنوان، توضیح کوتاه و در صورت نیاز دکمه‌ها. */
function jhd_page_head(array $opts): string {
    $eyebrow = (string)($opts['eyebrow'] ?? '');
    $title   = (string)($opts['title'] ?? '');
    $icon    = (string)($opts['icon'] ?? '');
    $lead    = (string)($opts['lead'] ?? '');
    $actions = (string)($opts['actions'] ?? '');
    $html = '<div class="jhd-page-head">';
    if ($eyebrow !== '') $html .= '<span class="jhd-eyebrow">' . sanitize($eyebrow) . '</span>';
    $html .= '<h1 class="jhd-page-title">';
    if ($icon !== '') $html .= '<i class="bi ' . sanitize($icon) . '" aria-hidden="true"></i>';
    $html .= sanitize($title) . '</h1>'
        . '<div class="jhd-rule" aria-hidden="true"></div>';
    if ($lead !== '') $html .= '<p class="jhd-page-lead">' . sanitize($lead) . '</p>';
    if ($actions !== '') $html .= '<div class="d-flex flex-wrap gap-2 mt-3">' . $actions . '</div>';
    return $html . '</div>';
}

/** نوار ابزار فهرست: جستجو + چیپ‌های فیلتر در یک ردیف فشرده. */
function jhd_listing_toolbar(array $opts): string {
    $searchAction = (string)($opts['search_action'] ?? '');
    $placeholder = (string)($opts['placeholder'] ?? 'جستجو...');
    $value = (string)($opts['value'] ?? '');
    $chips = (string)($opts['chips'] ?? '');
    $extra = (string)($opts['extra'] ?? '');
    $html = '<div class="jhd-toolbar">';
    if ($searchAction !== '') {
        $html .= '<form method="get" class="jhd-search-inline" role="search" action="' . sanitize($searchAction) . '">'
            . formRouteFields((string)($opts['route'] ?? ''))
            . '<label class="visually-hidden" for="jhd-list-search">جستجو</label>'
            . '<input id="jhd-list-search" type="search" name="q" maxlength="200" placeholder="' . sanitize($placeholder) . '" value="' . sanitize($value) . '">'
            . '<button type="submit" aria-label="جستجو"><i class="bi bi-search"></i></button>'
            . '</form>';
    }
    if ($chips !== '') $html .= '<nav class="jhd-cat-strip mb-0" aria-label="فیلترها">' . $chips . '</nav>';
    if ($extra !== '') $html .= $extra;
    return $html . '</div>';
}

/** کارت آمار کوچک (داشبوردها). */
function jhd_stat_card(string $icon, string $value, string $label, string $url): string {
    return '<a class="jhd-stat" href="' . sanitize($url) . '">'
        . '<i class="bi ' . sanitize($icon) . '" aria-hidden="true"></i>'
        . '<span><strong>' . sanitize($value) . '</strong><span>' . sanitize($label) . '</span></span>'
        . '</a>';
}
