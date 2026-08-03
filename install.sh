#!/usr/bin/env bash
#
# GitDeploy — installer for Webuzo v4.
#
# Idempotent: safe to run any number of times. A re-run is a repair run
# (`--repair` is accepted and behaves identically).
#
# What it touches:
#   /usr/local/gitdeploy                                        (payload, perms)
#   /var/lib/gitdeploy                                          (state tree, created if missing)
#   /var/webuzo/conf/gitdeploy                                  (OLD state tree: emptied,
#                                                                contents migrated to /var/lib)
#   /usr/local/webuzo/plugins/gitdeploy                         (symlink)
#   /usr/local/webuzo/web/enduser/gitdeploy                     (symlink)
#   /usr/local/webuzo/web/admin/gitdeploy                       (symlink)
#   /usr/local/webuzo/conf/webuzo/emps/enduser_locations_gitdeploy.conf (symlink)
#   /usr/local/webuzo/conf/webuzo/emps/admin_locations_gitdeploy.conf   (symlink)
#   /var/webuzo/plugins.json                                    (one key added)
#   /var/webuzo/plans/*                                         (features.gitdeploy=1, backed up)
#   /etc/cron.d/gitdeploy, /etc/logrotate.d/gitdeploy           (created)
#   /var/webuzo/logs/gitdeploy                                  (touched)
#
# What it NEVER touches:
#   any docroot (/home/*/public_html/**), any Webuzo core file, any other
#   plugin's registration, firewall, systemd.

set -euo pipefail

ROOT=/usr/local/gitdeploy
# State lives OUTSIDE /var/webuzo on purpose — see the migration section below and
# the GD_STATE comment in lib/common.php. Do not move it back.
STATE=/var/lib/gitdeploy
OLD_STATE=/var/webuzo/conf/gitdeploy

WEBUZO_ROOT=/usr/local/webuzo
WEBUZO_VAR=/var/webuzo
PLUGINS_JSON=$WEBUZO_VAR/plugins.json
PLANS_DIR=$WEBUZO_VAR/plans
PLUGIN_KEY='gitdeploy/gitdeploy.php'
FEATURE_KEY='gitdeploy'

PHP=/usr/local/emps/bin/php
NGINX=/usr/local/emps/sbin/nginx
NGINX_CONF=/usr/local/emps/etc/nginx/nginx.conf

LINK_PLUGIN=$WEBUZO_ROOT/plugins/gitdeploy
LINK_WEB=$WEBUZO_ROOT/web/enduser/gitdeploy
LINK_NGINX=$WEBUZO_ROOT/conf/webuzo/emps/enduser_locations_gitdeploy.conf
# Admin panel (ports 2004/2005): the server owner's overview page.
LINK_WEB_ADMIN=$WEBUZO_ROOT/web/admin/gitdeploy
LINK_NGINX_ADMIN=$WEBUZO_ROOT/conf/webuzo/emps/admin_locations_gitdeploy.conf

CRON_FILE=/etc/cron.d/gitdeploy
LOGROTATE_FILE=/etc/logrotate.d/gitdeploy
RUNNER_LOG=$WEBUZO_VAR/logs/gitdeploy

KNOWN_HOSTS=$ROOT/conf/github_known_hosts
CSRF_KEY=$STATE/.csrf_key

SELF_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)

say()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
ok()   { printf '    \033[1;32mok\033[0m   %s\n' "$*"; }
warn() { printf '    \033[1;33mwarn\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31mFATAL\033[0m %s\n' "$*" >&2; exit 1; }

# ---------------------------------------------------------------- args -----
MODE=install
for arg in "$@"; do
	case "$arg" in
		--repair) MODE=repair ;;
		--check) exec /usr/local/gitdeploy/bin/check.sh ;;
		-h|--help)
			cat <<-EOF
			Usage: install.sh [--repair|--check]

			  (no flags)   install / upgrade GitDeploy
			  --check      run a read-only health check and exit
			  --repair     identical to a plain run; re-asserts perms, links,
			               registration, cron, logrotate and nginx wiring.
			EOF
			exit 0
			;;
		*) die "unknown option: $arg (try --help)" ;;
	esac
done

# ------------------------------------------------------------ 1. preflight -
say "Preflight"

[[ $EUID -eq 0 ]] || die "install.sh must run as root (EUID 0)."

for d in "$WEBUZO_ROOT" "$WEBUZO_VAR"; do
	[[ -d "$d" ]] || die "$d not found — this does not look like a Webuzo server."
done

MISSING=()
for b in git node rsync /usr/bin/setpriv /usr/bin/setsid ssh-keygen "$PHP" "$NGINX"; do
	if [[ "$b" == /* ]]; then
		[[ -x "$b" ]] || MISSING+=("$b")
	else
		command -v "$b" >/dev/null 2>&1 || MISSING+=("$b")
	fi
done
if (( ${#MISSING[@]} )); then
	die "missing required binaries: ${MISSING[*]}
     Install them and re-run. GitDeploy needs git+node to build, rsync to
     publish, setpriv/setsid to drop privileges, ssh-keygen for deploy keys,
     and the EMPS php/nginx to integrate with the Webuzo panel."
fi
getent group webuzo >/dev/null 2>&1 \
	|| die "group 'webuzo' does not exist — the state tree needs it for the panel/webhook split."

ok "root, Webuzo dirs, group 'webuzo' and all required binaries present"

# ------------------------------------------------------ 2. stage the payload -
say "Staging payload"
if [[ "$SELF_DIR" != "$ROOT" ]]; then
	[[ -f "$SELF_DIR/lib/common.php" && -f "$SELF_DIR/bin/runner.php" ]] \
		|| die "$SELF_DIR does not look like a GitDeploy payload (lib/common.php or bin/runner.php missing)."
	mkdir -p "$ROOT"
	# No --delete: never remove files we did not ship (see README, "Upgrades").
	rsync -a \
		--exclude '.git/' \
		--exclude '.gitignore' \
		--exclude '*.tmp' \
		--exclude '*.swp' \
		--exclude '.DS_Store' \
		"$SELF_DIR"/ "$ROOT"/
	ok "payload synced $SELF_DIR -> $ROOT"
else
	ok "already running from $ROOT — nothing to sync"
fi

if [[ -f "$ROOT/VERSION" ]]; then ok "version $(tr -d '\n' < "$ROOT/VERSION")"; fi

# ---------------------------------------------------------- 3. permissions -
say "Applying permissions to $ROOT"
chown -R root:root "$ROOT"

# Dirs 0755, files 0644 across lib/ web/ webuzo/ conf/ and the tree root.
chmod 0755 "$ROOT"
for sub in lib web webuzo conf; do
	[[ -d "$ROOT/$sub" ]] || continue
	find "$ROOT/$sub" -type d -exec chmod 0755 {} +
	find "$ROOT/$sub" -type f -exec chmod 0644 {} +
done

# chmod only what is actually there (a partial payload must not abort the run).
set_mode() { # set_mode <mode> <path>
	[[ -e "$2" ]] && chmod "$1" "$2"
	return 0
}
set_mode 0755 "$ROOT/bin"
set_mode 0755 "$ROOT/bin/build.sh"
set_mode 0700 "$ROOT/bin/runner.php"   # root-only: it deploys as any user
set_mode 0644 "$ROOT/VERSION"
set_mode 0644 "$ROOT/README.md"
set_mode 0755 "$ROOT/install.sh"
set_mode 0755 "$ROOT/uninstall.sh"

# web/*/*.php are served through FPM — world-readable, never executable.
for wd in "$ROOT/web/enduser" "$ROOT/web/admin"; do
	[[ -d "$wd" ]] || continue
	find "$wd" -maxdepth 1 -type f -name '*.php' -exec chmod 0644 {} +
done
ok "ownership root:root; bin/build.sh 0755, bin/runner.php 0700, rest 0755/0644"

# ---------------------------------------------------------- 4. state tree --
say "State tree $STATE"
# base 0711: traversable by the panel/webuzo uid, NOT listable.
install -d -o root -g root    -m 0711 "$STATE"
install -d -o root -g root    -m 0700 "$STATE/sites"
install -d -o root -g root    -m 0700 "$STATE/locks"
install -d -o root -g root    -m 0700 "$STATE/logs"
install -d -o root -g webuzo  -m 0750 "$STATE/hookinfo"   # webhook FPM reads secrets
install -d -o root -g webuzo  -m 0750 "$STATE/status"     # panel reads build status
install -d -o root -g webuzo  -m 0770 "$STATE/spool"      # webhook FPM drops jobs here
# `install -d` on an existing dir still re-asserts owner+mode, so the above is
# also the repair path for a tree that drifted.
ok "sites/locks/logs 0700 root:root, hookinfo/status 0750 root:webuzo, spool 0770 root:webuzo"

# ------------------------------------------------ 4b. migrate legacy state -
# GitDeploy used to keep state in /var/webuzo/conf/gitdeploy. Webuzo's own daily
# cron (/etc/cron.d/webuzo -> /usr/local/webuzo/cron.php) walks /var/webuzo and
# forces everything under it to webuzo:webuzo 0770, so the state tree silently lost
# its permissions within a day of every install: sites/ became writable and
# .csrf_key readable by the very uid that runs the public webhook endpoint.
# /var/lib is outside everything Webuzo manages. NEVER move the state back.
if [[ -d "$OLD_STATE" ]]; then
	say "Migrating legacy state $OLD_STATE -> $STATE"

	# Hold the runner's lock: a deploy in flight must not be reading or writing the
	# old tree while we move it out from under it.
	exec 9>"$STATE/locks/runner.lock"
	if flock -w 120 9; then
		MIG_MOVED=0; MIG_KEPT=0; MIG_ASIDE=0

		# 1. mirror the directory skeleton (logs/<domain>/ included). Only genuinely
		#    new subdirectories are created — the seven top-level ones already exist
		#    with their correct modes — and a new one is root-only until proven otherwise.
		while IFS= read -r -d '' REL; do
			if [[ ! -d "$STATE/$REL" ]]; then
				mkdir -p "$STATE/$REL"
				chown root:root "$STATE/$REL"
				chmod 0700 "$STATE/$REL"
			fi
		done < <(cd "$OLD_STATE" && find . -mindepth 1 -type d -printf '%P\0')

		# 2. move every file across. The DESTINATION IS AUTHORITATIVE: an operator may
		#    already have staged a corrected copy there, and a re-run must not undo it.
		#    An identical leftover is simply dropped; a leftover that differs is parked
		#    beside the winner as *.pre-migration-<epoch>, so no site config is ever
		#    lost and the anomaly stays visible. Both branches make the run idempotent.
		while IFS= read -r -d '' REL; do
			if [[ ! -e "$STATE/$REL" ]]; then
				mv -- "$OLD_STATE/$REL" "$STATE/$REL"
				MIG_MOVED=$((MIG_MOVED+1))
			elif cmp -s -- "$OLD_STATE/$REL" "$STATE/$REL"; then
				rm -f -- "$OLD_STATE/$REL"
				MIG_KEPT=$((MIG_KEPT+1))
			else
				mv -- "$OLD_STATE/$REL" "$STATE/$REL.pre-migration-$(date +%s)"
				MIG_ASIDE=$((MIG_ASIDE+1))
				warn "$REL differs — kept $STATE/$REL, parked the old copy as $STATE/$REL.pre-migration-*"
			fi
		done < <(cd "$OLD_STATE" && find . -mindepth 1 ! -type d -printf '%P\0')

		# 3. leave the old tree in place but empty. rmdir only ever removes empty
		#    directories, so anything unexpected in there simply survives untouched.
		while IFS= read -r -d '' REL; do
			rmdir -- "$OLD_STATE/$REL" 2>/dev/null || true
		done < <(cd "$OLD_STATE" && find . -depth -mindepth 1 -type d -printf '%P\0')

		ok "$MIG_MOVED file(s) moved, $MIG_KEPT already current at the destination, $MIG_ASIDE parked aside"
		if [[ -n "$(ls -A "$OLD_STATE" 2>/dev/null)" ]]; then
			warn "$OLD_STATE is not empty — review what is left before removing it"
		else
			ok "$OLD_STATE is now empty and unused — remove it yourself when happy: rmdir $OLD_STATE"
		fi
	else
		warn "could not take $STATE/locks/runner.lock within 120s — migration SKIPPED."
		warn "A deploy is probably running. Re-run $ROOT/install.sh --repair afterwards."
	fi
	exec 9>&-
fi

if [[ ! -s "$CSRF_KEY" ]]; then
	umask 077
	od -An -N32 -tx1 < /dev/urandom | tr -d ' \n' > "$CSRF_KEY.tmp.$$"
	KEYLEN=$(wc -c < "$CSRF_KEY.tmp.$$")
	if [[ "$KEYLEN" -ne 64 ]]; then
		rm -f "$CSRF_KEY.tmp.$$"
		die "failed to generate a 64-hex-char CSRF key (got $KEYLEN bytes)."
	fi
	chown root:root "$CSRF_KEY.tmp.$$"
	chmod 0600 "$CSRF_KEY.tmp.$$"
	mv -f "$CSRF_KEY.tmp.$$" "$CSRF_KEY"
	umask 022
	ok "generated $CSRF_KEY (64 hex, root:root 0600)"
else
	chown root:root "$CSRF_KEY"
	chmod 0600 "$CSRF_KEY"
	ok "CSRF key already present — left untouched (sessions stay valid)"
fi

# ------------------------------------------------- 4c. state file perms ----
# Files migrated in from the old tree carry its (Webuzo-mangled) ownership, so the
# file half of the model has to be re-asserted too. Same table bin/check.sh asserts
# and lib/common.php's gd_enforce_state_perms() re-applies on every runner tick.
harden_files() { # harden_files <dir> <owner:group> <mode>
	[[ -d "$1" ]] || return 0
	find "$1" -maxdepth 1 -type f -exec chown "$2" {} + -exec chmod "$3" {} +
	return 0
}
harden_files "$STATE"          root:root   0600   # .csrf_key, ghapp-<user>.json, scratch
harden_files "$STATE/sites"    root:root   0600
harden_files "$STATE/hookinfo" root:webuzo 0640
harden_files "$STATE/status"   root:webuzo 0640
# logs/ and locks/ are 0700 root:root so their contents are already unreachable,
# but ownership that came in from the old tree still belongs back with root.
for d in "$STATE/logs" "$STATE/locks"; do
	if [[ -d "$d" ]]; then chown -R root:root "$d"; fi
done
ok "state files: root 0600 root:root, sites 0600 root:root, hookinfo/status 0640 root:webuzo"

# ------------------------------------------------- 5. github known_hosts ---
say "Refreshing GitHub host keys"
# We ship a pinned copy. Try to refresh it, but a network failure must never
# leave the file empty — deploys over SSH would then fail host verification.
mkdir -p "$ROOT/conf"
KH_TMP=$(mktemp "$ROOT/conf/.github_known_hosts.XXXXXX")
if timeout 10 ssh-keyscan -t ed25519,ecdsa,rsa github.com > "$KH_TMP" 2>/dev/null \
   && [[ $(grep -c '^github\.com ' "$KH_TMP" || true) -ge 1 ]]; then
	chown root:root "$KH_TMP"
	chmod 0644 "$KH_TMP"
	mv -f "$KH_TMP" "$KNOWN_HOSTS"
	ok "refreshed $KNOWN_HOSTS ($(grep -c '^github\.com ' "$KNOWN_HOSTS") keys)"
else
	rm -f "$KH_TMP"
	if [[ -s "$KNOWN_HOSTS" ]]; then
		chmod 0644 "$KNOWN_HOSTS"
		warn "ssh-keyscan failed or returned nothing — keeping the pinned copy at $KNOWN_HOSTS"
	else
		die "ssh-keyscan failed AND $KNOWN_HOSTS is missing/empty. Refusing to continue:
     SSH deploys would silently trust any host. Restore conf/github_known_hosts
     from the tarball, or run: ssh-keyscan -t ed25519,ecdsa,rsa github.com > $KNOWN_HOSTS"
	fi
fi

# ------------------------------------------------------------ 6. symlinks --
say "Wiring symlinks"
link_to() { # link_to <target> <linkname>
	local target=$1 link=$2 dir
	dir=$(dirname -- "$link")
	[[ -d "$dir" ]] || die "$dir does not exist — cannot install $link"
	if [[ -e "$link" && ! -L "$link" ]]; then
		die "$link exists and is NOT a symlink. Refusing to clobber it.
     Move it aside and re-run."
	fi
	ln -sfn "$target" "$link"
	ok "$link -> $target"
}
link_to "$ROOT/webuzo/plugin"                          "$LINK_PLUGIN"
link_to "$ROOT/web/enduser"                            "$LINK_WEB"
link_to "$ROOT/webuzo/enduser_locations_gitdeploy.conf" "$LINK_NGINX"
link_to "$ROOT/web/admin"                              "$LINK_WEB_ADMIN"
link_to "$ROOT/webuzo/admin_locations_gitdeploy.conf"   "$LINK_NGINX_ADMIN"

# --------------------------------------------------- 7. plugins.json entry -
say "Registering plugin in $PLUGINS_JSON"
[[ -f "$PLUGINS_JSON" ]] || die "$PLUGINS_JSON not found — is Webuzo installed?"

# Capture the live owner/mode BEFORE editing and restore it after: Webuzo runs
# the panel as webuzo:webuzo and this file is currently 0770. Never hardcode.
PJ_OWNER=$(stat -c '%U:%G' "$PLUGINS_JSON")
PJ_MODE=$(stat -c '%a'    "$PLUGINS_JSON")

if ! "$PHP" -d error_reporting=E_ALL -d display_errors=1 -- "$PLUGINS_JSON" "$PLUGIN_KEY" <<'PHPEOF'
<?php
if(!defined("STDOUT")) define("STDOUT", fopen("php://stdout","w"));
if(!defined("STDERR")) define("STDERR", fopen("php://stderr","w"));
// argv: [0]=- [1]=plugins.json [2]=plugin key
$path = $argv[1];
$key  = $argv[2];

$raw = @file_get_contents($path);
if($raw === false){ fwrite(STDERR, "cannot read $path\n"); exit(1); }

$data = json_decode($raw, true);
if(!is_array($data)){
	fwrite(STDERR, "REFUSING TO WRITE: $path is not valid JSON (".json_last_error_msg().").\n");
	fwrite(STDERR, "Fix or restore the registry by hand; GitDeploy will not overwrite it.\n");
	exit(1);
}

if(array_key_exists($key, $data)){
	fwrite(STDOUT, "already registered\n");
	exit(0);
}

$data[$key] = time();

// Match Webuzo's own formatting: 4-space pretty print with escaped slashes.
$out = json_encode($data, JSON_PRETTY_PRINT);
if($out === false){ fwrite(STDERR, "json_encode failed\n"); exit(1); }

$tmp = $path.'.gitdeploy.tmp.'.getmypid();   // same dir => rename() is atomic
// No trailing newline: matches Webuzo's own writer byte-for-byte.
if(@file_put_contents($tmp, $out) === false){ fwrite(STDERR, "cannot write $tmp\n"); exit(1); }
if(!@rename($tmp, $path)){ @unlink($tmp); fwrite(STDERR, "rename failed\n"); exit(1); }

fwrite(STDOUT, "registered\n");
exit(0);
PHPEOF
then
	die "could not register the plugin in $PLUGINS_JSON (see error above). Nothing was overwritten."
fi

chown "$PJ_OWNER" "$PLUGINS_JSON"
chmod "$PJ_MODE"  "$PLUGINS_JSON"
ok "$PLUGIN_KEY present; ownership restored to $PJ_OWNER $PJ_MODE"

# ------------------------------------------------------- 8. plan features --
say "Enabling the '$FEATURE_KEY' feature in plan files"
if [[ ! -d "$PLANS_DIR" ]]; then
	warn "$PLANS_DIR missing — skipping. The panel icon may be hidden until plans list the feature."
else
	shopt -s nullglob
	for PLAN in "$PLANS_DIR"/*; do
		[[ -f "$PLAN" ]] || continue
		case "$PLAN" in
			*.bak|*.tmp) continue ;;   # never edit backups (ours or anyone else's)
		esac

		PL_OWNER=$(stat -c '%U:%G' "$PLAN")
		PL_MODE=$(stat -c '%a'    "$PLAN")
		BACKUP="$PLAN.gitdeploy.bak"

		# One-time backup, taken before the first edit only. Track whether *this*
		# run created it so a later skip doesn't delete an older, valid backup.
		BACKUP_MADE_NOW=0
		if [[ ! -e "$BACKUP" ]]; then
			cp -p "$PLAN" "$BACKUP"
			BACKUP_MADE_NOW=1
			ok "backed up $(basename "$PLAN") -> $(basename "$BACKUP")"
		fi

		set +e
		OUTPUT=$("$PHP" -- "$PLAN" "$FEATURE_KEY" <<'PHPEOF'
<?php
if(!defined("STDOUT")) define("STDOUT", fopen("php://stdout","w"));
if(!defined("STDERR")) define("STDERR", fopen("php://stderr","w"));
$path = $argv[1];
$feat = $argv[2];

$raw = @file_get_contents($path);
if($raw === false){ fwrite(STDERR, "unreadable\n"); exit(2); }

$data = json_decode($raw, true);
if(!is_array($data)){ fwrite(STDERR, "not JSON\n"); exit(2); }

// Only touch plans that already carry a features map — anything else is not a plan.
if(!array_key_exists('features', $data) || !is_array($data['features'])){
	fwrite(STDOUT, "no features map\n"); exit(3);
}
if(array_key_exists($feat, $data['features'])){
	fwrite(STDOUT, "already enabled\n"); exit(0);
}

// Existing entries are integer 1 (verified against the live plans) — match that.
$data['features'][$feat] = 1;

$out = json_encode($data, JSON_PRETTY_PRINT);
if($out === false){ fwrite(STDERR, "json_encode failed\n"); exit(2); }

$tmp = $path.'.gitdeploy.tmp.'.getmypid();
if(@file_put_contents($tmp, $out) === false){ fwrite(STDERR, "cannot write tmp\n"); exit(2); }
if(!@rename($tmp, $path)){ @unlink($tmp); fwrite(STDERR, "rename failed\n"); exit(2); }

fwrite(STDOUT, "enabled\n"); exit(0);
PHPEOF
		)
		RC=$?
		set -e

		chown "$PL_OWNER" "$PLAN"
		chmod "$PL_MODE"  "$PLAN"

		case "$RC" in
			0) ok "$(basename "$PLAN"): $OUTPUT" ;;
			3) warn "$(basename "$PLAN"): no 'features' map — skipped, left untouched"
			   # nothing changed: drop the backup we just made (never an older one)
			   if (( BACKUP_MADE_NOW )); then rm -f "$BACKUP"; fi ;;
			*) warn "$(basename "$PLAN"): skipped (not valid plan JSON) — left untouched"
			   if (( BACKUP_MADE_NOW )); then rm -f "$BACKUP"; fi ;;
		esac
	done
	shopt -u nullglob
fi

# --------------------------------------------------------------- 9. nginx --
say "Validating and reloading nginx (EMPS)"
NGX_OUT=$(mktemp)
if "$NGINX" -t -c "$NGINX_CONF" >"$NGX_OUT" 2>&1; then
	ok "nginx -t passed"
	if "$NGINX" -s reload >>"$NGX_OUT" 2>&1; then
		ok "nginx reloaded gracefully"
	else
		warn "nginx -t passed but reload failed:"
		cat "$NGX_OUT" >&2
		warn "the config is valid — reload manually: $NGINX -s reload"
	fi
	rm -f "$NGX_OUT"
else
	echo "--- nginx -t output ---" >&2
	cat "$NGX_OUT" >&2
	echo "-----------------------" >&2
	# Both of our drop-ins go, not just one: we cannot tell from here which of
	# them nginx objected to, and a half-wired panel is worse than none.
	rm -f "$NGX_OUT" "$LINK_NGINX" "$LINK_NGINX_ADMIN"
	die "nginx rejected the config. BOTH of our drop-in symlinks have been REMOVED
     so the panel keeps serving. Fix
       $ROOT/webuzo/enduser_locations_gitdeploy.conf
       $ROOT/webuzo/admin_locations_gitdeploy.conf
     and re-run install.sh."
fi

# ---------------------------------------------------------------- 10. cron -
say "Installing cron job"
if [[ ! -f "$RUNNER_LOG" ]]; then
	: > "$RUNNER_LOG"
	chown root:root "$RUNNER_LOG"
	chmod 0640 "$RUNNER_LOG"
	ok "created $RUNNER_LOG"
else
	ok "$RUNNER_LOG already present — perms left as-is"
fi

# cron.d files MUST end in a newline or the last line is ignored.
cat > "$CRON_FILE" <<EOF
# GitDeploy — drains the deploy spool once a minute. Managed by install.sh.
* * * * * root $PHP $ROOT/bin/runner.php >> $RUNNER_LOG 2>&1
EOF
chown root:root "$CRON_FILE"
chmod 0644 "$CRON_FILE"
ok "$CRON_FILE (0644 root:root)"

# ----------------------------------------------------------- 11. logrotate -
say "Installing logrotate rule"
cat > "$LOGROTATE_FILE" <<EOF
# GitDeploy runner log. Managed by $ROOT/install.sh.
$RUNNER_LOG {
    weekly
    rotate 4
    compress
    missingok
    notifempty
    copytruncate
}
EOF
chown root:root "$LOGROTATE_FILE"
chmod 0644 "$LOGROTATE_FILE"
ok "$LOGROTATE_FILE (weekly, rotate 4, compress, copytruncate)"

# ------------------------------------------------------------- 12. summary -
VERSION_STR=$( [[ -f "$ROOT/VERSION" ]] && tr -d '\n' < "$ROOT/VERSION" || echo unknown )
SITE_COUNT=$(find "$STATE/sites" -maxdepth 1 -name '*.json' 2>/dev/null | wc -l)

cat <<EOF

$(printf '\033[1;32m==> GitDeploy %s installed (%s run complete).\033[0m' "$VERSION_STR" "$MODE")

  Payload      $ROOT                       (root:root)
  State        $STATE                      (0711, per-site config + logs)
  Panel plugin $LINK_PLUGIN
  Panel UI     $LINK_WEB
  Admin UI     $LINK_WEB_ADMIN
  nginx        $LINK_NGINX
               $LINK_NGINX_ADMIN
  Registry     $PLUGINS_JSON               (key: $PLUGIN_KEY)
  Plans        features.$FEATURE_KEY = 1   (originals: *.gitdeploy.bak)
  Cron         $CRON_FILE                  (runner every minute)
  Logrotate    $LOGROTATE_FILE
  Runner log   $RUNNER_LOG

  Open it:     enduser panel -> Apps -> GitDeploy
               (the icon only appears for users whose plan has the feature)
  Server view: admin panel (port 2004/2005) -> GitDeploy
               (every account's deploys, disk use and a global pause switch)

  Sites currently configured: $SITE_COUNT
  Per-site config, build logs and deploy status all live under
  $STATE — they survive upgrades and a plain uninstall.
  State is deliberately NOT under /var/webuzo: Webuzo's daily cron resets
  ownership there to webuzo:webuzo 0770, which would expose site configs and
  the CSRF key to the webhook's uid. Do not move it back.

  Re-run any time:  $ROOT/install.sh --repair
  Remove:           $ROOT/uninstall.sh          (keeps site data)
                    $ROOT/uninstall.sh --purge  (removes everything)

EOF
