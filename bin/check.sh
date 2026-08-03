#!/bin/bash
# GitDeploy health check ("doctor"). Read-only: reports, never repairs.
# Run it directly or via: install.sh --check
# Exit 0 = everything healthy, 1 = at least one problem found.
set -uo pipefail

G=$'\033[1;32m'; R=$'\033[1;31m'; Y=$'\033[1;33m'; B=$'\033[1;34m'; N=$'\033[0m'
PROBLEMS=0
ok(){   printf '  %sok%s   %s\n' "$G" "$N" "$1"; }
bad(){  printf '  %sFAIL%s %s\n' "$R" "$N" "$1"; PROBLEMS=$((PROBLEMS+1)); }
warn(){ printf '  %swarn%s %s\n' "$Y" "$N" "$1"; }
head(){ printf '\n%s==>%s %s\n' "$B" "$N" "$1"; }

ROOT=/usr/local/gitdeploy
STATE=/var/lib/gitdeploy
OLD_STATE=/var/webuzo/conf/gitdeploy
PHP=/usr/local/emps/bin/php

printf '%sGitDeploy health check%s  (version %s)\n' "$B" "$N" "$(cat $ROOT/VERSION 2>/dev/null || echo '?')"

head "Required tools"
for t in git node rsync ssh-keygen /usr/bin/setpriv /usr/bin/setsid $PHP; do
	if command -v "$t" >/dev/null 2>&1 || [ -x "$t" ]; then ok "$t"; else bad "$t is missing"; fi
done
command -v /usr/sbin/sendmail >/dev/null 2>&1 || warn "sendmail not found — email notifications will not be delivered"

head "Package files"
for f in lib/common.php lib/ghapp.php bin/runner.php bin/build.sh bin/asuser.sh \
         web/enduser/index.php web/enduser/hook.php webuzo/plugin/gitdeploy.php \
         webuzo/enduser_locations_gitdeploy.conf conf/github_known_hosts; do
	[ -s "$ROOT/$f" ] && ok "$f" || bad "$ROOT/$f is missing or empty"
done
for f in lib/common.php lib/ghapp.php bin/runner.php web/enduser/index.php web/enduser/hook.php webuzo/plugin/gitdeploy.php; do
	$PHP -l "$ROOT/$f" >/dev/null 2>&1 || bad "$f has a PHP syntax error"
done
bash -n "$ROOT/bin/build.sh" 2>/dev/null || bad "bin/build.sh has a shell syntax error"
[ -x "$ROOT/bin/build.sh" ] && [ -x "$ROOT/bin/asuser.sh" ] || bad "bin/build.sh and bin/asuser.sh must be executable"

head "Panel integration"
chk_link(){ # <path> <expected target>
	if [ -L "$1" ]; then
		[ "$(readlink -f "$1")" = "$(readlink -f "$2")" ] && ok "$1" || bad "$1 points at $(readlink "$1") (expected $2)"
	else bad "$1 is missing (run install.sh)"; fi
}
chk_link /usr/local/webuzo/plugins/gitdeploy "$ROOT/webuzo/plugin"
chk_link /usr/local/webuzo/web/enduser/gitdeploy "$ROOT/web/enduser"
chk_link /usr/local/webuzo/conf/webuzo/emps/enduser_locations_gitdeploy.conf "$ROOT/webuzo/enduser_locations_gitdeploy.conf"

if $PHP -r '$j=json_decode(file_get_contents("/var/webuzo/plugins.json"),true); exit(isset($j["gitdeploy/gitdeploy.php"])?0:1);' 2>/dev/null; then
	ok "registered in /var/webuzo/plugins.json"
else bad "not registered in /var/webuzo/plugins.json (run install.sh --repair)"; fi

MISSING_PLANS=""
for p in /var/webuzo/plans/*; do
	[ -f "$p" ] || continue
	case "$p" in *.bak|*.tmp) continue;; esac
	$PHP -r '$j=json_decode(file_get_contents($argv[1]),true); exit(!empty($j["features"]["gitdeploy"])?0:1);' "$p" 2>/dev/null \
		|| MISSING_PLANS="$MISSING_PLANS $(basename "$p")"
done
[ -z "$MISSING_PLANS" ] && ok "enabled in all plans" || bad "plan feature missing in:$MISSING_PLANS (icon will not appear for those users)"

head "Web server"
if /usr/local/emps/sbin/nginx -t -c /usr/local/emps/etc/nginx/nginx.conf >/dev/null 2>&1; then ok "panel nginx config is valid"
else bad "panel nginx config test FAILED — run: /usr/local/emps/sbin/nginx -t -c /usr/local/emps/etc/nginx/nginx.conf"; fi
for s in /usr/local/emps/var/fpm-root.sock /usr/local/emps/var/fpm-webuzo.sock; do
	[ -S "$s" ] && ok "$(basename $s) present" || bad "$s missing — the panel pages cannot run"
done

head "State directory"
chk_mode(){ # <path> <expected mode> <expected owner:group>
	if [ ! -d "$1" ]; then bad "$1 is missing"; return; fi
	local m o
	m=$(stat -c '%a' "$1"); o=$(stat -c '%U:%G' "$1")
	if [ "$m" = "$2" ] && [ "$o" = "$3" ]; then ok "$1 ($o $m)"
	else bad "$1 is $o $m — expected $3 $2 (run install.sh --repair)"; fi
}
# Regression guard. Anything under /var/webuzo is rewritten to webuzo:webuzo 0770
# by Webuzo's own daily cron, which would hand the public webhook's uid write access
# to site configs and read access to the CSRF key. The state root must stay out of it.
case "$STATE" in
	/var/webuzo/*) bad "state root $STATE is under /var/webuzo — Webuzo's daily cron will reset it to webuzo:webuzo 0770 (see lib/common.php GD_STATE)" ;;
	*)             ok  "state root is outside /var/webuzo ($STATE)" ;;
esac

chk_mode "$STATE"          711 root:root
chk_mode "$STATE/sites"    700 root:root
chk_mode "$STATE/locks"    700 root:root
chk_mode "$STATE/logs"     700 root:root
chk_mode "$STATE/hookinfo" 750 root:webuzo
chk_mode "$STATE/status"   750 root:webuzo
chk_mode "$STATE/spool"    770 root:webuzo

# File half of the same model — the runner re-applies it via gd_enforce_state_perms().
chk_files(){ # <glob> <expected mode> <expected owner:group> <label>
	local bad_list="" n=0 f m o
	for f in $1; do
		[ -f "$f" ] || continue
		n=$((n+1))
		m=$(stat -c '%a' "$f"); o=$(stat -c '%U:%G' "$f")
		[ "$m" = "$2" ] && [ "$o" = "$3" ] || bad_list="$bad_list $(basename "$f")($o $m)"
	done
	if [ -n "$bad_list" ]; then
		bad "$4 with wrong permissions:$bad_list — expected $3 $2 (run install.sh --repair)"
	elif [ "$n" -eq 0 ]; then ok "$4: none present"
	else ok "$4: $n file(s), all $3 $2"; fi
}
chk_files "$STATE/sites/*.json"    600 root:root   "site configs"
chk_files "$STATE/hookinfo/*.json" 640 root:webuzo "webhook info files"
chk_files "$STATE/status/*.json"   640 root:webuzo "status files"
chk_files "$STATE/ghapp-*.json"    600 root:root   "GitHub App credentials"

if [ -s "$STATE/.csrf_key" ]; then
	chk_files "$STATE/.csrf_key" 600 root:root "CSRF key"
else bad "$STATE/.csrf_key missing (run install.sh --repair)"; fi

# Leftovers from the pre-/var/lib layout: harmless but confusing, and a second copy
# of site configs sitting in a webuzo-writable directory is worth being loud about.
if [ -d "$OLD_STATE" ]; then
	if [ -n "$(ls -A "$OLD_STATE" 2>/dev/null)" ]; then
		warn "$OLD_STATE still has content — stale state from before the move to $STATE. Run install.sh --repair to migrate it, then remove the directory."
	else
		warn "$OLD_STATE still exists but is empty — safe to remove: rmdir $OLD_STATE"
	fi
fi

head "Scheduler"
if [ -f /etc/cron.d/gitdeploy ]; then
	ok "/etc/cron.d/gitdeploy installed"
	tail -c1 /etc/cron.d/gitdeploy | od -c | grep -q '\\n' || warn "cron file has no trailing newline — cron may ignore it"
else bad "/etc/cron.d/gitdeploy missing — nothing will ever deploy"; fi
[ -f /etc/logrotate.d/gitdeploy ] && ok "logrotate rule installed" || warn "no logrotate rule for /var/webuzo/logs/gitdeploy"
if [ -f "$STATE/.heartbeat" ]; then
	AGE=$(( $(date +%s) - $(stat -c %Y "$STATE/.heartbeat") ))
	if [ "$AGE" -lt 180 ]; then ok "runner last ran ${AGE}s ago"
	else bad "runner has not run for ${AGE}s — check cron: systemctl status crond"; fi
else
	warn "no heartbeat yet — the runner has not run since this version was installed (wait a minute)"
fi

head "Sites"
NS=$(ls -1 "$STATE"/sites/*.json 2>/dev/null | wc -l)
ok "$NS site(s) configured"
for f in "$STATE"/sites/*.json; do
	[ -f "$f" ] || continue
	SITE=$(basename "$f" .json)
	$PHP -r '
		require "/usr/local/gitdeploy/lib/common.php";
		$s = $argv[1];
		$cfg = gd_load_site($s);
		if(!$cfg){ echo "BAD|config is invalid or unreadable\n"; exit; }
		if(!is_file(GD_HOOKINFO."/$s.json")) echo "BAD|webhook info missing (pushes will not deploy)\n";
		$doc = gd_resolve_docroot($cfg["user"], $s);
		if(!$doc){ echo "BAD|domain no longer exists in Webuzo\n"; exit; }
		$risk = gd_docroot_risk($cfg["user"], $s);
		if($risk && (!empty($risk["shared_with"]) || $risk["is_home_docroot"]) && empty($cfg["allow_shared_docroot"]))
			echo "BAD|website folder is shared with other domains — deploys are refused\n";
		if(!empty($cfg["gh_installation_id"])){
			require_once GD_BASE."/lib/ghapp.php";
			if(ghapp_load($cfg["user"]) === null) echo "BAD|uses a GitHub App but none is connected for user ".$cfg["user"]."\n";
		}elseif(!empty($cfg["deploy_key"]) && !is_file($cfg["deploy_key"])){
			echo "BAD|deploy key file is missing\n";
		}
	' "$SITE" 2>/dev/null | while IFS='|' read -r kind msg; do
		[ "$kind" = "BAD" ] && bad "$SITE: $msg"
	done
done

head "Connectivity"
if timeout 8 curl -s -o /dev/null -w '%{http_code}' https://api.github.com/zen | grep -q '^200$'; then ok "api.github.com reachable"
else warn "cannot reach api.github.com — GitHub App features will not work"; fi

printf '\n'
if [ "$PROBLEMS" -eq 0 ]; then printf '%sAll checks passed.%s\n' "$G" "$N"; exit 0
else printf '%s%d problem(s) found.%s Most are fixed by: %s/install.sh --repair\n' "$R" "$PROBLEMS" "$N" "$ROOT"; exit 1; fi
