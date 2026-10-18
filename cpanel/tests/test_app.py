#!/usr/bin/env python3
"""End-to-end test of Advanced Disk Analyzer against a fake cPanel home folder.

Starts PHP's built-in server with DA_HOME pointing at the fixture and a stand-in
`uapi` command on PATH, then checks sign-in, scanning, sizes (against `du`),
search, duplicates, Trash, CSV export and the Account overview.

Run: python3 tests/test_app.py
"""
import http.cookiejar, json, os, shutil, subprocess, sys, tempfile, time, urllib.error, urllib.parse, urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PORT = 8793
BASE = f"http://127.0.0.1:{PORT}/"
PASSWORD = "test-password-123"
failures = []


def check(cond, what):
    print(("ok   " if cond else "FAIL ") + what)
    if not cond:
        failures.append(what)


def make_home(home):
    os.makedirs(f"{home}/public_html/images/2026")
    os.makedirs(f"{home}/public_html/backup-copy")
    os.makedirs(f"{home}/mail/example.com/info/cur")
    os.makedirs(f"{home}/logs")
    os.makedirs(f"{home}/etc")
    os.makedirs(f"{home}/locked")
    rnd = lambda n: os.urandom(n)
    open(f"{home}/public_html/index.php", "wb").write(b"<?php echo 1;")
    open(f"{home}/public_html/images/2026/photo.jpg", "wb").write(rnd(250_000))
    open(f"{home}/public_html/images/logo.png", "wb").write(rnd(40_000))
    open(f"{home}/public_html/backup-copy/photo.jpg", "wb").write(rnd(250_000))  # duplicate by name + size
    open(f"{home}/public_html/site-backup.zip", "wb").write(rnd(600_000))
    open(f"{home}/mail/example.com/info/cur/1.eml", "wb").write(rnd(30_000))
    open(f"{home}/logs/access.log", "wb").write(b"x" * 9_000)
    open(f"{home}/locked/secret.txt", "wb").write(b"hidden")
    os.link(f"{home}/public_html/site-backup.zip", f"{home}/public_html/hardlink.zip")  # counted once
    os.symlink(f"{home}/public_html", f"{home}/public_html/loop")  # must not be followed
    os.chmod(f"{home}/locked", 0)  # unreadable folder -> counted as an error
    os.makedirs(f"{home}/.diskanalyzer", mode=0o700)
    hashed = subprocess.check_output(["php", "-r", f"echo password_hash('{PASSWORD}', PASSWORD_BCRYPT);"]).decode()
    open(f"{home}/.diskanalyzer/password.hash", "w").write(hashed)


class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.csrf = ""

    def req(self, path, data=None, json_body=None, headers=None):
        h = dict(headers or {})
        body = None
        if json_body is not None:
            body = json.dumps(json_body).encode()
            h.update({"Content-Type": "application/json", "X-CSRF": self.csrf})
        elif data is not None:
            body = urllib.parse.urlencode(data).encode()
        try:
            r = self.op.open(urllib.request.Request(BASE + path, body, h), timeout=60)
            return r.status, r.read().decode()
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode()

    def api(self, action, body=None, **params):
        q = urllib.parse.urlencode({"a": action, **params})
        code, text = self.req("api.php?" + q, json_body=body)
        return code, json.loads(text) if text.startswith(("{", "[")) else text


def main():
    home = tempfile.mkdtemp(prefix="ada-home-")
    make_home(home)
    env = dict(os.environ, DA_HOME=home, PATH=f"{ROOT}/tests/fake-bin:" + os.environ["PATH"])
    server = subprocess.Popen(["php", "-S", f"127.0.0.1:{PORT}", "-t", f"{ROOT}/app"], env=env,
                              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        time.sleep(0.8)
        c = Client()
        code, page = c.req("")
        check("Sign in with your cPanel username" in page, "sign-in page is shown first")
        code, _ = c.api("status")
        check(code == 401, "API refuses requests before sign-in")
        code, page = c.req("", data={"user": "someone-else", "pass": PASSWORD})
        check("Wrong username or password" in page, "wrong username is refused")
        user = subprocess.check_output(["id", "-un"]).decode().strip()
        code, page = c.req("", data={"user": user, "pass": "nope"})
        check("Wrong username or password" in page, "wrong password is refused")
        code, page = c.req("", data={"user": user, "pass": PASSWORD})
        check('"csrf":"' in page and "Advanced Disk Analyzer" in page, "correct password signs in and shows the dashboard")
        c.csrf = page.split('"csrf":"')[1].split('"')[0]

        bad = Client(); bad.jar = c.jar; bad.op = c.op; bad.csrf = "wrong"
        code, _ = bad.api("scan", {"path": home})
        check(code == 403, "POST without the right CSRF token is refused")
        code, r = c.api("scan", {"path": "/etc"})
        check(code == 400 and "inside your account" in r["error"], "scanning outside the account is refused")

        code, r = c.api("scan", {"path": home})
        check(code == 200, "scan starts")
        for _ in range(100):
            code, st = c.api("status")
            if not st["running"]:
                break
            time.sleep(0.2)
        check(st["ready"] and not st["error"], f"scan finishes ({st['files']} files, {st['dirs']} folders)")
        check(st["errors"] == 1, "the unreadable folder is counted as 1 error")

        code, info = c.api("info")
        du = subprocess.run(["du", "-sB1", "--exclude=.diskanalyzer", home], capture_output=True, text=True)
        du_alloc = int(du.stdout.split()[0])  # du also reports the locked folder as unreadable
        # du cannot read the locked folder either, so both skip its contents.
        check(info["root"]["size"] == du_alloc, f"allocated total matches du ({info['root']['size']} vs {du_alloc})")
        apparent = 13 + 250_000 + 40_000 + 250_000 + 600_000 + 30_000 + 9_000 + len(os.readlink(f"{home}/public_html/loop"))
        check(info["root"]["apparent"] == apparent, "apparent size counts the hard link once and does not follow the symlink")
        check(info["disk"]["total"] == 2048 * 1048576 and info["disk"]["inodes_limit"] == 1_000_000, "quota and inodes come from cPanel")
        check(info["types"][0]["ext"] == "zip", "file types are listed, biggest first")

        code, kids = c.api("children", id=0)
        names = [k["name"] for k in kids["children"]]
        check(names[0] == "public_html", f"children are sorted biggest first ({names})")
        pub = kids["children"][0]
        code, node = c.api("node", id=pub["id"])
        check(node["path"] == f"{home}/public_html" and node["chain"] == [0, pub["id"]], "node path and chain")

        code, m = c.api("map", id=0)
        check(m[2] == -1 and len(m[4]) >= 3, "treemap data is nested")

        code, r = c.api("files", q="*.jpg", whole_path="false", max="10")
        check(sorted(f["name"] for f in r["files"]) == ["photo.jpg", "photo.jpg"], "wildcard search *.jpg")
        code, r = c.api("files", q="images/2026", whole_path="true")
        check([f["name"] for f in r["files"]] == ["photo.jpg"], "whole-path search")
        code, r = c.api("files", q="", dups="name_size", dups_only="true")
        check(len(r["files"]) == 2 and all(f["dup_count"] == 1 for f in r["files"]), "duplicates found by name + size")
        code, r = c.api("files", q="", folders="true", max="3")
        check(r["files"][0]["name"] == "public_html", "folder search, biggest first")

        code, r = c.api("trash", {"id": pub["id"]})
        check(code == 400 and "protected" in r["error"], "top-level folders cannot be moved to Trash")
        code, r = c.api("files", q="logo.png")
        logo = r["files"][0]
        code, r = c.api("trash", {"id": logo["id"]})
        check(r.get("ok") and os.path.exists(f"{home}/.trash/logo.png") and not os.path.exists(f"{home}/public_html/images/logo.png"),
              "Move to Trash puts the file in cPanel's ~/.trash")
        check("logo.png=" in open(f"{home}/.trash/.trash_restore").read(), "Trash records where the file came from")

        code, csv = c.req("api.php?a=export")
        check(csv.startswith("Path,Type,Allocated bytes") and csv.count("\r\n") == st["files"] + st["dirs"] + 2, "CSV export has every item")

        code, acct = c.api("account")
        kinds = {g["kind"]: g for g in acct["groups"]}
        check(acct["available"] and len(acct["databases"]) == 2 and acct["databases"][0]["name"] == "kb_shop", "databases from cPanel")
        check(acct["emails"][0]["used"] == 3145728 and acct["emails"][1]["used"] == 524288, "email accounts with usage")
        check({"Website files", "Email", "Logs", "Databases"} <= set(kinds), f"space grouped by kind ({sorted(kinds)})")

        code, page = c.req("lib.php")
        check(code in (200, 403, 404) and "function" not in page or code != 200, "lib.php source is not served (PHP runs it, prints nothing)")
        code, page = c.req("?logout=1")
        code, _ = c.api("status")
        check(code == 401, "sign out ends the session")
    finally:
        server.terminate()
        os.chmod(f"{home}/locked", 0o700)
        shutil.rmtree(home, ignore_errors=True)
    print(f"\n{'ALL PASSED' if not failures else str(len(failures)) + ' FAILED'}")
    sys.exit(1 if failures else 0)


if __name__ == "__main__":
    main()
