-- SQLite schema for local development/testing ONLY (DB_DRIVER=sqlite).
-- Mirrors database/database.mysql.sql: same tables, columns, defaults and
-- seed rows, translated to SQLite DDL. Production stays on MySQL/PostgreSQL.
-- Idempotent: IF NOT EXISTS + INSERT OR IGNORE throughout.

CREATE TABLE IF NOT EXISTS users (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  username   VARCHAR(80)      NULL,
  email      VARCHAR(180)     NULL,
  phone      VARCHAR(32)      NULL,
  phone_normalized VARCHAR(32) NULL,
  country    VARCHAR(80)      NOT NULL DEFAULT '',
  country_code VARCHAR(8)     NOT NULL DEFAULT '',
  password   VARCHAR(255)     NOT NULL,
  full_name  VARCHAR(120)     NOT NULL DEFAULT '',
  role       VARCHAR(30)      NOT NULL DEFAULT 'user',
  is_active  SMALLINT         NOT NULL DEFAULT 1,
  must_change_password SMALLINT NOT NULL DEFAULT 0,
  agreed_terms SMALLINT       NOT NULL DEFAULT 0,
  avatar     VARCHAR(350)     NULL,
  last_login DATETIME         NULL,
  created_at DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  auth_version INT            NOT NULL DEFAULT 1,
  UNIQUE (username),
  UNIQUE (email),
  UNIQUE (phone_normalized)
);

CREATE TABLE IF NOT EXISTS categories (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  name        VARCHAR(120) NOT NULL,
  slug        VARCHAR(160) NOT NULL,
  description TEXT             NULL,
  post_type   VARCHAR(30)  NOT NULL DEFAULT 'all',
  sort_order  INT          NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (slug)
);

CREATE TABLE IF NOT EXISTS topics (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  parent_id   INTEGER REFERENCES topics(id) ON DELETE SET NULL,
  name        VARCHAR(150) NOT NULL,
  slug        VARCHAR(200) NOT NULL,
  description TEXT,
  intro       TEXT,
  cover_image VARCHAR(500),
  sort_order  INT NOT NULL DEFAULT 0,
  is_active   SMALLINT NOT NULL DEFAULT 1,
  is_featured SMALLINT NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (slug)
);
CREATE INDEX IF NOT EXISTS topics_parent_idx ON topics(parent_id);
CREATE INDEX IF NOT EXISTS topics_active_sort ON topics(is_active, sort_order, id);
CREATE INDEX IF NOT EXISTS topics_featured ON topics(is_featured);

CREATE TABLE IF NOT EXISTS posts (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  title           VARCHAR(400)     NOT NULL,
  slug            VARCHAR(450)     NOT NULL,
  summary         TEXT                 NULL,
  content         TEXT             NULL,
  featured_image  VARCHAR(350)         NULL,
  featured_video  VARCHAR(500)         NULL,
  post_type       VARCHAR(30) NOT NULL DEFAULT 'news' CHECK (post_type IN ('news','article','research','report','announcement','speech','program','religious','qa','book_note')),
  page_section   VARCHAR(300) NOT NULL DEFAULT 'home,news',
  speaker        VARCHAR(200) NULL,
  sources        TEXT NULL,
  author_name    VARCHAR(250) NULL,
  category_id     INTEGER REFERENCES categories(id) ON DELETE SET NULL,
  author_id       INTEGER REFERENCES users(id) ON DELETE SET NULL,
  status          VARCHAR(30) NOT NULL DEFAULT 'draft' CHECK (status IN ('published','draft')),
  is_featured     SMALLINT       NOT NULL DEFAULT 0,
  published_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (slug)
);
CREATE INDEX IF NOT EXISTS posts_idx_status_type ON posts (status, post_type);
CREATE INDEX IF NOT EXISTS posts_idx_category ON posts (category_id);
CREATE INDEX IF NOT EXISTS posts_idx_published ON posts (published_at);

CREATE TABLE IF NOT EXISTS post_images (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  post_id    INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
  image_path VARCHAR(350) NOT NULL,
  alt_text   VARCHAR(200)     NULL,
  sort_order INTEGER      NOT NULL DEFAULT 0,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS post_images_idx_post ON post_images (post_id);
CREATE INDEX IF NOT EXISTS post_images_idx_order ON post_images (post_id, sort_order, id);
CREATE INDEX IF NOT EXISTS post_images_idx_post ON post_images (post_id);

CREATE TABLE IF NOT EXISTS post_topics (
  post_id  INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
  topic_id INTEGER NOT NULL REFERENCES topics(id) ON DELETE CASCADE,
  is_primary SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (post_id, topic_id)
);
CREATE INDEX IF NOT EXISTS post_topics_topic_idx ON post_topics(topic_id);

CREATE TABLE IF NOT EXISTS lesson_collections (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  title       VARCHAR(300) NOT NULL,
  slug        VARCHAR(300) NOT NULL,
  description TEXT,
  cover_image VARCHAR(500),
  sort_order  INT NOT NULL DEFAULT 0,
  is_active   SMALLINT NOT NULL DEFAULT 1,
  is_featured SMALLINT NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (slug)
);
CREATE INDEX IF NOT EXISTS lesson_collections_active_sort ON lesson_collections(is_active, sort_order);

CREATE TABLE IF NOT EXISTS lesson_volumes (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  collection_id INTEGER NOT NULL REFERENCES lesson_collections(id) ON DELETE CASCADE,
  title         VARCHAR(300) NOT NULL,
  slug          VARCHAR(300) NOT NULL,
  description   TEXT,
  sort_order    INT NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (collection_id, slug)
);
CREATE INDEX IF NOT EXISTS lesson_volumes_collection_sort ON lesson_volumes(collection_id, sort_order);

CREATE TABLE IF NOT EXISTS lessons (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  title          VARCHAR(400) NOT NULL,
  slug           VARCHAR(450) NOT NULL,
  subject        VARCHAR(150)     NULL,
  teacher        VARCHAR(150)     NULL,
  content        TEXT         NULL,
  summary        TEXT             NULL,
  audio_file     VARCHAR(350) NULL,
  video_file     TEXT,
  pdf_file       TEXT,
  sources        TEXT,
  featured_image VARCHAR(350)     NULL,
  level          VARCHAR(30) DEFAULT 'beginner',
  status         VARCHAR(30) NOT NULL DEFAULT 'draft' CHECK (status IN ('published','draft')),
  page_section   VARCHAR(255) NOT NULL DEFAULT 'home',
  sort_order     INT          NOT NULL DEFAULT 0,
  lesson_number  INT,
  collection_id  INTEGER REFERENCES lesson_collections(id) ON DELETE SET NULL,
  volume_id      INTEGER REFERENCES lesson_volumes(id) ON DELETE SET NULL,
  is_featured    SMALLINT NOT NULL DEFAULT 0,
  created_by     INTEGER     NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (slug)
);
CREATE INDEX IF NOT EXISTS lessons_idx_status ON lessons (status);
CREATE INDEX IF NOT EXISTS lessons_idx_collection ON lessons(collection_id, volume_id, lesson_number);
CREATE INDEX IF NOT EXISTS lessons_featured ON lessons(is_featured);

CREATE TABLE IF NOT EXISTS lesson_topics (
  lesson_id INTEGER NOT NULL REFERENCES lessons(id) ON DELETE CASCADE,
  topic_id  INTEGER NOT NULL REFERENCES topics(id) ON DELETE CASCADE,
  PRIMARY KEY (lesson_id, topic_id)
);
CREATE INDEX IF NOT EXISTS lesson_topics_topic_idx ON lesson_topics(topic_id);

CREATE TABLE IF NOT EXISTS contact_messages (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  name       VARCHAR(200) NOT NULL,
  email      VARCHAR(200)     NULL,
  phone      VARCHAR(50)      NULL,
  subject    VARCHAR(300)     NULL,
  message    TEXT         NOT NULL,
  ip_address VARCHAR(45)      NULL,
  is_read    SMALLINT   NOT NULL DEFAULT 0,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS contact_messages_idx_is_read ON contact_messages (is_read);
CREATE INDEX IF NOT EXISTS contact_messages_idx_created ON contact_messages (created_at);
CREATE INDEX IF NOT EXISTS contact_rate_limit ON contact_messages(ip_address,created_at);

CREATE TABLE IF NOT EXISTS media_files (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  ref_type   VARCHAR(30)  NOT NULL DEFAULT 'post',
  ref_id     INTEGER NOT NULL,
  kind       VARCHAR(30) NOT NULL DEFAULT 'image',
  file_path  VARCHAR(500) NOT NULL,
  title      VARCHAR(300) NULL,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS media_files_idx_ref ON media_files (ref_type, ref_id);
CREATE INDEX IF NOT EXISTS media_files_idx_kind ON media_files (kind);

CREATE TABLE IF NOT EXISTS settings (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  setting_key VARCHAR(100) NOT NULL,
  value      TEXT             NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (setting_key)
);

CREATE TABLE IF NOT EXISTS books (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  title       VARCHAR(400) NOT NULL,
  slug        VARCHAR(300) NULL,
  description TEXT             NULL,
  cover_image VARCHAR(350)     NULL,
  pdf_file    VARCHAR(350)     NULL,
  word_file   VARCHAR(350)     NULL,
  author      VARCHAR(250),
  translator  VARCHAR(250),
  publisher   VARCHAR(250),
  publish_year VARCHAR(20),
  pages       INTEGER,
  toc         TEXT,
  is_featured SMALLINT NOT NULL DEFAULT 0,
  sort_order  INT NOT NULL DEFAULT 0,
  status      VARCHAR(30) NOT NULL DEFAULT 'published' CHECK (status IN ('published','draft')),
  downloads   INTEGER NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS books_idx_created ON books (created_at);
CREATE INDEX IF NOT EXISTS books_idx_featured ON books(is_featured);
CREATE UNIQUE INDEX IF NOT EXISTS books_slug_unique ON books(slug);

CREATE TABLE IF NOT EXISTS book_topics (
  book_id  INTEGER NOT NULL REFERENCES books(id) ON DELETE CASCADE,
  topic_id INTEGER NOT NULL REFERENCES topics(id) ON DELETE CASCADE,
  PRIMARY KEY (book_id, topic_id)
);
CREATE INDEX IF NOT EXISTS book_topics_topic_idx ON book_topics(topic_id);

CREATE TABLE IF NOT EXISTS site_banners (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  title      VARCHAR(300) NOT NULL,
  content    TEXT,
  link_url   VARCHAR(500),
  link_text  VARCHAR(100),
  banner_type VARCHAR(30) NOT NULL DEFAULT 'info',
  is_active  SMALLINT NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  starts_at  DATETIME,
  ends_at    DATETIME,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS site_banners_active ON site_banners(is_active, sort_order);

CREATE TABLE IF NOT EXISTS featured_banners (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title VARCHAR(300) NOT NULL,
  description TEXT,
  image VARCHAR(500),
  link_url VARCHAR(500),
  button_text VARCHAR(100),
  is_active SMALLINT NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT OR IGNORE INTO settings (setting_key, value) VALUES
('site_name',        'مدرسه علمیه جامعه‌الهدی'),
('site_slogan',      'علم، معرفت و تهذیب در پرتو قرآن و عترت'),
('site_email',       'hajiahmads299@gmail.com'),
('address',          'کابل، افغانستان'),
('phone',            '0798228441'),
('email',            'hajiahmads299@gmail.com'),
('social_telegram',  ''),
('social_youtube',   ''),
('social_instagram', '');

INSERT OR IGNORE INTO categories (name, slug, description, post_type, sort_order) VALUES
('فقه و اصول',         'fiqh-osul',         'مباحث فقه و اصول فقه',              'all',      1),
('تفسیر و قرآن',       'tafsir-quran',       'تفسیر آیات قرآن کریم',              'all',      2),
('فلسفه و کلام',       'falsafe-kalam',      'فلسفه اسلامی و علم کلام',           'all',      3),
('اخلاق و معرفت',      'akhlaq-marefat',     'اخلاق اسلامی و معرفت دینی',         'all',      4),
('اخبار مدرسه',        'akhbar-madrasa',     'اخبار و رویدادهای مدرسه',           'news',     5),
('مقالات علمی',        'maqalat-elmi',       'مقالات علمی و پژوهشی',              'article',  6),
('برنامه‌های تابستانه','baraname-tabestane', 'دوره‌های تابستانه',                 'program',  7),
('فعالیت‌های مذهبی',   'faaliyet-mazhabie',  'مراسم، محافل و فعالیت‌های مذهبی',  'religious',8);

INSERT OR IGNORE INTO topics (name, slug, description, intro, sort_order, is_featured) VALUES
('مهدویت', 'mahdaviat', 'مباحث مرتبط با مهدویت و امام زمان (عج)', 'بررسی عقیده مهدویت، انتظار و ظهور در منابع اسلامی', 1, 1),
('امام حسین (ع)', 'imam-hussein', 'زندگی، قیام و پیام‌های عاشورا', 'شناخت نهضت حسینی و درس‌های عاشورا برای جامعه امروز', 2, 1),
('قرآن و حدیث', 'quran-hadith', 'علوم قرآن، تفسیر، حدیث و نهج‌البلاغه', 'آشنایی با معارف قرآنی و روایی اهل بیت', 3, 1),
('فقه و اصول', 'fiqh-osool', 'احکام، فقه استدلالی و اصول فقه', 'مبانی اجتهاد و استنباط احکام شرعی', 4, 0),
('اخلاق و تربیت', 'akhlaq-tarbiat', 'اخلاق اسلامی و سیر و سلوک', 'تهذیب نفس و پرورش معنوی بر اساس قرآن و عترت', 5, 0),
('تاریخ اسلام', 'tarikh-islam', 'سیره معصومین و تاریخ تشیع', 'بررسی تاریخی اسلام و نقش اهل بیت', 6, 0);

INSERT OR IGNORE INTO topics (parent_id, name, slug, description, sort_order) VALUES
((SELECT id FROM topics WHERE slug='mahdaviat' LIMIT 1), 'امام مهدی (عج)', 'imam-mahdi', 'شناخت حضرت مهدی (عج)', 1),
((SELECT id FROM topics WHERE slug='mahdaviat' LIMIT 1), 'غیبت', 'ghaybat', 'غیبت صغری و کبری', 2),
((SELECT id FROM topics WHERE slug='mahdaviat' LIMIT 1), 'ظهور', 'zohour', 'نشانه‌ها و شرایط ظهور', 3),
((SELECT id FROM topics WHERE slug='mahdaviat' LIMIT 1), 'انتظار', 'entezar', 'وظایف منتظران', 4),
((SELECT id FROM topics WHERE slug='imam-hussein' LIMIT 1), 'زندگی امام حسین (ع)', 'zendegi-imam-hussein', 'دوران زندگی و امامت', 1),
((SELECT id FROM topics WHERE slug='imam-hussein' LIMIT 1), 'قیام عاشورا', 'qiyam-ashura', 'تحلیل قیام عاشورا', 2),
((SELECT id FROM topics WHERE slug='imam-hussein' LIMIT 1), 'پیام‌های عاشورا', 'payam-ashura', 'درس‌ها و عبرت‌های عاشورا', 3),
((SELECT id FROM topics WHERE slug='quran-hadith' LIMIT 1), 'علوم قرآن', 'oloum-quran', 'مبانی علوم قرآنی', 1),
((SELECT id FROM topics WHERE slug='quran-hadith' LIMIT 1), 'تفسیر', 'tafsir', 'تفسیر آیات قرآن', 2),
((SELECT id FROM topics WHERE slug='quran-hadith' LIMIT 1), 'نهج‌البلاغه', 'nahjolbalaghe', 'شرح نهج‌البلاغه', 3);

CREATE TABLE IF NOT EXISTS app_sessions (
 id VARCHAR(128) PRIMARY KEY, data TEXT NOT NULL, expires_at DATETIME NOT NULL
);
CREATE INDEX IF NOT EXISTS app_sessions_expiry ON app_sessions(expires_at);

CREATE TABLE IF NOT EXISTS login_limits (
 limit_key VARCHAR(64) PRIMARY KEY, attempts INTEGER NOT NULL DEFAULT 0, expires_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS stored_files (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 file_key VARCHAR(500) NOT NULL UNIQUE, url VARCHAR(700) NOT NULL UNIQUE, mime TEXT NOT NULL,
 size BIGINT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS storage_deletions (
 reference VARCHAR(700) PRIMARY KEY,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 not_before DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS pending_uploads (
 reference VARCHAR(700) PRIMARY KEY,
 not_before DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS pending_uploads_due ON pending_uploads(not_before);

CREATE INDEX IF NOT EXISTS posts_public_listing ON posts(status,post_type,published_at DESC);
CREATE INDEX IF NOT EXISTS lessons_public_listing ON lessons(status,created_at DESC);
CREATE INDEX IF NOT EXISTS media_ordered_reference ON media_files(ref_type,ref_id,kind,sort_order,id);

-- رسانهٔ شاخص چندگانه (همتای MySQL/PostgreSQL)
ALTER TABLE post_images ADD COLUMN is_featured INTEGER NOT NULL DEFAULT 0;
ALTER TABLE post_images ADD COLUMN featured_order INTEGER NOT NULL DEFAULT 0;
CREATE INDEX IF NOT EXISTS post_images_featured ON post_images (post_id, is_featured, featured_order, id);
ALTER TABLE media_files ADD COLUMN is_featured INTEGER NOT NULL DEFAULT 0;
ALTER TABLE media_files ADD COLUMN featured_order INTEGER NOT NULL DEFAULT 0;
CREATE INDEX IF NOT EXISTS media_files_featured ON media_files (ref_type, ref_id, kind, is_featured, featured_order, id);
CREATE INDEX IF NOT EXISTS posts_slug_idx ON posts (slug);
CREATE INDEX IF NOT EXISTS posts_featured_listing ON posts (status, is_featured, published_at);
CREATE INDEX IF NOT EXISTS topics_slug_idx ON topics (slug);
CREATE INDEX IF NOT EXISTS books_slug_idx ON books (slug);
CREATE INDEX IF NOT EXISTS lessons_slug_idx ON lessons (slug);
CREATE INDEX IF NOT EXISTS post_topics_post_idx ON post_topics (post_id, topic_id);
