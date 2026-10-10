<?php
/**
 * member-auth.php — ثبت‌نام و حساب کاربری اعضای عمومی
 *
 * توجه: اعضای عمومی و مدیران در یک جدول (`users`) ذخیره می‌شوند و نقش آن‌ها
 * تفاوت را می‌سازد. این فایل فقط منطق «عضو عمومی» را نگه می‌دارد و توابع
 * مشترک (ورود، نشست، کنترل دسترسی) در `includes/auth.php` هستند.
 *
 * قاعدهٔ امنیتی مهم: نقش هرگز از ورودی فرم خوانده نمی‌شود. حساب ساخته‌شده در
 * این فایل همیشه role = user دارد؛ پس دستکاری POST نمی‌تواند کاربر را مدیر کند.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/identity.php';

/** کشورها و کدهای تلفن برای فرم ثبت‌نام. */
function jhd_countries(): array {
    return [
        'AF' => ['name' => 'افغانستان', 'dial' => '93'],
        'IR' => ['name' => 'ایران', 'dial' => '98'],
        'PK' => ['name' => 'پاکستان', 'dial' => '92'],
        'IQ' => ['name' => 'عراق', 'dial' => '964'],
        'TR' => ['name' => 'ترکیه', 'dial' => '90'],
        'SA' => ['name' => 'عربستان سعودی', 'dial' => '966'],
        'AE' => ['name' => 'امارات', 'dial' => '971'],
        'QA' => ['name' => 'قطر', 'dial' => '974'],
        'KW' => ['name' => 'کویت', 'dial' => '965'],
        'OM' => ['name' => 'عمان', 'dial' => '968'],
        'BH' => ['name' => 'بحرین', 'dial' => '973'],
        'LB' => ['name' => 'لبنان', 'dial' => '961'],
        'SY' => ['name' => 'سوریه', 'dial' => '963'],
        'JO' => ['name' => 'اردن', 'dial' => '962'],
        'YE' => ['name' => 'یمن', 'dial' => '967'],
        'EG' => ['name' => 'مصر', 'dial' => '20'],
        'IN' => ['name' => 'هند', 'dial' => '91'],
        'GB' => ['name' => 'بریتانیا', 'dial' => '44'],
        'DE' => ['name' => 'آلمان', 'dial' => '49'],
        'US' => ['name' => 'ایالات متحده', 'dial' => '1'],
        'CA' => ['name' => 'کانادا', 'dial' => '1'],
        'AU' => ['name' => 'استرالیا', 'dial' => '61'],
        'TJ' => ['name' => 'تاجیکستان', 'dial' => '992'],
        'UZ' => ['name' => 'ازبکستان', 'dial' => '998'],
        'OTHER' => ['name' => 'سایر کشورها', 'dial' => ''],
    ];
}

function normalizePhone(string $countryCode, string $phone): string {
    $dial = preg_replace('/\D+/', '', $countryCode) ?? '';
    $num = preg_replace('/\D+/', '', $phone) ?? '';
    $num = ltrim($num, '0');
    if ($dial !== '' && str_starts_with($num, $dial)) {
        $num = substr($num, strlen($dial));
        $num = ltrim($num, '0');
    }
    if ($num === '') return '';
    return $dial !== '' ? '+' . $dial . $num : '+' . $num;
}

/** سازگاری نام قدیمی: ساختار هویت در identity.php ساخته/مهاجرت می‌شود. */
function ensureMembersSchema(): void {
    jhd_ensure_identity_schema_once();
}

/**
 * ثبت‌نام عضو عمومی. نقش همیشه user است و از ورودی گرفته نمی‌شود.
 *
 * @return array{ok:bool,id?:int,error?:string}
 */
function registerMember(array $data, bool $establishSession = true): array {
    ensureMembersSchema();
    $field = static function (string $key) use ($data): string {
        $value = $data[$key] ?? null;
        return is_string($value) ? $value : '';
    };
    $name = trim($field('full_name'));
    $country = $field('country');
    $phone = trim($field('phone'));
    $email = mb_strtolower(trim($field('email')));
    $password = $field('password');
    $confirm = $field('password_confirm');
    $agreed = $field('agreed_terms') === '1';

    $countries = jhd_countries();
    if (!isset($countries[$country])) return ['ok' => false, 'error' => 'کشور انتخاب‌شده معتبر نیست.'];
    $dial = (string)$countries[$country]['dial'];
    $countryName = (string)$countries[$country]['name'];

    if (mb_strlen($name) < 2 || mb_strlen($name) > 120) return ['ok' => false, 'error' => 'نام را به‌درستی وارد کنید.'];
    if (!$agreed) return ['ok' => false, 'error' => 'برای ثبت‌نام باید با قوانین سایت موافق باشید.'];
    if (strlen($password) < 8 || strlen($password) > 4096) return ['ok' => false, 'error' => 'رمز عبور باید حداقل ۸ نویسه باشد.'];
    if (!hash_equals($password, $confirm)) return ['ok' => false, 'error' => 'تکرار رمز عبور مطابقت ندارد.'];
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'ایمیل واردشده معتبر نیست.'];

    $normalized = normalizePhone($dial, $phone);
    if ($normalized === '' || strlen($normalized) < 8) return ['ok' => false, 'error' => 'شماره تلفن معتبر نیست.'];

    $db = tryGetDB();
    if ($db === null) {
        return ['ok' => false, 'error' => 'ثبت‌نام در این لحظه ممکن نیست: اتصال به دیتابیس برقرار نشد.'];
    }
    $ipKey = hash('sha256', 'member-reg:' . clientIp());
    try {
        if (recordLoginAttempt($db, $ipKey) > 20) {
            return ['ok' => false, 'error' => 'تعداد تلاش‌ها بیش از حد است. بعداً دوباره تلاش کنید.'];
        }
    } catch (Throwable $e) {
        error_log('Registration limiter unavailable: ' . get_class($e));
    }

    $created = jhd_create_user([
        'full_name' => $name,
        'email' => $email,
        'phone' => $phone,
        'phone_normalized' => $normalized,
        'country' => $countryName,
        'country_code' => $dial,
        'password' => $password,
        'role' => JHD_ROLE_USER, // ثابت — نه از ورودی فرم
        'is_active' => 1,
        'agreed_terms' => 1,
    ]);
    if (empty($created['ok'])) {
        // پیام یکسان برای جلوگیری از افشای وجود/عدم وجود حساب.
        $generic = ['ok' => false, 'error' => 'امکان ایجاد حساب با این اطلاعات وجود ندارد.'];
        if (($created['error'] ?? '') === 'ایمیل واردشده معتبر نیست.' || ($created['error'] ?? '') === 'رمز عبور باید حداقل ۸ نویسه باشد.') {
            return $created;
        }
        return $generic;
    }

    if ($establishSession) {
        $user = jhd_user_by_id((int)$created['id']);
        if ($user !== null) {
            jhd_establish_session($user, false);
        }
    }
    return ['ok' => true, 'id' => (int)$created['id']];
}

/** تغییر رمز عضو عمومی (حداقل ۸ نویسه). */
function changeMemberPassword(int $memberId, string $current, string $next): bool {
    $result = jhd_change_password($memberId, $current, $next, '', true);
    return !empty($result['ok']);
}
