"""Builds dist/drift-website.zip for a GitHub release.

Entries sit under a drift-website/ top folder with forward slashes, so
WordPress unpacks the update into the right plugin folder on any host.
(PowerShell 5.1's Compress-Archive writes backslashes, which break on Linux.)

Usage, from the plugin folder:  python tools/build-zip.py
"""

import os
import zipfile

SLUG = "drift-website"
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
EXCLUDE_DIRS = {".git", "tests", "tools", "dist", "node_modules", ".idea", ".vscode"}
EXCLUDE_FILES = {".DS_Store", "Thumbs.db"}

out_dir = os.path.join(ROOT, "dist")
os.makedirs(out_dir, exist_ok=True)
out = os.path.join(out_dir, SLUG + ".zip")

count = 0
with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as zf:
    for dirpath, dirnames, filenames in os.walk(ROOT):
        rel_dir = os.path.relpath(dirpath, ROOT)
        # Only prune excluded folders at the top level; lib/ may contain its own "tests" etc.
        if rel_dir == ".":
            dirnames[:] = [d for d in dirnames if d not in EXCLUDE_DIRS]
        dirnames.sort()
        for name in sorted(filenames):
            if name in EXCLUDE_FILES or name.endswith((".log", ".bak")):
                continue
            full = os.path.join(dirpath, name)
            arc = SLUG + "/" + os.path.relpath(full, ROOT).replace(os.sep, "/")
            zf.write(full, arc)
            count += 1

print(f"{out} ({count} files, {os.path.getsize(out) // 1024} KB)")
