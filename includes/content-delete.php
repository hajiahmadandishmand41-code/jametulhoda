<?php
require_once __DIR__ . '/storage.php';
/** Commit DB deletion with an outbox; remote failures remain retryable, never silently lost. */
function deleteContentRecord(string $table, int $id, ?string $postType = null): bool {
    if (!in_array($table, ['posts','lessons','books'], true) || $id<1) return false;
    $db=getDB(); $db->beginTransaction();
    try {
        $stmt=$db->prepare("SELECT * FROM $table WHERE id=? FOR UPDATE");$stmt->execute([$id]);$row=$stmt->fetch();
        if (!$row || ($postType && ($row['post_type']??'')!==$postType)) { $db->rollBack(); return false; }
        $files=[];
        foreach(['featured_image','featured_video','audio_file','video_file','pdf_file','word_file','cover_image'] as $column) if (!empty($row[$column])) $files[]=$row[$column];
        if ($table==='posts') {
            $stmt=$db->prepare('SELECT image_path FROM post_images WHERE post_id=?');$stmt->execute([$id]);$files=array_merge($files,$stmt->fetchAll(PDO::FETCH_COLUMN));
            $db->prepare('DELETE FROM post_images WHERE post_id=?')->execute([$id]);
        }
        $ref=$table==='posts'?'post':($table==='lessons'?'lesson':'book');
        $stmt=$db->prepare('SELECT file_path FROM media_files WHERE ref_type=? AND ref_id=?');$stmt->execute([$ref,$id]);$files=array_merge($files,$stmt->fetchAll(PDO::FETCH_COLUMN));
        $db->prepare('DELETE FROM media_files WHERE ref_type=? AND ref_id=?')->execute([$ref,$id]);
        $db->prepare("DELETE FROM $table WHERE id=?")->execute([$id]);
        $enqueue=$db->prepare('INSERT INTO storage_deletions (reference) VALUES (?) ON CONFLICT DO NOTHING');
        foreach(array_unique($files) as $file) if(storageKey($file)) $enqueue->execute([$file]);
        $db->commit();
    } catch(Throwable $e) { if($db->inTransaction())$db->rollBack();throw $e; }
    processStorageDeletions();
    return true;
}
function processStorageDeletions(): int {
    $db=getDB();$deleted=0;
    // A separate staging journal prevents older deletion workers from seeing
    // in-flight uploads. Promote only expired/completed requests atomically.
    if (databaseDriver() === 'mysql' || databaseDriver() === 'sqlite') {
        // MySQL/MariaDB has no data-modifying CTE: claim each due row by
        // deleting it (rowCount proves the claim), then queue the deletion.
        // SQLite uses the same portable loop (its NOW() is normalized).
        $due=$db->prepare('SELECT reference FROM pending_uploads WHERE not_before<=NOW() LIMIT 100');
        $due->execute();
        $claim=$db->prepare('DELETE FROM pending_uploads WHERE reference=? AND not_before<=NOW()');
        $enqueue=$db->prepare(databaseDriver() === 'sqlite'
            ? 'INSERT OR IGNORE INTO storage_deletions (reference) VALUES (?)'
            : 'INSERT IGNORE INTO storage_deletions (reference) VALUES (?)');
        foreach($due->fetchAll(PDO::FETCH_COLUMN) as $reference) {
            $claim->execute([$reference]);
            if($claim->rowCount()>0) $enqueue->execute([$reference]);
        }
    } else {
        $db->exec("WITH due AS (
            SELECT reference FROM pending_uploads WHERE not_before<=NOW()
            ORDER BY not_before LIMIT 100 FOR UPDATE SKIP LOCKED
        ), claimed AS (
            DELETE FROM pending_uploads p USING due WHERE p.reference=due.reference RETURNING p.reference
        ) INSERT INTO storage_deletions (reference) SELECT reference FROM claimed ON CONFLICT DO NOTHING");
    }
    foreach($db->query('SELECT reference FROM storage_deletions WHERE not_before <= NOW() ORDER BY created_at LIMIT 100')->fetchAll() as $job) {
        try {
            if(storedFileIsReferenced($job['reference'])) {
                // An edit failed or another content item still uses this file; cancel.
                $db->prepare('DELETE FROM storage_deletions WHERE reference=?')->execute([$job['reference']]);
                continue;
            }
            if(deleteStoredFile($job['reference'])) {
                $db->prepare('DELETE FROM storage_deletions WHERE reference=?')->execute([$job['reference']]);$deleted++;
            }
        } catch(Throwable $e) { error_log('Storage deletion pending; retry with bin/storage-gc.php'); }
    }
    return $deleted;
}
