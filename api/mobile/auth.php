<?php
/**
 * api/mobile/auth.php — احراز هویت موبایل با توکن Bearer
 *
 * جریان‌ها: ثبت‌نام، ورود، پروفایل، تغییر رمز، خروج امن.
 *
 * امنیت:
 *   • رمز فقط با password_hash()/password_verify() — همان مسیر identity.php.
 *   • نقش هرگز از ورودی خوانده نمی‌شود؛ حساب ساخته‌شده همیشه role = user است.
 *   • توکن دسترسی تصادفی ۲۵۶ بیتی؛ فقط hash آن ذخیره می‌شود.
 *   • محدودیت تلاش ناموفق (login_limits) روی حساب و IP — همان قاعدهٔ وب.
 *   • تغییر رمز ⇒ auth_version بالا می‌رود و همهٔ توکن‌ها باطل می‌شوند.
 *   • پاسخ‌ها هرگز hash رمز، توکن دیگران یا اطلاعات مدیریتی ندارند.
 */
declare(strict_types=1);

require_once __DIR__ . '/common.php';

$action = (string)($_GET['action'] ?? '');

switch ($action) {
    // ─── ثبت‌نام ─────────────────────────────────────────────────────
    case 'register':
        jhd_api_method(['POST']);
        $body = jhd_api_body();
        $name = jhd_api_str($body, 'full_name', 120);
        $country = jhd_api_str($body, 'country', 40);
        $phone = jhd_api_str($body, 'phone', 32);
        $email = mb_strtolower(jhd_api_str($body, 'email', 180));
        $password = (string)($body['password'] ?? '');
        $confirm = (string)($body['password_confirm'] ?? $password);
        $device = jhd_api_str($body, 'device_label', 120);
        $agreed = $body['agreed_terms'] ?? false;
        if (!is_string($password)) jhd_api_error('invalid_password', 'رمز عبور معتبر نیست.', 422);
        if (!is_string($confirm)) $confirm = $password;

        $countries = jhd_countries();
        if ($country === '' || !isset($countries[$country])) {
            jhd_api_error('invalid_country', 'کشور انتخاب‌شده معتبر نیست.', 422);
        }
        $dial = (string)$countries[$country]['dial'];
        if (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 120) {
            jhd_api_error('invalid_name', 'نام را به‌درستی وارد کنید.', 422);
        }
        if ($agreed !== true && $agreed !== 1 && $agreed !== '1') {
            jhd_api_error('terms_required', 'برای ثبت‌نام باید با قوانین سایت موافق باشید.', 422);
        }
        if (strlen($password) < 8 || strlen($password) > 4096) {
            jhd_api_error('invalid_password', 'رمز عبور باید حداقل ۸ نویسه باشد.', 422);
        }
        if (!hash_equals($password, $confirm)) {
            jhd_api_error('password_mismatch', 'تکرار رمز عبور مطابقت ندارد.', 422);
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jhd_api_error('invalid_email', 'ایمیل واردشده معتبر نیست.', 422);
        }
        $normalized = normalizePhone($dial, $phone);
        if ($normalized === '' || strlen($normalized) < 8) {
            jhd_api_error('invalid_phone', 'شماره تلفن معتبر نیست.', 422);
        }

        $db = tryGetDB();
        if ($db === null) jhd_api_error('unavailable', 'ثبت‌نام در این لحظه ممکن نیست. بعداً دوباره تلاش کنید.', 503);
        try {
            if (recordLoginAttempt($db, hash('sha256', 'member-reg:' . clientIp())) > 20) {
                jhd_api_error('rate_limited', 'تعداد تلاش‌ها بیش از حد است. بعداً دوباره تلاش کنید.', 429);
            }
        } catch (Throwable $e) {
            error_log('Registration limiter unavailable: ' . get_class($e));
        }

        // نقش ثابت user است — از هیچ ورودی خوانده نمی‌شود.
        $created = jhd_create_user([
            'full_name' => $name,
            'email' => $email,
            'phone' => $phone,
            'phone_normalized' => $normalized,
            'country' => (string)$countries[$country]['name'],
            'country_code' => $dial,
            'password' => $password,
            'role' => JHD_ROLE_USER,
            'is_active' => 1,
            'agreed_terms' => 1,
        ]);
        if (empty($created['ok'])) {
            // پیام یکسان برای جلوگیری از افشای وجود/عدم وجود حساب.
            $error = (string)($created['error'] ?? '');
            if (in_array($error, ['ایمیل واردشده معتبر نیست.', 'رمز عبور باید حداقل ۸ نویسه باشد.'], true)) {
                jhd_api_error('invalid_request', $error, 422);
            }
            if (str_contains($error, 'این ایمیل قبلاً') || str_contains($error, 'این شمارهٔ تلفن قبلاً')) {
                jhd_api_error('duplicate_account', 'امکان ایجاد حساب با این اطلاعات وجود ندارد.', 422);
            }
            jhd_api_error('unavailable', 'امکان ایجاد حساب با این اطلاعات وجود ندارد.', 503);
        }
        $user = jhd_user_by_id((int)$created['id']);
        if ($user === null) jhd_api_error('unavailable', 'حساب ساخته شد اما بازیابی نشد. دوباره وارد شوید.', 503);
        $issued = jhd_api_issue_token((int)$user['id'], $device);
        jhd_api_data([
            'token' => $issued['token'],
            'token_type' => 'Bearer',
            'expires_at' => $issued['expires_at'],
            'user' => jhd_api_account($user),
        ], [], 201);

    // ─── ورود ───────────────────────────────────────────────────────
    case 'login':
        jhd_api_method(['POST']);
        $body = jhd_api_body();
        $identifier = jhd_api_str($body, 'identifier', 190);
        $password = (string)($body['password'] ?? '');
        $device = jhd_api_str($body, 'device_label', 120);
        if (!is_string($password)) jhd_api_error('invalid_credentials', 'شناسه یا رمز عبور نادرست است.', 401);
        if ($identifier === '' || $password === '') {
            jhd_api_error('missing_credentials', 'شناسه و رمز عبور را وارد کنید.', 422);
        }

        $db = tryGetDB();
        if ($db === null) jhd_api_error('unavailable', 'ورود در این لحظه ممکن نیست. بعداً دوباره تلاش کنید.', 503);
        try {
            if (recordLoginAttempt($db, hash('sha256', 'ip:' . clientIp())) > 50) {
                jhd_api_error('rate_limited', 'تلاش‌های ناموفق از این دستگاه بیش از حد مجاز است.', 429);
            }
            if (recordLoginAttempt($db, hash('sha256', 'user:' . mb_strtolower($identifier))) > 8) {
                jhd_api_error('rate_limited', 'تلاش‌های ناموفق زیاد است. ۱۵ دقیقه بعد دوباره تلاش کنید.', 429);
            }
        } catch (Throwable $e) {
            error_log('Login limiter unavailable: ' . get_class($e));
        }

        $result = jhd_verify_credentials($identifier, $password);
        if (empty($result['ok'])) {
            $code = (string)($result['code'] ?? 'invalid');
            $status = $code === 'inactive' ? 403 : 401;
            jhd_api_error($code === 'inactive' ? 'inactive' : 'invalid_credentials', (string)($result['error'] ?? 'شناسه یا رمز عبور نادرست است.'), $status);
        }
        $user = $result['user'];
        resetLoginAttempts($db, hash('sha256', 'user:' . mb_strtolower($identifier)));
        try {
            $nowSql = databaseDriver() === 'sqlite' ? "datetime('now')" : 'NOW()';
            $db->prepare("UPDATE users SET last_login = $nowSql WHERE id = ?")->execute([(int)$user['id']]);
        } catch (Throwable $e) {
            error_log('last_login update failed: ' . get_class($e));
        }
        $issued = jhd_api_issue_token((int)$user['id'], $device);
        jhd_api_data([
            'token' => $issued['token'],
            'token_type' => 'Bearer',
            'expires_at' => $issued['expires_at'],
            'user' => jhd_api_account($user),
        ]);

    // ─── کاربر جاری ─────────────────────────────────────────────────
    case 'me':
        jhd_api_method(['GET', 'HEAD']);
        $user = jhd_api_user(true);
        jhd_api_data(['user' => jhd_api_account($user)]);

    // ─── به‌روزرسانی پروفایل ────────────────────────────────────────
    case 'profile':
        jhd_api_method(['POST']);
        $user = jhd_api_user(true);
        $body = jhd_api_body();
        $data = [];
        if (array_key_exists('full_name', $body)) $data['full_name'] = jhd_api_str($body, 'full_name', 120);
        if (array_key_exists('email', $body)) $data['email'] = mb_strtolower(jhd_api_str($body, 'email', 180));
        if (array_key_exists('phone', $body)) {
            $phone = jhd_api_str($body, 'phone', 32);
            $data['phone'] = $phone;
            $dial = (string)($user['country_code'] ?? '93');
            $data['phone_normalized'] = $phone !== '' ? normalizePhone($dial, $phone) : '';
        }
        if (array_key_exists('avatar', $body)) $data['avatar'] = jhd_api_str($body, 'avatar', 350);
        if ($data === []) jhd_api_error('invalid_request', 'هیچ فیلدی برای به‌روزرسانی ارسال نشده است.', 422);
        $result = jhd_update_profile((int)$user['id'], $data);
        if (empty($result['ok'])) {
            jhd_api_error('update_failed', (string)($result['error'] ?? 'ذخیرهٔ اطلاعات انجام نشد.'), 422);
        }
        jhd_api_data(['user' => jhd_api_account(jhd_user_by_id((int)$user['id']) ?? $user)]);

    // ─── تغییر رمز ──────────────────────────────────────────────────
    case 'change-password':
        jhd_api_method(['POST']);
        $user = jhd_api_user(true);
        $body = jhd_api_body();
        $current = (string)($body['current_password'] ?? '');
        $next = (string)($body['new_password'] ?? '');
        $confirm = (string)($body['new_password_confirm'] ?? $next);
        if (!is_string($current) || !is_string($next) || !is_string($confirm)) {
            jhd_api_error('invalid_password', 'رمز عبور معتبر نیست.', 422);
        }
        $result = jhd_change_password((int)$user['id'], $current, $next, $confirm, true);
        if (empty($result['ok'])) {
            $message = (string)($result['error'] ?? 'تغییر رمز انجام نشد.');
            $status = str_contains($message, 'نادرست') ? 401 : 422;
            jhd_api_error('password_change_failed', $message, $status);
        }
        // همهٔ نشست‌ها/توکن‌ها باطل می‌شوند؛ خود این درخواست‌دهنده هم دوباره وارد می‌شود.
        jhd_api_revoke_tokens((int)$user['id']);
        jhd_api_data(['changed' => true, 'reauthenticated_required' => true]);

    // ─── خروج امن ───────────────────────────────────────────────────
    case 'logout':
        jhd_api_method(['POST']);
        $user = jhd_api_user(true);
        jhd_api_revoke_tokens((int)$user['id'], jhd_api_bearer_token());
        jhd_api_data(['logged_out' => true]);

    default:
        jhd_api_error('not_found', 'مسیر درخواستی وجود ندارد.', 404);
}
