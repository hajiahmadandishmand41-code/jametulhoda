<?php
/**
 * config/routes.php — تنها مرجع مسیردهی سایت (single source of truth)
 *
 * `.htaccess` همه درخواست‌ها را به `router.php` می‌فرستد و `router.php` فقط همین
 * جدول را می‌خواند؛ بنابراین هیچ فایل PHP دیگری از بیرون مستقیماً قابل اجرا نیست.
 *
 * ساختار:
 *   routes   → نشانی اصلی (canonical) ⇒ اسکریپت
 *              مقدار می‌تواند رشته (مسیر فایل) یا آرایه
 *              ['file' => …, 'get' => [پارامترهای پیش‌فرض]] باشد.
 *   aliases  → نشانی قدیمی یا جایگزین ⇒ نشانی اصلی
 *   patterns → مسیرهای پویا (slug / id / actions) با الگوهای منظم
 */

return [
    'routes' => [
        // ─── صفحات عمومی ────────────────────────────────────────────────
        '/'                     => 'index.php',
        '/index.php'            => 'index.php',
        // Read-only JSON feed for the lightweight native Android app.
        '/api/mobile-feed'      => 'api/mobile-feed.php',
        // Native Android account API (opaque bearer tokens; never shares browser cookies).
        '/api/mobile-auth'      => 'api/mobile-auth.php',
        '/about'                => 'pages/about.php',
        '/announcements'        => 'pages/announcements.php',
        '/article'              => 'pages/post.php',
        '/articles'             => 'pages/articles.php',
        '/book'                 => 'pages/book.php',
        '/books'                => 'pages/books.php',
        '/category'             => 'pages/category.php',
        '/contact'              => 'pages/contact.php',
        '/events'               => 'pages/events.php',
        '/lesson'               => 'pages/lesson.php',
        '/lessons'              => 'pages/lessons.php',
        '/media'                => 'pages/media-library.php',
        '/media-library'        => 'pages/media-library.php',
        '/audio'                => ['file' => 'pages/media-library.php', 'get' => ['kind' => 'audio']],
        '/audios'               => ['file' => 'pages/media-library.php', 'get' => ['kind' => 'audio']],
        '/video'                => ['file' => 'pages/media-library.php', 'get' => ['kind' => 'video']],
        '/videos'               => ['file' => 'pages/media-library.php', 'get' => ['kind' => 'video']],
        '/news'                 => 'pages/news.php',
        '/post'                 => 'pages/post.php',
        '/programs'             => 'pages/programs.php',
        '/qa'                   => 'pages/qa.php',
        '/religious-activities' => 'pages/religious-activities.php',
        '/report'               => 'pages/post.php',
        '/reports'              => 'pages/reports.php',
        '/research'             => 'pages/research.php',
        '/search'               => 'pages/search.php',
        '/read'                 => 'pages/reader.php',
        '/speech'               => 'pages/speech.php',
        '/speeches'             => 'pages/speeches.php',
        '/topic'                => 'pages/topic.php',
        '/topics'               => 'pages/topics.php',

        // ─── احراز هویت عمومی (جدا از پنل مدیر) ──────────────────────────
        '/login'                => 'pages/login.php',
        '/register'             => 'pages/register.php',
        '/logout'               => 'pages/logout.php',
        '/account'              => 'pages/account.php',
        '/profile'              => 'pages/account.php',
        '/password-change'      => 'pages/password-change.php',
        '/sitemap.xml'          => 'sitemap.php',
        '/sitemap.php'          => 'sitemap.php',
        '/robots.txt'           => 'robots.php',
        '/robots.php'           => 'robots.php',

        // ─── نصب ────────────────────────────────────────────────────────
        // تنها نشانی مجاز نصاب `/php/install` است. ریشهٔ `/install.php` عمداً
        // ۴۰۴ می‌ماند (docs/FILE_ROUTE_MAP.md و tests/http.mjs همین را می‌سنجند)
        // تا مسیر قدیمی نصاب روی میزبان‌های اشتراکی قابل اجرا نباشد.
        '/php/install'          => 'php/install.php',
        '/php/install.php'      => 'php/install.php',
        // Schema bootstrap for hosts without a shell (Vercel). Disabled unless
        // MIGRATION_TOKEN is set — see php/migrate.php.
        '/php/migrate'          => 'php/migrate.php',
        '/php/migrate.php'      => 'php/migrate.php',

        // ─── پنل مدیریت ─────────────────────────────────────────────────
        // ورود، خروج و ورود مدیر همه از یک کنترلر واحد می‌آیند:
        //   /login و /admin/login ⇒ pages/login.php
        //   /logout و /admin/logout ⇒ pages/logout.php
        '/admin'                     => 'admin/index.php',
        // داشبورد، ورود و سایر مسیرهای مدیریتی. هر کلید فقط یک‌بار تعریف می‌شود.
        '/admin/dashboard'           => 'admin/dashboard.php',
        '/admin/profile'             => 'admin/profile.php',
        '/admin/login'               => 'admin/login.php',
        '/admin/logout'              => 'admin/logout.php',
        '/admin/change-password'     => 'admin/change-password.php',
        '/admin/settings'            => 'admin/settings.php',
        '/admin/uploads'             => 'admin/media/index.php',
        '/admin/videos'              => ['file' => 'admin/videos.php', 'get' => ['kind' => 'video']],
        '/admin/audios'              => ['file' => 'admin/audios.php', 'get' => ['kind' => 'audio']],
        '/admin/courses'             => 'admin/courses.php',
        '/admin/courses/create'      => 'admin/lessons/create.php',
        '/admin/courses/edit'        => 'admin/lessons/edit.php',
        '/admin/courses/delete'      => 'admin/lessons/delete.php',
        '/admin/research'            => ['file' => 'admin/research.php', 'get' => ['type' => 'research']],
        '/admin/research/create'     => ['file' => 'admin/posts/create.php', 'get' => ['type' => 'research']],
        '/admin/research/edit'       => ['file' => 'admin/posts/edit.php', 'get' => ['type' => 'research']],
        '/admin/reports'             => ['file' => 'admin/reports.php', 'get' => ['type' => 'report']],
        '/admin/reports/create'      => ['file' => 'admin/posts/create.php', 'get' => ['type' => 'report']],
        '/admin/reports/edit'        => ['file' => 'admin/posts/edit.php', 'get' => ['type' => 'report']],
        '/admin/content'             => 'admin/posts/index.php',
        // مدیریت گالری تصاویر و رسانهٔ مطلب (نقاط پایانی JSON پنل)
        '/admin/content/gallery'     => 'admin/posts/gallery.php',
        '/admin/content/media'       => 'admin/posts/media-manage.php',
        '/admin/storage/direct'      => 'admin/storage/direct.php',
        // همان نقاط پایانی با مسیر فیزیکی (میزبان بدون mod_rewrite)
        '/admin/posts/gallery.php'   => 'admin/posts/gallery.php',
        '/admin/posts/media-manage.php' => 'admin/posts/media-manage.php',
        '/admin/content/edit'        => 'admin/posts/edit.php',
        '/admin/content/create'      => 'admin/posts/create.php',
        '/admin/users'               => 'admin/users/index.php',
        '/admin/users/new'           => 'admin/users/index.php',
        '/admin/members'             => 'admin/members/index.php',
        '/admin/diagnostics'         => 'admin/diagnostics.php',
        '/admin/messages'            => 'admin/messages/index.php',
        '/admin/media'               => 'admin/media/index.php',
        '/admin/categories'          => 'admin/categories/index.php',
        '/admin/topics'              => 'admin/topics/index.php',
        '/admin/topics/create'       => 'admin/topics/create.php',
        '/admin/topics/edit'         => 'admin/topics/edit.php',
        '/admin/articles'            => 'admin/articles/index.php',
        '/admin/articles/create'     => 'admin/articles/create.php',
        '/admin/articles/edit'       => 'admin/articles/edit.php',
        '/admin/articles/delete'     => 'admin/articles/delete.php',
        '/admin/books'               => 'admin/books/index.php',
        '/admin/books/create'        => 'admin/books/create.php',
        '/admin/books/edit'          => 'admin/books/edit.php',
        '/admin/books/delete'        => 'admin/books/delete.php',
        '/admin/lessons'             => 'admin/lessons/index.php',
        '/admin/lessons/create'      => 'admin/lessons/create.php',
        '/admin/lessons/edit'        => 'admin/lessons/edit.php',
        '/admin/lessons/delete'      => 'admin/lessons/delete.php',
        '/admin/lesson-collections'  => 'admin/lesson-collections/index.php',
        '/admin/news'                => 'admin/news/index.php',
        '/admin/news/create'         => 'admin/news/create.php',
        '/admin/news/edit'           => 'admin/news/edit.php',
        '/admin/news/delete'         => 'admin/news/delete.php',
        '/admin/posts'               => 'admin/posts/index.php',
        '/admin/posts/create'        => 'admin/posts/create.php',
        '/admin/posts/edit'          => 'admin/posts/edit.php',
        '/admin/posts/delete'        => 'admin/posts/delete.php',
        '/admin/posts/action.php'    => 'admin/posts/action.php',
        '/admin/content/action'      => 'admin/posts/action.php',
        '/admin/speeches'            => 'admin/speeches/index.php',
        '/admin/speeches/create'     => 'admin/speeches/create.php',
        '/admin/speeches/edit'       => 'admin/speeches/edit.php',
        '/admin/speeches/delete'     => 'admin/speeches/delete.php',
        '/admin/banners'             => 'admin/banners/index.php',
    ],

    // نشانی‌های قدیمی و مترادف
    'aliases' => [
        '/library'   => '/books',
        '/files'     => '/books',
        '/dashboard' => '/admin',
        '/event'     => '/events',
        '/notices'   => '/announcements',
        '/notice'    => '/announcements',
        // نشانی کوتاه نصاب. aliasها فقط با همان نوشتار (به‌علاوهٔ اسلش پایانی)
        // پاسخ می‌دهند، پس `/install.php` همچنان ۴۰۴ می‌ماند.
        '/install'   => '/php/install',
    ],

    // مسیرهای پویا: [الگو, اسکریپت (با جای‌گیری $n), نگاشت پارامترها]
    'patterns' => [
        // ─── پنل مدیریت: روت‌های پویا و عملیات محتوا ───────────────────
        ['~^/admin/users/edit/(\d+)/?$~D',                                 'admin/users/index.php',     ['edit' => 1]],
        ['~^/admin/topics/(\d+)/edit/?$~D',                                'admin/topics/edit.php',      ['id' => 1]],
        ['~^/admin/topics/edit/(\d+)/?$~D',                                'admin/topics/edit.php',      ['id' => 1]],
        ['~^/admin/content/(\d+)/(publish|unpublish|archive|delete)/?$~D', 'admin/posts/action.php',     ['id' => 1, 'action' => 2]],
        // ویرایش مطلب با شناسهٔ عددی در نشانی تمیز (پنل مدیریت)
        ['~^/admin/(?:posts|articles|news|reports|research)/edit/(\d+)/?$~D', 'admin/posts/edit.php',    ['id' => 1]],
        ['~^/admin/content/(\d+)/edit/?$~D',                               'admin/posts/edit.php',      ['id' => 1]],

        // ─── کتاب‌ها (شناسه عددی یا اسلاگ / مفرد و جمع) ───────────────
        ['~^/books?/(\d+)/?$~D',                                           'pages/book.php',            ['id' => 1]],
        ['~^/books?/([^/]+)/?$~uD',                                        'pages/book.php',            ['slug' => 1]],

        // ─── انواع مطالب با پیشوند نوع (مفرد و جمع) ────────────────────
        // Typed post URLs. Every published post_type owns a prefix here so each
        // piece of content has a direct, independent URL:
        //   news, article(s), research(es), report(s), event(s),
        //   announcement(s), program(s), religious(-activities), qa, speech(es)
        // The singular/plural and legacy spellings resolve too, but
        // jhd_redirect_to_canonical() sends them on to the one canonical path,
        // so they never compete with it as a duplicate URL.
        ['~^/(article|articles|news|research|researches|report|reports|event|events|announcement|announcements|program|programs|religious|religious-activities|qa|speeches)/([^/]+)/?$~uD', 'pages/post.php', ['expected_type' => 1, 'slug' => 2]],

        // ─── رسانه (شناسه عددی ویدیو / صوت / مدیا) ─────────────────────
        ['~^/(video|audio|media)/(\d+)/?$~D',                              'pages/media.php',           ['kind' => 1, 'id' => 2]],

        // ─── موضوعات (مفرد و جمع، والد/فرزند) ──────────────────────────
        ['~^/topics?/((?:[^/]+)(?:/[^/]+)*)/?$~uD',                        'pages/topic.php',           ['slug' => 1]],

        // ─── جزئیات درس، سخنرانی، دسته‌بندی و پست متفرقه ───────────────
        ['~^/lesson/([^/]+)/?$~uD',                                        'pages/lesson.php',          ['slug' => 1]],
        ['~^/speech/([^/]+)/?$~uD',                                        'pages/speech.php',          ['slug' => 1]],
        ['~^/category/([^/]+)/?$~uD',                                      'pages/category.php',        ['slug' => 1]],
        ['~^/post/([^/]+)/?$~uD',                                          'pages/post.php',            ['slug' => 1]],

        // ─── مجموعه‌ها و جلدهای درسی ──────────────────────────────────
        ['~^/lessons/([^/]+)/([^/]+)/?$~uD',                               'pages/lessons.php',         ['collection' => 1, 'volume' => 2]],
        ['~^/lessons/([^/]+)/?$~uD',                                       'pages/lesson-route.php',   ['slug' => 1]],

        // ─── جستجو ─────────────────────────────────────────────────────
        ['~^/search/([^/]+)/?$~uD',                                        'pages/search.php',          ['q' => 1]],
    ],
];
