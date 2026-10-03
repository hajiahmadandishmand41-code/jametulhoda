<?php
/**
 * tests/fixtures/attach-demo-media.php — attaches a real image to seeded rows.
 *
 * The seed intentionally leaves most media empty (the design system renders an
 * editorial plate instead of a stock photo). The responsive audit needs cards
 * WITH images so that the measured card heights are the real ones, so this
 * helper points them at a committed asset.
 *
 * Usage (local/CI, throwaway database only):
 *   php tests/fixtures/attach-demo-media.php /tmp/jametulhoda-responsive.sqlite
 */
declare(strict_types=1);

$path = $argv[1] ?? '';
if ($path === '') {
    fwrite(STDERR, "Usage: php tests/fixtures/attach-demo-media.php <sqlite-path>\n");
    exit(1);
}
if (!is_file($path)) {
    fwrite(STDERR, "Database not found: $path\n");
    exit(1);
}

$db = new PDO('sqlite:' . $path);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$image = 'assets/img/founder.jpg';

$statements = [
    "UPDATE posts   SET featured_image = '$image' WHERE featured_image IS NULL OR featured_image = ''",
    "UPDATE books   SET cover_image    = '$image' WHERE cover_image    IS NULL OR cover_image    = ''",
    "UPDATE topics  SET cover_image    = '$image' WHERE cover_image    IS NULL OR cover_image    = ''",
    "UPDATE lessons SET featured_image = '$image' WHERE featured_image IS NULL OR featured_image = ''",
];
foreach ($statements as $statement) {
    $db->exec($statement);
}

$counts = [];
foreach (['posts', 'books', 'topics', 'lessons'] as $table) {
    $column = $table === 'books' || $table === 'topics' ? 'cover_image' : 'featured_image';
    $counts[$table] = (int)$db->query("SELECT COUNT(*) FROM $table WHERE $column IS NOT NULL AND $column <> ''")->fetchColumn();
}
echo 'demo media attached: ' . json_encode($counts, JSON_UNESCAPED_SLASHES) . "\n";
