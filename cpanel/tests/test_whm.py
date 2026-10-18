#!/usr/bin/env python3
"""End-to-end test of the WHM admin dashboard and the hourly alert job.

Uses a stand-in whmapi1 (tests/fake-bin/whmapi1) with demo accounts and a fake
sendmail that appends each email to a file.

Run: python3 tests/test_whm.py
"""
import json, os, shutil, subprocess, sys, tempfile, time, urllib.error, urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PORT = 8799
BASE = f"http://127.0.0.1:{PORT}/index.php"
failures = []
GB = 1024 * 1024  # in KB


def check(cond, what):
    print(("ok   " if cond else "FAIL ") + what)
    if not cond:
        failures.append(what)


def demo_accounts():
    return [
        {"user": "shopnow", "domain": "shopnow.example", "plan": "Business", "used_kb": 19 * GB, "limit_kb": 20 * GB, "inodes": 410_000, "inode_limit": 500_000, "email": "owner@shopnow.example"},
        {"user": "blogly", "domain": "blogly.example", "plan": "Starter", "used_kb": int(4.2 * GB), "limit_kb": 5 * GB, "inodes": 90_000, "inode_limit": 250_000, "email": "me@blogly.example"},
        {"user": "photos", "domain": "photos.example", "plan": "Unlimited", "used_kb": 48 * GB, "limit_kb": 0, "inodes": 820_000},
        {"user": "tiny", "domain": "tiny.example", "plan": "Starter", "used_kb": 300 * 1024, "limit_kb": 5 * GB, "inodes": 2_100, "inode_limit": 250_000},
        {"user": "oldsite", "domain": "oldsite.example", "plan": "Starter", "used_kb": 2 * GB, "limit_kb": 5 * GB, "inodes": 30_000, "inode_limit": 250_000, "suspended": 1},
        {"user": "agency", "domain": "agency.example", "owner": "reseller1", "plan": "reseller1_Pro", "used_kb": 9 * GB, "limit_kb": 10 * GB, "inodes": 120_000, "inode_limit": 500_000},
    ]


def call(action, body=None, token=None, headers=None):
    h = dict(headers or {})
    data = None
    if body is not None:
        data = json.dumps(body).encode()
        h.update({"Content-Type": "application/json", "X-CSRF": token or ""})
    try:
        r = urllib.request.urlopen(urllib.request.Request(f"{BASE}?a={action}", data, h), timeout=60)
        text = r.read().decode()
        return r.status, (json.loads(text) if text[:1] in "{[" else text)
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read().decode())


def main():
    tmp = tempfile.mkdtemp(prefix="ada-whm-")
    accounts_file, mail_file = f"{tmp}/accounts.json", f"{tmp}/mail.txt"
    accounts = demo_accounts()
    json.dump(accounts, open(accounts_file, "w"))
    env = dict(os.environ, ADA_DATA=f"{tmp}/data", ADA_WHMAPI=f"{ROOT}/tests/fake-bin/whmapi1",
               ADA_SENDMAIL=f"cat >> {mail_file}", FAKE_WHM_ACCOUNTS=accounts_file, ADA_WWWACCT=f"{tmp}/wwwacct.conf")
    open(f"{tmp}/wwwacct.conf", "w").write("ADDR 192.0.2.10\nCONTACTEMAIL hostmaster@example.net\nNS ns1.example.net\n")
    server = subprocess.Popen(["php", "-S", f"127.0.0.1:{PORT}", "-t", f"{ROOT}/whm", f"{ROOT}/tests/whm_router.php"], env=env,
                              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        time.sleep(0.8)
        page = urllib.request.urlopen(BASE, timeout=30).read().decode()
        token = page.split("const TOKEN = ")[1].split(";")[0].strip('"')
        check("Advanced Disk Analyzer" in page and len(token) == 32, "dashboard page loads with its CSRF token")

        code, d = call("data")
        by = {a["user"]: a for a in d["accounts"]}
        check(code == 200 and len(d["accounts"]) == 6, "all accounts are listed")
        check(by["shopnow"]["pct"] == 95.0 and by["shopnow"]["ipct"] == 82.0, "disk % and inode % are computed")
        check(by["photos"]["limit"] is None and by["photos"]["pct"] is None, "unlimited accounts have no %")
        check(by["oldsite"]["suspended"] and by["agency"]["owner"] == "reseller1", "suspended flag and reseller owner")
        check(by["shopnow"]["used"] == 19 * GB * 1024, "usage comes from get_disk_usage in bytes")
        check(d["settings"]["thresholds"] == [80, 90] and d["settings"]["contact"] == "hostmaster@example.net", "default settings; server contact read from wwwacct.conf")
        check("token" not in d["settings"], "the CSRF token is not sent in the data")

        code, r = call("settings", {"alerts": True, "thresholds": ["90", "80"], "admin_emails": "ops@example.net", "notify_owner": True}, token="wrong")
        check(code == 403, "settings refuse a wrong CSRF token")
        code, r = call("settings", {"alerts": True, "thresholds": [], "admin_emails": ""}, token=token)
        check(code == 400, "settings need at least one alert level")
        code, r = call("settings", {"alerts": True, "thresholds": [80], "admin_emails": "not-an-email"}, token=token)
        check(code == 400 and "not a valid email" in r["error"], "invalid email is refused")
        code, r = call("settings", {"alerts": True, "thresholds": ["90", "80"], "admin_emails": "ops@example.net", "notify_owner": True}, token=token)
        check(code == 200 and r["settings"]["thresholds"] == [80, 90], "settings are saved (levels sorted)")

        code, r = call("check", {}, token=token)
        mail = open(mail_file).read() if os.path.exists(mail_file) else ""
        # shopnow: disk 95% (level 90) + inodes 82% (level 80); blogly 84%; agency 90%
        check(r["new"] == 4, f"first check finds 4 new alerts ({r['new']})")
        check("To: ops@example.net" in mail and "shopnow" in mail and "agency" in mail and "tiny" not in mail, "admin digest lists the right accounts")
        check("To: owner@shopnow.example" in mail and "To: me@blogly.example" in mail, "account owners are emailed")
        check("photos" not in mail.split("Subject")[1] if "Subject" in mail else False, "unlimited accounts never alert")

        open(mail_file, "w").close()
        code, r = call("check", {}, token=token)
        check(r["new"] == 0 and open(mail_file).read() == "", "the same level is not reported twice")

        accounts[1]["used_kb"] = 1 * GB  # blogly drops to 20%
        json.dump(accounts, open(accounts_file, "w"))
        call("check", {}, token=token)
        accounts[1]["used_kb"] = int(4.1 * GB)  # and rises again to 82%
        json.dump(accounts, open(accounts_file, "w"))
        code, r = call("check", {}, token=token)
        check(r["new"] == 1 and "blogly" in open(mail_file).read(), "an alert repeats after usage dropped below the level")

        # growth: seed a snapshot from 35 days ago
        hist_file = f"{tmp}/data/history.json"
        hist = json.load(open(hist_file))
        old_day = time.strftime("%Y-%m-%d", time.gmtime(time.time() - 35 * 86400))
        hist[old_day] = {"total": 0, "users": {"shopnow": 15 * GB * 1024, "tiny": 400 * 1024 * 1024}}
        json.dump(hist, open(hist_file, "w"))
        code, d = call("data")
        by = {a["user"]: a for a in d["accounts"]}
        check(by["shopnow"]["growth"] == 4 * GB * 1024 and by["tiny"]["growth"] == -100 * 1024 * 1024, "30-day growth from the snapshot history")
        check(by["photos"]["growth"] is None and len(d["history"]) == 2, "no growth without an old snapshot; history has 2 days")

        code, csv = call("csv")
        check(csv.startswith("User,Domain") and csv.count("\r\n") == 7 and '"photos","photos.example"' in csv, "CSV export of all accounts")

        out = subprocess.run(["php", f"{ROOT}/whm/cron.php"], env=env, capture_output=True, text=True)
        check(out.returncode == 0 and "checked 6 accounts" in out.stdout, f"hourly cron job runs ({out.stdout.strip()})")

        code, _ = call("nope")
        check(code == 404, "unknown actions are refused")
    finally:
        server.terminate()
        shutil.rmtree(tmp, ignore_errors=True)
    print(f"\n{'ALL PASSED' if not failures else str(len(failures)) + ' FAILED'}")
    sys.exit(1 if failures else 0)


if __name__ == "__main__":
    main()
