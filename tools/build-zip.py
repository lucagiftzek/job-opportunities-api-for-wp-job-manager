#!/usr/bin/env python3
"""Build the release zip: dist/job-opportunities-api-for-wp-job-manager-<version>.zip.

The zip is built from the COMMITTED plugin folder (git archive HEAD), never from the working tree, and is
deterministic. Directories are 0755 and files 0644; every entry is dated at the commit time; the entry order is fixed
(sorted, each directory before its contents). Two builds of one commit are byte-identical.

It refuses to build when the plugin folder has uncommitted changes, or when the three version strings (the plugin
header Version, JOAWPJM_VERSION and readme.txt Stable tag) disagree.

Why: the 1.0.3 zip was first written with Python's zipfile defaults, so its entries were 0600 files, directories
without the execute bit, and dates of 1980-01-01. A site that unzips the file by hand and uploads the folder would get
a plugin the web server cannot read.
"""
import hashlib
import io
import os
import re
import subprocess
import sys
import tarfile
import time
import zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SLUG = "job-opportunities-api-for-wp-job-manager"


def git(*args):
    return subprocess.check_output(["git", "-C", ROOT, *args])


def main():
    if git("status", "--porcelain", "--", SLUG).strip():
        sys.exit("refused: the plugin folder has uncommitted changes; commit first")
    header = git("show", f"HEAD:{SLUG}/{SLUG}.php").decode()
    readme = git("show", f"HEAD:{SLUG}/readme.txt").decode()
    v_header = re.search(r"^\s*\*\s*Version:\s*(\S+)", header, re.M).group(1)
    v_const = re.search(r"define\(\s*'JOAWPJM_VERSION',\s*'([^']+)'", header).group(1)
    v_stable = re.search(r"^Stable tag:\s*(\S+)", readme, re.M).group(1)
    if not (v_header == v_const == v_stable):
        sys.exit(f"refused: version mismatch (header {v_header}, JOAWPJM_VERSION {v_const}, Stable tag {v_stable})")
    when = time.gmtime(int(git("log", "-1", "--format=%ct").strip()))[:6]

    tar = tarfile.open(fileobj=io.BytesIO(git("archive", "--format=tar", "HEAD", SLUG)))
    entries = []
    for m in tar.getmembers():
        if m.isdir():
            entries.append((m.name.rstrip("/") + "/", None))
        elif m.isfile():
            entries.append((m.name, tar.extractfile(m).read()))
    entries.sort(key=lambda e: e[0])

    os.makedirs(os.path.join(ROOT, "dist"), exist_ok=True)
    out = os.path.join(ROOT, "dist", f"{SLUG}-{v_header}.zip")
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w") as z:
        for name, data in entries:
            info = zipfile.ZipInfo(name, date_time=when)
            info.create_system = 3  # unix, so the mode bits below are honoured
            if data is None:
                info.external_attr = (0o040755 << 16) | 0x10
                info.compress_type = zipfile.ZIP_STORED
                z.writestr(info, b"")
            else:
                info.external_attr = 0o100644 << 16
                info.compress_type = zipfile.ZIP_DEFLATED
                z.writestr(info, data, compresslevel=9)
    with open(out, "wb") as f:
        f.write(buf.getvalue())
    print(f"{os.path.relpath(out, ROOT)}  {len(entries)} entries  sha256 {hashlib.sha256(buf.getvalue()).hexdigest()}")


if __name__ == "__main__":
    main()
