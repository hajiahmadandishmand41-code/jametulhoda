-- Comprehensive seed for local testing ONLY. Covers every public route,
-- every post type, topics, books, lessons, media and admin fixtures.
-- Idempotent-ish: run on a fresh database created from schema.sqlite.sql.
-- NOTE: no ASCII semicolon may appear inside string literals (the test
-- runner splits statements on ";\n"). Persian text uses «» quotes.

-- ── Users ─────────────────────────────────────────────────────────────
-- admin / TestAdmin123!@#  (bcrypt, generated for tests only)
INSERT OR IGNORE INTO users (id, username, email, password, full_name, role, is_active, auth_version) VALUES
(1, 'admin', 'admin@example.test', '$2y$10$TH2NLrc3C9Dq3woNwlc2j.XFBjvD5Sl6xKXnBOezVV6TkWXX6FyfC', 'مدیر آزمون', 'super_admin', 1, 1),
(2, 'editor', 'editor@example.test', '$2y$10$TH2NLrc3C9Dq3woNwlc2j.XFBjvD5Sl6xKXnBOezVV6TkWXX6FyfC', 'ویراستار آزمون', 'admin', 1, 1);

-- ── Extra topics (the twelve Islamic hubs from the spec) ──────────────
INSERT OR IGNORE INTO topics (name, slug, description, intro, sort_order, is_featured) VALUES
('قرآن', 'quran', 'علوم و معارف قرآن کریم', 'آشنایی با قرآن کریم و علوم قرآنی', 7, 1),
('حدیث', 'hadith', 'حدیث و سنت معصومین', 'آشنایی با میراث روایی اهل بیت', 8, 1),
('عقاید', 'aqaid', 'کلام و اعتقادات اسلامی', 'مبانی اعتقادی تشیع', 9, 1),
('فقه', 'fiqh', 'احکام عملی اسلام', 'احکام و مسائل شرعی', 10, 0),
('اصول فقه', 'usul-fiqh', 'مبانی استنباط احکام', 'قواعد اجتهاد و استنباط', 11, 0),
('سیره', 'seerah', 'سیره پیامبر و اهل بیت', 'زندگانی پیامبر اکرم و ائمه اطهار', 12, 0),
('اهل‌بیت', 'ahlulbayt', 'شناخت اهل بیت', 'مقام و سیره اهل بیت پیامبر', 13, 0),
('اخلاق', 'akhlaq', 'اخلاق اسلامی', 'فضائل و رذائل اخلاقی در اسلام', 14, 0),
('پژوهش', 'pazhuhesh', 'روش پژوهش دینی', 'مقالات و پژوهش‌های علمی حوزوی', 15, 0),
('پرسش و پاسخ', 'qa-topic', 'پاسخ به پرسش‌های دینی', 'پرسش‌ها و پاسخ‌های اعتقادی و فقهی', 16, 0);

-- ── Posts: news ───────────────────────────────────────────────────────
INSERT OR IGNORE INTO posts (title, slug, summary, content, featured_image, post_type, page_section, category_id, author_id, status, is_featured, published_at) VALUES
('آغاز سال تحصیلی جدید در مدرسه علمیه جامعه‌الهدی', 'new-school-year', 'مراسم آغاز سال تحصیلی با حضور اساتید و طلاب برگزار شد', '<p>مراسم آغاز سال تحصیلی جدید مدرسه علمیه جامعه‌الهدی با حضور اساتید و طلاب برگزار شد</p><p>در این مراسم برنامه‌های آموزشی سال جدید تشریح شد</p>', 'uploads/images/seed-cover.png', 'news', 'home,news', 5, 1, 'published', 1, '2026-09-10 09:00:00'),
('برگزاری محفل انس با قرآن در ماه رمضان', 'quran-gathering', 'محفل انس با قرآن با حضور قاریان برگزار می‌شود', '<p>محفل انس با قرآن کریم در ماه مبارک رمضان برگزار می‌شود</p>', NULL, 'news', 'home,news', 5, 1, 'published', 0, '2026-09-05 09:00:00'),
('نشست علمی بررسی اندیشه‌های کلامی برگزار شد', 'kalam-session', 'نشست علمی با موضوع کلام اسلامی برگزار شد', '<p>نشست علمی بررسی اندیشه‌های کلامی با حضور پژوهشگران برگزار شد</p>', NULL, 'news', 'news', 5, 1, 'published', 0, '2026-08-28 09:00:00');

-- ── Posts: articles ───────────────────────────────────────────────────
INSERT OR IGNORE INTO posts (title, slug, summary, content, featured_image, post_type, page_section, sources, author_name, category_id, author_id, status, is_featured, published_at) VALUES
('جایگاه عقل در معرفت دینی', 'aql-in-religion', 'بررسی نقش عقل در فهم معارف اسلامی', '<p>عقل یکی از منابع مهم معرفت دینی در مکتب اهل بیت است</p><p>در این مقاله جایگاه عقل در کنار نقل بررسی می‌شود</p>', 'uploads/images/seed-cover.png', 'article', 'home,articles', 'اصول کافی جلد اول', 'استاد نمونه', 6, 1, 'published', 1, '2026-09-12 10:00:00'),
('مهدویت و امید به آینده در اندیشه اسلامی', 'mahdaviat-hope', 'نقش اعتقاد به مهدویت در امید اجتماعی', '<p>اعتقاد به ظهور منجی موعود سرچشمه امید در جامعه اسلامی است</p>', NULL, 'article', 'home,articles', NULL, NULL, 6, 1, 'published', 0, '2026-09-08 10:00:00'),
('سیره اخلاقی پیامبر اکرم در برخورد با مردم', 'prophet-ethics', 'نگاهی به اخلاق اجتماعی پیامبر اسلام', '<p>پیامبر اکرم الگوی کامل اخلاق اجتماعی برای همه انسان‌هاست</p>', NULL, 'article', 'articles', NULL, NULL, 4, 1, 'published', 0, '2026-09-01 10:00:00'),
('درس‌هایی از قیام عاشورا برای امروز', 'ashura-lessons', 'پیام‌های ماندگار نهضت حسینی', '<p>قیام عاشورا درس‌های بزرگی برای آزادی و عدالت دارد</p>', 'uploads/images/seed-cover.png', 'article', 'home,articles', NULL, NULL, 6, 1, 'published', 0, '2026-08-20 10:00:00'),
('مقاله آزمایشی با نشانی فارسی', 'مقاله-نمونه-فارسی', 'بررسی پشتیبانی از نشانی فارسی', '<p>این مقاله برای آزمون نشانی‌های فارسی ایجاد شده است</p>', NULL, 'article', 'articles', NULL, NULL, 6, 1, 'published', 0, '2026-08-15 10:00:00');

-- ── Posts: research ───────────────────────────────────────────────────
INSERT OR IGNORE INTO posts (title, slug, summary, content, post_type, page_section, sources, category_id, author_id, status, published_at) VALUES
('روش‌شناسی تحقیق در علوم اسلامی', 'research-method', 'مبانی روش تحقیق در حوزه علوم دینی', '<p>پژوهش در علوم اسلامی نیازمند روش‌شناسی دقیق است</p><p>در این پژوهش مراحل تحقیق علمی بررسی می‌شود</p>', 'research', 'home,research', 'کتاب روش تحقیق', 6, 1, 'published', '2026-09-11 10:00:00'),
('تحلیل تاریخی شکل‌گیری حوزه‌های علمیه', 'hawza-history', 'سیر تاریخی حوزه‌های علمیه شیعه', '<p>حوزه‌های علمیه شیعه تاریخی کهن و پرافتخار دارند</p>', 'research', 'research', NULL, 6, 1, 'published', '2026-08-25 10:00:00'),
('بررسی تطبیقی مذاهب فقهی در مسئله نماز جمعه', 'fiqh-comparison', 'دیدگاه مذاهب درباره نماز جمعه', '<p>نماز جمعه از منظر مذاهب مختلف فقهی بررسی می‌شود</p>', 'research', 'research', NULL, 1, 1, 'published', '2026-08-10 10:00:00');

-- ── Posts: reports ────────────────────────────────────────────────────
INSERT OR IGNORE INTO posts (title, slug, summary, content, featured_image, featured_video, post_type, page_section, category_id, author_id, status, published_at) VALUES
('گزارش مراسم جشن میلاد پیامبر اکرم', 'milad-report', 'مراسم جشن میلاد با شکوه برگزار شد', '<p>مراسم جشن میلاد پیامبر اکرم با حضور طلاب و مردم برگزار شد</p>', 'uploads/images/seed-cover.png', NULL, 'report', 'home,reports', 5, 1, 'published', '2026-09-09 10:00:00'),
('گزارش تصویری اردوی علمی طلاب', 'urdu-report', 'اردوی علمی و تفریحی طلاب مدرسه', '<p>اردوی علمی طلاب با برنامه‌های متنوع برگزار شد</p>', NULL, 'uploads/videos/seed-video.mp4', 'report', 'home,reports', 5, 1, 'published', '2026-09-02 10:00:00'),
('گزارش جلسه درس اخلاق هفتگی', 'ethics-class-report', 'درس اخلاق هفتگی با استقبال طلاب', '<p>درس اخلاق هفتگی مدرسه با حضور گسترده طلاب برگزار شد</p>', NULL, NULL, 'report', 'reports', 5, 1, 'published', '2026-08-22 10:00:00');

-- ── Posts: announcements / programs / religious / qa / book_note ──────
INSERT OR IGNORE INTO posts (title, slug, summary, content, post_type, page_section, category_id, author_id, status, published_at) VALUES
('اطلاعیه ثبت‌نام دوره تابستانه', 'summer-announce', 'ثبت‌نام دوره تابستانه آغاز شد', '<p>ثبت‌نام دوره تابستانه مدرسه آغاز شد</p>', 'announcement', 'home,announcements', 5, 1, 'published', '2026-09-07 10:00:00'),
('اطلاعیه برنامه امتحانات پایان ترم', 'exam-announce', 'برنامه امتحانات اعلام شد', '<p>برنامه امتحانات پایان ترم اعلام شد</p>', 'announcement', 'home,announcements', 5, 1, 'published', '2026-08-30 10:00:00'),
('دوره آموزشی تابستانه حفظ قرآن', 'summer-quran-program', 'دوره تابستانه حفظ قرآن برگزار می‌شود', '<p>دوره آموزشی تابستانه حفظ قرآن برای نوجوانان برگزار می‌شود</p>', 'program', 'home,programs', 7, 1, 'published', '2026-09-06 10:00:00'),
('کلاس‌های تقویتی زبان عربی', 'arabic-program', 'کلاس تقویتی زبان عربی برای طلاب جدید', '<p>کلاس‌های تقویتی زبان عربی ویژه طلاب جدید برگزار می‌شود</p>', 'program', 'programs', 7, 1, 'published', '2026-08-18 10:00:00'),
('مراسم عزاداری دهه اول محرم', 'moharram-religious', 'برنامه عزاداری دهه محرم اعلام شد', '<p>مراسم عزاداری دهه اول محرم در مدرسه برگزار می‌شود</p>', 'religious', 'home,religious', 8, 1, 'published', '2026-09-04 10:00:00'),
('جشن نیمه شعبان در مدرسه', 'shaban-religious', 'جشن میلاد امام زمان برگزار می‌شود', '<p>جشن میلاد امام زمان در مدرسه برگزار می‌شود</p>', 'religious', 'home,religious', 8, 1, 'published', '2026-08-12 10:00:00'),
('آیا نماز جمعه واجب است', 'jomeh-qa', 'پاسخ به پرسش درباره نماز جمعه', '<p>نماز جمعه در عصر غیبت از نظر مشهور فقها واجب تخییری است</p>', 'qa', 'home,qa', 1, 1, 'published', '2026-09-03 10:00:00'),
('حکم روزه مسافر چیست', 'safar-qa', 'پاسخ به پرسش درباره روزه مسافر', '<p>مسافر شرعی باید نماز را شکسته بخواند و روزه نگیرد</p>', 'qa', 'qa', 1, 1, 'published', '2026-08-16 10:00:00'),
('معرفی کتاب اصول کافی', 'kafi-note', 'نگاهی کوتاه به کتاب شریف کافی', '<p>کتاب کافی از مهم‌ترین منابع حدیثی شیعه است</p>', 'book_note', 'home', 6, 1, 'published', '2026-08-08 10:00:00');

-- ── Posts: speeches ───────────────────────────────────────────────────
INSERT OR IGNORE INTO posts (title, slug, summary, content, post_type, page_section, speaker, category_id, author_id, status, published_at) VALUES
('سخنرانی درباره فضیلت ماه رمضان', 'ramadan-speech', 'سخنرانی استاد درباره ماه رمضان', '<p>متن سخنرانی درباره فضیلت ماه مبارک رمضان</p>', 'speech', 'home,speeches', 'استاد نمونه', 5, 1, 'published', '2026-09-11 12:00:00'),
('سخنرانی درباره اهمیت نماز جماعت', 'jamaat-speech', 'سخنرانی درباره نماز جماعت', '<p>متن سخنرانی درباره اهمیت نماز جماعت در اسلام</p>', 'speech', 'speeches', 'استاد نمونه', 5, 1, 'published', '2026-08-29 12:00:00'),
('سخنرانی درباره سبک زندگی اسلامی', 'lifestyle-speech', 'سخنرانی درباره سبک زندگی', '<p>متن سخنرانی درباره سبک زندگی اسلامی</p>', 'speech', 'speeches', 'استاد دوم', 5, 1, 'published', '2026-08-05 12:00:00');

-- ── Post ↔ topic links ────────────────────────────────────────────────
INSERT OR IGNORE INTO post_topics (post_id, topic_id) VALUES
((SELECT id FROM posts WHERE slug='aql-in-religion'), (SELECT id FROM topics WHERE slug='aqaid')),
((SELECT id FROM posts WHERE slug='aql-in-religion'), (SELECT id FROM topics WHERE slug='quran')),
((SELECT id FROM posts WHERE slug='mahdaviat-hope'), (SELECT id FROM topics WHERE slug='mahdaviat')),
((SELECT id FROM posts WHERE slug='prophet-ethics'), (SELECT id FROM topics WHERE slug='akhlaq')),
((SELECT id FROM posts WHERE slug='ashura-lessons'), (SELECT id FROM topics WHERE slug='imam-hussein')),
((SELECT id FROM posts WHERE slug='ashura-lessons'), (SELECT id FROM topics WHERE slug='qiyam-ashura')),
((SELECT id FROM posts WHERE slug='research-method'), (SELECT id FROM topics WHERE slug='pazhuhesh')),
((SELECT id FROM posts WHERE slug='hawza-history'), (SELECT id FROM topics WHERE slug='tarikh-islam')),
((SELECT id FROM posts WHERE slug='fiqh-comparison'), (SELECT id FROM topics WHERE slug='fiqh')),
((SELECT id FROM posts WHERE slug='milad-report'), (SELECT id FROM topics WHERE slug='seerah')),
((SELECT id FROM posts WHERE slug='jomeh-qa'), (SELECT id FROM topics WHERE slug='fiqh')),
((SELECT id FROM posts WHERE slug='jomeh-qa'), (SELECT id FROM topics WHERE slug='qa-topic')),
((SELECT id FROM posts WHERE slug='safar-qa'), (SELECT id FROM topics WHERE slug='fiqh')),
((SELECT id FROM posts WHERE slug='ramadan-speech'), (SELECT id FROM topics WHERE slug='akhlaq')),
((SELECT id FROM posts WHERE slug='new-school-year'), (SELECT id FROM topics WHERE slug='tarikh-islam')),
((SELECT id FROM posts WHERE slug='kafi-note'), (SELECT id FROM topics WHERE slug='hadith'));

-- ── Post gallery images ───────────────────────────────────────────────
INSERT OR IGNORE INTO post_images (post_id, image_path, alt_text) VALUES
((SELECT id FROM posts WHERE slug='milad-report'), 'uploads/images/seed-cover.png', 'تصویر مراسم'),
((SELECT id FROM posts WHERE slug='milad-report'), 'uploads/images/seed-cover.png', 'تصویر دوم مراسم');

-- ── Lesson collections / volumes / lessons ────────────────────────────
INSERT OR IGNORE INTO lesson_collections (title, slug, description, sort_order, is_active, is_featured) VALUES
('دروس فقه', 'fiqh-dars', 'مجموعه دروس فقه استدلالی', 1, 1, 1),
('دروس عقاید', 'aqaid-dars', 'مجموعه دروس اعتقادات', 2, 1, 0);

INSERT OR IGNORE INTO lesson_volumes (collection_id, title, slug, description, sort_order) VALUES
((SELECT id FROM lesson_collections WHERE slug='fiqh-dars'), 'جلد اول طهارت', 'taharat-1', 'احکام طهارت', 1),
((SELECT id FROM lesson_collections WHERE slug='fiqh-dars'), 'جلد دوم نماز', 'namaz-2', 'احکام نماز', 2),
((SELECT id FROM lesson_collections WHERE slug='aqaid-dars'), 'بخش اول توحید', 'tohid-1', 'مباحث توحید', 1);

INSERT OR IGNORE INTO lessons (title, slug, subject, teacher, content, summary, audio_file, video_file, pdf_file, sources, featured_image, level, status, page_section, sort_order, lesson_number, collection_id, volume_id, is_featured) VALUES
('درس اول مقدمات فقه', 'fiqh-lesson-1', 'فقه', 'استاد نمونه', '<p>متن درس اول مقدمات علم فقه</p>', 'آشنایی با علم فقه', 'uploads/audios/seed-audio.mp3', NULL, NULL, NULL, 'uploads/images/seed-cover.png', 'beginner', 'published', 'home', 1, 1, (SELECT id FROM lesson_collections WHERE slug='fiqh-dars'), (SELECT id FROM lesson_volumes WHERE slug='taharat-1'), 1),
('درس دوم اقسام آب‌ها', 'fiqh-lesson-2', 'فقه', 'استاد نمونه', '<p>متن درس دوم درباره اقسام آب‌ها</p>', 'احکام آب مضاف و مطلق', NULL, 'uploads/videos/seed-video.mp4', NULL, NULL, NULL, 'beginner', 'published', 'home', 2, 2, (SELECT id FROM lesson_collections WHERE slug='fiqh-dars'), (SELECT id FROM lesson_volumes WHERE slug='taharat-1'), 0),
('درس سوم احکام وضو', 'fiqh-lesson-3', 'فقه', 'استاد نمونه', '<p>متن درس سوم درباره احکام وضو</p>', 'شرایط و موانع وضو', 'uploads/audios/seed-audio.mp3', 'uploads/videos/seed-video.mp4', NULL, 'عروه الوثقی', NULL, 'intermediate', 'published', 'home', 3, 3, (SELECT id FROM lesson_collections WHERE slug='fiqh-dars'), (SELECT id FROM lesson_volumes WHERE slug='taharat-1'), 0),
('درس چهارم مقدمات نماز', 'fiqh-lesson-4', 'فقه', 'استاد نمونه', '<p>متن درس چهارم مقدمات نماز</p>', 'اهمیت نماز در اسلام', NULL, NULL, 'uploads/documents/seed-doc.pdf', NULL, NULL, 'intermediate', 'published', 'home', 4, 4, (SELECT id FROM lesson_collections WHERE slug='fiqh-dars'), (SELECT id FROM lesson_volumes WHERE slug='namaz-2'), 0),
('درس پنجم قبله و پوشش', 'fiqh-lesson-5', 'فقه', 'استاد نمونه', '<p>متن درس پنجم درباره قبله</p>', 'احکام قبله و لباس نمازگزار', NULL, NULL, NULL, NULL, NULL, 'advanced', 'published', 'home', 5, 5, (SELECT id FROM lesson_collections WHERE slug='fiqh-dars'), (SELECT id FROM lesson_volumes WHERE slug='namaz-2'), 0),
('درس اول توحید', 'aqaid-lesson-1', 'عقاید', 'استاد دوم', '<p>متن درس اول توحید</p>', 'براهین اثبات وجود خدا', 'uploads/audios/seed-audio.mp3', NULL, NULL, NULL, NULL, 'beginner', 'published', 'home', 1, 1, (SELECT id FROM lesson_collections WHERE slug='aqaid-dars'), (SELECT id FROM lesson_volumes WHERE slug='tohid-1'), 0),
('درس دوم صفات خدا', 'aqaid-lesson-2', 'عقاید', 'استاد دوم', '<p>متن درس دوم صفات الهی</p>', 'صفات ثبوتی و سلبی', NULL, NULL, NULL, NULL, NULL, 'beginner', 'published', 'home', 2, 2, (SELECT id FROM lesson_collections WHERE slug='aqaid-dars'), (SELECT id FROM lesson_volumes WHERE slug='tohid-1'), 0),
('درس آزاد اخلاق', 'free-ethics-lesson', 'اخلاق', 'استاد نمونه', '<p>متن درس آزاد اخلاق</p>', 'درسی خارج از مجموعه‌ها', NULL, NULL, NULL, NULL, NULL, 'beginner', 'published', 'home', 9, NULL, NULL, NULL, 0);

INSERT OR IGNORE INTO lesson_topics (lesson_id, topic_id) VALUES
((SELECT id FROM lessons WHERE slug='fiqh-lesson-1'), (SELECT id FROM topics WHERE slug='fiqh')),
((SELECT id FROM lessons WHERE slug='fiqh-lesson-2'), (SELECT id FROM topics WHERE slug='fiqh')),
((SELECT id FROM lessons WHERE slug='fiqh-lesson-3'), (SELECT id FROM topics WHERE slug='fiqh')),
((SELECT id FROM lessons WHERE slug='aqaid-lesson-1'), (SELECT id FROM topics WHERE slug='aqaid')),
((SELECT id FROM lessons WHERE slug='aqaid-lesson-2'), (SELECT id FROM topics WHERE slug='aqaid')),
((SELECT id FROM lessons WHERE slug='free-ethics-lesson'), (SELECT id FROM topics WHERE slug='akhlaq'));

-- ── Books ─────────────────────────────────────────────────────────────
INSERT OR IGNORE INTO books (title, slug, description, cover_image, pdf_file, word_file, author, translator, publisher, publish_year, pages, toc, is_featured, sort_order, status) VALUES
('اصول عقاید اسلامی', 'usul-aqaid', 'کتابی درباره اصول اعتقادات شیعه', 'uploads/images/seed-cover.png', 'uploads/documents/seed-doc.pdf', NULL, 'استاد نمونه', NULL, 'نشر مدرسه', '1402', 220, 'فصل اول توحید - فصل دوم نبوت - فصل سوم امامت', 1, 1, 'published'),
('احکام نماز', 'ahkam-namaz', 'رساله احکام نماز', NULL, 'uploads/documents/seed-doc.pdf', 'uploads/documents/seed-doc.docx', 'استاد نمونه', NULL, 'نشر مدرسه', '1401', 150, 'مقدمات - واجبات - مبطلات', 0, 2, 'published'),
('تفسیر سوره حمد', 'tafsir-hamd', 'تفسیری کوتاه بر سوره حمد', NULL, NULL, NULL, 'استاد دوم', NULL, NULL, NULL, 80, NULL, 0, 3, 'published'),
('سیره پیامبر اکرم', 'seerah-payambar', 'زندگانی پیامبر اسلام', 'uploads/images/seed-cover.png', 'uploads/documents/seed-doc.pdf', NULL, 'نویسنده نمونه', 'مترجم نمونه', 'نشر نمونه', '1400', 300, 'ولادت - بعثت - هجرت - وفات', 0, 4, 'published'),
('اخلاق در اسلام', 'akhlaq-islam', 'مباحث اخلاق اسلامی', NULL, NULL, NULL, 'استاد نمونه', NULL, NULL, NULL, 120, NULL, 0, 5, 'published'),
('کتاب بدون نشانی یکتا', NULL, 'کتابی که فقط با شناسه باز می‌شود', NULL, NULL, NULL, 'نویسنده نمونه', NULL, NULL, NULL, 50, NULL, 0, 6, 'published'),
('پیش‌نویس منتشرنشده', 'draft-book', 'این کتاب پیش‌نویس است و نباید دیده شود', NULL, NULL, NULL, 'نویسنده نمونه', NULL, NULL, NULL, 10, NULL, 0, 7, 'draft');

INSERT OR IGNORE INTO book_topics (book_id, topic_id) VALUES
((SELECT id FROM books WHERE slug='usul-aqaid'), (SELECT id FROM topics WHERE slug='aqaid')),
((SELECT id FROM books WHERE slug='ahkam-namaz'), (SELECT id FROM topics WHERE slug='fiqh')),
((SELECT id FROM books WHERE slug='tafsir-hamd'), (SELECT id FROM topics WHERE slug='quran')),
((SELECT id FROM books WHERE slug='seerah-payambar'), (SELECT id FROM topics WHERE slug='seerah')),
((SELECT id FROM books WHERE slug='akhlaq-islam'), (SELECT id FROM topics WHERE slug='akhlaq'));

-- ── Media files ───────────────────────────────────────────────────────
INSERT OR IGNORE INTO media_files (ref_type, ref_id, kind, file_path, title, sort_order) VALUES
('post', (SELECT id FROM posts WHERE slug='aql-in-religion'), 'video', 'uploads/videos/seed-video.mp4', 'ویدیو تبیینی', 1),
('post', (SELECT id FROM posts WHERE slug='aql-in-religion'), 'audio', 'uploads/audios/seed-audio.mp3', 'صوت مقاله', 1),
('post', (SELECT id FROM posts WHERE slug='aql-in-religion'), 'audio', 'uploads/audios/seed-audio.mp3', 'صوت تکمیلی', 2),
('lesson', (SELECT id FROM lessons WHERE slug='aqaid-lesson-1'), 'audio', 'uploads/audios/seed-audio.mp3', 'صوت درس', 1),
('lesson', (SELECT id FROM lessons WHERE slug='fiqh-lesson-2'), 'video', 'uploads/videos/seed-video.mp4', 'فیلم درس', 1);

-- ── Messages / banners ────────────────────────────────────────────────
INSERT OR IGNORE INTO contact_messages (name, email, phone, subject, message, ip_address, is_read) VALUES
('کاربر نمونه', 'user@example.test', '0798000000', 'پرسش درباره ثبت‌نام', 'شرایط ثبت‌نام در مدرسه چیست', '127.0.0.1', 0);

INSERT OR IGNORE INTO site_banners (title, content, link_url, link_text, banner_type, is_active, sort_order) VALUES
('ثبت‌نام دوره جدید', 'ثبت‌نام دوره‌های آموزشی جدید آغاز شد', '/announcements', 'مشاهده اطلاعیه‌ها', 'info', 1, 1);

INSERT OR IGNORE INTO featured_banners (title, description, link_url, button_text, is_active, sort_order) VALUES
('جشن میلاد پیامبر', 'مراسم جشن میلاد برگزار می‌شود', '/reports', 'مشاهده گزارش‌ها', 1, 1);

-- ── Pagination fixtures: 10 extra articles, 10 extra news, 8 extra books ──
INSERT OR IGNORE INTO posts (title, slug, summary, content, post_type, page_section, category_id, author_id, status, published_at) VALUES
('مقاله تستی شماره 1', 'seed-article-01', 'خلاصه مقاله تستی شماره 1', '<p>متن مقاله تستی شماره 1 برای آزمون صفحه‌بندی</p>', 'article', 'articles', 6, 1, 'published', '2026-07-01 10:00:00');
INSERT OR IGNORE INTO posts (title, slug, summary, content, post_type, page_section, category_id, author_id, status, published_at) VALUES
('مقاله تستی شماره 2', 'seed-article-02', 'خلاصه مقاله تستی شماره 2', '<p>متن مقاله تستی شماره 2 برای آزمون صفحه‌بندی</p>', 'article', 'articles', 6, 1, 'published', '2026-07-02 10:00:00');
INSERT OR IGNORE INTO posts (title, slug, summary, content, post_type, page_section, category_id, author_id, status, published_at) VALUES
('مقاله تستی شماره 3', 'seed-article-03', 'خلاصه مقاله تستی شماره 3', '<p>متن مقاله تستی شماره 3 برای آزمون صفحه‌بندی</p>', 'article', 'articles', 6, 1, 'published', '2026-07-03 10:00:00');
INSERT OR IGNORE INTO posts (title, slug, summary, content, post_type, page_section, category_id, author_id, status, published_at) VALUES
('مقاله تستی شماره 4', 'seed-article-04', 'خلاصه مقاله تستی شماره 4', '<p>متن مقاله تستی شماره 4 برای آزمون صفحه‌بندی</p>', 'article', 'articles', 6, 1, 'published', '2026-07-04 10:00:00');
INSERT OR IGNORE INTO posts (title, slug, summary, content, post_type, page_section, category_id, author_id, status, published_at) VALUES
('مقاله تستی شماره 5', 'seed-article-05', 'خلاصه مقاله تستی شماره 5', '<p>متن مقاله تستی شماره 5 برای آزمون صفحه‌بندی</p>', 'article', 'articles', 6, 1, 'published', '2026-07-05 10:00:00');
INSERT OR IGNORE INTO posts (title, slug, summary, content, post_type, page_section, category_id, author_id, status, published_at) VALUES
('مقاله تستی شماره 6', 'seed-article-06', 'خلاصه مقاله تستی شماره 6', '<p>متن مقاله تستی شماره 6 برای آزمون صفحه‌بندی</p>', 'article', 'articles', 6, 1, 'published', '2026-07-06 10:00:00');
INSERT OR IGNORE INTO posts (title, slug, summary, content, post_type, page_section, category_id, author_id, status, published_at) VALUES
('مقاله تستی شماره 7', 'seed-article-07', 'خلاصه مقاله تستی شماره 7', '<p>متن مقاله تستی شماره 7 برای آزمون صفحه‌بندی</p>', 'article', 'articles', 6, 1, 'published', '2026-07-07 10:00:00');
INSERT OR IGNORE INTO posts (title, slug, summary, content, post_type, page_section, category_id, author_id, status, published_at) VALUES
('مقاله تستی شماره 8', 'seed-article-08', 'خلاصه مقاله تستی شماره 8', '<p>متن مقاله تستی شماره 8 برای آزمون صفحه‌بندی</p>', 'article', 'articles', 6, 1, 'published', '2026-07-08 10:00:00');
INSERT OR IGNORE INTO posts (title, slug, summary, content, post_type, page_section, category_id, author_id, status, published_at) VALUES
('مقاله تستی شماره 9', 'seed-article-09', 'خلاصه مقاله تستی شماره 9', '<p>متن مقاله تستی شماره 9 برای آزمون صفحه‌بندی</p>', 'article', 'articles', 6, 1, 'published', '2026-07-09 10:00:00');
INSERT OR IGNORE INTO posts (title, slug, summary, content, post_type, page_section, category_id, author_id, status, published_at) VALUES
('مقاله تستی شماره 10', 'seed-article-10', 'خلاصه مقاله تستی شماره 10', '<p>متن مقاله تستی شماره 10 برای آزمون صفحه‌بندی</p>', 'article', 'articles', 6, 1, 'published', '2026-07-10 10:00:00');
INSERT OR IGNORE INTO posts (title, slug, summary, content, post_type, page_section, category_id, author_id, status, published_at) VALUES
('خبر تستی شماره 1', 'seed-news-01', 'خلاصه خبر تستی شماره 1', '<p>متن خبر تستی شماره 1 برای آزمون صفحه‌بندی</p>', 'news', 'news', 5, 1, 'published', '2026-07-01 11:00:00');
INSERT OR IGNORE INTO posts (title, slug, summary, content, post_type, page_section, category_id, author_id, status, published_at) VALUES
('خبر تستی شماره 2', 'seed-news-02', 'خلاصه خبر تستی شماره 2', '<p>متن خبر تستی شماره 2 برای آزمون صفحه‌بندی</p>', 'news', 'news', 5, 1, 'published', '2026-07-02 11:00:00');
INSERT OR IGNORE INTO posts (title, slug, summary, content, post_type, page_section, category_id, author_id, status, published_at) VALUES
('خبر تستی شماره 3', 'seed-news-03', 'خلاصه خبر تستی شماره 3', '<p>متن خبر تستی شماره 3 برای آزمون صفحه‌بندی</p>', 'news', 'news', 5, 1, 'published', '2026-07-03 11:00:00');
INSERT OR IGNORE INTO posts (title, slug, summary, content, post_type, page_section, category_id, author_id, status, published_at) VALUES
('خبر تستی شماره 4', 'seed-news-04', 'خلاصه خبر تستی شماره 4', '<p>متن خبر تستی شماره 4 برای آزمون صفحه‌بندی</p>', 'news', 'news', 5, 1, 'published', '2026-07-04 11:00:00');
INSERT OR IGNORE INTO posts (title, slug, summary, content, post_type, page_section, category_id, author_id, status, published_at) VALUES
('خبر تستی شماره 5', 'seed-news-05', 'خلاصه خبر تستی شماره 5', '<p>متن خبر تستی شماره 5 برای آزمون صفحه‌بندی</p>', 'news', 'news', 5, 1, 'published', '2026-07-05 11:00:00');
INSERT OR IGNORE INTO posts (title, slug, summary, content, post_type, page_section, category_id, author_id, status, published_at) VALUES
('خبر تستی شماره 6', 'seed-news-06', 'خلاصه خبر تستی شماره 6', '<p>متن خبر تستی شماره 6 برای آزمون صفحه‌بندی</p>', 'news', 'news', 5, 1, 'published', '2026-07-06 11:00:00');
INSERT OR IGNORE INTO posts (title, slug, summary, content, post_type, page_section, category_id, author_id, status, published_at) VALUES
('خبر تستی شماره 7', 'seed-news-07', 'خلاصه خبر تستی شماره 7', '<p>متن خبر تستی شماره 7 برای آزمون صفحه‌بندی</p>', 'news', 'news', 5, 1, 'published', '2026-07-07 11:00:00');
INSERT OR IGNORE INTO posts (title, slug, summary, content, post_type, page_section, category_id, author_id, status, published_at) VALUES
('خبر تستی شماره 8', 'seed-news-08', 'خلاصه خبر تستی شماره 8', '<p>متن خبر تستی شماره 8 برای آزمون صفحه‌بندی</p>', 'news', 'news', 5, 1, 'published', '2026-07-08 11:00:00');
INSERT OR IGNORE INTO posts (title, slug, summary, content, post_type, page_section, category_id, author_id, status, published_at) VALUES
('خبر تستی شماره 9', 'seed-news-09', 'خلاصه خبر تستی شماره 9', '<p>متن خبر تستی شماره 9 برای آزمون صفحه‌بندی</p>', 'news', 'news', 5, 1, 'published', '2026-07-09 11:00:00');
INSERT OR IGNORE INTO posts (title, slug, summary, content, post_type, page_section, category_id, author_id, status, published_at) VALUES
('خبر تستی شماره 10', 'seed-news-10', 'خلاصه خبر تستی شماره 10', '<p>متن خبر تستی شماره 10 برای آزمون صفحه‌بندی</p>', 'news', 'news', 5, 1, 'published', '2026-07-10 11:00:00');
INSERT OR IGNORE INTO books (title, slug, description, author, status) VALUES
('کتاب تستی شماره 1', 'seed-book-01', 'توضیح کتاب تستی شماره 1 برای آزمون صفحه‌بندی', 'نویسنده نمونه', 'published');
INSERT OR IGNORE INTO books (title, slug, description, author, status) VALUES
('کتاب تستی شماره 2', 'seed-book-02', 'توضیح کتاب تستی شماره 2 برای آزمون صفحه‌بندی', 'نویسنده نمونه', 'published');
INSERT OR IGNORE INTO books (title, slug, description, author, status) VALUES
('کتاب تستی شماره 3', 'seed-book-03', 'توضیح کتاب تستی شماره 3 برای آزمون صفحه‌بندی', 'نویسنده نمونه', 'published');
INSERT OR IGNORE INTO books (title, slug, description, author, status) VALUES
('کتاب تستی شماره 4', 'seed-book-04', 'توضیح کتاب تستی شماره 4 برای آزمون صفحه‌بندی', 'نویسنده نمونه', 'published');
INSERT OR IGNORE INTO books (title, slug, description, author, status) VALUES
('کتاب تستی شماره 5', 'seed-book-05', 'توضیح کتاب تستی شماره 5 برای آزمون صفحه‌بندی', 'نویسنده نمونه', 'published');
INSERT OR IGNORE INTO books (title, slug, description, author, status) VALUES
('کتاب تستی شماره 6', 'seed-book-06', 'توضیح کتاب تستی شماره 6 برای آزمون صفحه‌بندی', 'نویسنده نمونه', 'published');
INSERT OR IGNORE INTO books (title, slug, description, author, status) VALUES
('کتاب تستی شماره 7', 'seed-book-07', 'توضیح کتاب تستی شماره 7 برای آزمون صفحه‌بندی', 'نویسنده نمونه', 'published');
INSERT OR IGNORE INTO books (title, slug, description, author, status) VALUES
('کتاب تستی شماره 8', 'seed-book-08', 'توضیح کتاب تستی شماره 8 برای آزمون صفحه‌بندی', 'نویسنده نمونه', 'published');
INSERT OR IGNORE INTO post_topics (post_id, topic_id) VALUES
((SELECT id FROM posts WHERE slug='seed-article-01'), (SELECT id FROM topics WHERE slug='pazhuhesh')),
((SELECT id FROM posts WHERE slug='seed-article-02'), (SELECT id FROM topics WHERE slug='pazhuhesh')),
((SELECT id FROM posts WHERE slug='seed-article-03'), (SELECT id FROM topics WHERE slug='pazhuhesh')),
((SELECT id FROM posts WHERE slug='seed-article-04'), (SELECT id FROM topics WHERE slug='pazhuhesh')),
((SELECT id FROM posts WHERE slug='seed-article-05'), (SELECT id FROM topics WHERE slug='pazhuhesh')),
((SELECT id FROM posts WHERE slug='seed-article-06'), (SELECT id FROM topics WHERE slug='pazhuhesh')),
((SELECT id FROM posts WHERE slug='seed-article-07'), (SELECT id FROM topics WHERE slug='pazhuhesh')),
((SELECT id FROM posts WHERE slug='seed-article-08'), (SELECT id FROM topics WHERE slug='pazhuhesh')),
((SELECT id FROM posts WHERE slug='seed-article-09'), (SELECT id FROM topics WHERE slug='pazhuhesh')),
((SELECT id FROM posts WHERE slug='seed-article-10'), (SELECT id FROM topics WHERE slug='pazhuhesh'));
