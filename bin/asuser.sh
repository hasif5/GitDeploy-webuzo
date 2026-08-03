#!/bin/bash
# GitDeploy — small privileged-drop helper. Invoked by the panel UI (root) through
# setpriv so that NOTHING root-owned ever touches a path inside a user's home.
# The site user owns their home, so a root chown/mkdir/rm there is a symlink-race
# waiting to happen; doing the work as the user removes the class of bug entirely.
#
# Usage (already running as the target user):
#   asuser.sh keygen <site>     -> create ~/.ssh/gitdeploy-<site> (ed25519) if absent, print path
#   asuser.sh keydel <site>     -> remove that key pair
#   asuser.sh rmwork <site>     -> remove ~/.gitdeploy/<site>
#   asuser.sh disk              -> READ-ONLY: print ~/.gitdeploy sizes in KiB as
#                                  total=/work=/releases=/toolchains= lines
set -euo pipefail
umask 077

op="${1:-}"
site="${2:-}"

# Every op except `disk` acts on one site; `disk` takes no argument at all and
# must never be given one (a stray second argument means a confused caller).
case "$op" in
	disk)
		[ -z "$site" ] || { echo "disk takes no arguments" >&2; exit 2; }
		;;
	*)
		# site ids are domain names; never allow traversal
		case "$site" in
			''|*/*|*..*|.*) echo "bad site id" >&2; exit 2;;
		esac
		printf '%s' "$site" | grep -Eq '^[a-z0-9][a-z0-9.-]{0,80}$' || { echo "bad site id" >&2; exit 2; }
		# Shared folders under ~/.gitdeploy that are not per-site workspaces. The caller
		# already skips these; refusing here too means a bug there cannot wipe the cache.
		case "$site" in toolchains) echo "refusing: reserved folder" >&2; exit 2;; esac
		;;
esac
[ "$(id -u)" -ge 1000 ] || { echo "refusing to run as a system user" >&2; exit 3; }
[ -n "${HOME:-}" ] && [ -d "$HOME" ] || { echo "no home directory" >&2; exit 3; }

KEY="$HOME/.ssh/gitdeploy-$site"

# KiB used by one directory, 0 when it is missing. -x stays on one filesystem and
# du never follows symlinks, so this cannot wander outside the home it is given.
du_kb() {
	local p=$1 v=''
	if [ -d "$p" ] && [ ! -L "$p" ]; then
		v=$(du -sxk -- "$p" 2>/dev/null | awk 'NR==1{print $1}') || v=''
	fi
	case "$v" in ''|*[!0-9]*) v=0;; esac
	printf '%s' "$v"
}

case "$op" in
	keygen)
		[ -L "$HOME/.ssh" ] && { echo "~/.ssh is a symlink" >&2; exit 4; }
		mkdir -p "$HOME/.ssh"
		chmod 700 "$HOME/.ssh"
		if [ -f "$KEY" ] && [ -f "$KEY.pub" ]; then
			printf '%s\n' "$KEY"; exit 0
		fi
		rm -f "$KEY" "$KEY.pub"
		ssh-keygen -q -t ed25519 -N '' -C "gitdeploy-$site" -f "$KEY"
		chmod 600 "$KEY"; chmod 644 "$KEY.pub"
		printf '%s\n' "$KEY"
		;;
	keydel)
		rm -f "$KEY" "$KEY.pub"
		;;
	rmwork)
		W="$HOME/.gitdeploy/$site"
		[ -L "$W" ] && { rm -f "$W"; exit 0; }
		if [ -d "$W" ]; then rm -rf --one-file-system "$W"; fi
		rmdir "$HOME/.gitdeploy" 2>/dev/null || true
		;;
	disk)
		# Read-only accounting for the server admin's overview page. Reads nothing,
		# writes nothing, and only ever prints four integers.
		GD="$HOME/.gitdeploy"
		total=0; toolchains=0; releases=0
		if [ -d "$GD" ] && [ ! -L "$GD" ]; then
			total=$(du_kb "$GD")
			toolchains=$(du_kb "$GD/toolchains")
			for d in "$GD"/*/releases; do
				if [ -d "$d" ] && [ ! -L "$d" ]; then
					releases=$(( releases + $(du_kb "$d") ))
				fi
			done
		fi
		# Whatever is left is the build workspaces (node_modules, checkouts, ...).
		work=$(( total - toolchains - releases ))
		if [ "$work" -lt 0 ]; then work=0; fi
		printf 'total=%s\nwork=%s\nreleases=%s\ntoolchains=%s\n' \
			"$total" "$work" "$releases" "$toolchains"
		;;
	*)
		echo "unknown op" >&2; exit 2;;
esac
