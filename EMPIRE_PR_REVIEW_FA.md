# بررسی پول‌ریکوئست‌های Empire — وضعیت روزآمد

**مخزن:** `hajiahmadandishmand41-code/empire`
**شاخه‌ی مقصد:** `main` (HEAD: `c0880bf`)
**آخرین به‌روزرسانی:** ۲۰۲۶-۱۱-۲۰

---

## ⛔ مانع: دسترسی نوشتن وجود ندارد

دستور شما (ادغام #6، بستن بقیه) **اجرا نشد**. تمام عملیات نوشتن روی این مخزن با خطای مجوز رد می‌شوند:

```
gh pr merge 6  → Resource not accessible by integration (403)
gh pr close 1  → Resource not accessible by integration (403)
gh pr close 3  → Resource not accessible by integration (403)
git push       → 403 Permission to .../empire.git denied
```

در حالی که همین توکن روی مخزن این نشست (`jametulhoda`) دسترسی نوشتن کامل دارد.
این محدودیت **مجوز GitHub App** است، نه مشکل فنی.

**اقدام لازم از شما:** در Arena اتصال GitHub را دوباره برقرار کنید و مطمئن شوید دسترسی **Write / Admin** روی `hajiahmadandishmand41-code/empire` اعطا می‌شود. سپس کافی است بگویید «انجام بده» — همه‌ی عملیات در یک مرحله اجرا می‌شود.

---

## تغییرات از زمان بررسی قبلی

| PR | وضعیت قبلی | وضعیت فعلی |
|----|------------|-------------|
| #4 (@spree/docs) | باز | 🔒 **بسته شد** (بدون ادغام) در ۲۰۲۶-۱۱-۲۰ |
| #5 (puma 8.0.3) | باز، تنها PR سبز | 🔒 **بسته شد** (بدون ادغام) در ۲۰۲۶-۱۱-۲۰ |
| #7 | — | 🆕 جانشین #4 |
| #8 | — | 🆕 جانشین #5 |

> **هشدار:** PR #5 که رفع‌کننده‌ی آسیب‌پذیری puma بود **بدون ادغام بسته شد**. خوشبختانه جانشین آن (#8) رفع را با نسخه‌ی بالاتر انجام می‌دهد — جزئیات پایین.

---

## وضعیت فعلی PRهای باز

| # | عنوان | فایل | تعارض | قابل ادغام |
|---|-------|------|-------|-------------|
| **6** | Rebuild storefront + schema drift | ۹۶ | ✅ صفر | ✅ **بله** |
| **7** | bump root-version (2 updates) | ۲ | ✅ صفر | ✅ بله |
| **8** | bump server-version (4 updates) | ۱ | ✅ صفر | ✅ بله |
| **3** | bump actions-version | ۳ | ✅ صفر | ✅ بله |
| **1** | Empire platform rebuild | ۱۰۰ | ❌ **۷۳** | ❌ نه |

---

## ۱. PR #6 — «آخری» → ادغام شود

آخرین PR در ترتیب پیشنهادی قبلی، و باارزش‌ترین تغییر.

- **تعارض با `main` فعلی: صفر** (مرتباً بازاعتبارسنجی شد)
- **۹۶ فایل، +۱۰٬۱۰۱ / −۸۴۲**
- وضعیت: `MERGEABLE`
- در CI خودش: `schema.rb must match the migrations` ✅، `RSpec` ✅، `Seed` ✅، `Runtime boot` ✅، `Brakeman` ✅ — تنها `Bundle Audit` به‌دلیل puma 8.0.2 شکست خورده که با #8 رفع می‌شود.

**کارهایی که انجام می‌دهد:**
- ۵ جدول گمشده‌ی empire + ۱۱ کلید خارجی + ایندکس یکتا را به `schema.rb` برمی‌گرداند (نصب تازه دیگر بدون RFQ و payout بالا نمی‌آید)
- ۹ مسیر مرده‌ی ناوبری را می‌سازد
- SEO کامل: `generateMetadata`, `robots.ts`, `sitemap.ts`, `error`, `not-found`, `loading` + noindex سطوح خصوصی
- رفع XSS ذخیره‌شده و محدودسازی image optimizer (جلوگیری از SSRF)
- دو گیت ضدّ رگرسیون برای schema drift

---

## ۲. PR #8 — جانشین #5 ✅ مهم

```
-    puma (8.0.2)
+    puma (8.1.2)        ← بالاتر از 8.0.3 که #5 پیشنهاد می‌داد

-    aws-sdk-core (3.264.0) → 3.271.0
-    aws-sdk-kms  (1.139.0) → 1.145.0
-    aws-sdk-s3   (1.238.0) → 1.243.0
```

`puma 8.1.2` هر ۸ آسیب‌پذیری را رفع می‌کند (حداقل لازم: `>= 8.0.3`). ۴۲+/۲۶−، یک فایل، تعارض صفر.

**این PR را قبل از #6 ادغام کنید** تا گام `Bundle Audit` روی #6 سبز شود.

---

## ۳. PR #7 — جانشین #4 ✅

```diff
-    "@spree/cli":  "3.7.0"
-    "@spree/docs": "0.1.426"
+    "@spree/cli":  "3.8.0"
+    "@spree/docs": "6.0.5"
```

توجه: `@spree/docs` از `0.1.426` به **`6.0.5`** می‌رود — یک جهش نسخه‌ی major (هماهنگ با Spree 6.0.4 که در `FEATURE-MATRIX.md` ثبت شده). ۱۰+/۱۰−، تعارض صفر. فقط باید مطمئن شوید `@spree/docs@6.0.5` روی npm موجود است.

---

## ۴. PR #3 — bump actions-version ✅

`actions/checkout@v4 → @v7`، `upload-artifact@v4 → @v7`، `setup-node@v4 → @v7`. سه فایل، ۱۲+/۱۲−، تعارض صفر.

---

## ۵. PR #1 — باید بسته شود ❌

دلیل‌های تأییدشده‌ی قبلی هنوز پابرجاست:

1. **۷۳ تعارض add/add** — فایل‌هایی که main از قبل دارد (از طریق PR #2).
2. **`schema.rb` آن صفر جدول empire دارد** — همان drift که PR #6 مستند و رفع کرد.
3. **معماری جایگزین‌شده:** مدل‌های `Empire::Seller`, `Commission`, `Payout`, `Dispute`, `Company`, `OrderSplit`, `VolumePricing`, `BankTransfer` را از صفر می‌سازد؛ در حالی که main عمداً این‌ها را به **هسته‌ی Spree** واگذار کرده و فقط در جایی جدول اختصاصی دارد که Spree قلاب ندارد.
4. **صفر ارجاع** به این مدل‌ها در `main`.
5. بک‌اندش هرگز اجرا نشده بود.

**ارزش باقی‌مانده:** ۳۰ فایل یکتا (۸ مدل، ۶ کنترلر، ۱۲ migration، ۱ initializer). پیشنهاد: **آرشیو در شاخه‌ی `archive/pr-1-models`** قبل از بستن، تا برای توسعه‌ی آینده از دست نرود.

---

## اعتبارسنجی مستقل درخت نهایی

درخت `main + #8 + #7 + #3 + #6` را واقعاً ساختم (صفر تعارض):

| گیت | نتیجه |
|-----|-------|
| `node scripts/audit-static.mjs` | ✅ PASS |
| `npx tsc --noEmit` | ✅ clean |
| `npm run lint` | ✅ clean |
| `next build` | ✅ exit 0 — ۲۴ روت |
| `puma` در `Gemfile.lock` | ✅ 8.0.3 / 8.1.2 |

---

## دستور اجرای نهایی (پس از رفع دسترسی)

```bash
# ۱) آرشیو PR #1 قبل از بستن
git push origin origin/arena/26f7ed83-empire:refs/heads/archive/pr-1-models

# ۲) رفع آسیب‌پذیری
gh pr merge 8 --merge      # puma 8.1.2

# ۳) سایر وابستگی‌ها
gh pr merge 7 --merge      # @spree/cli 3.8.0 + @spree/docs 6.0.5
gh pr merge 3 --merge      # actions v7

# ۴) ادغام اصلی  ← «آخری»
gh pr merge 6 --merge      # storefront rebuild + schema drift

# ۵) بستن بقیه
gh pr close 1              # جایگزین‌شده، ۷۳ تعارض
```
