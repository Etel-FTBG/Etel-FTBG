"""Menu catalog keeps its photo upload (file picker, not a typed web address) and its photo-coverage board."""
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
src = open(os.path.join(HERE, "..", "..", "js", "admin-menucat-pro.js")).read()
bad = []
for needle, why in [("data-upload", "dish form upload button"), ("uploadPhoto(", "upload helper"), ("data-addpic", "add-photo button on cards"),
                    ("Photo coverage", "coverage bar"), ("nopic", "no-photo filter")]:
    if needle not in src:
        bad.append(why)
if "placeholder=\"https://" in src:
    bad.append("dish form asks for a typed photo address again")
if bad:
    print("FAIL: " + ", ".join(bad))
    sys.exit(1)
print("OK   menu catalog photo upload and coverage")
