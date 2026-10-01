<?php
/** Shared, row-locked sessions; separate connection avoids committing application transactions. */
final class DatabaseSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface {
    private ?PDO $db = null;

    /**
     * قفل ردیفی نشست فقط روی MySQL/PostgreSQL معنا دارد. در SQLite تراکنشِ باز
     * روی همین فایل، کل دیتابیس را قفل می‌کند و نوشتن‌های بعدیِ همان درخواست
     * (مثل ثبت آخرین ورود) تا پایان تراکنش بلوکه می‌شوند؛ پس روی درایور
     * توسعهٔ SQLite قفل نمی‌گیریم و هر دستور خودکار commit می‌شود.
     */
    private function usesRowLocks(): bool {
        return databaseDriver() !== 'sqlite';
    }
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool {
        if ($this->usesRowLocks() && $this->db?->inTransaction()) $this->db->commit();
        $this->db = null;
        return true;
    }
    private function connection(): PDO { return $this->db ??= newDatabaseConnection(); }
    private function nowExpr(): string {
        return databaseDriver() === 'sqlite' ? "datetime('now')" : 'NOW()';
    }
    private function expiryExpr(string $amount, string $unit): string {
        if (databaseDriver() === 'sqlite') return "datetime('now','+$amount $unit')";
        if (databaseDriver() === 'mysql') return 'NOW()+INTERVAL ' . $amount . ' ' . strtoupper(rtrim($unit, 's'));
        return "NOW() + INTERVAL '$amount $unit'";
    }
    public function validateId(string $id): bool {
        $s = $this->connection()->prepare('SELECT 1 FROM app_sessions WHERE id=? AND expires_at>' . $this->nowExpr());
        $s->execute([$id]); return (bool)$s->fetchColumn();
    }
    public function read(string $id): string|false {
        $db = $this->connection();
        // PHP may call read() more than once per request (e.g. after
        // session_regenerate_id()); keep the existing row-lock transaction
        // instead of failing with "already an active transaction".
        if ($this->usesRowLocks() && !$db->inTransaction()) $db->beginTransaction();
        // ON CONFLICT DO NOTHING is normalized to INSERT IGNORE on MySQL.
        $db->prepare("INSERT INTO app_sessions (id,data,expires_at) VALUES (?, '', " . $this->nowExpr() . ') ON CONFLICT DO NOTHING')->execute([$id]);
        $lock = $this->usesRowLocks() ? ' FOR UPDATE' : '';
        $s = $db->prepare('SELECT data, expires_at>' . $this->nowExpr() . ' AS valid FROM app_sessions WHERE id=?' . $lock);
        $s->execute([$id]); $row = $s->fetch();
        return $row && $row['valid'] ? (base64_decode($row['data'], true) ?: '') : '';
    }
    public function write(string $id, string $data): bool {
        $payload = base64_encode($data);
        if (databaseDriver() === 'mysql') {
            // MySQL/MariaDB upsert; VALUES() refers to the row being inserted.
            $s = $this->connection()->prepare("INSERT INTO app_sessions (id,data,expires_at) VALUES (?,?,NOW()+INTERVAL 2 HOUR) ON DUPLICATE KEY UPDATE data=VALUES(data), expires_at=VALUES(expires_at)");
            return $s->execute([$id, $payload]);
        }
        $expiry = $this->expiryExpr('2', 'hours');
        $s = $this->connection()->prepare("INSERT INTO app_sessions (id,data,expires_at) VALUES (?, ?, $expiry) ON CONFLICT (id) DO UPDATE SET data=EXCLUDED.data, expires_at=EXCLUDED.expires_at");
        return $s->execute([$id, $payload]);
    }
    public function destroy(string $id): bool {
        return $this->connection()->prepare('DELETE FROM app_sessions WHERE id=?')->execute([$id]);
    }
    public function gc(int $max_lifetime): int|false {
        return $this->connection()->exec('DELETE FROM app_sessions WHERE expires_at<NOW()');
    }
    public function updateTimestamp(string $id, string $data): bool { return $this->write($id, $data); }
}
