# GitDeploy for Webuzo

**Powered by [Digitalbkk Co., Ltd.](https://www.digitalbkk.com) — developed by the Digitalbkk team. Author: Hasif Ahmed <hasif5@gmail.com>**

Push to deploy static sites from GitHub to your Webuzo domains. No Docker, no
Node process left running — the server builds on push and publishes the result.

A Webuzo v4 plugin that deploys static sites (Vite, Astro, Next export — anything
that produces a `dist/`) from GitHub straight into a domain's docroot. It works
out how to build most projects on its own, and installs the Node version a
repository asks for without touching the server's own Node.

An end user picks a repo, branch, build command and output directory in the
panel. A GitHub webhook (or the "Deploy now" button) queues a job; a root cron
runner picks it up once a minute, clones/fetches into a per-user workspace,
builds as that **unprivileged user**, and rsyncs the output into the domain's
docroot.

- **Panel:** enduser panel → **Apps → GitDeploy**
- **Server view:** admin panel (ports 2004/2005) → **GitDeploy** — every account's
  deployments, disk use per account and a global pause switch
- **Version:** see `VERSION`
- **Package root:** `/usr/local/gitdeploy` (code — replaceable)
- **State root:** `/var/lib/gitdeploy` (data — precious)

---

## Install

```bash
# from an unpacked tarball anywhere, or in place
sudo /usr/local/gitdeploy/install.sh
```

The installer is **idempotent** — running it twice is the same as running it
once. It:

1. **Preflight.** Requires root, `/usr/local/webuzo`, `/var/webuzo`, the
   `webuzo` group, and: `git`, `node`, `rsync`, `/usr/bin/setpriv`,
   `/usr/bin/setsid`, `ssh-keygen`, `/usr/local/emps/bin/php`,
   `/usr/local/emps/sbin/nginx`. Aborts with a specific message on any miss.
2. **Stages the payload.** If run from outside `/usr/local/gitdeploy`, `rsync -a`s
   itself there. If run in place, skips.
3. **Fixes permissions.** Whole tree `root:root`; `bin/build.sh` 0755;
   `bin/runner.php` 0700 (root-only — it deploys as arbitrary users);
   `lib/ web/ webuzo/ conf/` 0755 dirs / 0644 files.
4. **Creates the state tree** with exact owners and modes (see file map). Creates
   `.csrf_key` (64 hex chars from `/dev/urandom`, 0600) **only if missing** — an
   existing key is never regenerated, so open panel sessions keep working.
5. **Refreshes `conf/github_known_hosts`** via `ssh-keyscan` (10s timeout). On
   network failure it keeps the pinned copy shipped in the tarball. The file is
   never left empty — SSH deploys would otherwise trust any host.
6. **Symlinks** the plugin, the panel UI and the nginx drop-in into Webuzo.
7. **Registers** `gitdeploy/gitdeploy.php` in `/var/webuzo/plugins.json`.
8. **Enables** `features.gitdeploy = 1` in every plan file (backing each up once).
9. **Validates nginx** (`nginx -t`) and reloads. If the test fails it **removes
   our drop-in symlink and aborts**, so the panel keeps serving.
10. **Installs cron + logrotate.**

`install.sh --repair` is accepted and does exactly the same thing — idempotency
means the normal run *is* the repair run. Use it after manual tinkering to
re-assert perms, links, registration and cron.

## Uninstall

```bash
sudo /usr/local/gitdeploy/uninstall.sh                    # remove integration, KEEP data
sudo /usr/local/gitdeploy/uninstall.sh --purge            # + state, workspaces, deploy keys
sudo /usr/local/gitdeploy/uninstall.sh --purge-features   # + strip gitdeploy from plan features
```

| Mode | cron, logrotate, symlinks, `plugins.json`, `/usr/local/gitdeploy` | `/var/lib/gitdeploy` | workspaces + deploy keys | plan `features.gitdeploy` |
| --- | --- | --- | --- | --- |
| *(no flags)* | removed | **kept** | **kept** | **kept** |
| `--purge` | removed | removed | removed | kept |
| `--purge-features` | removed | kept | kept | removed |
| `--purge --purge-features` | removed | removed | removed | removed |

Notes:

- `/usr/local/gitdeploy` is removed **last, in every mode**. Because the script
  lives inside the directory it must delete, it re-execs a throwaway copy from
  `/tmp` (`GD_FINAL_RM=1`) that only performs the `rm -rf`, then unlinks itself.
- A plain uninstall leaves every site's config, build history and status intact.
  Re-installing restores all of it verbatim — that is the supported rollback.
- `--purge` deletes a deploy key only if its path matches
  `^/home/[a-z0-9_-]+/\.ssh/gitdeploy-[A-Za-z0-9.-]+$`, and a workspace only if
  `realpath` of `/home/<user>/.gitdeploy/<site>` equals that literal path (which
  also proves no symlink anywhere in it). Anything that fails validation is
  reported and left alone.
- Plan backups (`*.gitdeploy.bak`) are always left behind for you to inspect.
- **Docroots are never touched, in any mode.** Neither is any Webuzo core file
  or any other plugin's registration.

## Upgrade

```bash
tar xf gitdeploy-<version>.tar.gz && cd gitdeploy-<version>
sudo ./install.sh
```

Run the **new** tarball's `install.sh` directly. It rsyncs over
`/usr/local/gitdeploy` and re-runs every idempotent step. No uninstall first —
uninstalling would delete state you want to keep.

The stage-in rsync deliberately runs **without `--delete`**: it will never
remove a file it did not ship. Files dropped between versions therefore linger.
For a strictly clean tree:

```bash
sudo mv /usr/local/gitdeploy /usr/local/gitdeploy.old   # state is elsewhere; safe
sudo ./install.sh
sudo rm -rf /usr/local/gitdeploy.old
```

Downgrades work the same way. State written by a newer version is not
guaranteed readable by an older one.

---

## File map

### Package — `/usr/local/gitdeploy` (`root:root`, replaceable)

| Path | Mode | What |
| --- | --- | --- |
| `VERSION` | 0644 | version string |
| `install.sh`, `uninstall.sh` | 0755 | this tooling |
| `lib/common.php` | 0644 | shared helpers, path constants, validators |
| `bin/runner.php` | **0700** | root cron worker — drains the spool, deploys |
| `bin/build.sh` | 0755 | build script, executed **as the site's user** |
| `conf/github_known_hosts` | 0644 | pinned GitHub host keys |
| `web/enduser/index.php` | 0644 | panel UI (root FPM) |
| `web/enduser/hook.php` | 0644 | GitHub webhook receiver (**webuzo** FPM) |
| `web/enduser/assets/` | 0755/0644 | static assets |
| `web/admin/index.php` | 0644 | admin panel server overview (root FPM) |
| `web/admin/assets` | symlink | → `../enduser/assets` (one stylesheet, both panels) |
| `webuzo/plugin/gitdeploy.php` | 0644 | Webuzo plugin — registers the panel icon |
| `webuzo/enduser_locations_gitdeploy.conf` | 0644 | nginx location drop-in (enduser) |
| `webuzo/admin_locations_gitdeploy.conf` | 0644 | nginx location drop-in (admin, **no webhook**) |

### State — `/var/lib/gitdeploy` (data, survives upgrades)

| Path | Owner | Mode | What | Who reads it |
| --- | --- | --- | --- | --- |
| *(base)* | `root:root` | **0711** | traversable, **not listable** | — |
| `.csrf_key` | `root:root` | 0600 | panel CSRF secret | root only |
| `.paused` | `root:root` | 0600 | present = deploys paused server-wide | root only |
| `.diskcache.json` | `root:root` | 0600 | cached `~/.gitdeploy` sizes per account | root only |
| `ghapp-<user>.json` | `root:root` | 0600 | GitHub App creds incl. RSA private key | root only |
| `sites/` | `root:root` | 0700 | per-site config `<domain>.json` (files 0600 `root:root`) | root only |
| `locks/` | `root:root` | 0700 | runner + per-site flocks | root only |
| `logs/` | `root:root` | 0700 | `<site>/deploy-*.log` build logs | root only |
| `hookinfo/` | `root:webuzo` | 0750 | webhook secrets, per site (files 0640 `root:webuzo`) | webhook FPM (read) |
| `status/` | `root:webuzo` | 0750 | last build status per site (files 0640 `root:webuzo`) | panel (read) |
| `spool/` | `root:webuzo` | **0770** | queued jobs, one file per site | webhook FPM (write) |

The mode split *is* the security model. `hook.php` runs as `webuzo`, not root:
it can read the webhook secret it needs to verify an HMAC, and drop a job file
into `spool/`, and **nothing else**. It cannot read `sites/`, deploy keys, or
the CSRF key. Permissions enforce that, not code.

`install.sh` sets this table, `bin/check.sh` asserts it, and the runner re-applies
it on every tick via `gd_enforce_state_perms()` — any correction it has to make is
written to the runner log, so drift is visible instead of silent.

#### Why the state root is `/var/lib/gitdeploy` and must never move under `/var/webuzo`

Earlier versions kept state in `/var/webuzo/conf/gitdeploy`. Webuzo's own daily cron
(`/etc/cron.d/webuzo` → `/usr/local/webuzo/cron.php`) walks `/var/webuzo` and forces
**everything** beneath it to `webuzo:webuzo 0770`. The table above was therefore
correct at install time and wrong within 24 hours, which broke exactly the split it
describes:

- `sites/` became writable by `webuzo` — the uid that runs the public webhook
  endpoint. A request to `hook.php` could rewrite `sites/<domain>.json` and the root
  runner would then act on attacker-chosen `repo`, `user` and `deploy_key` values:
  privilege escalation from the web pool into root-orchestrated builds.
- `.csrf_key` became readable *and* writable by `webuzo`, making panel CSRF tokens
  forgeable.
- `ghapp-<user>.json`, which holds a GitHub App RSA private key, would be created
  inside a `webuzo`-writable directory.

`/var/lib` is outside everything Webuzo manages: its cron never touches it, the EMPS
FPM pools set no `open_basedir` and no `chroot`, and `/var/lib` is traversable by the
`webuzo` user so the 0711 state root still lets the webhook reach `hookinfo/` and
`spool/`. **Do not move the state back under `/var/webuzo`** — `bin/check.sh` fails
the run if you do. `install.sh` migrates a pre-existing
`/var/webuzo/conf/gitdeploy` automatically (destination wins on conflict, nothing is
deleted without an identical copy already at the destination) and leaves the old
directory empty for you to `rmdir`.

### Integration points (created by `install.sh`, all removed by `uninstall.sh`)

| Path | Kind | Target / content |
| --- | --- | --- |
| `/usr/local/webuzo/plugins/gitdeploy` | symlink | `…/gitdeploy/webuzo/plugin` |
| `/usr/local/webuzo/web/enduser/gitdeploy` | symlink | `…/gitdeploy/web/enduser` |
| `/usr/local/webuzo/web/admin/gitdeploy` | symlink | `…/gitdeploy/web/admin` |
| `/usr/local/webuzo/conf/webuzo/emps/enduser_locations_gitdeploy.conf` | symlink | `…/gitdeploy/webuzo/enduser_locations_gitdeploy.conf` |
| `/usr/local/webuzo/conf/webuzo/emps/admin_locations_gitdeploy.conf` | symlink | `…/gitdeploy/webuzo/admin_locations_gitdeploy.conf` |
| `/var/webuzo/plugins.json` | key | `"gitdeploy/gitdeploy.php": <unixtime>` |
| `/var/webuzo/plans/*` | key | `features.gitdeploy = 1` |
| `/etc/cron.d/gitdeploy` | file 0644 | runs `bin/runner.php` every minute |
| `/etc/logrotate.d/gitdeploy` | file 0644 | weekly, rotate 4, compress, copytruncate |
| `/var/webuzo/logs/gitdeploy` | file | runner stdout/stderr |

### Per-user, outside both trees

| Path | What | Removed by |
| --- | --- | --- |
| `/home/<user>/.gitdeploy/<site>/` | git clone + build workspace | `--purge` |
| `/home/<user>/.ssh/gitdeploy-<site>` | deploy key (private repos only) | `--purge` |
| `/home/<user>/<docroot>/` | published output | **never** |

---

## How update-safety works

Webuzo overwrites its own files on every panel update. GitDeploy is built so
that a Webuzo upgrade cannot break it, and so that GitDeploy cannot break
Webuzo.

**1. Nothing of ours lives inside a Webuzo-owned file.**
Every integration point is a **symlink** into `/usr/local/gitdeploy`, placed in
a directory Webuzo scans (`plugins/`, `web/enduser/`, `conf/webuzo/emps/`) —
the same pattern Backuply and Softaculous use. We never patch a Webuzo PHP file,
never edit `nginx.conf`, never add an `include` line anywhere. Editing our code
means editing `/usr/local/gitdeploy`; the symlinks keep pointing at it.

**2. The two files we must edit are merged, never rewritten.**
`plugins.json` and the plan files belong to Webuzo and contain other products'
entries. For both, and in both directions (install and uninstall), we:

- capture the live `owner:group` and mode with `stat` **before** touching it;
- parse with `/usr/local/emps/bin/php` — the same interpreter Webuzo uses;
- **abort loudly if `json_decode` fails.** A corrupt or unexpected registry is
  never overwritten. This is the single most important rule in the installer:
  clobbering `plugins.json` would silently unregister Backuply, Softaculous and
  everything else;
- add or remove **only our own key**, leaving every foreign entry byte-for-byte
  identical (verified: an install→uninstall roundtrip reproduces the original
  file exactly, including Webuzo's escaped-slash 4-space `JSON_PRETTY_PRINT`
  formatting and absence of a trailing newline);
- write a temp file **in the same directory** and `rename()` it — atomic, so a
  crash mid-write can never leave a truncated registry;
- restore the captured owner and mode. Never hardcoded: on this box
  `plugins.json` is `webuzo:webuzo 0770`, but we restore whatever we found.

Plan files additionally get a one-time backup at `<plan>.gitdeploy.bak` before
the first edit. Files that are not JSON, or have no `features` map, are skipped
with a warning and left untouched — and the pointless backup is cleaned up.
Files ending in `.bak` or `.tmp` are never edited at all.

**3. If a Webuzo update wipes the symlinks, re-run the installer.**

```bash
sudo /usr/local/gitdeploy/install.sh --repair
```

Because every step is idempotent, this re-asserts the whole integration without
touching a single byte of site data. `.csrf_key` is preserved, `sites/` is
untouched, existing state directories only have their owner/mode re-applied.
It is safe to run on a live server at any time — including from cron, if you
want the integration self-healing.

**4. nginx is validated before it is reloaded.**
`install.sh` runs `nginx -t -c /usr/local/emps/etc/nginx/nginx.conf` before any
reload. If it fails, our drop-in symlink is removed and the installer aborts —
the panel is never left with a config that will not load. `uninstall.sh` runs
the same test *after* removing our symlink; a failure there is by definition
pre-existing, so it warns and refuses to reload rather than restoring anything.

**5. State is separate from code.**
`/usr/local/gitdeploy` is disposable; `/var/lib/gitdeploy` is not.
You can delete and reinstall the package tree at will without losing a single
site configuration. State deliberately lives outside `/var/webuzo` — see
[Why the state root is `/var/lib/gitdeploy`](#why-the-state-root-is-varlibgitdeploy-and-must-never-move-under-varwebuzo).

---

## The server administrator page

Open the **admin** panel (ports 2004/2005) → **GitDeploy**. It is a root-FPM page
embedded exactly like the enduser one: the plugin registers an `admin_menu` entry
plus an `admin_act`, and the act (which runs as the non-root panel user) frames
`gitdeploy/` from `admin_locations_gitdeploy.conf`. There is deliberately **no
webhook location** in the admin drop-in — the webhook is an enduser endpoint only.

**Who sees what** (enforced in `web/admin/index.php`, in this order):

1. The request must be on the admin panel — `Webuzo_Sessions::is_admin_panel()`.
   A copy of the page reached on the enduser port refuses to run at all.
2. There must be a valid panel session.
3. `root` — the server owner — sees **every** hosting account.
4. Any other admin-panel user is treated as a **reseller** and sees only the
   accounts whose `/var/webuzo/users/<u>/info` has `owner` equal to their
   username. Never all accounts.
5. Anything else is refused with a plain message.

The page is not added to `reseller_menu` / `reseller_acts`: it enforces its own
scope, and staying out of reseller menus is the conservative default.

**What it does**

- *At a glance* — sites in scope, how many are live / failed / building, and the
  runner heartbeat (healthy under 180s, the same threshold `bin/check.sh` uses).
- *All sites* — domain, owning account, repository, branch, state, last deploy
  and short commit sha, **sorted so failures come first**.
- *Disk used* — `~/.gitdeploy` per account, split into build workspaces, release
  archives and Node toolchains (~200 MB each). Measured by running `du` **as the
  account** through `bin/asuser.sh disk` behind `setpriv` + `timeout` — root never
  walks a user's home — and cached in `.diskcache.json` for five minutes so the
  page always renders quickly.
- *Pause all deploys* (server owner only) — writes `/var/lib/gitdeploy/.paused`.
  The runner then builds nothing and **leaves every queued job in the spool**, so
  pushes that arrive while paused deploy as soon as you resume. The pause is
  announced in the runner log and reported by `bin/check.sh`, because a paused
  queue otherwise looks exactly like a broken one.
- *Deploy now* and *View log* per site. There is **no delete and no edit**: site
  configuration stays with the account that owns it.

```bash
# pause / resume from the shell (same flag the page writes)
touch /var/lib/gitdeploy/.paused
rm -f  /var/lib/gitdeploy/.paused
```

---

## Operations

```bash
# is the runner alive?
tail -f /var/webuzo/logs/gitdeploy

# a specific site's build log
ls -t /var/lib/gitdeploy/logs/<domain>/
tail -f /var/lib/gitdeploy/logs/<domain>/deploy-*.log

# what is queued right now?
ls -l /var/lib/gitdeploy/spool/

# last known status
cat /var/lib/gitdeploy/status/<domain>.json

# force one drain cycle by hand
/usr/local/emps/bin/php /usr/local/gitdeploy/bin/runner.php
```

**Icon missing from the panel?** The user's plan must list the feature. Check
`features.gitdeploy` in `/var/webuzo/plans/<plan>` and re-run
`install.sh --repair`.

**Webhook returning 403?** `hook.php` verifies the GitHub HMAC signature against
`hookinfo/<domain>.json`. Confirm `hookinfo/` is still `root:webuzo 0750` — if a
manual `chown -R` flattened it, the `webuzo` FPM pool can no longer read the
secret. `install.sh --repair` fixes the modes.

**Nothing deploying at all?** Check whether deploys are paused server-wide:
`ls -l /var/lib/gitdeploy/.paused` (or run `bin/check.sh`, which says so). Queued
jobs are kept while paused — resume from the admin panel and they drain.

**Deploys hanging?** Check for a stale lock in
`/var/lib/gitdeploy/locks/`. The runner uses `flock`, so a leftover file
is harmless; a genuinely stuck build is a `node` process owned by the site user.
