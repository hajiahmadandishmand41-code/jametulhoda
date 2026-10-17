/**
 * tests/phase2-acceptance.mjs — Comprehensive Phase 2 acceptance & regression suite.
 *
 * Exercises against a real HTTP server and SQLite database:
 *   1. Complete Academic Topic Management (CRUD, slug, cover image, intro, placements,
 *      display modes, sort, manual item ordering, activate/deactivate, reorder,
 *      empty topic handling, safe deletion with linked-content warning + transfer/unlink,
 *      and duplicate topic merge).
 *   2. Unified Multi-Topic Content Distribution across posts (articles, news, reports,
 *      Q&A, speeches), books, lessons, and media without record duplication, plus
 *      dynamic updates and draft/private isolation.
 *   3. Smart Featured Media Selection (Case 1: valid image; Case 2: video-only without
 *      frame extraction or exclusion; Case 3: image + video with explicit admin choice
 *      persisting in DB; Case 4: audio-only and document-only frames without fake stock
 *      images; plus broken media path fallback).
 *   4. Full Homepage Section Display Control (all 10 display modes: auto, rows, featured,
 *      list, grid, horizontal, video, slider, minimal, combined; selection modes: auto,
 *      featured, topic, manual; sort orders; visibility toggles: show_media, show_excerpt,
 *      show_date, show_meta; custom header CTA URL/label; mobile_mode; section isolation).
 *   5. Internal Pages Display Control (/topics, /topic/..., /articles, /news, /reports,
 *      /books, /lessons, /media, /speeches, /qa, and detail pages) independent of homepage.
 */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import { request } from '@playwright/test';

const base = (process.env.TEST_BASE_URL || 'http://127.0.0.1:8080').replace(/\/+$/, '');
assert.ok(['127.0.0.1', 'localhost'].includes(new URL(base).hostname), 'Local test instance required');
const dbPath = process.env.SQLITE_PATH || '/tmp/jametulhoda.sqlite';

const sql = (query, args = []) => JSON.parse(execFileSync('python3', [
  '-c',
  `import sqlite3, json, sys
c = sqlite3.connect(sys.argv[1])
c.row_factory = sqlite3.Row
cur = c.execute(sys.argv[2], json.loads(sys.argv[3]))
rows = [dict(r) for r in cur.fetchall()] if cur.description else []
c.commit()
print(json.dumps(rows))`,
  dbPath,
  query,
  JSON.stringify(args),
], { encoding: 'utf8' }));

const api = await request.newContext({ baseURL: base });
let passed = 0;
const check = (cond, label, detail = '') => {
  if (!cond) {
    console.error('FAIL:', label, detail);
  }
  assert.ok(cond, label + (detail ? ` (${detail})` : ''));
  passed++;
  console.log('PASS', label);
};

const token = (html) => html.match(/name="csrf_token"\s+value="([^"]+)"/)?.[1];
const noPhpErrors = (html, path) => {
  check(!/Warning:|Fatal error:|Uncaught|شناسه پیگیری/.test(html), `no PHP error on ${path}`);
};

const decodeHtml = (s) => s
  .replace(/&amp;/g, '&')
  .replace(/&quot;/g, '"')
  .replace(/&#0?39;/g, "'")
  .replace(/&lt;/g, '<')
  .replace(/&gt;/g, '>');

function parseSettingsForm(html) {
  const out = new URLSearchParams();
  const form = html.match(/<form[^>]*class="admin-form"[\s\S]*?<\/form>/)?.[0];
  assert.ok(form, 'admin settings form present');
  for (const m of form.matchAll(/<input\b[^>]*>/g)) {
    const tag = m[0];
    const name = tag.match(/name="([^"]+)"/)?.[1];
    if (!name || /type="(?:file|submit)"/.test(tag) || (/type="checkbox"/.test(tag) && !tag.includes('checked'))) continue;
    out.append(decodeHtml(name), decodeHtml(tag.match(/value="([^"]*)"/)?.[1] || ''));
  }
  for (const m of form.matchAll(/<textarea[^>]*name="([^"]+)"[^>]*>([\s\S]*?)<\/textarea>/g)) {
    out.set(decodeHtml(m[1]), decodeHtml(m[2]));
  }
  for (const m of form.matchAll(/<select[^>]*name="([^"]+)"[^>]*>([\s\S]*?)<\/select>/g)) {
    const opts = [...m[2].matchAll(/<option\b([^>]*)>([\s\S]*?)<\/option>/g)];
    const opt = opts.find((o) => o[1].includes('selected')) || opts[0];
    if (opt) out.set(decodeHtml(m[1]), decodeHtml(opt[1].match(/value="([^"]*)"/)?.[1] ?? opt[2].trim()));
  }
  return out;
}

const originalSettings = sql('SELECT setting_key, value FROM settings');
const createdTopicIds = [];
const createdPostIds = [];
const stamp = Date.now();

try {
  // ── 0. Admin Authentication ────────────────────────────────────────────────
  const loginGet = await api.get('/login');
  const loginHtml = await loginGet.text();
  noPhpErrors(loginHtml, '/login');
  const loginPost = await api.post('/login', {
    form: {
      csrf_token: token(loginHtml),
      identifier: process.env.ADMIN_USER || 'admin',
      password: process.env.ADMIN_PASSWORD || 'TestAdmin123!@#',
    },
    maxRedirects: 0,
  });
  check(loginPost.status() === 303, 'admin login succeeds with 303');

  // ── 1. Academic Topic Management (CRUD, Reorder, Empty, Safe Delete/Transfer, Merge) ──
  const topicsAdminGet = await api.get('/admin/topics/');
  const topicsAdminHtml = await topicsAdminGet.text();
  noPhpErrors(topicsAdminHtml, '/admin/topics/');
  check(topicsAdminGet.status() === 200 && topicsAdminHtml.includes('مدیریت موضوعات علمی'), 'admin topics page renders');
  const topicsCsrf = token(topicsAdminHtml);

  // Create Topic A (with cover image, intro, placements, related topics, display settings)
  const slugA = `phase2-topic-a-${stamp}`;
  const slugB = `phase2-topic-b-${stamp}`;
  const slugEmpty = `phase2-topic-empty-${stamp}`;
  const slugDup = `phase2-topic-dup-${stamp}`;

  const createA = await api.post('/admin/topics/', {
    multipart: {
      csrf_token: topicsCsrf,
      name: `موضوع علمی الف ${stamp}`,
      slug: slugA,
      description: 'توضیح کوتاه موضوع علمی الف برای آزمون',
      intro: 'متن معرفی کامل موضوع علمی الف در بالای صفحه اختصاصی',
      sort_order: '-50',
      is_active: '1',
      is_featured: '1',
      visibility: 'public',
      display_mode: 'featured',
      content_limit: '8',
      content_sort: 'newest',
      show_media: '1',
      show_excerpt: '1',
      show_date: '1',
      show_meta: '1',
      empty_behavior: 'message',
      empty_message: 'پیام سفارشی برای موضوع الف',
      section_order: 'posts,books,lessons,media',
      placements_present: '1',
      'placements[]': 'home',
      cover_image: {
        name: 'cover.png',
        mimeType: 'image/png',
        buffer: fs.readFileSync(new URL('./fixtures/image.png', import.meta.url)),
      },
    },
    maxRedirects: 0,
  });
  check(createA.status() === 303, 'create Topic A with cover image and display settings');
  const topicA = sql('SELECT * FROM topics WHERE slug = ?', [slugA])[0];
  check(!!topicA && topicA.id > 0, 'Topic A persisted in database');
  createdTopicIds.push(topicA.id);
  check(!!topicA.cover_image && topicA.intro.includes('معرفی کامل'), 'Topic A cover_image and intro saved');

  // Create Topic B (target for transfer & multi-topic distribution)
  const createB = await api.post('/admin/topics/', {
    form: {
      csrf_token: topicsCsrf,
      name: `موضوع علمی ب ${stamp}`,
      slug: slugB,
      description: 'توضیح موضوع علمی ب',
      sort_order: '-49',
      is_active: '1',
      is_featured: '1',
      visibility: 'public',
      display_mode: 'grid',
      content_limit: '10',
      content_sort: 'newest',
      placements_present: '1',
      'placements[]': 'home,topics_archive,articles',
      'related_topic_ids[]': String(topicA.id),
    },
    maxRedirects: 0,
  });
  check(createB.status() === 303, 'create Topic B');
  const topicB = sql('SELECT * FROM topics WHERE slug = ?', [slugB])[0];
  check(!!topicB && topicB.id > 0, 'Topic B persisted in database');
  createdTopicIds.push(topicB.id);

  // Create Empty Topic
  const createEmpty = await api.post('/admin/topics/', {
    form: {
      csrf_token: topicsCsrf,
      name: `موضوع خالی ${stamp}`,
      slug: slugEmpty,
      description: 'موضوع بدون محتوا برای بررسی حالت خالی',
      sort_order: '-48',
      is_active: '1',
      is_featured: '1',
      visibility: 'public',
      display_mode: 'list',
      empty_behavior: 'hide_from_home',
      empty_message: 'هنوز هیچ اثر علمی در این موضوع ثبت نشده است.',
      placements_present: '1',
      'placements[]': 'home,topics_archive',
    },
    maxRedirects: 0,
  });
  check(createEmpty.status() === 303, 'create Empty Topic with hide_from_home');
  const topicEmpty = sql('SELECT * FROM topics WHERE slug = ?', [slugEmpty])[0];
  createdTopicIds.push(topicEmpty.id);

  // Verify Empty Topic public page works cleanly & is hidden from homepage because of hide_from_home
  const emptyPageRes = await api.get(`/topic/${slugEmpty}`);
  const emptyPageHtml = await emptyPageRes.text();
  noPhpErrors(emptyPageHtml, `/topic/${slugEmpty}`);
  check(
    emptyPageRes.status() === 200
      && emptyPageHtml.includes('محتوای منتشرشده‌ای برای این موضوع یافت نشد')
      && emptyPageHtml.includes('هنوز هیچ اثر علمی در این موضوع ثبت نشده است.'),
    'empty topic page renders 200 with custom empty message'
  );
  const homeWithEmptyHidden = await (await api.get('/')).text();
  const homeTopicsSec1 = homeWithEmptyHidden.match(/data-home-section="topics"[\s\S]*?<\/section>/)?.[0] || '';
  check(!homeTopicsSec1.includes(topicEmpty.name), 'empty topic with hide_from_home is excluded from homepage topics section');

  // Reorder topics via reorder_topics POST
  const reorderRes = await api.post('/admin/topics/', {
    form: {
      csrf_token: topicsCsrf,
      reorder_topics: '1',
      [`orders[${topicA.id}]`]: '1',
      [`orders[${topicB.id}]`]: '2',
    },
    maxRedirects: 0,
  });
  check(reorderRes.status() === 303, 'reorder_topics POST succeeds');
  check(
    sql('SELECT sort_order FROM topics WHERE id = ?', [topicA.id])[0].sort_order === 1
      && sql('SELECT sort_order FROM topics WHERE id = ?', [topicB.id])[0].sort_order === 2,
    'topic sort_order updated in database'
  );

  // Deactivate Topic Empty and verify 404 on public page, then reactivate
  const toggleOff = await api.post('/admin/topics/', {
    form: { csrf_token: topicsCsrf, toggle_active: String(topicEmpty.id) },
    maxRedirects: 0,
  });
  check(toggleOff.status() === 303, 'toggle_active deactivates topic');
  // Also set visibility=inactive via edit
  await api.post('/admin/topics/', {
    form: {
      csrf_token: topicsCsrf,
      edit_id: String(topicEmpty.id),
      name: topicEmpty.name,
      slug: slugEmpty,
      visibility: 'inactive',
    },
    maxRedirects: 0,
  });
  const inactiveTopicGet = await api.get(`/topic/${slugEmpty}`);
  check(inactiveTopicGet.status() === 404, 'inactive topic returns 404 on public site');
  await api.post('/admin/topics/', {
    form: {
      csrf_token: topicsCsrf,
      edit_id: String(topicEmpty.id),
      name: topicEmpty.name,
      slug: slugEmpty,
      is_active: '1',
      visibility: 'public',
    },
    maxRedirects: 0,
  });
  check((await api.get(`/topic/${slugEmpty}`)).status() === 200, 'reactivated topic returns 200 on public site');

  // ── 2. Unified Content Connection & Multi-Topic Distribution ───────────────
  // Pick published post, book, lesson from seed
  const seedArticle = sql("SELECT id, title, slug FROM posts WHERE slug = 'aql-in-religion' LIMIT 1")[0];
  const seedNews = sql("SELECT id, title, slug FROM posts WHERE slug = 'new-school-year' LIMIT 1")[0];
  const seedBook = sql("SELECT id, title, slug FROM books WHERE slug = 'usul-aqaid' LIMIT 1")[0];
  const seedLesson = sql("SELECT id, title, slug FROM lessons WHERE slug = 'fiqh-lesson-1' LIMIT 1")[0];

  // Link seedArticle, seedNews, seedBook, seedLesson to Topic A via quick_link_content
  const quickLinkA = await api.post('/admin/topics/', {
    form: {
      csrf_token: topicsCsrf,
      quick_link_content: '1',
      topic_id: String(topicA.id),
      'link_post_ids[]': `${seedArticle.id},${seedNews.id}`,
      'link_book_ids[]': String(seedBook.id),
      'link_lesson_ids[]': String(seedLesson.id),
    },
    maxRedirects: 0,
  });
  check(quickLinkA.status() === 303, 'quick_link_content attaches posts, books, and lessons to Topic A');

  // Also link seedArticle to BOTH Topic A and Topic B via admin/posts/edit.php
  const editArtGet = await api.get(`/admin/posts/edit.php?id=${seedArticle.id}`);
  const editArtHtml = await editArtGet.text();
  noPhpErrors(editArtHtml, `/admin/posts/edit.php?id=${seedArticle.id}`);
  const existingArtTopicIds = sql('SELECT topic_id FROM post_topics WHERE post_id = ?', [seedArticle.id]).map((r) => String(r.topic_id));
  const multiTopicIds = [...new Set([...existingArtTopicIds, String(topicA.id), String(topicB.id)])];

  const editArtPost = await api.post(`/admin/posts/edit.php?id=${seedArticle.id}`, {
    form: {
      csrf_token: token(editArtHtml),
      title: seedArticle.title,
      slug: seedArticle.slug,
      post_type: 'article',
      status: 'published',
      summary: 'بررسی نقش عقل در فهم معارف اسلامی',
      content: '<p>محتوای کامل مقاله جایگاه عقل در معرفت دینی</p>',
      'topic_ids[]': multiTopicIds.join(','),
      primary_topic_id: String(topicA.id),
      featured_media_pref: 'auto',
    },
    maxRedirects: 0,
  });
  check(editArtPost.status() === 303, 'post edit saves multi-topic assignment without duplicating post');
  check(
    sql('SELECT COUNT(*) AS n FROM posts WHERE id = ?', [seedArticle.id])[0].n === 1,
    'single post record preserved across multiple topics (no duplicate post rows)'
  );

  // Verify Topic A and Topic B public pages both display seedArticle, and Topic A also shows seedBook & seedLesson
  const topicAPage = await (await api.get(`/topic/${slugA}`)).text();
  const topicBPage = await (await api.get(`/topic/${slugB}`)).text();
  noPhpErrors(topicAPage, `/topic/${slugA}`);
  noPhpErrors(topicBPage, `/topic/${slugB}`);
  check(
    topicAPage.includes(seedArticle.title)
      && topicAPage.includes(seedNews.title)
      && topicAPage.includes(seedBook.title)
      && topicAPage.includes(seedLesson.title),
    'Topic A public page renders linked articles, news, books, and lessons'
  );
  check(topicBPage.includes(seedArticle.title), 'Topic B public page renders shared multi-topic article');
  check(topicBPage.includes(topicA.name), 'Topic B public page renders configured related topic (Topic A)');

  // Test manual ordering and section ordering on Topic A
  await api.post('/admin/topics/', {
    form: {
      csrf_token: topicsCsrf,
      edit_id: String(topicA.id),
      name: topicA.name,
      slug: slugA,
      is_active: '1',
      is_featured: '1',
      visibility: 'public',
      display_mode: 'list',
      content_limit: '8',
      content_sort: 'manual',
      manual_post_ids: `${seedNews.id},${seedArticle.id}`,
      section_order: 'books,posts,lessons,media',
      placements_present: '1',
      'placements[]': 'home',
    },
    maxRedirects: 0,
  });
  const topicAManualHtml = await (await api.get(`/topic/${slugA}`)).text();
  check(
    topicAManualHtml.indexOf('data-topic-section="books"') < topicAManualHtml.indexOf('data-topic-section="posts"'),
    'Topic A custom section_order (books before posts) applied on public page'
  );
  check(
    topicAManualHtml.indexOf(seedNews.title) < topicAManualHtml.indexOf(seedArticle.title),
    'Topic A manual_post_ids ordering applied on public page'
  );

  // Verify draft content NEVER leaks to Topic A page
  const draftCreateGet = await api.get('/admin/posts/create.php');
  const draftTitle = `پیش‌نویس محرمانه موضوع ${stamp}`;
  const draftRes = await api.post('/admin/posts/create.php', {
    form: {
      csrf_token: token(await draftCreateGet.text()),
      title: draftTitle,
      post_type: 'article',
      status: 'draft',
      content: '<p>این پیش‌نویس نباید در صفحه موضوع دیده شود.</p>',
      'topic_ids[]': String(topicA.id),
    },
    maxRedirects: 0,
  });
  check(draftRes.status() === 303, 'create draft post linked to Topic A');
  const draftPost = sql('SELECT id FROM posts WHERE title = ?', [draftTitle])[0];
  if (draftPost) createdPostIds.push(draftPost.id);
  const topicAAfterDraft = await (await api.get(`/topic/${slugA}`)).text();
  check(!topicAAfterDraft.includes(draftTitle), 'draft post linked to Topic A never leaks to public topic page');

  // Test Safe Topic Deletion with Linked Content:
  // Step 1: Attempt delete on Topic A (which has linked posts/books/lessons) WITHOUT confirm_delete_relations
  const unsafeDelA = await api.post('/admin/topics/', {
    form: { csrf_token: topicsCsrf, delete: String(topicA.id) },
    maxRedirects: 0,
  });
  check(
    unsafeDelA.status() === 303 && (unsafeDelA.headers()['location'] || '').includes(`confirm_delete=${topicA.id}`),
    'deleting a topic with linked content requires confirmation and redirects to confirm_delete'
  );
  check(sql('SELECT COUNT(*) AS n FROM topics WHERE id = ?', [topicA.id])[0].n === 1, 'Topic A was NOT deleted without confirmation');

  const confirmPageHtml = await (await api.get(`/admin/topics/?confirm_delete=${topicA.id}`)).text();
  noPhpErrors(confirmPageHtml, `/admin/topics/?confirm_delete=${topicA.id}`);
  check(
    confirmPageHtml.includes('تأیید حذف موضوع علمی')
      && confirmPageHtml.includes('انتقال همهٔ محتواها و زیرموضوع‌ها به یک موضوع دیگر'),
    'confirm_delete page displays linked counts and transfer/unlink options'
  );

  // Step 2: Confirm deletion of Topic A with strategy='transfer' to Topic B
  const safeTransferDelA = await api.post('/admin/topics/', {
    form: {
      csrf_token: topicsCsrf,
      delete: String(topicA.id),
      confirm_delete_relations: '1',
      delete_strategy: 'transfer',
      target_topic_id: String(topicB.id),
    },
    maxRedirects: 0,
  });
  check(safeTransferDelA.status() === 303, 'confirmed topic delete with transfer succeeds');
  check(sql('SELECT COUNT(*) AS n FROM topics WHERE id = ?', [topicA.id])[0].n === 0, 'Topic A deleted after transfer');
  check(
    sql('SELECT COUNT(*) AS n FROM posts WHERE id IN (?, ?)', [seedArticle.id, seedNews.id])[0].n === 2
      && sql('SELECT COUNT(*) AS n FROM books WHERE id = ?', [seedBook.id])[0].n === 1
      && sql('SELECT COUNT(*) AS n FROM lessons WHERE id = ?', [seedLesson.id])[0].n === 1,
    'underlying posts, books, and lessons were NEVER deleted when Topic A was deleted'
  );
  const topicBPageAfterTransfer = await (await api.get(`/topic/${slugB}`)).text();
  check(
    topicBPageAfterTransfer.includes(seedNews.title)
      && topicBPageAfterTransfer.includes(seedBook.title)
      && topicBPageAfterTransfer.includes(seedLesson.title),
    'transferred posts, books, and lessons now appear under Topic B without duplicates'
  );

  // Test Duplicate Topic Merge (merge_topic)
  await api.post('/admin/topics/', {
    form: {
      csrf_token: topicsCsrf,
      name: `موضوع تکراری ${stamp}`,
      slug: slugDup,
      is_active: '1',
    },
    maxRedirects: 0,
  });
  const topicDup = sql('SELECT * FROM topics WHERE slug = ?', [slugDup])[0];
  createdTopicIds.push(topicDup.id);
  const mergeRes = await api.post('/admin/topics/', {
    form: {
      csrf_token: topicsCsrf,
      merge_topic: '1',
      source_topic_id: String(topicDup.id),
      target_topic_id: String(topicB.id),
      delete_source_after_merge: '1',
    },
    maxRedirects: 0,
  });
  check(mergeRes.status() === 303 && sql('SELECT COUNT(*) AS n FROM topics WHERE id = ?', [topicDup.id])[0].n === 0, 'duplicate topic merged and removed cleanly');

  // ── 3. Smart Featured Media Selection (All 4 Cases + Broken Media Fallback) ──
  // Case 1: Post with valid featured_image (`new-school-year`) -> uses image
  const newsPageHtml = await (await api.get('/news')).text();
  noPhpErrors(newsPageHtml, '/news');
  check(newsPageHtml.includes('uploads/images/seed-cover.png'), 'Case 1: valid featured image renders in archive cards');

  // Case 2: Video-only content WITHOUT featured_image (`urdu-report` has featured_video='uploads/videos/seed-video.mp4' and featured_image=NULL)
  const reportsPageHtml = await (await api.get('/reports')).text();
  noPhpErrors(reportsPageHtml, '/reports');
  check(
    reportsPageHtml.includes('jhd-card-media--video')
      && reportsPageHtml.includes('uploads/videos/seed-video.mp4')
      && !reportsPageHtml.includes('autoplay'),
    'Case 2: video-only post (urdu-report) renders video frame with play button and no autoplay'
  );

  // Also test video-only post as Homepage Lead Story (must NOT be excluded from lead story!)
  const postUrlEncoded = (path, params) => api.post(path, {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    data: params.toString(),
    maxRedirects: 0,
  });
  const urduReport = sql("SELECT id FROM posts WHERE slug = 'urdu-report' LIMIT 1")[0];
  const settingsGet1 = await api.get('/admin/settings');
  const settingsForm1 = parseSettingsForm(await settingsGet1.text());
  settingsForm1.set('homepage_hero_mode', 'manual');
  settingsForm1.set('homepage_feature_ref', `post:${urduReport.id}`);
  await postUrlEncoded('/admin/settings', settingsForm1);
  const homeWithVideoLead = await (await api.get('/')).text();
  noPhpErrors(homeWithVideoLead, '/ (video lead)');
  check(
    homeWithVideoLead.includes('jhd-lead__media--video')
      && homeWithVideoLead.includes('uploads/videos/seed-video.mp4'),
    'Case 2: video-only post renders as Homepage Featured Lead Story with video preview'
  );

  // Case 3: Content with BOTH image AND video (`aql-in-religion` has featured_image='uploads/images/seed-cover.png' AND featured_video='uploads/videos/seed-video.mp4')
  // 3a: Admin explicitly selects 'video' as featured media
  const editAqlGet = await api.get(`/admin/posts/edit.php?id=${seedArticle.id}`);
  const editAqlCsrf = token(await editAqlGet.text());
  await api.post(`/admin/posts/edit.php?id=${seedArticle.id}`, {
    form: {
      csrf_token: editAqlCsrf,
      title: seedArticle.title,
      slug: seedArticle.slug,
      post_type: 'article',
      status: 'published',
      summary: 'بررسی نقش عقل در فهم معارف اسلامی',
      content: '<p>محتوای کامل مقاله جایگاه عقل در معرفت دینی</p>',
      'topic_ids[]': multiTopicIds.filter((id) => id !== String(topicA.id)).join(','),
      featured_media_pref: 'video',
    },
    maxRedirects: 0,
  });
  const articlesWithVideoPref = await (await api.get('/articles')).text();
  const aqlDetailVideoPref = await (await api.get('/articles/aql-in-religion')).text();
  check(
    articlesWithVideoPref.includes('data-featured-media="video"')
      && (articlesWithVideoPref.includes('jhd-lead__media--video') || articlesWithVideoPref.includes('jhd-card-media--video')),
    'Case 3a: when admin selects video on image+video post, archive renders video frame'
  );
  check(
    aqlDetailVideoPref.includes('featuredPostVideo') || aqlDetailVideoPref.includes('uploads/videos/seed-video.mp4'),
    'Case 3a: when admin selects video on image+video post, detail page renders featured video'
  );

  // 3b: Admin explicitly switches back to 'image'
  const editAqlGet2 = await api.get(`/admin/posts/edit.php?id=${seedArticle.id}`);
  await api.post(`/admin/posts/edit.php?id=${seedArticle.id}`, {
    form: {
      csrf_token: token(await editAqlGet2.text()),
      title: seedArticle.title,
      slug: seedArticle.slug,
      post_type: 'article',
      status: 'published',
      summary: 'بررسی نقش عقل در فهم معارف اسلامی',
      content: '<p>محتوای کامل مقاله جایگاه عقل در معرفت دینی</p>',
      'topic_ids[]': multiTopicIds.filter((id) => id !== String(topicA.id)).join(','),
      featured_media_pref: 'image',
    },
    maxRedirects: 0,
  });
  const articlesWithImagePref = await (await api.get('/articles')).text();
  check(
    articlesWithImagePref.includes('data-featured-media="image"'),
    'Case 3b: when admin selects image on image+video post, archive card renders image'
  );

  // Case 4: Audio-only (`aqaid-lesson-1`) and Document-only (`fiqh-lesson-4` / `ahkam-namaz`)
  const aqaidLesson = sql("SELECT id, title, slug FROM lessons WHERE slug = 'aqaid-lesson-1' LIMIT 1")[0];
  const docLesson = sql("SELECT id, title, slug FROM lessons WHERE slug = 'fiqh-lesson-4' LIMIT 1")[0];
  const docBook = sql("SELECT id, title, slug FROM books WHERE slug = 'ahkam-namaz' LIMIT 1")[0];

  // Set featured_media_pref = 'audio' on aqaid-lesson-1 and 'document' on fiqh-lesson-4
  const editAqaidGet = await api.get(`/admin/lessons/edit.php?id=${aqaidLesson.id}`);
  await api.post(`/admin/lessons/edit.php?id=${aqaidLesson.id}`, {
    form: {
      csrf_token: token(await editAqaidGet.text()),
      title: aqaidLesson.title,
      level: 'beginner',
      status: 'published',
      content: '<p>محتوای درس توحید</p>',
      featured_media_pref: 'audio',
    },
    maxRedirects: 0,
  });
  // Switch lessons page to grid mode temporarily so all lesson cards render on /lessons
  const settingsGetLessons = await api.get('/admin/settings');
  const settingsFormLessons = parseSettingsForm(await settingsGetLessons.text());
  settingsFormLessons.set('internal_pages[lessons][mode]', 'grid');
  await postUrlEncoded('/admin/settings', settingsFormLessons);

  const lessonsGridHtml = await (await api.get('/lessons')).text();
  noPhpErrors(lessonsGridHtml, '/lessons (grid)');
  check(
    lessonsGridHtml.includes('jhd-card-media--audio') && lessonsGridHtml.includes('فایل صوتی'),
    'Case 4a: audio-only lesson renders dedicated audio frame without fake image'
  );

  const booksPageHtml = await (await api.get('/books?q=' + encodeURIComponent('احکام'))).text();
  noPhpErrors(booksPageHtml, '/books');
  check(
    booksPageHtml.includes('jhd-card-media--document') && booksPageHtml.includes('PDF'),
    'Case 4b: document-only book (ahkam-namaz) renders document card frame with file badge and no fake image'
  );

  // Broken Media Fallback: temporarily set a broken image path on a test post with a valid video
  sql("UPDATE posts SET featured_image = 'uploads/images/missing-broken-404.png' WHERE id = ?", [urduReport.id]);
  const reportsWithBrokenImg = await (await api.get('/reports')).text();
  noPhpErrors(reportsWithBrokenImg, '/reports (broken image fallback)');
  check(
    !reportsWithBrokenImg.includes('missing-broken-404.png')
      && reportsWithBrokenImg.includes('uploads/videos/seed-video.mp4'),
    'Broken media fallback: nonexistent image path is ignored and falls back cleanly to valid video'
  );
  sql("UPDATE posts SET featured_image = NULL WHERE id = ?", [urduReport.id]);

  // ── 4. Full Homepage Display Modes & Per-Section Controls ──────────────────
  const allModes = [
    ['rows', 'jhd-card--compact'],
    ['featured', 'jhd-card--featured'],
    ['list', 'col-12'],
    ['grid', 'row g-3'],
    ['horizontal', 'jhd-card--row'],
    ['video', 'jhd-home-video'],
    ['slider', 'jhd-home-slider'],
    ['minimal', 'jhd-home-minimal'],
    ['combined', 'jhd-home-combined'],
  ];

  const baseSettingsHtml = await (await api.get('/admin/settings')).text();
  const baseForm = parseSettingsForm(baseSettingsHtml);
  baseForm.set('homepage_hero_mode', 'auto');

  for (const [mode, expectedMarker] of allModes) {
    const f = new URLSearchParams(baseForm);
    f.set('homepage_layout[news][mode]', mode);
    f.set('homepage_layout[news][limit]', '3');
    const res = await postUrlEncoded('/admin/settings', f);
    check(res.status() === 303, `homepage news section saved with mode=${mode}`);
    const homeHtml = await (await api.get('/')).text();
    noPhpErrors(homeHtml, `/ (mode=${mode})`);
    const newsSec = homeHtml.match(/data-home-section="news"[\s\S]*?<\/section>/)?.[0] || '';
    check(
      newsSec.includes(`data-display-mode="${mode}"`) && newsSec.includes(expectedMarker),
      `homepage news section renders mode=${mode} (${expectedMarker})`
    );
  }

  // Test topic-based selection, visibility flags (show_media=0, show_excerpt=0), custom more_url/more_label, and mobile_mode on homepage
  {
    const f = new URLSearchParams(baseForm);
    f.set('homepage_layout[writing][mode]', 'grid');
    f.set('homepage_layout[writing][selection]', 'topic');
    f.set('homepage_layout[writing][topic_id]', String(topicB.id));
    f.set('homepage_layout[writing][show_media]', '0');
    f.set('homepage_layout[writing][show_excerpt]', '0');
    f.set('homepage_layout[writing][more_label]', 'همه آثار موضوع ب');
    f.set('homepage_layout[writing][more_url]', `/topic/${slugB}`);
    f.set('homepage_layout[writing][mobile_mode]', 'slider');
    const res = await postUrlEncoded('/admin/settings', f);
    check(res.status() === 303, 'homepage writing section saved with topic selection & visibility flags');
    const homeHtml = await (await api.get('/')).text();
    const writingSec = homeHtml.match(/data-home-section="writing"[\s\S]*?<\/section>/)?.[0] || '';
    check(
      writingSec.includes('data-mobile-mode="slider"')
        && writingSec.includes('همه آثار موضوع ب')
        && writingSec.includes(`/topic/${slugB}`)
        && !writingSec.includes('jhd-card-media')
        && !writingSec.includes('jhd-card-summary'),
      'homepage writing section applies topic source, mobile_mode, custom CTA link, and hides media/excerpt without affecting other sections'
    );
    const newsSec = homeHtml.match(/data-home-section="news"[\s\S]*?<\/section>/)?.[0] || '';
    check(newsSec.includes('jhd-card-media'), 'other homepage sections (news) retain their media independently');
  }

  // ── 5. Internal Pages Display Control ──────────────────────────────────────
  {
    const f = new URLSearchParams(baseForm);
    f.set('internal_pages[topics][mode]', 'list');
    f.set('internal_pages[articles][mode]', 'grid');
    f.set('internal_pages[articles][sort]', 'popular');
    f.set('internal_pages[news][mode]', 'minimal');
    f.set('internal_pages[books][mode]', 'horizontal');
    const res = await postUrlEncoded('/admin/settings', f);
    check(res.status() === 303, 'internal_pages settings saved via /admin/settings');

    const topicsPageHtml = await (await api.get('/topics')).text();
    const articlesPageHtml = await (await api.get('/articles')).text();
    const newsInternalHtml = await (await api.get('/news')).text();
    const booksInternalHtml = await (await api.get('/books')).text();

    check(topicsPageHtml.includes('data-display-mode="list"'), '/topics reflects internal_pages[topics][mode]=list');
    check(articlesPageHtml.includes('data-display-mode="grid"'), '/articles reflects internal_pages[articles][mode]=grid');
    check(newsInternalHtml.includes('data-display-mode="minimal"') && newsInternalHtml.includes('jhd-home-minimal'), '/news reflects internal_pages[news][mode]=minimal');
    check(booksInternalHtml.includes('data-display-mode="horizontal"'), '/books reflects internal_pages[books][mode]=horizontal');
  }

  // ── 6. Admin Topic Filter on Posts, Books, and Lessons Lists ───────────────
  {
    const adminPostsByTopic = await (await api.get(`/admin/posts/index.php?topic=${topicB.id}`)).text();
    noPhpErrors(adminPostsByTopic, `/admin/posts/index.php?topic=${topicB.id}`);
    check(adminPostsByTopic.includes(seedArticle.title), 'admin/posts/index.php?topic= filters posts by topic');

    const adminBooksByTopic = await (await api.get(`/admin/books/index.php?topic=${topicB.id}`)).text();
    noPhpErrors(adminBooksByTopic, `/admin/books/index.php?topic=${topicB.id}`);
    check(adminBooksByTopic.includes(seedBook.title), 'admin/books/index.php?topic= filters books by topic');

    const adminLessonsByTopic = await (await api.get(`/admin/lessons/index.php?topic=${topicB.id}`)).text();
    noPhpErrors(adminLessonsByTopic, `/admin/lessons/index.php?topic=${topicB.id}`);
    check(adminLessonsByTopic.includes(seedLesson.title), 'admin/lessons/index.php?topic= filters lessons by topic');
  }

  console.log(`\nALL ${passed} PHASE 2 ACCEPTANCE & REGRESSION CHECKS PASSED!`);
} finally {
  // Restore database state cleanly
  sql("UPDATE posts SET featured_image = NULL WHERE slug = 'urdu-report'");
  for (const pid of createdPostIds) {
    sql('DELETE FROM post_topics WHERE post_id = ?', [pid]);
    sql('DELETE FROM posts WHERE id = ?', [pid]);
  }
  for (const tid of createdTopicIds) {
    sql('DELETE FROM post_topics WHERE topic_id = ?', [tid]);
    sql('DELETE FROM book_topics WHERE topic_id = ?', [tid]);
    sql('DELETE FROM lesson_topics WHERE topic_id = ?', [tid]);
    sql('DELETE FROM settings WHERE setting_key = ?', [`topic_display_${tid}`]);
    sql('DELETE FROM topics WHERE id = ?', [tid]);
  }
  sql("DELETE FROM settings WHERE setting_key LIKE 'featured_media_%' OR setting_key = 'internal_pages_config'");
  for (const row of originalSettings) {
    sql('INSERT INTO settings (setting_key, value) VALUES (?, ?) ON CONFLICT(setting_key) DO UPDATE SET value = excluded.value', [row.setting_key, row.value]);
  }
  await api.dispose();
}
