<?php
/**
 * roles.php — رجیستری نقش‌ها و سطوح دسترسی (تنها مرجع مجوزها)
 *
 * سامانه سه نقش قطعی دارد:
 *   user        → عضو عمومی سایت (فقط حساب کاربری خودش)
 *   admin       → مدیر محتوا (اخبار، مقالات، کتاب‌ها، درس‌ها، موضوعات، رسانه، پیام‌ها)
 *   super_admin → مدیر ارشد (همه‌چیز + کاربران، تنظیمات، تشخیص سامانه)
 *
 * نقش‌های قدیمی نصب‌های پیشین هنگام خواندن نگاشت می‌شوند:
 *   superadmin → super_admin        editor → admin
 *
 * مجوزها با «قابلیت» (capability) کنترل می‌شوند، نه با مقایسهٔ رشته‌ای نقش در
 * هر صفحه؛ بنابراین افزودن نقش یا تغییر سطح دسترسی فقط در همین فایل انجام می‌شود.
 */
declare(strict_types=1);

const JHD_ROLE_USER        = 'user';
const JHD_ROLE_ADMIN       = 'admin';
const JHD_ROLE_SUPER_ADMIN = 'super_admin';

/** همهٔ نقش‌های مجاز که در دیتابیس ذخیره می‌شوند. */
function jhd_role_values(): array {
    return [JHD_ROLE_USER, JHD_ROLE_ADMIN, JHD_ROLE_SUPER_ADMIN];
}

/** نقش‌های کارکنان (دسترسی به پنل مدیریت). */
function jhd_staff_roles(): array {
    return [JHD_ROLE_ADMIN, JHD_ROLE_SUPER_ADMIN];
}

/**
 * نگاشت هر نقش قدیمی/ناشناخته به نقش قطعی. مقدار ناشناخته هرگز دسترسی
 * مدیریتی نمی‌گیرد و به پایین‌ترین سطح (user) تنزل داده می‌شود.
 */
function jhd_normalize_role(?string $role): string {
    $value = strtolower(trim((string)$role));
    return match ($value) {
        'super_admin', 'superadmin', 'super-admin', 'super admin', 'owner', 'root' => JHD_ROLE_SUPER_ADMIN,
        'admin', 'editor', 'manager', 'moderator', 'administrator' => JHD_ROLE_ADMIN,
        default => JHD_ROLE_USER,
    };
}

function jhd_role_is_staff(?string $role): bool {
    return in_array(jhd_normalize_role($role), jhd_staff_roles(), true);
}

function jhd_role_is_super(?string $role): bool {
    return jhd_normalize_role($role) === JHD_ROLE_SUPER_ADMIN;
}

/** برچسب فارسی نقش‌ها برای نمایش در پنل. */
function jhd_role_labels(): array {
    return [
        JHD_ROLE_USER => 'عضو سایت',
        JHD_ROLE_ADMIN => 'مدیر محتوا',
        JHD_ROLE_SUPER_ADMIN => 'مدیر ارشد',
    ];
}

function jhd_role_label(?string $role): string {
    $labels = jhd_role_labels();
    $role = jhd_normalize_role($role);
    return $labels[$role] ?? $labels[JHD_ROLE_USER];
}

/**
 * قابلیت‌ها:
 *   content    مدیریت مطالب (اخبار/مقاله/پژوهش/گزارش)، موضوعات، دسته‌بندی
 *   library    کتاب‌ها، درس‌ها، مجموعه‌های درسی
 *   media      رسانه، سخنرانی، آپلود فایل
 *   messages   پیام‌های تماس و بنرها
 *   users      مدیریت کاربران و اعضای سایت
 *   settings   تنظیمات سایت
 *   diagnostics وضعیت سامانه و ابزار تشخیص
 */
function jhd_capabilities(): array {
    return [
        JHD_ROLE_USER => [],
        JHD_ROLE_ADMIN => ['content', 'library', 'media', 'messages'],
        JHD_ROLE_SUPER_ADMIN => ['content', 'library', 'media', 'messages', 'users', 'settings', 'diagnostics'],
    ];
}

function jhd_can(string $capability, ?string $role = null): bool {
    if ($role === null) {
        $role = function_exists('currentUserRole') ? currentUserRole() : null;
    }
    return in_array($capability, jhd_capabilities()[jhd_normalize_role($role)] ?? [], true);
}

/** فهرست قابلیت‌های یک نقش (برای صفحهٔ مدیریت کاربران). */
function jhd_role_permission_summary(string $role): string {
    $labels = [
        'content' => 'محتوا',
        'library' => 'کتابخانه و درس',
        'media' => 'رسانه',
        'messages' => 'پیام‌ها و بنر',
        'users' => 'کاربران',
        'settings' => 'تنظیمات',
        'diagnostics' => 'تشخیص سامانه',
    ];
    $capabilities = jhd_capabilities()[jhd_normalize_role($role)] ?? [];
    if (!$capabilities) return 'فقط حساب کاربری شخصی';
    return implode('، ', array_map(static fn(string $c): string => $labels[$c] ?? $c, $capabilities));
}
