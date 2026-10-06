# EtelID live-vs-repo fingerprint

Why: the live EtelID plugin turned out to be older than the repo's `EtelId/`
(it lacks "Upgrade 9", limited accounts), so deploying newer files can crash.
This finds exactly which live files differ before anything else is deployed.

1. A one-time `fp-xxxxxxxx.php` is generated for you (secret link, 6 h expiry,
   self-deleting after one run, prints no file contents, only checksums).
2. Upload it next to `wp-config.php` (`public_html/EtelId/`), open its link,
   copy the whole text output.
3. Save it as `fingerprint.txt` and run:
   `python3 tools/etelid-fingerprint/compare_fingerprint.py fingerprint.txt`
4. If the tool is still on the server afterwards, delete it by hand.
