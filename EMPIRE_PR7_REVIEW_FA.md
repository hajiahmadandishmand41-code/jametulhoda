# بررسی و ادغام PR #7 — مخزن `empire`

**PR:** [#7 build(deps): bump the root-version group](https://github.com/hajiahmadandishmand41-code/empire/pull/7)
**شاخه:** `dependabot/npm_and_yarn/root-version-1153997f03` → `main`
**تاریخ بررسی:** ۲۰۲۶-۱۱-۲۲

---

## ⛔ نتیجه‌ی اجرایی: ادغام انجام نشد — مانع مجوز

```
gh pr merge 7 --merge
→ Resource not accessible by integration (403)

curl POST /repos/.../empire/issues/7/comments  → 403
git push origin <branch>                       → 403
POST /repos/.../empire/git/refs                → 403
PUT  /repos/.../pulls/7/update-branch          → 403
```

**همه‌ی نوشتن‌ها روی `empire` رد می‌شوند، در حالی که خواندن آزاد است.**
`GET /repos/.../empire` مقدار `admin: true` برمی‌گرداند، اما این ادعای متادیتاست؛
توکن این نشست یک توکن **read-only** برای این مخزن است (فقط ۲۴ کاراکتر).
قبلاً هم همین مانع در فایل `EMPIRE_PR_REVIEW_FA.md` ثبت شده بود.

**اقدام لازم از شما:** در Arena اتصال GitHub را دوباره برقرار کنید و مطمئن شوید
اپ GitHub روی `hajiahmadandishmand41-code/empire` دسترسی **Contents: write** و
**Pull requests: write** دارد. بعد از آن، فقط بگویید «ادغام کن».

> نکته: اگر فیلد `admin: true` را می‌بینید ولی ادغام ۴۰۳ می‌دهد، مشکل از مجوز
> اپ است نه از دسترسی حساب.

### تشخیص دقیق (۲۰۲۶-۱۱-۲۲)

اپ دارای مجوز: **`arena-ai-coding-agent`**، `repository_selection: "all"`،
`installation_id: 182650706`. اما مسیرهای نوشتن روی `empire` بسته‌اند:

| تست | `empire` | `jametulhoda` (همین نشست) |
|-----|----------|---------------------------|
| `POST /git/blobs` | ❌ 403 | ✅ sha برگشت |
| `POST /git/refs` | ❌ 403 | — |
| `POST /labels` | ❌ 403 | — |
| `POST /issues/7/comments` | ❌ 403 | — |
| `PUT /pulls/7/update-branch` | ❌ 403 | — |
| `gh pr merge 7` | ❌ 403 | — |

توکن سالم است و اپ روی حساب نصب است؛ مجوز نوشتنش فقط به `jametulhoda` داده شده.
فیلد `admin: true` در `GET /repos/.../empire` از متادیتای عمومی حساب می‌آید و
معیار مجوز واقعی نیست.

**رفع:** GitHub → **Settings → Applications → `arena-ai-coding-agent` → Configure**
→ افزودن `empire` به Repository access → `Contents` و `Pull requests` روی
**Read and write** → ذخیره → سپس اتصال GitHub را در Arena دوباره برقرار کنید.

---

## ۱. خود تغییر — درست است ✅

```diff
-    "@spree/cli":  "3.7.0"
-    "@spree/docs": "0.1.426"
+    "@spree/cli":  "3.8.0"
+    "@spree/docs": "6.0.5"
```

۲ فایل، +۱۰/−۱۰ (`package.json`، `package-lock.json`). تعارض با `main`: **صفر**.

هر دو نسخه را روی npm بررسی کردم — موجود و سالم‌اند:

| بسته | نسخه | engines | حجم باز‌شده |
|------|------|---------|-------------|
| `@spree/cli` | 3.8.0 | `node >= 20` | ۲.۱ MB |
| `@spree/docs` | 6.0.5 | `node >= 18` | ۳.۲ MB |

هر دو با `engines.node >= 20` ریشه سازگارند. `@spree/docs` فقط مستندات است
(`dist/` + `LICENSE`)، اسکریپت `postinstall` ندارد، پس در Vercel و CI فقط
حجم اضافه می‌کند و ریسک اجرایی صفر دارد.

### اعتبارسنجی مستقل درخت ادغام‌شده

درخت `main@ec6117e + PR #7` را واقعاً ساختم:

| گیت | نتیجه |
|-----|-------|
| `git merge origin/main` | ✅ بدون تعارض |
| `npm ci` (ریشه) | ✅ ۳۰ بسته، نسخه‌ها 3.8.0 / 6.0.5 |
| `npm ci` (storefront) | ✅ ۳۷۱ بسته |
| `node scripts/audit-static.mjs` | ✅ PASS (۱۴۰ کلید ترجمه، Spree 6.0.4) |
| `npx tsc --noEmit` | ✅ پاک |
| `npx eslint` | ✅ پاک |
| `next build` | ✅ ۱۶ روت، exit 0 (با کلید publishable و بدون آن) |
| `puma` در `Gemfile.lock` | ✅ 8.1.2 (از `main`) |

---

## ۲. خطای `test` (Backend CI) — ربطی به این PR ندارد، از قبل رفع شده ✅

گام شکست‌خورده: **`Bundle Audit`**، نه RSpec.

```
Name: puma
Version: 8.0.2
GHSA-86xc-wrr9-xmmq / GHSA-jm4r-w4xx-qxw7 / GHSA-m5xw-3hrw-qcmf
GHSA-r885-m39c-939g / GHSA-rf6g-xhmp-gm7v / GHSA-x646-wp7c-h9mg
GHSA-xw3h-pgr9-3vg7
Solution: update to '~> 7.2.2', '>= 8.0.3'
Vulnerabilities found!
```

**علت ریشه‌ای:** شاخه‌ی PR روی کامیت قدیمی `c0880bf` بنا شده که `puma 8.0.2`
دارد. در `main` جدید (`ec6117e`) این مشکل با ارتقای `puma` به **8.1.2** رفع شده است.

**نتیجه:** با به‌روز‌رسانی شاخه، `Bundle Audit` خودبه‌خود سبز می‌شود. نیازی به
تغییر کد در این PR نیست — فقط یک `Update branch` یا merge از `main`.
(در درخت ادغام‌شده‌ای که ساختم، `puma 8.1.2` تأیید شد.)

بقیه‌ی گام‌های همان job همه سبز بودند:
`Locked dependency check`، `db:migrate`، `db:seed` (دو بار، idempotent)،
`Runtime boot`، **`RSpec`**، `Brakeman`.

---

## ۳. خطای `Vercel – empire-ljxs` — خطای زیرساخت Vercel، نه کد ⚠️

```
Vercel – empire-dprj   success
Vercel – empire        success
Vercel – empire-ljxs   failure   ← این یکی
```

شواهد:

- **هیچ `vercel.json` در مخزن نیست** و Vercel از autodetect استفاده می‌کند؛
  هر سه پروژه از یک کامیت با تنظیمات یکسان ساخته می‌شوند.
- `empire-ljxs` روی **هر** شاخه‌ای که از `c0880bf` منشعب شده شکست می‌خورد
  و روی `main` فعلی سبز است — یعنی به محتوای PR وابسته نیست.
- `Frontend CI` همان بیلد را مستقل می‌سازد و **سبز** است (`storefront` job).
- `next build` را محلی هم با و بدون `NEXT_PUBLIC_SPREE_PUBLISHABLE_KEY`
  اجرا کردم: هر دو موفق.

**حکم:** این خطا از سمت داشبورد Vercel (تنظیم پروژه/دامنه/کوتا) است، نه از
این PR. قابل رفع از داخل مخزن نیست. اگر می‌خواهید بررسی شود، باید در داشبورد
تیم `eshop4` → پروژه `empire-ljxs` → آخرین دیپلوی، لاگ بیلد دیده شود.
اتصال Vercel این نشست روی تیم `eshop4` دسترسی ندارد (`list_teams` خالی است)،
پس نتوانستم لاگ را بخوانم.

این چک اگر هم شکست بخورد، ادغام را مسدود نمی‌کند (شاخه‌ی `main` محافظت
قابل‌خواندن ندارد که آن را الزامی کند).

---

## ۴. تنها اصلاح لازم روی محتوا — یک خط مستندات 🔧

`README.md` در جدول پشته هنوز نسخه‌ی قدیمی CLI را اعلام می‌کند؛ بعد از این
PR نادرست می‌شود:

```diff
-| Spree CLI | `@spree/cli` 3.7.0 (wraps Docker Compose for humans) |
+| Spree CLI | `@spree/cli` 3.8.0 (wraps Docker Compose for humans) |
```

پچ آماده در `pr-7-fix.patch` (ریشه‌ی همین مخزن) است:

```bash
git apply pr-7-fix.patch
```

---

## ۵. دستور اجرا (پس از رفع دسترسی)

```bash
# ۱) شاخه را با main هم‌تراز کن تا Bundle Audit سبز شود
gh api -X PUT repos/hajiahmadandishmand41-code/empire/pulls/7/update-branch

# ۲) اصلاح یک‌خطی README را روی شاخه‌ی PR بگذار
git apply pr-7-fix.patch
git commit -am "docs: record the @spree/cli 3.8.0 bump in the stack table"
git push origin HEAD:dependabot/npm_and_yarn/root-version-1153997f03

# ۳) ادغام
gh pr merge 7 --repo hajiahmadandishmand41-code/empire --merge
```

اگر نمی‌خواهید شاخه را دستکاری کنید، **همین حالا هم قابل ادغام است** —
تعارض صفر و ادغام با `--merge` یک کامیت merge می‌سازد که `main` را با خود می‌آورد
و در نتیجه `Bundle Audit` روی نتیجه سبز خواهد بود.

---

## خلاصه

| مورد | وضعیت |
|------|-------|
| صحت تغییر وابستگی‌ها | ✅ درست، نسخه‌ها روی npm موجود |
| تعارض | ✅ صفر |
| بیلد/تایپ/لینت/آدیت محلی | ✅ همه سبز |
| `test` (Backend CI) | 🟡 از پایه‌ی قدیمی؛ با هم‌ترازی `main` رفع می‌شود |
| `Vercel – empire-ljxs` | 🔵 خطای Vercel، بی‌ربط به PR |
| ادغام | ⛔ **مسدود — مجوز نوشتن روی `empire` وجود ندارد** |

---

## راه جایگزین بدون نیاز به تغییر دسترسی

در GitHub روی PR #7 دکمه‌ی **«Merge pull request»** را بزنید. PR آماده است:
`mergeable: MERGEABLE`، تعارض صفر، و بیلد/تایپ/لینت محلی همه سبز.

پیشنهاد: اول **«Update branch»** را بزنید تا شاخه با `main@ec6117e` هم‌تراز شود و
`Bundle Audit` (puma 8.0.2) هم سبز شود.
