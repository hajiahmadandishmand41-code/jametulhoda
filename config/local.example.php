<?php
/**
 * config/local.example.php — الگوی فایل تنظیمات خصوصی
 *
 * این فایل را با نام `config/local.php` کپی کنید (در پوشه config) و مقدارهای
 * واقعی میزبان خود را وارد کنید. `config/local.php` در .gitignore ثبت شده و
 * توسط .htaccess و router.php از دسترسی وب مسدود است؛ هرگز آن را کامیت نکنید.
 *
 * نصب خودکار: به‌جای ساخت دستی این فایل می‌توانید `https://your-domain/php/install`
 * را باز کنید تا نصاب مرورگری آن را بسازد.
 *
 * هر کلید را می‌توان با متغیر محیطی هم‌نام هم مقداردهی کرد؛ متغیر محیطی
 * بر مقدار این فایل اولویت دارد (نگاه کنید به env_value() در config/config.php).
 */

return [
    // ─── محیط اجرا ────────────────────────────────────────────────────────
    'APP_ENV' => 'production',            // production | development

    // ─── دیتابیس MySQL (InfinityFree / cPanel) ────────────────────────────
    'DB_DRIVER' => 'mysql',
    'DB_HOST' => 'sqlXXX.infinityfree.com',
    'DB_PORT' => '3306',
    'DB_NAME' => 'if0_00000000_jametulhoda',
    'DB_USER' => 'if0_00000000',
    'DB_PASS' => 'رمز-دیتابیس-اینجا',

    // ─── یا PostgreSQL (به‌جای بلوک بالا) ─────────────────────────────────
    // 'DB_DRIVER' => 'pgsql',
    // 'DATABASE_URL' => 'postgresql://user:password@host:5432/dbname?sslmode=require',

    // ─── نشانی سایت ───────────────────────────────────────────────────────
    // برای sitemap.xml و robots.txt و پیوندهای canonical لازم است.
    'SITE_URL' => 'https://your-domain.example',
    // اگر سایت در زیرپوشه نصب شده است: '/subfolder' (در ریشه: '')
    'BASE_PATH' => '',

    // ─── تماس ─────────────────────────────────────────────────────────────
    'SITE_EMAIL' => 'hajiahmads299@gmail.com',
    'SITE_PHONE' => '0798228441',
    'SITE_ADDRESS' => 'کابل، افغانستان',

    // ─── ذخیره‌سازی فایل‌ها ────────────────────────────────────────────────
    'UPLOAD_STORAGE' => 'local',          // local | s3
    // 'UPLOAD_LOCAL_PATH' => __DIR__ . '/../uploads',
    // 'UPLOAD_BASE_URL' => '/uploads',

    // ─── نشست‌ها ───────────────────────────────────────────────────────────
    // در محیط production باید database باشد (جدول app_sessions).
    'SESSION_DRIVER' => 'database',

    // ─── حساب مدیر ────────────────────────────────────────────────────────
    // هیچ رمز عمومی/پیش‌فرضی تنظیم نکنید؛ از محیط محرمانه با حداقل ۱۴ نویسه استفاده کنید.
    // 'ADMIN_USERNAME' => 'admin',
    // 'ADMIN_PASSWORD' => 'یک-رمز-تصادفی-و-محرمانه-۱۴-نویسه-یا-بیشتر',
    // 'ADMIN_EMAIL' => 'hajiahmads299@gmail.com',
];
