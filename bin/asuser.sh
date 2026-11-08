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
#   asuser.sh relsize <site>    -> READ-ONLY: print releases=<KiB> for that site
#   asuser.sh reldel <site> <sha> <live-sha|->
#                               -> remove one saved release (never the live one)
#   asuser.sh relprune <site> <keep> <live-sha|->
#                               -> keep the <keep> newest releases (the live one always
#                                  stays and counts toward <keep>), remove the rest
set -euo pipefail
umask 077

op="${1:-}"
site="${2:-}"
arg3="${3:-}"
arg4="${4:-}"

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

is_sha() { printf '%s' "$1" | grep -Eqx '[0-9a-f]{40}'; }

# ~/.gitdeploy/<site>/releases, refusing symlinks on the way (we run as the
# user, so this is about not being steered somewhere unexpected, not privilege).
rel_dir() {
	local g="$HOME/.gitdeploy" w="$HOME/.gitdeploy/$site" r="$HOME/.gitdeploy/$site/releases"
	if [ -L "$g" ] || [ -L "$w" ] || [ -L "$r" ]; then echo "symlinked workspace" >&2; exit 4; fi
	printf '%s' "$r"
}

# The live release: the panel passes the one it knows, the workspace has its own
# record. Both are protected, so a disagreement can only ever keep more.
live_ok() {   # $1 = candidate sha; returns 0 if it must be kept
	local c=$1 f="$HOME/.gitdeploy/$site/last_ok_sha" disk=''
	[ "$arg4" != "-" ] && [ "$c" = "$arg4" ] && return 0
	if [ -f "$f" ] && [ ! -L "$f" ]; then disk=$(head -c 41 "$f" | tr -d '\n'); fi
	[ -n "$disk" ] && [ "$c" = "$disk" ] && return 0
	return 1
}

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
	relsize)
		R=$(rel_dir)
		printf 'releases=%s\n' "$(du_kb "$R")"
		;;
	reldel)
		is_sha "$arg3" || { echo "bad release id" >&2; exit 2; }
		[ "$arg4" = "-" ] || is_sha "$arg4" || { echo "bad live id" >&2; exit 2; }
		live_ok "$arg3" && { echo "refusing: that version is live" >&2; exit 5; }
		R=$(rel_dir)
		[ -d "$R/$arg3" ] && [ ! -L "$R/$arg3" ] || { echo "no such release" >&2; exit 6; }
		rm -rf --one-file-system -- "$R/$arg3"
		echo "deleted $arg3"
		;;
	relprune)
		case "$arg3" in ''|*[!0-9]*) echo "bad keep count" >&2; exit 2;; esac
		[ "$arg3" -ge 1 ] && [ "$arg3" -le 30 ] || { echo "bad keep count" >&2; exit 2; }
		[ "$arg4" = "-" ] || is_sha "$arg4" || { echo "bad live id" >&2; exit 2; }
		R=$(rel_dir)
		[ -d "$R" ] || exit 0
		# newest first; the live release always stays and uses up one of the slots
		left=$arg3
		for d in $(ls -1t -- "$R"); do
			is_sha "$d" && [ -d "$R/$d" ] && [ ! -L "$R/$d" ] || continue
			if live_ok "$d"; then left=$((left - 1)); fi
		done
		for d in $(ls -1t -- "$R"); do
			is_sha "$d" && [ -d "$R/$d" ] && [ ! -L "$R/$d" ] || continue
			live_ok "$d" && continue
			if [ "$left" -gt 0 ]; then left=$((left - 1)); continue; fi
			rm -rf --one-file-system -- "$R/$d"
			echo "pruned $d"
		done
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
