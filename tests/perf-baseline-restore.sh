#!/usr/bin/env bash
#
# tests/perf-baseline-restore.sh — put the PRE-optimisation application code
# back in the working tree and re-inject only the measurement hooks, so the
# "before" numbers are produced by exactly the same instrument as the "after"
# numbers. Nothing here is used by the application at runtime; it exists so the
# performance report can be verified instead of believed.
#
# Usage: tests/perf-baseline-restore.sh <baseline-sha>
set -euo pipefail
BASE_SHA="${1:?baseline sha required}"

# Application code as it was before the optimisation work.
git checkout "$BASE_SHA" -- \
  index.php router.php vercel.json \
  config/config.php config/database.php \
  includes pages

# `git checkout <sha> -- <path>` never deletes files that the old tree did not
# contain, so includes/profiler.php is still here.
test -f includes/profiler.php

# 1) Boot the profiler from the restored config.php.
cat >> config/config.php <<'PHP'

// [perf-baseline] measurement hook — not part of the baseline application code.
require_once __DIR__ . '/../includes/profiler.php';
jhd_profile_boot();
PHP

# 2) Count queries: swap the plain PDO for the profiling subclass and time the
#    connect. This mirrors exactly what the optimised build does.
python3 - "$BASE_SHA" <<'PY'
import re, sys
p = 'config/database.php'
s = open(p).read()

old = """    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,"""
new = """    $jhdConnectStart = microtime(true);
    $pdo = new JhdProfiledPDO($dsn, $user, $pass, [
        PDO::ATTR_STATEMENT_CLASS => [JhdProfiledStatement::class, []],
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,"""
assert old in s, 'baseline PDO construction not found'
s = s.replace(old, new, 1)

old = """    $pdo->exec("SET TIME ZONE 'Asia/Kabul'");"""
new = """    jhd_profile_record_connect((microtime(true) - $jhdConnectStart) * 1000);
    $pdo->exec("SET TIME ZONE 'Asia/Kabul'");"""
assert old in s, 'baseline SET TIME ZONE not found'
s = s.replace(old, new, 1)

# A CI PostgreSQL service has no TLS listener; the baseline refused anything
# but sslmode=require. This only affects the test harness, never production.
old = """    if (!in_array($ssl, ['require', 'verify-ca', 'verify-full'], true)) {"""
new = """    if (!in_array($ssl, ['require', 'verify-ca', 'verify-full', 'disable'], true)) {"""
assert old in s, 'baseline sslmode guard not found'
s = s.replace(old, new, 1)

open(p, 'w').write(s)
print('baseline instrumented')
PY

php -l config/config.php
php -l config/database.php
echo "baseline restored from $BASE_SHA"
