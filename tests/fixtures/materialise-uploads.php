<?php
/**
 * tests/fixtures/materialise-uploads.php — creates the files the seed points at.
 *
 * The seed references real paths under uploads/ (a cover image, an audio
 * track, a short video, a PDF and a Word document). Those folders are
 * gitignored — uploaded media is never committed — so on a fresh checkout the
 * links to them are broken and the link audit reports false failures.
 *
 * This helper writes small, VALID stand-ins for exactly those paths, so a
 * seeded instance is internally consistent:
 *
 *   uploads/images/seed-cover.png     ← tests/fixtures/image.png
 *   uploads/audios/seed-audio.mp3     ← tests/fixtures/audio.mp3
 *   uploads/videos/seed-video.mp4     ← tests/fixtures/video.mp4
 *   uploads/documents/seed-doc.pdf    ← generated
 *   uploads/documents/seed-doc.docx   ← generated
 *
 * Usage (local/CI, throwaway instance only):
 *   php tests/fixtures/materialise-uploads.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$fixtures = $root . '/tests/fixtures';

$copies = [
    ['image.png',  'uploads/images/seed-cover.png'],
    ['audio.mp3',  'uploads/audios/seed-audio.mp3'],
    ['video.mp4',  'uploads/videos/seed-video.mp4'],
];

$written = [];
foreach ($copies as [$from, $to]) {
    $source = $fixtures . '/' . $from;
    if (!is_file($source)) {
        fwrite(STDERR, "Missing fixture: $source\n");
        exit(1);
    }
    $target = $root . '/' . $to;
    if (!is_dir(dirname($target))) mkdir(dirname($target), 0775, true);
    if (!copy($source, $target)) {
        fwrite(STDERR, "Could not write $target\n");
        exit(1);
    }
    $written[] = $to;
}

// ── A minimal but genuinely valid PDF ────────────────────────────────────────
$pdfPath = $root . '/uploads/documents/seed-doc.pdf';
if (!is_dir(dirname($pdfPath))) mkdir(dirname($pdfPath), 0775, true);
$pdf = "%PDF-1.4\n"
    . "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
    . "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
    . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]"
    . "/Resources<</Font<</F1 5 0 R>>>>/Contents 4 0 R>>endobj\n"
    . "4 0 obj<</Length 92>>stream\n"
    . "BT /F1 16 Tf 60 780 Td (Jameh-ye Jame-ye al-Hoda - sample document) Tj ET\n"
    . "endstream\nendobj\n"
    . "5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\n"
    . "trailer<</Root 1 0 R>>\n"
    . "%%EOF\n";
file_put_contents($pdfPath, $pdf);
$written[] = 'uploads/documents/seed-doc.pdf';

// ── A minimal but genuinely valid DOCX (Open XML) ────────────────────────────
$docxPath = $root . '/uploads/documents/seed-doc.docx';
if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "The zip extension is required to build the .docx fixture\n");
    exit(1);
}
$parts = [
    '[Content_Types].xml' =>
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '</Types>',
    '_rels/.rels' =>
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '</Relationships>',
    'word/document.xml' =>
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
        . '<w:p><w:r><w:t>Jameh-ye Jame-ye al-Hoda - sample document</w:t></w:r></w:p>'
        . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/></w:sectPr>'
        . '</w:body></w:document>',
];
$zip = new ZipArchive();
if ($zip->open($docxPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Could not write $docxPath\n");
    exit(1);
}
foreach ($parts as $name => $content) $zip->addFromString($name, $content);
$zip->close();
$written[] = 'uploads/documents/seed-doc.docx';

echo 'seed upload fixtures written: ' . json_encode($written, JSON_UNESCAPED_SLASHES) . "\n";
