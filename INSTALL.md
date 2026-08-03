# Installing GitDeploy on a Webuzo v4 server

**Powered by [Digitalbkk Co., Ltd.](https://www.digitalbkk.com) — developed by the Digitalbkk team.
Author: Hasif Ahmed <hasif5@gmail.com>**

GitDeploy is a Webuzo v4 plugin that deploys static sites from GitHub straight
into a domain's docroot. This document takes you from a fresh Webuzo v4 server
to a working panel page. You do not need to know anything about the codebase.

If you are looking for how it works internally, read `README.md` instead.

---

## 1. Prerequisites

### Server

- **Root shell access.** The installer refuses to run as anyone else
  (`install.sh must run as root (EUID 0)`).
- **Webuzo v4**, already installed and working. The installer checks for
  `/usr/local/webuzo` and `/var/webuzo` and aborts if either is missing.
  Developed and verified against **Webuzo 4.7.4**; `info.json` declares a floor
  of `4.0.0`.
- The **`webuzo` group** must exist. It does on any normal Webuzo install — the
  state tree uses it to split panel access from webhook access.
- Linux with `cron` running (the deploy runner is a `/etc/cron.d` job).

### Binaries

`install.sh` checks for every one of these at preflight and aborts naming
whichever is missing:

| Binary | Why |
| --- | --- |
| `git` | clone/fetch the repository |
| `node` | run the build |
| `rsync` | publish the build output into the docroot |
| `/usr/bin/setpriv` | drop privileges to the site's user before building |
| `/usr/bin/setsid` | detach the build from the runner's session |
| `ssh-keygen` | generate per-site deploy keys for private repos |
| `/usr/local/emps/bin/php` | EMPS PHP (7.4) — the interpreter Webuzo itself uses |
| `/usr/local/emps/sbin/nginx` | EMPS nginx — validated and reloaded during install |

Optional but recommended (`bin/check.sh` warns, it is not fatal):

- `/usr/sbin/sendmail` — without it, deploy **email notifications are not
  delivered**. Everything else works.
- Outbound HTTPS to `api.github.com` and `nodejs.org` — needed for the GitHub
  App integration and for downloading a per-site Node version.

On AlmaLinux/RHEL 9 the usual fill-in is:

```bash
dnf install -y git rsync util-linux openssh-clients   # setpriv/setsid, ssh-keygen
# Node from your preferred source, e.g. dnf module or NodeSource
```

### Network

**Inbound TCP 2003 must be reachable from the public internet.** That is the
Webuzo enduser panel's HTTPS port, and it is where GitHub delivers webhooks:

```
https://<server-host>:2003/gitdeploy/hook.php?site=<domain>
```

If 2003 is firewalled off, pushes will never trigger a deploy — the "Deploy now"
button in the panel will still work. Open it before you configure a repository:

```bash
firewall-cmd --permanent --add-port=2003/tcp && firewall-cmd --reload
```

---

## 2. Clone and install

Clone **outside** `/usr/local/gitdeploy`. The installer stages itself into that
directory for you, and it deliberately excludes `.git/` and `.gitignore` from
the copy, so the live tree stays free of repository metadata.

```bash
sudo mkdir -p /usr/local/src
sudo git clone <your-repository-url> /usr/local/src/gitdeploy
sudo /usr/local/src/gitdeploy/install.sh
```

The installer is **idempotent** — running it twice is the same as running it
once. In order it: preflights, `rsync`s the payload into `/usr/local/gitdeploy`,
fixes permissions, creates the state tree at `/var/lib/gitdeploy`, generates a
CSRF key (only if one does not already exist), refreshes GitHub's SSH host keys,
symlinks the plugin/UI/nginx drop-in into Webuzo, registers itself in
`/var/webuzo/plugins.json`, enables `features.gitdeploy` in every plan file,
validates nginx with `nginx -t` before reloading, and installs the cron job and
logrotate rule.

It prints a summary on success. If `nginx -t` fails it removes its own drop-in
symlink and aborts, so the panel is never left unable to serve.

Alternatively, install from a release tarball produced by
`packaging/make-release.sh`:

```bash
tar xzf gitdeploy-1.6.0.tar.gz     # unpacks to ./gitdeploy/
sudo ./gitdeploy/install.sh
```

---

## 3. Verify

```bash
sudo /usr/local/gitdeploy/install.sh --check
```

This runs the read-only health check (`bin/check.sh`) — it reports, it never
repairs. It verifies required tools, package file integrity and PHP/shell
syntax, the three Webuzo symlinks, the `plugins.json` registration, the plan
feature flag, nginx config validity and both FPM sockets, every permission in
the state tree, the cron job and logrotate rule, the runner heartbeat, and
per-site configuration sanity. Exit code 0 means healthy.

The heartbeat check will warn on a fresh install until the cron runner has
fired once — wait a minute and re-run.

Anything it flags is almost always fixed by:

```bash
sudo /usr/local/gitdeploy/install.sh --repair
```

which is identical to a plain run (idempotency means the normal run *is* the
repair run).

---

## 4. Reaching the panel page

Log into the **enduser** panel (not the admin panel) as the hosting user, then:

> **Apps → GitDeploy**

The icon only appears for users whose plan lists the feature. The installer sets
`features.gitdeploy = 1` in every plan file it finds, but if you create a new
plan later, re-run `install.sh --repair` to add it.

---

## 5. Setting up your first site

Open **Apps → GitDeploy** and click **Help** (top right of the page, or the
"Need help?" link on the settings form). That in-panel Help page is the
authoritative user guide — it covers supported frameworks, build commands and
output directories, the optional `.gitdeploy.json` repository config, Node
version selection, monorepo `root_dir`, the after-deploy command, rollback to a
previous release, and troubleshooting. Follow it rather than duplicating it
here.

### The one manual step: connecting GitHub

If you use the **GitHub App** integration (the easier path — no keys to copy,
private repos work automatically), you must finish the connection **in a
browser**. It cannot be scripted from the shell:

1. In the GitDeploy panel page, start the GitHub connection.
2. You are redirected to GitHub, where you create and install the App on your
   account or organisation and choose which repositories it can see.
3. GitHub redirects back to
   `https://<server-host>:2003/gitdeploy/index.php?act=ghapp_callback`, which
   completes the handshake and stores the credentials root-only under
   `/var/lib/gitdeploy/ghapp-<user>.json`.

For this round trip to work, **your browser must be able to reach the panel on
port 2003 at the same hostname GitHub was given**, and that hostname must
resolve publicly.

If you would rather not connect a GitHub App, the alternative is per-repository
deploy keys plus a webhook you paste into GitHub yourself — the panel shows the
exact webhook URL and secret for each site under its settings.

---

## 6. Upgrading

```bash
cd /usr/local/src/gitdeploy
sudo git pull
sudo ./install.sh
```

Always run the **new** checkout's `install.sh`. Never uninstall first —
uninstalling is not part of an upgrade, and a purge would delete state you want
to keep. Every step is idempotent: the existing CSRF key is preserved, `sites/`
is untouched, and build history and per-site configuration survive intact.

The stage-in `rsync` runs **without `--delete`**, so it will never remove a file
it did not ship. Files dropped between versions therefore linger harmlessly. If
you want a strictly clean tree:

```bash
sudo mv /usr/local/gitdeploy /usr/local/gitdeploy.old   # state is elsewhere; safe
sudo /usr/local/src/gitdeploy/install.sh
sudo rm -rf /usr/local/gitdeploy.old
```

Finish with `sudo /usr/local/gitdeploy/install.sh --check`.

**Webuzo panel updates** can wipe plugin symlinks. GitDeploy never patches a
Webuzo file, so recovery is just `install.sh --repair`.

---

## 7. Uninstalling

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

**Use no flags unless you mean it.** A plain uninstall leaves every site's
configuration, build history and status intact; re-installing restores all of it
verbatim. That is the supported rollback path. `--purge` is irreversible.

In every mode, **docroots are never touched** — your published sites keep
serving. No Webuzo core file and no other plugin's registration is modified.
Plan backups (`*.gitdeploy.bak`) are always left behind for you to inspect.

If you cloned to `/usr/local/src/gitdeploy`, remove that checkout separately —
the uninstaller only knows about `/usr/local/gitdeploy`.

---

## 8. If something goes wrong

```bash
sudo /usr/local/gitdeploy/install.sh --check     # start here, it names the problem
tail -f /var/webuzo/logs/gitdeploy               # is the runner alive?
ls -l /var/lib/gitdeploy/spool/                  # what is queued right now?
```

The `README.md` "Operations" section has the full list, including per-site build
logs and how to force a drain cycle by hand.

---

## License

GitDeploy is open source under the **Apache License 2.0**. The full text is in
[`LICENSE`](LICENSE); attribution is in [`NOTICE`](NOTICE). You are free to use,
modify and redistribute it under those terms.

Copyright 2026 Digitalbkk Co., Ltd.

Digitalbkk offers **paid setup, support and custom development** for GitDeploy —
<https://www.digitalbkk.com>.

---

Powered by **Digitalbkk Co., Ltd.** — <https://www.digitalbkk.com>
