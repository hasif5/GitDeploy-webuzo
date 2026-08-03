#!/usr/bin/env bash
#
# GitDeploy — uninstaller for Webuzo v4.
#
#   ./uninstall.sh                   remove the integration, KEEP site data
#   ./uninstall.sh --purge           also remove state, workspaces and deploy keys
#   ./uninstall.sh --purge-features  also strip 'gitdeploy' from plan features
#   (flags combine, e.g. --purge --purge-features)
#
# NEVER touches: any docroot (/home/*/public_html/**), any Webuzo core file,
# any other plugin's registration, or anything not created by GitDeploy.
#
# /usr/local/gitdeploy itself is removed LAST, in every mode — this script
# re-execs a copy of itself from /tmp so it can delete the directory it lives in.

set -euo pipefail

ROOT=/usr/local/gitdeploy
STATE=/var/lib/gitdeploy
# Pre-1.6.0 layout. Kept here so --purge cleans it up too, and so a purge still finds
# the site list on a box that was never migrated.
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

say()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
ok()   { printf '    \033[1;32mok\033[0m   %s\n' "$*"; }
warn() { printf '    \033[1;33mwarn\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31mFATAL\033[0m %s\n' "$*" >&2; exit 1; }

# ------------------------------------------------- second-stage self-delete -
# The final `rm -rf $ROOT` cannot run from inside $ROOT reliably, so the main
# pass re-execs a /tmp copy of this script with GD_FINAL_RM=1. That copy does
# nothing except the removal, then unlinks itself.
if [[ "${GD_FINAL_RM:-0}" == "1" ]]; then
	rm -rf -- "$ROOT"
	printf '    \033[1;32mok\033[0m   removed %s\n' "$ROOT"
	printf '\033[1;32m==> GitDeploy uninstalled.\033[0m\n'
	rm -f -- "${BASH_SOURCE[0]}"
	exit 0
fi

SELF=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)/$(basename -- "${BASH_SOURCE[0]}")

# ---------------------------------------------------------------- args -----
PURGE=0
PURGE_FEATURES=0
for arg in "$@"; do
	case "$arg" in
		--purge)          PURGE=1 ;;
		--purge-features) PURGE_FEATURES=1 ;;
		-h|--help)
			cat <<-EOF
			Usage: uninstall.sh [--purge] [--purge-features]

			  (no flags)         remove cron, logrotate, symlinks, plugin
			                     registration and $ROOT.
			                     KEEPS $STATE, user workspaces and deploy keys.
			  --purge            additionally delete $STATE (and any leftover
			                     $OLD_STATE), every
			                     /home/<user>/.gitdeploy/<site> workspace and every
			                     GitDeploy deploy key. Irreversible.
			  --purge-features   additionally remove features.gitdeploy from
			                     $PLANS_DIR/*.

			Docroots are never touched, in any mode.
			EOF
			exit 0
			;;
		*) die "unknown option: $arg (try --help)" ;;
	esac
done

[[ $EUID -eq 0 ]] || die "uninstall.sh must run as root (EUID 0)."

say "Uninstalling GitDeploy (purge=$PURGE, purge-features=$PURGE_FEATURES)"

# -------------------------------------------------------- cron / logrotate -
say "Removing cron and logrotate rules"
for f in "$CRON_FILE" "$LOGROTATE_FILE"; do
	if [[ -e "$f" ]]; then rm -f "$f"; ok "removed $f"; else ok "$f already absent"; fi
done
# The runner log itself is left in place; logrotate no longer manages it.

# ---------------------------------------------------------------- symlinks -
say "Removing symlinks"
for l in "$LINK_PLUGIN" "$LINK_WEB" "$LINK_NGINX" "$LINK_WEB_ADMIN" "$LINK_NGINX_ADMIN"; do
	if [[ -L "$l" ]]; then
		rm -f "$l"; ok "removed $l"
	elif [[ -e "$l" ]]; then
		warn "$l exists but is NOT a symlink — leaving it alone (not ours to delete)"
	else
		ok "$l already absent"
	fi
done

# ------------------------------------------------------------------ nginx --
say "Validating and reloading nginx (EMPS)"
if [[ -x "$NGINX" ]]; then
	NGX_OUT=$(mktemp)
	if "$NGINX" -t -c "$NGINX_CONF" >"$NGX_OUT" 2>&1; then
		ok "nginx -t passed"
		if "$NGINX" -s reload >>"$NGX_OUT" 2>&1; then
			ok "nginx reloaded gracefully"
		else
			warn "nginx reload failed — reload manually: $NGINX -s reload"
			cat "$NGX_OUT" >&2
		fi
	else
		# Our drop-ins are already gone, so this failure is NOT ours. Do not
		# restore anything; just make it loud.
		warn "nginx -t FAILED after our symlinks were removed — this is a pre-existing"
		warn "problem in the Webuzo nginx config, not GitDeploy. NOT reloading."
		cat "$NGX_OUT" >&2
	fi
	rm -f "$NGX_OUT"
else
	warn "$NGINX not found — skipping nginx validation/reload"
fi

# --------------------------------------------------------- plugins.json ----
say "Deregistering plugin from $PLUGINS_JSON"
if [[ -f "$PLUGINS_JSON" ]]; then
	PJ_OWNER=$(stat -c '%U:%G' "$PLUGINS_JSON")
	PJ_MODE=$(stat -c '%a'    "$PLUGINS_JSON")

	set +e
	PJ_OUT=$("$PHP" -- "$PLUGINS_JSON" "$PLUGIN_KEY" <<'PHPEOF'
<?php
if(!defined("STDOUT")) define("STDOUT", fopen("php://stdout","w"));
if(!defined("STDERR")) define("STDERR", fopen("php://stderr","w"));
$path = $argv[1];
$key  = $argv[2];

$raw = @file_get_contents($path);
if($raw === false){ fwrite(STDERR, "cannot read $path\n"); exit(1); }

$data = json_decode($raw, true);
if(!is_array($data)){
	fwrite(STDERR, "REFUSING TO WRITE: $path is not valid JSON (".json_last_error_msg().").\n");
	exit(1);
}

if(!array_key_exists($key, $data)){ fwrite(STDOUT, "not registered\n"); exit(0); }

unset($data[$key]);   // foreign entries are preserved verbatim

$out = json_encode($data, JSON_PRETTY_PRINT);
if($out === false){ fwrite(STDERR, "json_encode failed\n"); exit(1); }

$tmp = $path.'.gitdeploy.tmp.'.getmypid();
// No trailing newline: matches Webuzo's own writer byte-for-byte.
if(@file_put_contents($tmp, $out) === false){ fwrite(STDERR, "cannot write $tmp\n"); exit(1); }
if(!@rename($tmp, $path)){ @unlink($tmp); fwrite(STDERR, "rename failed\n"); exit(1); }

fwrite(STDOUT, "deregistered\n"); exit(0);
PHPEOF
	)
	PJ_RC=$?
	set -e

	chown "$PJ_OWNER" "$PLUGINS_JSON"
	chmod "$PJ_MODE"  "$PLUGINS_JSON"

	if (( PJ_RC == 0 )); then
		ok "$PLUGIN_KEY: $PJ_OUT (ownership restored to $PJ_OWNER $PJ_MODE)"
	else
		warn "could not edit $PLUGINS_JSON — left untouched. Remove '$PLUGIN_KEY' by hand."
	fi
else
	ok "$PLUGINS_JSON absent — nothing to deregister"
fi

# ------------------------------------------------------- plan features -----
if (( PURGE_FEATURES )); then
	say "Removing the '$FEATURE_KEY' feature from plan files"
	if [[ ! -d "$PLANS_DIR" ]]; then
		warn "$PLANS_DIR missing — skipped"
	else
		shopt -s nullglob
		for PLAN in "$PLANS_DIR"/*; do
			[[ -f "$PLAN" ]] || continue
			case "$PLAN" in
				*.bak|*.tmp) continue ;;   # never edit backups
			esac

			PL_OWNER=$(stat -c '%U:%G' "$PLAN")
			PL_MODE=$(stat -c '%a'    "$PLAN")

			set +e
			PL_OUT=$("$PHP" -- "$PLAN" "$FEATURE_KEY" <<'PHPEOF'
<?php
if(!defined("STDOUT")) define("STDOUT", fopen("php://stdout","w"));
if(!defined("STDERR")) define("STDERR", fopen("php://stderr","w"));
$path = $argv[1];
$feat = $argv[2];

$raw = @file_get_contents($path);
if($raw === false){ fwrite(STDERR, "unreadable\n"); exit(2); }

$data = json_decode($raw, true);
if(!is_array($data)){ fwrite(STDERR, "not JSON\n"); exit(2); }

if(!isset($data['features']) || !is_array($data['features'])){
	fwrite(STDOUT, "no features map\n"); exit(3);
}
if(!array_key_exists($feat, $data['features'])){
	fwrite(STDOUT, "already absent\n"); exit(0);
}

unset($data['features'][$feat]);

$out = json_encode($data, JSON_PRETTY_PRINT);
if($out === false){ fwrite(STDERR, "json_encode failed\n"); exit(2); }

$tmp = $path.'.gitdeploy.tmp.'.getmypid();
if(@file_put_contents($tmp, $out) === false){ fwrite(STDERR, "cannot write tmp\n"); exit(2); }
if(!@rename($tmp, $path)){ @unlink($tmp); fwrite(STDERR, "rename failed\n"); exit(2); }

fwrite(STDOUT, "removed\n"); exit(0);
PHPEOF
			)
			PL_RC=$?
			set -e

			chown "$PL_OWNER" "$PLAN"
			chmod "$PL_MODE"  "$PLAN"

			case "$PL_RC" in
				0) ok "$(basename "$PLAN"): $PL_OUT" ;;
				3) ok  "$(basename "$PLAN"): no features map — skipped" ;;
				*) warn "$(basename "$PLAN"): not valid plan JSON — left untouched" ;;
			esac
		done
		shopt -u nullglob
	fi
	# install.sh's *.gitdeploy.bak snapshots are deliberately left behind.
	say "Plan backups (*.gitdeploy.bak) left in $PLANS_DIR — delete them yourself if unwanted"
fi

# ------------------------------------------------------------ purge state --
if (( PURGE )); then
	say "PURGE: removing deploy keys, workspaces and state"

	# A box that was never migrated still has its site list in the old tree.
	SITES_SRC=""
	if   [[ -d "$STATE/sites" ]];     then SITES_SRC="$STATE/sites"
	elif [[ -d "$OLD_STATE/sites" ]]; then SITES_SRC="$OLD_STATE/sites"
	fi

	if [[ -n "$SITES_SRC" ]]; then
		# Emit one validated "site<TAB>user<TAB>deploy_key" record per site file.
		# Anything that fails validation in PHP is simply not emitted, and bash
		# re-validates independently before any destructive call.
		set +e
		RECORDS=$("$PHP" -- "$SITES_SRC" <<'PHPEOF'
<?php
if(!defined("STDOUT")) define("STDOUT", fopen("php://stdout","w"));
if(!defined("STDERR")) define("STDERR", fopen("php://stderr","w"));
$dir = $argv[1];
foreach((glob($dir.'/*.json') ?: []) as $f){
	$cfg = json_decode(@file_get_contents($f), true);
	if(!is_array($cfg)) continue;

	$site = isset($cfg['site']) ? $cfg['site'] : '';
	$user = isset($cfg['user']) ? $cfg['user'] : '';
	$key  = isset($cfg['deploy_key']) ? $cfg['deploy_key'] : '';

	if(!is_string($site) || !preg_match('~^[a-z0-9][a-z0-9.-]{0,80}$~', $site)) continue;
	if(strpos($site, '..') !== false) continue;
	if(!is_string($user) || !preg_match('~^[a-z_][a-z0-9_-]{0,31}$~', $user)) continue;

	// Deploy key is optional (public repos are keyless).
	if(!is_string($key) || !preg_match('~^/home/[a-z0-9_-]+/\.ssh/gitdeploy-[A-Za-z0-9.-]+$~', $key)){
		$key = '';
	}

	echo $site."\t".$user."\t".$key."\n";
}
PHPEOF
		)
		set -e

		while IFS=$'\t' read -r SITE SITE_USER KEY; do
			[[ -n "$SITE" && -n "$SITE_USER" ]] || continue

			# --- independent bash-side re-validation ------------------------
			if [[ ! "$SITE" =~ ^[a-z0-9][a-z0-9.-]{0,80}$ ]]; then
				warn "skipping malformed site id"; continue
			fi
			if [[ "$SITE" == *".."* ]]; then
				warn "skipping site id containing '..'"; continue
			fi
			if [[ ! "$SITE_USER" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]]; then
				warn "skipping malformed user for $SITE"; continue
			fi

			# --- deploy key -------------------------------------------------
			if [[ -n "$KEY" ]]; then
				if [[ "$KEY" =~ ^/home/[a-z0-9_-]+/\.ssh/gitdeploy-[A-Za-z0-9.-]+$ ]]; then
					for K in "$KEY" "$KEY.pub"; do
						if [[ -f "$K" && ! -L "$K" ]]; then rm -f -- "$K"; ok "removed key $K"; fi
					done
				else
					warn "deploy_key for $SITE failed validation — NOT deleted: $KEY"
				fi
			fi

			# --- workspace --------------------------------------------------
			WS="/home/$SITE_USER/.gitdeploy/$SITE"
			WS_REAL=$(realpath -e -- "$WS" 2>/dev/null || true)
			if [[ -z "$WS_REAL" ]]; then
				ok "no workspace at $WS"
			elif [[ "$WS_REAL" == "$WS" ]]; then
				# realpath equality also proves no symlink anywhere in the path.
				rm -rf -- "$WS"
				ok "removed workspace $WS"
			else
				warn "workspace $WS resolves to $WS_REAL — refusing to delete"
			fi

			# Tidy the per-user parent only if we emptied it (rmdir fails if not).
			if rmdir -- "/home/$SITE_USER/.gitdeploy" 2>/dev/null; then
				ok "removed empty /home/$SITE_USER/.gitdeploy"
			fi
		done <<< "$RECORDS"
	else
		ok "no sites/ directory under $STATE or $OLD_STATE — no workspaces or keys to clean"
	fi

	# Both layouts: the current one and anything left behind by a partial migration.
	for D in "$STATE" "$OLD_STATE"; do
		if [[ -d "$D" ]]; then
			rm -rf -- "$D"
			ok "removed $D (site config, hookinfo, status, spool, locks, logs)"
		else
			ok "$D already absent"
		fi
	done
else
	say "Keeping site data"
	ok "$STATE left intact — re-running install.sh restores every site as-is"
	if [[ -d "$OLD_STATE" ]]; then
		ok "$OLD_STATE (pre-1.6.0 location) also left intact"
	fi
fi

# ---------------------------------------------------- remove the payload ---
say "Removing $ROOT"
if [[ ! -e "$ROOT" ]]; then
	ok "$ROOT already absent"
	printf '\033[1;32m==> GitDeploy uninstalled.\033[0m\n'
	exit 0
fi

if [[ "$SELF" == "$ROOT/"* ]]; then
	# We are executing from inside the directory we must delete: hand off to a
	# throwaway copy in /tmp that only performs the removal.
	STAGE2=$(mktemp /tmp/gitdeploy-uninstall.XXXXXXXX.sh)
	cp -- "$SELF" "$STAGE2"
	chmod 0700 "$STAGE2"
	ok "handing off to $STAGE2 to delete the directory this script lives in"
	exec env GD_FINAL_RM=1 bash "$STAGE2"
fi

rm -rf -- "$ROOT"
ok "removed $ROOT"
printf '\033[1;32m==> GitDeploy uninstalled.\033[0m\n'
