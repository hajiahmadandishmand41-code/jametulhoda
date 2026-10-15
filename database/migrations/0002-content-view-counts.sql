-- ═══════════════════════════════════════════════════════════════════════════
-- 0002 — شمارندهٔ بازدید محتوا (content view counter)
-- ═══════════════════════════════════════════════════════════════════════════
--
-- چرا این مهاجرت لازم است
-- ───────────────────
-- ستون `views` در نصب‌های قدیمی وجود داشت و در اسکیمای PostgreSQL با
-- `DROP COLUMN IF EXISTS views` حذف می‌شد. حذف یک ستون، دادهٔ تاریخیِ
-- بازدیدها را برای همیشه از بین می‌برد و برگشت‌پذیر نیست. این مهاجرت:
--
--   ۱) ستون `views` را **حذف نمی‌کند** (هیچ داده‌ای از بین نمی‌رود)،
--   ۲) ستون تازهٔ `view_count` را اضافه می‌کند (idempotent)،
--   ۳) مقادیر `views` را در صورت وجود به `view_count` منتقل می‌کند،
--   ۴) ایندکس لازم برای فهرست «پربازدیدترین‌ها» را می‌سازد.
--
-- سازگاری
-- ─────────
--   • هیچ جدول، رکورد، سیاست RLS یا حسابی حذف یا بازنویسی نمی‌شود.
--   • اجرای دوبارهٔ این مهاجرت بی‌خطر است (idempotent).
--   • MySQL/MariaDB: ستون `views` اصلاً در اسکیما نبوده، پس فقط
--     `ADD COLUMN` لازم است و بازگردانی مقادیر کاربردی ندارد.
--
-- بازگشت (rollback) — در صورت نیاز:
--   PostgreSQL:
--     ALTER TABLE posts   DROP COLUMN IF EXISTS view_count;
--     ALTER TABLE lessons DROP COLUMN IF EXISTS view_count;
--     DROP INDEX IF EXISTS idx_posts_view_count;
--   MySQL:
--     ALTER TABLE posts   DROP COLUMN view_count;
--     ALTER TABLE lessons DROP COLUMN view_count;
--
-- توجه: با حذف `view_count` شمارندهٔ بازدید از کار می‌افتد، اما هیچ‌یک از
-- محتواهای منتشرشده، رسانه‌ها یا حساب‌های مدیر آسیب نمی‌بینند.
-- ═══════════════════════════════════════════════════════════════════════════

-- ─── PostgreSQL / Supabase ────────────────────────────────────────────────
ALTER TABLE posts   ADD COLUMN IF NOT EXISTS view_count INTEGER NOT NULL DEFAULT 0;
ALTER TABLE lessons ADD COLUMN IF NOT EXISTS view_count INTEGER NOT NULL DEFAULT 0;

-- انتقال دادهٔ ستون قدیمی در صورت وجود (بدون حذف آن)
DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'posts' AND column_name = 'views') THEN
    UPDATE posts SET view_count = views WHERE view_count = 0 AND views IS NOT NULL;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'lessons' AND column_name = 'views') THEN
    UPDATE lessons SET view_count = views WHERE view_count = 0 AND views IS NOT NULL;
  END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_posts_view_count ON posts (view_count DESC, published_at DESC);
CREATE INDEX IF NOT EXISTS idx_posts_published ON posts (status, published_at DESC);

-- ─── MySQL / MariaDB (InfinityFree) ───────────────────────────────────────
-- اجرای این بخش روی PostgreSQL خطا می‌دهد و بی‌خطر نادیده گرفته می‌شود:
--   ALTER TABLE posts   ADD COLUMN view_count INT NOT NULL DEFAULT 0;
--   ALTER TABLE lessons ADD COLUMN view_count INT NOT NULL DEFAULT 0;
