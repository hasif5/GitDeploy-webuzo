# Changelog

All notable changes to **GitDeploy**.

Powered by [Digitalbkk Co., Ltd.](https://www.digitalbkk.com) — developed by the
Digitalbkk team. Author: Hasif Ahmed <hasif5@gmail.com>

This history was reconstructed from the source tree at the point the project was
first placed under version control, so entries describe what each release added
rather than a commit-by-commit record. Release dates before 1.6.0 were not
recorded and are deliberately not guessed at. There was **no 1.4.0**.

The format follows [Keep a Changelog](https://keepachangelog.com/); this project
uses semantic versioning.

---

## [1.7.0] — 2026-08-03

### Added

- **Server administrator page** in the Webuzo admin panel (ports 2004/2005) →
  **GitDeploy**. Shows every account's sites in one table (owner, repository,
  branch, state, last deploy, commit) with failures sorted first, a summary of
  site states, and runner health.
- **Disk used per account**, split into build workspaces, release archives and
  Node toolchains. Measured by a read-only `asuser.sh disk` op run as the account
  owner — root never walks a user's home — and cached for five minutes so
  rendering the page never waits on `du`.
- **Global pause switch** on the admin page. While paused the runner builds
  nothing, and queued jobs are left in the spool rather than discarded, so every
  push that arrives during a pause deploys once it is lifted. The runner logs why
  it is idle, and `install.sh --check` reports the paused state.
- Per-site **Deploy now** and **View log** for administrators. Deliberately no
  edit or delete: site configuration stays with the account that owns it.

### Security

- The admin page refuses to run unless it is being served on an admin panel port,
  checked before the session is even loaded.
- Resellers also sign in to the admin panel, so scope is enforced by account
  ownership: `root` sees every account, any other admin-panel user sees only
  accounts whose `owner` names them, and anyone else is refused. The pause switch
  is root-only. The plugin does not register itself in reseller menus.

### Fixed

- `~/.gitdeploy/toolchains` is no longer treated as an abandoned site workspace by
  the orphan collector, which would have deleted an account's Node toolchains
  after 24 hours and forced a needless re-download. Both the collector and the
  privilege-drop helper now refuse the name.

## [1.6.0] — 2026-08-03

### Added

- **Framework auto-detection.** `bin/detect.js framework` inspects the checked
  out repository and infers the build command and output directory, so a new
  site usually needs no manual build settings.
- **Per-site Node version.** The build resolves the Node version a project asks
  for — the panel's *Node version* field, then `.nvmrc`, then `.node-version`,
  then `engines.node` — and installs the official build from nodejs.org into a
  shared toolchain cache in the user's own home. The cache is reused across that
  account's sites and builds; versions that fall out of use are reclaimed after
  60 days.

### Security

- **State relocated from `/var/webuzo/conf/gitdeploy` to `/var/lib/gitdeploy`.**
  Webuzo's own daily cron walks `/var/webuzo` and forces everything beneath it to
  `webuzo:webuzo 0770`, which silently undid the installer's permissions within a
  day of every install. That handed the uid running the public webhook endpoint
  write access to `sites/*.json` — which the root runner then acts on, giving
  attacker-chosen `repo`, `user` and `deploy_key` values (privilege escalation
  into root-orchestrated builds) — plus read/write access to the panel CSRF key
  and to GitHub App RSA private keys. `/var/lib` is outside everything Webuzo
  manages.
  - `install.sh` migrates an existing `/var/webuzo/conf/gitdeploy` automatically.
    The destination is authoritative; nothing is deleted unless an identical copy
    already exists at the destination, and a differing leftover is parked aside
    as `*.pre-migration-<epoch>`. The old directory is left in place but empty.
  - `bin/check.sh` now fails the run if the state root is under `/var/webuzo`,
    as a permanent regression guard.
  - The runner re-applies the whole permission table on every tick via
    `gd_enforce_state_perms()`, logging any correction it has to make, so drift
    is visible rather than silent.

---

## [1.5.0]

### Added

- **Monorepo support** via a `root_dir` setting — build from a subdirectory of
  the repository instead of its root.
- **Package manager detection.** pnpm, yarn and bun lockfiles are recognised and
  the matching frozen-lockfile install is used; npm remains the fallback, and a
  missing manager is fetched on demand via `npx`.
- **After-deploy command** (`post_cmd`), run once publishing has succeeded.
- **Build concurrency cap** — at most 2 builds run at a time
  (`GD_MAX_CONCURRENT`), so one busy account cannot starve a shared server.
- **Disk hygiene.** `gd_gc_orphans()` reclaims build workspaces, log directories
  and status files belonging to sites that no longer exist. The shared Node
  toolchain cache is exempt from orphan collection.
- **Webhook cooldown**, so a misconfigured CI loop pushing dozens of times a
  minute cannot queue a deploy for each push.
- **`install.sh --check` doctor.** A read-only health check (`bin/check.sh`)
  covering required tools, package integrity and syntax, panel symlinks,
  `plugins.json` registration, plan features, nginx validity, FPM sockets, the
  full state permission table, cron, logrotate, the runner heartbeat, per-site
  configuration sanity and GitHub connectivity. It reports; it never repairs.

---

## [1.3.0]

### Added

- **GitHub App integration.** Connect a GitHub App once per hosting user instead
  of managing a deploy key and webhook per repository. Private repositories work
  without copying keys around. Credentials, including the App's RSA private key,
  are stored root-only as `ghapp-<user>.json`; installation tokens are minted per
  deploy. The connection is completed in the browser through a redirect back to
  the panel.

---

## [1.2.0]

### Added

- **Rollback / previous versions.** Successful builds are archived, and a site's
  Logs page lists its previous releases. *Restore this version* republishes that
  exact build straight from the archived copy — nothing is rebuilt and no GitHub
  access is needed, so a rollback works even when the repository or the network
  does not.

---

## [1.1.0]

### Added

- **Email notifications** on deploy success and failure, delivered via
  `/usr/sbin/sendmail`.
- **`.gitdeploy.json` repository config.** A file at the repository root can set
  the build command, output directory and related build settings, so deployment
  configuration lives with the code.
- **Live log streaming** in the panel — watch a build as it runs instead of
  waiting for it to finish.

### Changed

- **Panel-native restyle.** The UI was rebuilt to match the Webuzo enduser panel
  rather than sitting apart from it.

---

## [1.0.0]

### Added

- Initial release: GitHub **webhook** receiver, **build** as the site's
  unprivileged user, and **publish** of the build output into the domain's
  docroot.
- Root cron runner draining a spool directory, with the permission split that is
  still the security model: the webhook endpoint runs as `webuzo` and can only
  read the webhook secret it needs and drop a job file into the spool.
- Idempotent `install.sh` and a staged `uninstall.sh` with `--purge` and
  `--purge-features`.
