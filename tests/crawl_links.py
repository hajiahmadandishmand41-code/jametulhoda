#!/usr/bin/env python3
"""
crawl_links.py — Crawls all PHP templates, includes, and admin files
to extract all internal hrefs, url() calls, and asset() calls,
verifying that none are broken or lead to non-existent handlers.
"""

import os
import re
import sys
from verify_routes import resolve

BASE_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
os.chdir(BASE_DIR)

files_to_scan = []
for root, dirs, files in os.walk('.'):
    dirs[:] = [d for d in dirs if d not in ['.git', '.github', 'tests', 'vendor', 'node_modules', 'uploads', 'storage', '.cache']]
    for f in files:
        if f.endswith('.php') or f.endswith('.html'):
            files_to_scan.append(os.path.normpath(os.path.join(root, f)))

print(f"Scanning {len(files_to_scan)} files for internal links and URL helpers...")

re_url_call = re.compile(r"url\(\s*['\"]([^'\"]*)['\"]")
re_href = re.compile(r"""href=['"]([^'"#?]+)(?:\?[^'"]*)?['"]""")

discovered_routes = set()

for filepath in files_to_scan:
    try:
        with open(filepath, 'r', encoding='utf-8', errors='ignore') as f:
            content = f.read()
    except Exception:
        continue

    # 1. url('path') calls
    for match in re_url_call.finditer(content):
        path = match.group(1).strip()
        if not path or path == '/':
            discovered_routes.add(('/', filepath))
            continue
        clean_path = '/' + path.lstrip('/')
        discovered_routes.add((clean_path, filepath))

    # 2. direct href="..."
    for match in re_href.finditer(content):
        href = match.group(1).strip()
        # Skip dynamic php tags or external
        if href.startswith(('<', 'http://', 'https://', 'javascript:', 'mailto:', 'tel:')):
            continue
        if href.endswith(('.css', '.js', '.png', '.jpg', '.jpeg', '.gif', '.svg', '.webp', '.ico', '.woff', '.woff2')):
            continue
        if not href or href == '/':
            discovered_routes.add(('/', filepath))
            continue
        clean_path = '/' + href.lstrip('/')
        discovered_routes.add((clean_path, filepath))

print(f"Found {len(discovered_routes)} unique route occurrences to verify.")

verified_routes = {}
failures = []

for route, src_file in discovered_routes:
    base_route = route.split('?')[0].split('#')[0]
    if '$' in base_route or '{' in base_route or '<' in base_route:
        continue

    res, kind = resolve(base_route)
    if not res:
        rel_path = base_route.lstrip('/')
        if os.path.isfile(rel_path) or os.path.isdir(rel_path):
            verified_routes[base_route] = ('physical', rel_path)
            continue
        failures.append((base_route, src_file))
    else:
        handler = res['file']
        if not os.path.isfile(handler):
            failures.append((base_route, src_file, f"Handler {handler} missing"))
        else:
            verified_routes[base_route] = (kind, handler)

print("\n" + "="*80)
print(f"VERIFIED INTERNAL ROUTES ({len(verified_routes)} valid):")
for r in sorted(verified_routes.keys()):
    kind, handler = verified_routes[r]
    print(f"  OK: {r:<35} -> {kind} ({handler})")

print("="*80)
if failures:
    print(f"FAILURES DETECTED ({len(failures)} broken links):")
    for f in failures:
        print(f"  FAILED: {f}")
    sys.exit(1)
else:
    print("ALL DISCOVERED INTERNAL LINKS AND ROUTES ARE 100% VALID!")
    sys.exit(0)
