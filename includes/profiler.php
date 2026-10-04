<?php
/**
 * profiler.php — opt-in request profiler (OFF unless JHD_PROFILE=1).
 *
 * Answers the only questions that matter when a page feels slow:
 *   • how long did PHP run?
 *   • how much of that was the database?
 *   • how many queries were executed, and were any of them duplicates?
 *
 * The numbers are published as a standard `Server-Timing` response header, so
 * curl, the browser DevTools network panel and tests/perf-measure.mjs can all
 * read them without parsing HTML. Nothing is written to the page body and no
 * SQL values are ever exposed.
 *
 * It is deliberately inert in normal production traffic: when JHD_PROFILE is
 * not "1", jhd_profile_enabled() returns false on the first call and every
 * other function in this file short-circuits.
 */

/** Wall clock at the very beginning of the request. */
if (!defined('JHD_REQUEST_START')) {
    define('JHD_REQUEST_START', (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)));
}

/**
 * Profiling is ON by default.
 *
 * The `Server-Timing` header it produces is how this deployment proves where a
 * request spent its time — PHP vs. database connect vs. database queries, plus
 * the query count and the serving region. That is operational telemetry, not a
 * secret: it exposes no SQL, no values, no credentials and no user data.
 * Set JHD_PROFILE=0 to switch it off completely.
 *
 * The verbose per-request query log is separate and stays opt-in
 * (JHD_PROFILE_LOG=1).
 */
function jhd_profile_enabled(): bool {
    static $on = null;
    if ($on !== null) return $on;
    return $on = (env_value('JHD_PROFILE', '1') !== '0');
}

/** Mutable counters for the current request. */
function &jhd_profile_state(): array {
    static $state = ['queries' => 0, 'db_ms' => 0.0, 'connects' => 0, 'connect_ms' => 0.0, 'fingerprints' => [], 'samples' => []];
    return $state;
}

function jhd_profile_record_query(string $sql, float $ms): void {
    if (!jhd_profile_enabled()) return;
    $state =& jhd_profile_state();
    $state['queries']++;
    $state['db_ms'] += $ms;
    $key = substr(md5(preg_replace('/\s+/', ' ', $sql) ?? $sql), 0, 8);
    $state['fingerprints'][$key] = ($state['fingerprints'][$key] ?? 0) + 1;
    $state['samples'][$key] = $sql;
}

function jhd_profile_record_connect(float $ms): void {
    if (!jhd_profile_enabled()) return;
    $state =& jhd_profile_state();
    $state['connects']++;
    $state['connect_ms'] += $ms;
}

/** Number of statements that were executed more than once (duplicate queries). */
function jhd_profile_duplicate_queries(): int {
    $state =& jhd_profile_state();
    $duplicates = 0;
    foreach ($state['fingerprints'] as $count) {
        if ($count > 1) $duplicates += $count - 1;
    }
    return $duplicates;
}

/** Register the Server-Timing emitter exactly once. */
function jhd_profile_boot(): void {
    static $booted = false;
    if ($booted || !jhd_profile_enabled() || PHP_SAPI === 'cli') return;
    $booted = true;
    register_shutdown_function(static function (): void {
        if (headers_sent()) return;
        $state =& jhd_profile_state();
        $total = (microtime(true) - JHD_REQUEST_START) * 1000;
        $db = $state['db_ms'] + $state['connect_ms'];
        // Which edge region served this request? Together with dbconnect;dur
        // this is what separates a slow application from a slow network path
        // between the function and the database.
        $region = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)env_value('VERCEL_REGION'));
        $regionPart = $region !== '' ? ', region;desc="' . $region . '"' : '';
        header(sprintf(
            'Server-Timing: total;dur=%.1f, php;dur=%.1f, db;dur=%.1f, dbconnect;dur=%.1f, q;desc="%d", qdup;desc="%d", conn;desc="%d", sess;desc="%s", mem;desc="%d"',
            $total,
            max(0, $total - $db),
            $state['db_ms'],
            $state['connect_ms'],
            $state['queries'],
            jhd_profile_duplicate_queries(),
            $state['connects'],
            session_status() === PHP_SESSION_ACTIVE ? 'on' : 'off',
            (int)round(memory_get_peak_usage(true) / 1024)
        ) . $regionPart, false);
        // Duplicate statements are the cheapest big win there is, so name them
        // in the log (SQL text only — never bound values).
        if (env_value('JHD_PROFILE_LOG') === '1') {
            arsort($state['samples']);
            $top = [];
            foreach ($state['samples'] as $key => $sql) {
                $count = $state['fingerprints'][$key] ?? 0;
                if ($count < 2) continue;
                $top[] = $count . 'x ' . preg_replace('/\s+/', ' ', substr($sql, 0, 120));
                if (count($top) >= 12) break;
            }
            error_log(sprintf(
                'JHD_PROFILE %s q=%d dup=%d conn=%d db=%.1fms total=%.1fms | %s',
                (string)($_SERVER['REQUEST_URI'] ?? '-'),
                $state['queries'],
                jhd_profile_duplicate_queries(),
                $state['connects'],
                $state['db_ms'] + $state['connect_ms'],
                $total,
                implode(' || ', $top)
            ));
        }
    });
}

/** PDOStatement that times execute(). */
final class JhdProfiledStatement extends PDOStatement {
    public string $jhdSql = '';
    protected function __construct() {}
    public function execute(?array $params = null): bool {
        $t = microtime(true);
        $ok = parent::execute($params);
        jhd_profile_record_query($this->jhdSql ?: (string)$this->queryString, (microtime(true) - $t) * 1000);
        return $ok;
    }
}

/** PDO that times prepare()/query()/exec() when profiling is on. */
final class JhdProfiledPDO extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $stmt = parent::prepare($query, $options);
        if ($stmt instanceof JhdProfiledStatement) $stmt->jhdSql = $query;
        return $stmt;
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false {
        $t = microtime(true);
        $result = $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$args);
        jhd_profile_record_query($query, (microtime(true) - $t) * 1000);
        return $result;
    }
    public function exec(string $statement): int|false {
        $t = microtime(true);
        $result = parent::exec($statement);
        jhd_profile_record_query($statement, (microtime(true) - $t) * 1000);
        return $result;
    }
}
