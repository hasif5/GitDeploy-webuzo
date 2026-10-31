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

## [1.9.0] — 2026-10-31

### Added

- **First-deploy preview for files-only sites.** When the website folder already
  holds a site and has never received a files-only deploy, the first deploy
  changes nothing: it lists every file that would be added, replaced (each with
  its live copy backed up) or left alone, and ends in a new **"Waiting for your
  OK"** state (build.sh exit 20, status `review` + `review_sha`). The owner
  confirms with **"Looks right — deploy <sha>"**; the confirmation is bound to
  that exact commit, so a newer push in between produces a fresh preview instead
  of deploying unread changes. Notification email is sent for previews too.
- **Automatic backups.** Before a files-only deploy replaces a live file that is
  not exactly what the previous deploy published (first deploys, server-side
  edits), the live copy is saved to `~/.gitdeploy/<site>/backups/<time>/`
  (newest 10 kept; one folder per deploy). Combined with releases, every
  overwritten file is recoverable.

- **Optional panel-managed `.env` for files-only sites** ("also write these into
  the website's `.env` file" under Advanced; `env_to_dotenv: true`). On every
  deploy, before any code is copied, the panel's environment variables are
  written into a marked block at the end of `.env` (created 0600 if missing;
  existing mode kept). Lines outside the block are preserved; a hand-written line
  for a key the panel now manages is replaced. Keys removed in the panel are
  removed from the file; unticking leaves the file alone. Values are quoted so
  `parse_ini_file()` and vlucas/phpdotenv both read them back unchanged
  (verified); a value containing both `'` and `${` is refused. The old `.env` is
  backed up, staging happens in the private work dir, a symlinked `.env` aborts
  the deploy before anything is published, and logs show key names only. The
  first-deploy preview reports the `.env` change without making it.
- **Duplicate-name checks for environment variables.** The panel refuses a name
  listed twice (it used to keep the last value silently) or two names differing
  only in case, with the line numbers. When writing `.env`: a hand-written line
  for a managed key is replaced (any `KEY=`, `KEY =`, `export KEY=` form); names
  set twice in the hand-written lines and case-only clashes with panel keys are
  reported by name but not changed (`parse_ini_file` keeps the last value,
  phpdotenv the first); and a final scan refuses to install a `.env` in which a
  managed name appears twice, leaving the current file untouched.

### Safety

- Deploys (both modes, and rollbacks) **refuse a website folder that contains
  `.git`**: it is already deployed with git by hand, the two would conflict, and
  a build-mode mirror would delete it.

### Changed

- The environment-variables hint now states plainly that these values exist only
  while a deploy runs, never for the live site, and points PHP sites at `.env`
  / `SetEnv`.

## [1.8.0] — 2026-10-31

### Added

- **Files-only deploy type for PHP sites** ("Deploy type → Files only (PHP, no
  build)" on the add/edit form; `deploy_mode: "pull"` in the site config). No
  Node, no install, no build and no `.gitdeploy.json`: every push publishes the
  repository's tracked files (`git ls-files`, submodules included) with
  `git pull` semantics:
  - tracked files are added/updated — the docroot is **never** mirrored with
    `--delete`, so uploads, `vendor/`, caches and anything else not in the
    repository are untouched;
  - files the previous deploy published that the repository no longer has are
    removed **only if still byte-identical** to what was published; a file edited
    on the server is kept and reported, as git keeps local changes. The list of
    published paths lives in `~/.gitdeploy/<site>/published.list`;
  - the first files-only deploy into an existing site deletes nothing;
  - `.env` is never shipped (the live one always survives), `.well-known/` and
    `cgi-bin/` are never written, `.htaccess` is updated when tracked but never
    deleted, and git/CI tooling (`.github/`, `.gitignore`, …) is not published;
  - a server-side edit to a tracked file that the deploy overwrites is named in
    the log.
  Optional "Publish only this folder" (stored as `root_dir`). The after-deploy
  command runs **inside the docroot** for files-only sites (10-minute cap), so
  e.g. `php artisan migrate --force` works. Rollback uses the same rules.
  Releases hard-link unchanged files to the previous release (`--link-dest`).
- Site summaries, the setup page and the admin table show the deploy type.

### Safety

- Build mode now **refuses a PHP project** published "as-is" (Plain HTML
  detection with `composer.json` or top-level `*.php`): mirroring it would delete
  uploads/caches/`.env` from the live folder. The log points at files-only mode.
  A PHP repo that build mode cannot detect gets the same pointer.
- Switching an existing files-only site to build mode requires an explicit
  confirmation, because the next build deploy mirror-deletes everything the build
  does not produce. A build-mode publish also discards a stale files-only list.

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
