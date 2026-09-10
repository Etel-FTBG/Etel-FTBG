#!/usr/bin/env bash
# Preflight check -- run this before uploading ANY file(s) to the live
# server, whether via the in-app File Updater or a future automated
# deploy. Catches the two mistakes that have actually caused outages:
#
#   1. A real secrets file (.env) accidentally included in the upload,
#      which overwrites the live server's real credentials with
#      whatever this repo's working copy currently has (this exact
#      thing happened once already -- see git log).
#   2. A PHP syntax error that would only be discovered by the site
#      going down in front of real users.
#
# Usage:
#   deploy/preflight.sh <file-or-dir> [<file-or-dir> ...]
#
# Exits non-zero (and refuses to say "OK") if anything is wrong.

set -u
FAIL=0

if [ "$#" -eq 0 ]; then
  echo "Usage: $0 <file-or-dir> [<file-or-dir> ...]" >&2
  exit 2
fi

echo "== Preflight: secrets check =="
for target in "$@"; do
  if [ -d "$target" ]; then
    found=$(find "$target" -name '.env' -not -name '.env.example')
  elif [ "$(basename -- "$target")" = ".env" ]; then
    found="$target"
  else
    found=""
  fi
  if [ -n "$found" ]; then
    echo "REFUSING TO DEPLOY: a real .env file is in this upload set:"
    echo "$found" | sed 's/^/  /'
    echo ".env belongs on the live server only -- never in a deploy payload."
    FAIL=1
  fi
done
[ "$FAIL" -eq 0 ] && echo "OK -- no .env file in the deploy set."

echo
echo "== Preflight: PHP syntax check =="
php_files=()
for target in "$@"; do
  if [ -d "$target" ]; then
    while IFS= read -r -d '' f; do php_files+=("$f"); done < <(find "$target" -name '*.php' -print0)
  elif [[ "$target" == *.php ]]; then
    php_files+=("$target")
  fi
done

if [ "${#php_files[@]}" -eq 0 ]; then
  echo "(no .php files in this upload set)"
else
  for f in "${php_files[@]}"; do
    if ! php -l "$f" > /tmp/preflight_lint.$$ 2>&1; then
      echo "SYNTAX ERROR: $f"
      sed 's/^/  /' /tmp/preflight_lint.$$
      FAIL=1
    fi
  done
  rm -f /tmp/preflight_lint.$$
  [ "$FAIL" -eq 0 ] && echo "OK -- ${#php_files[@]} PHP file(s), no syntax errors."
fi

echo
if [ "$FAIL" -ne 0 ]; then
  echo "PREFLIGHT FAILED -- do not upload until the issues above are fixed."
  exit 1
fi
echo "PREFLIGHT PASSED -- safe to deploy."
