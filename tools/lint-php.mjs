#!/usr/bin/env node
/**
 * tools/lint-php.mjs — بررسی نگارشی همهٔ فایل‌های PHP مخزن، بدون نیاز به PHP.
 *
 * چرا؟ در بیشتر محیط‌های بازبینی (CI سبک، Vercel preview، لپ‌تاپ توسعه‌دهندهٔ
 * تازه‌کار) باینری `php` نصب نیست و `php -l` در دسترس نبود؛ در نتیجه یک `?>`
 * جاافتاده یا پرانتز بسته‌نشده می‌توانست تا اولین درخواست زنده پیش نیاید.
 * این اسکریپت همان کار را با AST‑parser انجام می‌دهد: هر فایل را پارس می‌کند و
 * اولین خطای نگارشی (با شمارهٔ خط) را گزارش می‌دهد.
 *
 * کاربرد:
 *   node tools/lint-php.mjs              # کل مخزن
 *   node tools/lint-php.mjs a.php b.php  # فقط فایل‌های داده‌شده (مثلاً در pre-commit)
 */
import { readdirSync, statSync, readFileSync } from 'node:fs';
import { join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
let phpParserModule;
try {
    phpParserModule = require('php-parser');
} catch {
    console.error('✗ بستهٔ «php-parser» نصب نیست. ابتدا `npm ci` (یا `npm i`) را اجرا کنید.');
    process.exit(1);
}
const PhpParser = phpParserModule.default ?? phpParserModule;
const ROOT = fileURLToPath(new URL('..', import.meta.url));

const SKIP_DIRS = new Set(['node_modules', '.git', '.vercel', 'vendor', 'test-results', 'snapshots', 'dist', '.cache']);

/** همهٔ فایل‌های PHP داخل ریشهٔ مخزن (یا لیست داده‌شده در خط فرمان). */
function collect(dir) {
    const out = [];
    for (const entry of readdirSync(dir)) {
        if (entry.startsWith('.') && entry !== '.htaccess') continue;
        const full = join(dir, entry);
        const st = statSync(full);
        if (st.isDirectory()) {
            if (!SKIP_DIRS.has(entry)) out.push(...collect(full));
        } else if (entry.endsWith('.php')) {
            out.push(full);
        }
    }
    return out;
}

const root = resolve(ROOT);
const args = process.argv.slice(2);
const files = args.length ? args.map((f) => resolve(process.cwd(), f)) : collect(root).sort();
const parser = new PhpParser({
    parser: { php7: true, suppressErrors: false },
    ast: { withPositions: true },
});

let failed = 0;
for (const file of files) {
    let source;
    try {
        source = readFileSync(file, 'utf8');
    } catch (error) {
        failed++;
        console.error(`✗ ${relative(root, file)} — خوانده نشد (${error.message})`);
        continue;
    }
    try {
        parser.parseCode(source, file);
    } catch (error) {
        failed++;
        const line = error?.lineNumber ?? error?.line ?? '?';
        console.error(`✗ ${relative(root, file)}:${line} — ${String(error.message ?? error).split('\n')[0]}`);
    }
}

if (failed) {
    console.error(`\n${failed} فایل از ${files.length} فایل PHP خطای نگارشی دارد.`);
    process.exit(1);
}
console.log(`✓ ${files.length} فایل PHP بدون خطای نگارشی پارس شد.`);
