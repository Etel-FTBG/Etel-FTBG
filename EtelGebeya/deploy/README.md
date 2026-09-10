# Deploying EtelGebeya

## Right now: manual, per-file

This host is shared cPanel hosting with no SSH access, so there's no
automated deploy pipeline yet. Changes go live by uploading the
specific changed file(s) through the in-app **File Updater**
(admin panel -> System -> File Updater), which auto-backs-up the
original before replacing it.

**The one rule that matters most:** never include `.env` in an
upload. `.env` holds the live database password and service keys;
uploading this repo's `.env` (or any zip that contains it) overwrites
the real live secrets with whatever placeholder values happen to be
in the working copy. This has already caused a real outage once.
`.env` is now gitignored specifically so this repo can never
accidentally ship it again -- `.env.example` is the template that
*is* safe to commit and share.

Before uploading anything, run:

```
EtelGebeya/deploy/preflight.sh <file-or-dir> [<file-or-dir> ...]
```

It refuses to say "OK" if a `.env` file is in the set, or if any
`.php` file has a syntax error. Treat a non-zero exit as "do not
upload."

## Later: automated, once shell access exists

If this hosting account ever gets SSH access, or cPanel's own **Git
Version Control** feature gets set up for this site (many cPanel
accounts support this without needing a terminal -- it deploys on
push via a server-side hook), `deploy/deploy.sh` replaces the manual
Updater workflow:

```
EtelGebeya/deploy/deploy.sh [branch]   # defaults to main
```

It pulls the target branch, and refuses to leave a broken deploy live
by preflight-checking both the working tree before pulling and the
files that actually changed after pulling. It never touches `.env` --
that stays a manually-managed file on the server, exactly as today,
just no longer at risk from `git pull` because it's gitignored.
