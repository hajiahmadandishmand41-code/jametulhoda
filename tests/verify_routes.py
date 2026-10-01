#!/usr/bin/env python3
"""
verify_routes.py — Static routing and link audit test suite for Jametulhoda
Verifies every route defined in config/routes.php, aliases, dynamic patterns,
and checks that handlers physically exist on disk.
"""

import os
import re
import sys

BASE_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
os.chdir(BASE_DIR)

with open('config/routes.php', 'r', encoding='utf-8') as f:
    text = f.read()

pos_routes = text.find("'routes' => [")
pos_aliases = text.find("'aliases' => [")
pos_patterns = text.find("'patterns' => [")

routes_chunk = text[pos_routes:pos_aliases]
aliases_chunk = text[pos_aliases:pos_patterns]
patterns_chunk = text[pos_patterns:]

routes = {}
for line in routes_chunk.splitlines():
    line = line.strip()
    if not line or line.startswith('//') or line.startswith("'routes'"):
        continue
    m = re.match(r"['\"]([^'\"]+)['\"]\s*=>\s*(.*)", line)
    if m:
        path = m.group(1)
        target = m.group(2).rstrip(',')
        if target.startswith('{') or target.startswith('['):
            fm = re.search(r"['\"]file['\"]\s*=>\s*['\"]([^'\"]+)['\"]", target)
            routes[path] = {'file': fm.group(1) if fm else 'unknown'}
        else:
            routes[path] = {'file': target.strip("'\" ")}

aliases = {}
for line in aliases_chunk.splitlines():
    line = line.strip()
    if not line or line.startswith('//') or line.startswith("'aliases'"):
        continue
    m = re.match(r"['\"]([^'\"]+)['\"]\s*=>\s*['\"]([^'\"]+)['\"]", line)
    if m:
        aliases[m.group(1)] = m.group(2)

patterns = []
for line in patterns_chunk.splitlines():
    line = line.strip()
    if not line or line.startswith('//') or 'patterns' in line:
        continue
    m = re.search(r"\[\s*'([^']+)'\s*,\s*'([^']+)'\s*,\s*(\[[^\]]*\])", line)
    if m:
        patterns.append((m.group(1), m.group(2), m.group(3)))

# Expand static table exactly as router.php does
table = {}
for path, target in routes.items():
    entry = dict(target)
    entry['canonical'] = path
    table[path] = entry
    if path != '/' and '.' not in path[1:]:
        table[path + '/'] = entry
        table[path + '.php'] = entry
        if os.path.basename(entry['file']) == 'index.php':
            table[path + '/index.php'] = entry

for fr, to in aliases.items():
    if to in table:
        entry = dict(table[to])
        table[fr] = entry
        if fr != '/' and '.' not in fr[1:]:
            table[fr + '/'] = entry

def resolve(req_path):
    # Static match
    entry = table.get(req_path)
    if entry:
        return entry, 'static'
    # Pattern match
    for pat_str, script, params_str in patterns:
        m_regex = re.match(r'^~(.*)~([a-zA-Z]*)$', pat_str)
        if not m_regex:
            continue
        pattern_body = m_regex.group(1)
        pattern_body = pattern_body.rstrip('D').rstrip('$') + '$'
        flags = 0
        if 'i' in m_regex.group(2):
            flags |= re.IGNORECASE
        m = re.search(pattern_body, req_path, flags)
        if m:
            sc = script
            for i in range(1, len(m.groups()) + 1):
                sc = sc.replace(f'${i}', m.group(i) or '')
            return {'file': sc, 'canonical': req_path, 'matches': m.groups()}, 'pattern'
    return None, '404'

if __name__ == '__main__':
    print(f"Loaded {len(routes)} static routes, {len(aliases)} aliases, and {len(patterns)} dynamic patterns.")
    print(f"Expanded route table contains {len(table)} lookup entries.")

    test_cases = [
        ('/', 200),
        ('/news', 200),
        ('/news/', 200),
        ('/news.php', 200),
        ('/articles', 200),
        ('/articles/', 200),
        ('/reports', 200),
        ('/reports/', 200),
        ('/events', 200),
        ('/events/', 200),
        ('/books', 200),
        ('/books/', 200),
        ('/lessons', 200),
        ('/lessons/', 200),
        ('/research', 200),
        ('/research/', 200),
        ('/media', 200),
        ('/media/', 200),
        ('/videos', 200),
        ('/audios', 200),
        ('/topics', 200),
        ('/topics/', 200),
        ('/search', 200),
        ('/contact', 200),
        ('/about', 200),
        ('/login', 200),
        ('/register', 200),
        ('/account', 200),
        ('/admin/login', 200),
        ('/admin/login.php', 200),
        ('/admin/dashboard', 200),
        ('/admin/profile', 200),
        ('/admin/uploads', 200),
        ('/admin/videos', 200),
        ('/admin/audios', 200),
        ('/admin/courses', 200),
        ('/admin/research', 200),
        ('/admin', 200),
        ('/admin/content', 200),
        ('/admin/users', 200),
        ('/admin/users/new', 200),
        ('/admin/settings', 200),
        ('/sitemap.xml', 200),
        ('/robots.txt', 200),
        ('/install', 200),
        ('/install/', 200),
        ('/php/install', 200),
        ('/php/install.php', 200),
        # The legacy root installer path must stay unreachable (docs/FILE_ROUTE_MAP.md).
        ('/install.php', 404),
        ('/news/new-school-year', 200),
        ('/post/generic-post', 200),
        ('/article/aql-in-religion', 200),
        ('/articles/aql-in-religion', 200),
        ('/report/milad-report', 200),
        ('/reports/milad-report', 200),
        ('/research/research-method', 200),
        ('/events/annual-event', 200),
        ('/book/usul-aqaid', 200),
        ('/books/usul-aqaid', 200),
        ('/book/1', 200),
        ('/books/1', 200),
        ('/lesson/fiqh-lesson-1', 200),
        ('/lessons/fiqh-lesson-1', 200),
        ('/topic/mahdaviat', 200),
        ('/topics/mahdaviat', 200),
        ('/topic/quran-hadith/oloum-quran', 200),
        ('/video/1', 200),
        ('/audio/2', 200),
        ('/admin/users/edit/1', 200),
        ('/admin/topics/1/edit', 200),
        ('/admin/content/1/publish', 200),
        ('/admin/content/1/unpublish', 200),
        ('/admin/content/1/archive', 200),
        ('/admin/content/1/delete', 200),
        ('/missing-xyz', 404),
        ('/config/database.php', 404),
        ('/.env', 404),
        ('/bin/migrate.php', 404),
        ('/database/database.postgres.sql', 404),
        ('/storage/logs/.gitkeep', 404),
        ('/includes/functions.php', 404),
        ('/admin/includes/header.php', 404),
    ]

    # The target files are part of the public route contract, not just that a
    # URL happens to match some unrelated pattern.
    expected_handlers = {
        '/': 'index.php', '/news': 'pages/news.php', '/articles': 'pages/articles.php',
        '/reports': 'pages/reports.php', '/events': 'pages/events.php', '/books': 'pages/books.php',
        '/lessons': 'pages/lessons.php', '/research': 'pages/research.php', '/media': 'pages/media-library.php',
        '/videos': 'pages/media-library.php', '/audios': 'pages/media-library.php', '/topics': 'pages/topics.php',
        '/search': 'pages/search.php', '/about': 'pages/about.php', '/contact': 'pages/contact.php',
        '/qa': 'pages/qa.php', '/login': 'pages/login.php', '/register': 'pages/register.php',
        '/admin/login': 'admin/login.php', '/account': 'pages/account.php',
        '/admin/dashboard': 'admin/dashboard.php', '/admin/profile': 'admin/profile.php',
        '/admin/uploads': 'admin/media/index.php', '/admin/videos': 'admin/videos.php',
        '/admin/audios': 'admin/audios.php', '/admin/courses': 'admin/courses.php',
        '/admin/research': 'admin/research.php',
        '/news/new-school-year': 'pages/post.php', '/article/aql-in-religion': 'pages/post.php',
        '/articles/aql-in-religion': 'pages/post.php', '/report/milad-report': 'pages/post.php',
        '/reports/milad-report': 'pages/post.php', '/post/generic-post': 'pages/post.php',
        '/book/usul-aqaid': 'pages/book.php', '/books/usul-aqaid': 'pages/book.php',
        '/lesson/fiqh-lesson-1': 'pages/lesson.php', '/lessons/fiqh-lesson-1': 'pages/lesson-route.php', '/topic/mahdaviat': 'pages/topic.php',
        '/topics/mahdaviat': 'pages/topic.php', '/topic/quran-hadith/oloum-quran': 'pages/topic.php',
        '/video/1': 'pages/media.php', '/audio/2': 'pages/media.php',
    }

    all_passed = True
    print("\n" + "="*100)
    print(f"{'ROUTE':<35} {'EXPECTED':<10} {'GOT':<10} {'EXPECTED TARGET':<30} {'ACTUAL TARGET'}")
    print("="*80)

    for req, exp in test_cases:
        res, kind = resolve(req)
        got = 200 if res else 404
        file_exists = False
        if res:
            target_file = res['file']
            file_exists = os.path.isfile(target_file)
        handler_str = res['file'] if res else '-'
        expected_handler = expected_handlers.get(req)
        target_matches = expected_handler is None or handler_str == expected_handler
        status = 'PASS' if got == exp and (exp == 404 or file_exists) and target_matches else 'FAIL'
        if status == 'FAIL':
            all_passed = False
        print(f"[{status}] {req:<32} {exp:<10} {got:<10} {(expected_handler or '-'): <30} {handler_str}")

    print("="*80)
    if all_passed:
        print(f"SUCCESS: ALL {len(test_cases)} ROUTE RESOLUTION TESTS PASSED! ALL FILES EXIST!")
        sys.exit(0)
    else:
        print("FAILURE: ONE OR MORE TESTS FAILED!")
        sys.exit(1)
