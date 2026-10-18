# Advanced Disk Analyzer: installation guide

Advanced Disk Analyzer shows what is using the space in a cPanel account: a folder tree, a
colored map of every file, the largest files, duplicates, databases, email
accounts, disk quota and inodes.

There are two packages. Pick the one that matches your access:

| You have | Use | Where it appears |
|---|---|---|
| A normal cPanel account (shared hosting) | `advanced-disk-analyzer.zip` | `https://your-domain/disk-analyzer/` |
| Root access to the server (VPS/dedicated with WHM) | `advanced-disk-analyzer-plugin.zip` | cPanel → **Tools** → **Files** → **Advanced Disk Analyzer** |

Adding an icon to cPanel's Tools page is only possible with root access. On
shared hosting, use option A (or ask your hosting company to install option B).

Requirements: PHP 8.0 or newer with PDO SQLite (standard on cPanel servers).

---

## A. Install on any cPanel account (no root needed), about 3 minutes

1. Log in to **cPanel**.
2. Open **Files → File Manager** and go into **public_html**.
3. Click **Upload** and upload `advanced-disk-analyzer.zip`.
4. Back in File Manager, right-click `advanced-disk-analyzer.zip` → **Extract** →
   extract into `/public_html`. A folder `disk-analyzer` appears.
5. Delete `advanced-disk-analyzer.zip` (right-click → Delete).
6. Open `https://your-domain/disk-analyzer/` in your browser.
7. Sign in with your **cPanel username and password**. They are checked by
   cPanel on the same server and are never stored by Advanced Disk Analyzer.
8. In **Select**, keep "Whole account (home folder)" and click **Scan**.
   Keep the page open until it says "Scan complete" (seconds for small accounts,
   a few minutes for very large ones).

That's it. Use the tabs:

- **Tree View**: folders with sizes, a color map of all files, file types.
- **File View**: largest files, search (`*.zip`, `backup*`), duplicates.
- **Account**: quota, inodes, what kind of data uses the space, databases,
  email accounts.
- Right-click any item: open it in File Manager, copy its path, or move it to
  cPanel's Trash (asks first; restore from File Manager → **View Trash**).

### If sign-in says it cannot check the password with cPanel

Some servers block connections from PHP to cPanel. Then set a separate
dashboard password:

1. File Manager → **Settings** (top right) → tick **Show Hidden Files**.
2. Open the folder `.diskanalyzer` in your home folder (it is created on first visit).
3. Create a file named `password.hash` containing a bcrypt hash of your chosen
   password. To make one, open **Terminal** in cPanel (if available) and run
   `php -r 'echo password_hash("YOUR-PASSWORD", PASSWORD_BCRYPT);'`, then
   paste the output into the file.
4. Sign in with your cPanel username and that password.

### Update to a new version

Upload and extract the new zip over the old `disk-analyzer` folder. Your scan
data in `~/.diskanalyzer` is kept.

### Uninstall

Delete the folder `public_html/disk-analyzer` and the hidden folder `.diskanalyzer`
in your home folder.

---

## B. Install as a real cPanel plugin (root / WHM), about 2 minutes

1. Copy `advanced-disk-analyzer-plugin.zip` to the server, e.g.
   `scp advanced-disk-analyzer-plugin.zip root@your-server:/root/`
2. Log in as root (SSH, or WHM → **Terminal**) and run:

   ```sh
   cd /root
   unzip advanced-disk-analyzer-plugin.zip
   sh advanced-disk-analyzer-plugin/install-plugin.sh
   ```

3. Log in to any cPanel account on that server. **Advanced Disk Analyzer** now appears on
   the **Tools** page in the **Files** section. Click it; no extra sign-in is
   needed (cPanel already knows the user).
4. To turn it on or off per hosting package: **WHM → Feature Manager** →
   edit a feature list → **Advanced Disk Analyzer**.

Each user only ever sees their own account: the plugin runs as the cPanel
user, exactly like cPanel's own tools.

Uninstall: `sh advanced-disk-analyzer-plugin/uninstall-plugin.sh`

---

## How it works (for the curious)

- The scan reads folder listings and file sizes only, never file contents.
- It runs in short steps while the page is open, so it never hits the host's
  PHP time limits. Results are stored in `~/.diskanalyzer/scan.sqlite`, outside
  `public_html`, so they cannot be downloaded from the web.
- "Allocated" is the space on disk (what the cPanel quota counts); "Size" is
  the file length. Hard links are counted once; symbolic links are not followed.
- Databases and email accounts are read through cPanel's own `uapi` command.
- Sign-in is limited to 5 failed attempts per 15 minutes per IP address.
  Sessions end after 8 hours or when you click **Sign out**.

## Troubleshooting

| Problem | Fix |
|---|---|
| "Page cannot be displayed" right after creating an account | The web server needs a few minutes to activate a new account. Try again later. |
| Account tab says cPanel information is not available | The host does not allow PHP to run `uapi`. File sizes still work. |
| Scan seems stuck | Keep the page open; scanning only progresses while it is open. Reload the page if needed: the scan continues where it stopped. |
| "Too many failed attempts" | Wait 15 minutes. |
