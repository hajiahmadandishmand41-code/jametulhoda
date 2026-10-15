import fs from 'node:fs';
import assert from 'node:assert/strict';
const source=fs.readFileSync('.github/workflows/production-e2e.yml','utf8');
assert.ok(!source.includes('jametulhoda-git-{0}'),'never invent a hostname from a branch containing slashes');
assert.match(source,/sha: process\.env\.DEPLOYMENT_SHA/,'select deployment by exact commit');
assert.match(source,/status\.environment_url/,'use deployment platform URL');
assert.match(source,/set -euo pipefail\s+node tests\/production-probe\.mjs 2>&1 \| tee/,'tee cannot mask probe failure');
assert.match(source,/TEST_BASE_URL: \$\{\{ needs\.probe\.outputs\.url \}\}/,'authenticated audit targets the same verified deployment');
console.log('5 deployment workflow contract checks passed');
