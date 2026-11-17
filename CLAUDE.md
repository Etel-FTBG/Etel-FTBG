# Etel Group repository: start here

Read `.claude/skills/etel-working-method/SKILL.md` first. It explains how Dagi (they/them) and Claude work on every Etel system: plain short answers, one draft pull request per change, tests before the PR, a zip of only the changed files after each merge, and the standing rules. Then read the memory skill for the system you work on:

- `.claude/skills/etel-investors-memory/SKILL.md`: investors, launch plan, branding, EtelPay and EtelTaxi looks
- `.claude/skills/etel-delivery-ride-memory/SKILL.md`: Delivery and Ride

Core rules in one line each: develop on `claude/admiring-fermi-8pcqlx` from `origin/main`; open DRAFT PRs and subscribe with the number returned; PHP is ASCII-only, files under about 30KB; never commit `config.php`, `.env` or any secret; bump `?v=` and service-worker caches when a file changes; say honestly what was not tested.
