#!/usr/bin/env python3
"""
Builds the API documentation for the ETEL Delivery & Ride backend straight from the code, so it cannot drift:
  docs/api/openapi.json    OpenAPI 3.0.3 (import into Postman, Insomnia, Burp, Swagger UI)
  docs/api/API_REFERENCE.md  one table row per action
Every action is a POST (or GET) to /api/boot.php with {"action": "<name>", ...}. The parameters are read from the handler
($body['x'], $_GET['x']); the auth rule from the require_* call inside it. Re-run after adding actions:  python3 tools/gen_api_docs.py
"""
import json
import os
import re
import sys

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..")
API = os.path.join(ROOT, "api")
OUT = os.path.join(ROOT, "docs", "api")

CASE_RE = re.compile(r"^[ \t]{0,4}case\s+'([A-Za-z0-9_]+)'\s*:\s*\{?\s*(?://.*)?$", re.M)
BODY_RE = re.compile(r"\$body\[\s*'([A-Za-z0-9_]+)'\s*\]")
GET_RE = re.compile(r"\$_GET\[\s*'([A-Za-z0-9_]+)'\s*\]")
ISSET_BODY_RE = re.compile(r"isset\(\s*\$body\[\s*'([A-Za-z0-9_]+)'\s*\]")
AUTH_RULES = [
    (re.compile(r"require_admin\(\s*\$pdo\s*(?:,\s*'([a-z_.]+)')?"), "admin"),
    (re.compile(r"require_capability\(\s*\$pdo\s*,\s*'([a-z_.]+)'"), "admin"),
    (re.compile(r"require_courier\("), "courier"),
    (re.compile(r"require_driver\("), "driver"),
    (re.compile(r"require_service_giver\(|require_sg\("), "service_giver"),
    (re.compile(r"require_customer\("), "customer"),
    (re.compile(r"require_auth\("), "any signed-in user"),
]
SKIP = {"default"}
# actions that sign a user in (the code checks credentials, not a token) are public
PUBLIC_RX = re.compile(r"login|register|otp|forgot|reset")
# order creation reads its fields in a shared helper
LIB_PARAMS = {"customer_create_order": "lib/delivery_order_core.php"}


def controller_files():
    files = [os.path.join(API, "boot.php")]
    cdir = os.path.join(API, "controllers")
    files += [os.path.join(cdir, f) for f in sorted(os.listdir(cdir)) if f.endswith(".php")]
    return files


def parse_file(path):
    text = open(path, encoding="utf-8", errors="replace").read()
    marks = [(m.start(), m.group(1)) for m in CASE_RE.finditer(text)]
    out = {}
    pending = []
    for i, (pos, name) in enumerate(marks):
        end = marks[i + 1][0] if i + 1 < len(marks) else len(text)
        block = text[pos:end]
        if name in SKIP:
            continue
        if "break;" not in block and "ok(" not in block and "fail(" not in block:
            pending.append(name)
            continue
        # a routing line (case 'a': case 'b': require ...) has no handler logic of its own
        if not re.search(r"\$body|\$_GET|require_|ok\(|fail\(|\$pdo", block):
            pending.append(name)
            continue
        params = set(BODY_RE.findall(block)) | set(ISSET_BODY_RE.findall(block))
        query = set(GET_RE.findall(block))
        auth, cap = "public", None
        for rx, who in AUTH_RULES:
            m = rx.search(block)
            if m:
                auth = who
                cap = m.group(1) if m.groups() and m.group(1) else None
                break
        if PUBLIC_RX.search(name):
            auth, cap = "public (this call signs you in)", None
        if name in LIB_PARAMS:
            lt = open(os.path.join(API, LIB_PARAMS[name]), encoding="utf-8", errors="replace").read()
            params |= set(BODY_RE.findall(lt))
        for alias in pending + [name]:
            out[alias] = {"file": os.path.relpath(path, ROOT).replace("\\", "/"), "body": sorted(params - {"action"}),
                     "query": sorted(query), "auth": auth, "capability": cap}
        pending = []
    return out


def main():
    actions = {}
    for f in controller_files():
        for name, info in parse_file(f).items():
            if name in actions and actions[name]["auth"] != "public":
                continue
            actions[name] = info
    names = sorted(actions)
    if not names:
        print("no actions found")
        sys.exit(1)

    tags = {}
    for n in names:
        tags.setdefault(actions[n]["file"].split("/")[-1].replace("Controller.php", "").replace(".php", ""), []).append(n)

    schemas = {}
    one_of = []
    examples = {}
    for n in names:
        a = actions[n]
        props = {"action": {"type": "string", "enum": [n]}}
        for p in a["body"]:
            props[p] = {"description": "request field (type not declared in code)"}
        title = "action: " + n
        schemas[n] = {"type": "object", "title": title, "required": ["action"], "properties": props,
                      "description": ("Auth: %s%s. Source: %s." % (a["auth"], (" (capability %s)" % a["capability"]) if a["capability"] else "", a["file"]))}
        one_of.append({"$ref": "#/components/schemas/" + n})
        ex = {"action": n}
        for p in a["body"][:6]:
            ex[p] = "<" + p + ">"
        examples[n] = {"summary": n + " (" + a["auth"] + ")", "value": ex}

    doc = {
        "openapi": "3.0.3",
        "info": {
            "title": "ETEL Delivery & Ride API",
            "version": "generated",
            "description": ("Every action is a JSON POST to /api/boot.php with an `action` field (a few read-only actions also accept GET query parameters). "
                            "Responses are JSON: {success: true|false, data: {...}, message: '...', v: '<app version>'}. "
                            "Send the session token as `Authorization: Bearer <token>`; get one from `login`, `etelid_login` (Delivery/Ride apps) or `admin_etelid_login` (admin). "
                            "Admin accounts with two-step sign-in answer {requires_2fa: true}: repeat the same login request with `totp_code`. "
                            "Errors use HTTP 400/401/403/404/409/429/500/503 with the same JSON shape. Throttled actions answer 429. "
                            "This file is generated from the source by tools/gen_api_docs.py: %d actions. Parameter names come from the handlers; types and limits are in the handler code." % len(names)),
        },
        "servers": [{"url": "https://ethelmarket.com/delivery", "description": "production"}],
        "paths": {
            "/api/boot.php": {
                "post": {
                    "summary": "Call any action",
                    "operationId": "callAction",
                    "tags": ["actions"],
                    "security": [{}, {"bearerAuth": []}],
                    "requestBody": {"required": True, "content": {"application/json": {
                        "schema": {"oneOf": one_of, "discriminator": {"propertyName": "action"}},
                        "examples": examples}}},
                    "responses": {
                        "200": {"description": "{success:true, data:{...}}", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/Envelope"}}}},
                        "400": {"description": "validation error"}, "401": {"description": "missing/expired token"},
                        "403": {"description": "wrong role or missing capability"}, "429": {"description": "rate limited"},
                        "503": {"description": "a dependency (for example EtelID) did not answer"},
                    },
                }
            }
        },
        "components": {
            "securitySchemes": {"bearerAuth": {"type": "http", "scheme": "bearer", "description": "token from login / etelid_login / admin_etelid_login"}},
            "schemas": dict(schemas, Envelope={"type": "object", "properties": {"success": {"type": "boolean"}, "data": {"type": "object"}, "message": {"type": "string"}, "v": {"type": "string"}}}),
        },
        "x-actions": {n: {"auth": actions[n]["auth"], "capability": actions[n]["capability"], "body": actions[n]["body"], "query": actions[n]["query"], "source": actions[n]["file"]} for n in names},
    }
    os.makedirs(OUT, exist_ok=True)
    with open(os.path.join(OUT, "openapi.json"), "w", encoding="utf-8") as f:
        json.dump(doc, f, indent=1, ensure_ascii=False)
        f.write("\n")

    lines = ["# ETEL Delivery & Ride API reference", "",
             "Generated from the source by `tools/gen_api_docs.py` (%d actions). Machine-readable: `openapi.json`." % len(names), "",
             "**Call:** `POST /delivery/api/boot.php` with JSON `{\"action\": \"<name>\", ...fields}`; header `Authorization: Bearer <token>` for everything except the *public* actions.",
             "**Reply:** `{success, data, message, v}`. **Login:** `login` / `etelid_login` (apps) or `admin_etelid_login` (admin; add `totp_code` when the reply says `requires_2fa`).", ""]
    by_auth = {}
    for n in names:
        by_auth.setdefault(actions[n]["auth"], []).append(n)
    lines.append("| Who can call | Actions |")
    lines.append("|---|---|")
    for k in sorted(by_auth):
        lines.append("| %s | %d |" % (k, len(by_auth[k])))
    lines.append("")
    for tag in sorted(tags):
        lines.append("## " + tag)
        lines.append("")
        lines.append("| Action | Auth | Fields | Query |")
        lines.append("|---|---|---|---|")
        for n in sorted(tags[tag]):
            a = actions[n]
            auth = a["auth"] + ((" · " + a["capability"]) if a["capability"] else "")
            lines.append("| `%s` | %s | %s | %s |" % (n, auth, ", ".join("`%s`" % p for p in a["body"]) or "-", ", ".join("`%s`" % p for p in a["query"]) or "-"))
        lines.append("")
    with open(os.path.join(OUT, "API_REFERENCE.md"), "w", encoding="utf-8") as f:
        f.write("\n".join(lines) + "\n")
    print("%d actions; auth mix: %s" % (len(names), {k: len(v) for k, v in by_auth.items()}))


if __name__ == "__main__":
    main()
