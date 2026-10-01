<?php
/**
 * bin/dev-db.php — ساخت/بازسازی دیتابیس محلی SQLite (فقط CLI)
 *
 * طرحواره و داده‌های نمونه از tests/fixtures/{schema,seed}.sqlite.sql خوانده
 * می‌شوند؛ همان فایل‌هایی که درایور محلی DB_DRIVER=sqlite انتظار دارد
 * (config/database.php). این اسکریپت دیتابیس را **از نو** می‌سازد، پس فقط برای
 * توسعه/آزمون محلی است و هرگز در production اجرا نمی‌شود (SQLite هم در
 * production رد می‌شود).
 *
 * نمونه:
 *   DB_DRIVER=sqlite SQLITE_PATH=/tmp/jametulhoda-dev.sqlite php bin/dev-db.php
 *   php bin/dev-db.php /tmp/jametulhoda-dev.sqlite
 * سپس:
 *   DB_DRIVER=sqlite SQLITE_PATH=/tmp/jametulhoda-dev.sqlite php -S 0.0.0.0:8080 router.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';

$path = trim($argv[1] ?? env_value('SQLITE_PATH', ''));
if ($path === '') {
    $path = rtrim(sys_get_temp_dir(), '/') . '/jametulhoda-test.sqlite';
}
if (!str_starts_with($path, '/') || str_contains($path, '..')) {
    fwrite(STDERR, "SQLITE_PATH must be an absolute path without '..': $path\n");
    exit(1);
}
if (APP_ENV === 'production' || env_value('VERCEL') !== '') {
    fwrite(STDERR, "Refusing to build a SQLite database in production.\n");
    exit(1);
}

$root = dirname(__DIR__);
$fixtures = ['tests/fixtures/schema.sqlite.sql', 'tests/fixtures/seed.sqlite.sql'];

$directory = dirname($path);
if (!is_dir($directory) && !@mkdir($directory, 0777, true)) {
    fwrite(STDERR, "Cannot create directory: $directory\n");
    exit(1);
}
@unlink($path);

$pdo = new PDO('sqlite:' . $path, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys=ON');

$applied = 0;
$failed = 0;
foreach ($fixtures as $fixture) {
    $sql = file_get_contents($root . '/' . $fixture);
    if ($sql === false) {
        fwrite(STDERR, "Missing fixture: $fixture\n");
        exit(1);
    }
    // The fixtures never contain an ASCII semicolon inside a string literal, so
    // splitting on ";\n" is safe and keeps the multi-row INSERTs intact.
    foreach (preg_split('/;\s*\n/', $sql) as $statement) {
        $lines = array_filter(
            explode("\n", $statement),
            static fn($line) => trim($line) !== '' && !str_starts_with(trim($line), '--')
        );
        $statement = trim(implode("\n", $lines));
        if ($statement === '') continue;
        try {
            $pdo->exec($statement);
            $applied++;
        } catch (Throwable $e) {
            $failed++;
            fwrite(STDERR, "FAILED in $fixture: " . substr($statement, 0, 120) . "\n  → " . $e->getMessage() . "\n");
        }
    }
}

echo "SQLite database: $path\n";
echo "Statements applied: $applied, failed: $failed\n";
foreach (['users', 'posts', 'topics', 'books', 'lessons', 'media_files', 'categories', 'settings'] as $table) {
    try {
        printf("  %-18s %d rows\n", $table, (int)$pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn());
    } catch (Throwable $e) {
        printf("  %-18s missing\n", $table);
        $failed++;
    }
}
echo "Run the site with:\n";
echo "  DB_DRIVER=sqlite SQLITE_PATH=$path php -S 0.0.0.0:8080 router.php\n";
echo "Seeded administrator: admin / TestAdmin123!@# (local testing only)\n";
exit($failed === 0 ? 0 : 1);
