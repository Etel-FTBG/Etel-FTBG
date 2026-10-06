#!/usr/bin/env python3
"""
Compare the output of the one-time EtelID fingerprint tool (live server) with
this repository's EtelId/ folder.

    python3 tools/etelid-fingerprint/compare_fingerprint.py fingerprint.txt

Reports, per file: SAME / DIFFERENT (older or newer than the repo) /
MISSING ON SERVER / EXTRA ON SERVER, and flags files the live site should not
have (backups, dumps, stray copies). Line endings (CRLF vs LF) are ignored.
"""
import hashlib
import os
import re
import subprocess
import sys

REPO = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
ETELID = os.path.join(REPO, "EtelId")
# Files that only exist in the repo (docs/tests), never expected on the server.
REPO_ONLY = ("tests/", "SECURITY_WEEK1.md", "BRANDING.md", "README.md", "SETUP-SSO-KEY.txt")
RISKY = re.compile(r"\.(sql|zip|gz|tar|bak|old|orig|swp|log)$|(^|/)error_log$|\.env|phpinfo|backup|dump", re.I)


def md5s(path):
    data = open(path, "rb").read()
    return hashlib.md5(data).hexdigest(), hashlib.md5(data.replace(b"\r\n", b"\n")).hexdigest()


def parse(text):
    server, header, root = {}, [], []
    section = "plugin"
    for line in text.splitlines():
        if line.startswith("# ---"):
            section = "root" if "SITE ROOT" in line else ("other" if "---" in line and "PLUGIN FILES" not in line else "plugin")
            continue
        if line.startswith("#"):
            header.append(line)
            continue
        if not line.strip():
            continue
        if section == "plugin":
            parts = line.split(" ", 4)
            if len(parts) == 5:
                server[parts[4]] = dict(raw=parts[0], norm=parts[1], size=int(parts[2]) if parts[2].isdigit() else 0, mtime=parts[3])
        elif section == "root":
            root.append(line)
    return server, header, root


def repo_files():
    out = {}
    for base, _, files in os.walk(ETELID):
        for f in files:
            full = os.path.join(base, f)
            rel = os.path.relpath(full, ETELID).replace(os.sep, "/")
            out[rel] = md5s(full)
    return out


def changed_since_import():
    try:
        r = subprocess.run(["git", "-C", REPO, "log", "--format=%H", "--", "EtelId"], capture_output=True, text=True)
        commits = r.stdout.split()
        if len(commits) < 2:
            return set()
        d = subprocess.run(["git", "-C", REPO, "diff", "--name-only", commits[-1], "HEAD", "--", "EtelId"],
                           capture_output=True, text=True)
        return {l[len("EtelId/"):] for l in d.stdout.split("\n") if l.startswith("EtelId/")}
    except Exception:
        return set()


def main():
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    server, header, root = parse(open(sys.argv[1], encoding="utf-8", errors="replace").read())
    if not server:
        sys.exit("No plugin file lines found - paste the whole output of the tool.")
    repo = repo_files()
    mine = changed_since_import()
    for h in header:
        if any(k in h for k in ("php=", "opcache", "plugin_header_version", "fingerprint")):
            print(h)
    print()

    same, diff, missing, extra = [], [], [], []
    for rel, (raw, norm) in sorted(repo.items()):
        if rel.startswith(REPO_ONLY) or rel in REPO_ONLY:
            continue
        s = server.get(rel)
        if s is None:
            missing.append(rel)
        elif s["norm"] == norm or s["raw"] == raw:
            same.append(rel)
        else:
            diff.append((rel, s))
    for rel in sorted(server):
        if rel not in repo:
            extra.append((rel, server[rel]))

    print("SAME as repo: %d files" % len(same))
    print("\nDIFFERENT from repo (%d) - live version is OLDER/NEWER than the repo copy:" % len(diff))
    for rel, s in diff:
        tag = "  (I shipped this one)" if rel in mine else "  (NOT shipped by me - live is its own version)"
        print("  %-52s server mtime %s size %d vs repo %d%s" % (rel, s["mtime"], s["size"], os.path.getsize(os.path.join(ETELID, rel)), tag))
    print("\nMISSING ON SERVER (%d) - in repo, not live (features the live site does not have):" % len(missing))
    for rel in missing:
        print("  " + rel)
    print("\nEXTRA ON SERVER (%d) - live has it, repo does not:" % len(extra))
    for rel, s in extra:
        flag = "   <-- looks risky (backup/dump/log)" if RISKY.search(rel) else ""
        print("  %-52s mtime %s size %d%s" % (rel, s["mtime"], s["size"], flag))
    flagged = [l for l in root if "RISKY?" in l or "STRAY-COPY?" in l]
    print("\nSITE ROOT items to review (%d):" % len(flagged))
    for l in flagged:
        print("  " + l)
    print("\nSHIPPED BY ME BUT STILL OLD ON SERVER:" )
    stale = [rel for rel in mine if rel in server and rel in repo and server[rel]["norm"] != repo[rel][1] and server[rel]["raw"] != repo[rel][0]]
    print("  " + (", ".join(stale) if stale else "none"))


if __name__ == "__main__":
    main()
