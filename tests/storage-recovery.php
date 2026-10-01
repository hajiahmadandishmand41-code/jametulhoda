<?php
/** Destructive only to generated fixtures; run against a disposable development DB. */
require_once __DIR__.'/../includes/content-delete.php';
if (PHP_SAPI !== 'cli' || APP_ENV !== 'development' || UPLOAD_STORAGE !== 'local' || env_value('ALLOW_DESTRUCTIVE_TESTS') !== '1') {
    throw new RuntimeException('Explicit disposable-development test opt-in required.');
}
$checks=0;
function verifyRecovery(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    $checks++;
    echo "PASS $label\n";
}
beginContentUploadScope();
$db=getDB();
$db->beginTransaction();
$url=storeValidatedFile(__DIR__.'/fixtures/image.png','image','posts');
verifyRecovery($url!=='','staged image stored');
$path=UPLOAD_DIR.storageKey($url);
$db->rollBack();
$job=uploadJournalDB()->prepare('SELECT not_before > NOW() FROM pending_uploads WHERE reference=?');
$job->execute([$url]);
verifyRecovery((bool)$job->fetchColumn(),'cleanup intent survives content transaction rollback');
processStorageDeletions();
verifyRecovery(is_file($path),'worker respects in-flight grace period');
uploadJournalDB()->prepare('UPDATE pending_uploads SET not_before=NOW() WHERE reference=?')->execute([$url]);
processStorageDeletions();
clearstatcache(true,$path);
verifyRecovery(!is_file($path),'expired unreferenced upload removed');
$job->execute([$url]);
verifyRecovery($job->fetchColumn()===false,'completed cleanup job removed');

$db->beginTransaction();
$url=storeValidatedFile(__DIR__.'/fixtures/image.png','image','posts');
verifyRecovery($url!=='','second staged image stored');
$path=UPLOAD_DIR.storageKey($url);
$insert=$db->prepare('INSERT INTO books (title,cover_image) VALUES (?,?) RETURNING id');
$insert->execute(['qa-storage-recovery-'.bin2hex(random_bytes(8)),$url]);
$id=(int)$insert->fetchColumn();
// SQLite refuses COMMIT while a RETURNING cursor is still open.
$insert->closeCursor();
$db->commit();
uploadJournalDB()->prepare('UPDATE pending_uploads SET not_before=NOW() WHERE reference=?')->execute([$url]);
processStorageDeletions();
verifyRecovery(is_file($path),'committed content keeps its upload');
$job->execute([$url]);
verifyRecovery($job->fetchColumn()===false,'referenced upload cleanup cancelled');
verifyRecovery(deleteContentRecord('books',$id),'fixture content deleted');
clearstatcache(true,$path);
verifyRecovery(!is_file($path),'fixture file deleted with content');
echo "$checks storage recovery checks passed\n";
