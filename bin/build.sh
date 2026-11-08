#!/bin/bash
# GitDeploy build script. Runs AS THE SITE USER (via setpriv from runner.php).
# Env contract (set by runner): HOME USER PATH GD_SITE GD_REPO GD_BRANCH GD_DEPLOY_MODE
#   GD_BUILD_CMD GD_OUTPUT_DIR GD_DOCROOT GD_KEY GD_ROOT_DIR GD_POST_CMD
#   GD_NODE_VERSION  plus GD_ENV_* user vars.
# GD_BUILD_CMD / GD_OUTPUT_DIR may be empty, which means "work it out from the repo".
set -euo pipefail
umask 022

echo "== GitDeploy $(date -u '+%Y-%m-%d %H:%M:%S UTC') site=$GD_SITE user=$(id -un) uid=$(id -u)"

GD_BIN=/usr/local/gitdeploy/bin
WORK="$HOME/.gitdeploy/$GD_SITE"
REPO="$WORK/repo"
TOOLROOT="$HOME/.gitdeploy/toolchains"
# The panel setting outranks anything the repository asks for, so remember it
# before .gitdeploy.json gets a chance to set GD_NODE_VERSION.
PANEL_NODE_VERSION="${GD_NODE_VERSION:-}"
readonly GD_BIN TOOLROOT PANEL_NODE_VERSION
export npm_config_fund=false npm_config_audit=false CI=true
case "$GD_REPO" in
	git@github.com:*)
		export GIT_SSH_COMMAND="ssh -i $GD_KEY -o IdentitiesOnly=yes -o UserKnownHostsFile=/usr/local/gitdeploy/conf/github_known_hosts -o StrictHostKeyChecking=yes"
		[ -r "$GD_KEY" ] || { echo "FATAL: deploy key unreadable"; exit 10; }
		;;
esac

# GitHub App clone auth: credential helper reads the installation token from the
# environment at fetch time — the token never appears in URLs, argv or logs.
if [ -n "${GD_TOKEN:-}" ]; then
	export GIT_CONFIG_COUNT=1
	export GIT_CONFIG_KEY_0=credential.helper
	export GIT_CONFIG_VALUE_0='!f(){ echo username=x-access-token; echo "password=$GD_TOKEN"; }; f'
	export GIT_TERMINAL_PROMPT=0
fi

# Defense in depth — root already validated all of these.
case "$GD_DOCROOT" in "$HOME"/*) ;; *) echo "FATAL: docroot outside \$HOME"; exit 10;; esac
# Empty means "work it out from the repo", so this guard only rejects bad shapes.
case "${GD_OUTPUT_DIR:-}" in /*|*..*) echo "FATAL: bad output dir"; exit 10;; esac
[ -d "$GD_DOCROOT" ] || { echo "FATAL: docroot missing — create the domain in Webuzo first"; exit 10; }

# Snapshot every security-relevant value BEFORE any repo/panel env import can run.
# Imported env vars must never be able to redirect where we publish (a repo could
# otherwise set GD_DOCROOT and rsync --delete-after over another site's docroot).
PUBLISH_ROOT="$GD_DOCROOT"
PUBLISH_HOME="$HOME"
# build = install + build + mirror the output (the folder becomes an exact copy).
# pull  = publish the repository's tracked files as they are, git-pull style.
DEPLOY_MODE="${GD_DEPLOY_MODE:-build}"
case "$DEPLOY_MODE" in build|pull) ;; *) echo "FATAL: unknown deploy mode"; exit 10;; esac
readonly PUBLISH_ROOT PUBLISH_HOME DEPLOY_MODE

# A website folder that is itself a git checkout is being deployed some other way
# (someone runs "git pull" in it). Two tools writing one folder fight each other,
# and a build-mode mirror would delete the .git folder outright. Refuse both.
if [ -e "$PUBLISH_ROOT/.git" ] || [ -L "$PUBLISH_ROOT/.git" ]; then
	echo "FATAL: the website folder contains a .git folder — it is already a git checkout."
	echo "       GitDeploy will not write into it: deploying there with git as well would conflict,"
	echo "       and a .git folder inside a website can expose your source code to visitors."
	echo "       Move it out of the website folder (e.g. mv $PUBLISH_ROOT/.git ~/old-site.git) and deploy again."
	exit 10
fi

# Env names that must never be imported from a repo or from panel env settings.
gd_env_blocked(){
	case "$1" in
		GD_*|HOME|USER|LOGNAME|PATH|IFS|SHELL|BASH_ENV|ENV|LD_*|GIT_SSH*|GIT_CONFIG*|GIT_DIR|GIT_WORK_TREE|GIT_INDEX_FILE|GIT_ALTERNATE*|GIT_EXEC_PATH|GIT_TERMINAL_PROMPT|PUBLISH_*) return 0;;
		*) return 1;;
	esac
}

mkdir -p "$WORK"

# Expose GD_ENV_FOO=bar to the build as FOO=bar (called after repo env, so panel wins)
gd_export_panel_env(){
	local v
	while IFS= read -r v; do
		[ -n "$v" ] || continue
		if gd_env_blocked "${v#GD_ENV_}"; then echo "   env (panel): ${v%%=*} REFUSED (reserved name)"; continue; fi
		export "${v#GD_ENV_}"
	done < <(env | grep '^GD_ENV_' || true)
}

# Keep the GD_KEEP_RELEASES most recent releases (site setting, 1-30, default 5),
# never pruning the one just published
gd_prune_releases(){
	local keep="$1" n="${GD_KEEP_RELEASES:-5}" old
	case "$n" in ''|*[!0-9]*) n=5;; esac
	if [ "$n" -lt 1 ] || [ "$n" -gt 30 ]; then n=5; fi
	ls -1t "$WORK/releases" 2>/dev/null | grep -v '\.new$' | tail -n +$((n + 1)) | while IFS= read -r old; do
		[ "$old" != "$keep" ] && rm -rf "$WORK/releases/$old" && echo "   pruned old release $old"
	done
}

# ----------------------------------------------------------- files-only mode
# What the website folder is known to hold from us: a NUL-separated list of the
# paths the last files-only publish wrote. Anything not on it (uploads, caches,
# .env, config the app writes for itself) was never ours and is never touched.
PULL_LIST="$WORK/published.list"

# ------------------------------------------------- panel-managed .env (optional)
# Files-only sites can have the panel's environment variables written into the
# website folder's .env. Only a marked block is ours; every other line in the
# file (keys added by hand on the server) is kept exactly as it is.
DOTENV_BEGIN='# >>> GitDeploy managed — edit these in the GitDeploy panel; changes made here are overwritten on deploy'
DOTENV_END='# <<< GitDeploy managed'

# Quote a value the way a person would write it, so parse_ini_file() and
# vlucas/phpdotenv both read it back unchanged (verified against both).
gd_env_quote(){
	local v="$1"
	if [[ "$v" =~ ^[A-Za-z0-9_./:@+,-]*$ ]]; then printf '%s' "$v"
	elif [[ "$v" != *"'"* ]]; then printf "'%s'" "$v"
	else v=${v//\\/\\\\}; v=${v//\"/\\\"}; printf '"%s"' "$v"; fi
}

# The managed variables as sorted "KEY<TAB>VALUE" lines.
gd_dotenv_vars(){
	local line k
	while IFS= read -r line; do
		k=${line%%=*}; k=${k#GD_ENV_}
		[[ "$k" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] || continue
		printf '%s\t%s\n' "$k" "$(printenv "GD_ENV_$k")"
	done < <(env | grep -E '^GD_ENV_[A-Za-z_][A-Za-z0-9_]*=' | sort || true)
}

# Variable names assigned in a .env file (stdin), one per line, in file order.
# Understands "KEY=", "KEY =", "export KEY=", leading spaces and CRLF endings.
gd_env_file_keys(){
	awk '{ line = $0; sub(/\r$/, "", line); sub(/^[ \t]*(export[ \t]+)?/, "", line)
		if(match(line, /^[A-Za-z_][A-Za-z0-9_]*[ \t]*=/)){ k = substr(line, 1, RLENGTH); sub(/[ \t]*=$/, "", k); print k } }'
}

# Write/update the managed block of $PUBLISH_ROOT/.env. $1 = backup dir for the
# old file; $2 = "dry" to only report. Values are never printed — names only.
gd_write_dotenv(){
	[ "${GD_DOTENV:-}" = 1 ] || return 0
	local bdir="$1" dry="${2:-}" f="$PUBLISH_ROOT/.env" tmp newf='' keys='' k v line oldblock
	local -a added=() changed=() removed=() adopted=()
	if [ -L "$f" ] || { [ -e "$f" ] && [ ! -f "$f" ]; }; then
		echo "FATAL: .env in the website folder is a symlink or not a regular file — refusing to write through it"
		exit 11
	fi
	while IFS=$'\t' read -r k v; do
		if [[ "$v" == *"'"* && "$v" == *'${'* ]]; then
			echo "   WARN: .env: $k skipped — its value contains both ' and \${, which .env readers cannot store safely"
			continue
		fi
		keys+="$k"$'\n'
		newf+="$k=$(gd_env_quote "$v")"$'\n'
	done < <(gd_dotenv_vars)
	# nothing to manage and no file yet: do not create an empty .env
	[ -z "$keys" ] && [ ! -f "$f" ] && return 0

	# Old managed block (KEY=line per key) and the lines outside it.
	oldblock=$(awk -v b="$DOTENV_BEGIN" -v e="$DOTENV_END" '{ sub(/\r$/, "") } $0==b{inb=1; next} $0==e{inb=0; next} inb' "$f" 2>/dev/null || true)
	# staged in the private work dir (same filesystem), so a crash never leaves
	# a stray copy of the secrets inside the website folder
	tmp=$(mktemp "$WORK/.env.gitdeploy.XXXXXX")
	# Keep everything outside our block, minus hand-written lines for a key the
	# panel now manages (a duplicate key means two readers could disagree).
	if [ -f "$f" ]; then
		awk -v b="$DOTENV_BEGIN" -v e="$DOTENV_END" -v keys="$keys" '
			BEGIN { n = split(keys, a, "\n"); for(i = 1; i <= n; i++) if(a[i] != "") m[a[i]] = 1 }
			{ line = $0; sub(/\r$/, "", line) }
			line == b { inb = 1; next }
			line == e { inb = 0; next }
			inb { next }
			{ k = line; sub(/^[ \t]*(export[ \t]+)?/, "", k); if(match(k, /^[A-Za-z_][A-Za-z0-9_]*[ \t]*=/)){ k = substr(k, 1, RLENGTH); sub(/[ \t]*=$/, "", k); if(k in m){ print k > "/dev/stderr"; next } } }
			{ print $0 }' "$f" > "$tmp" 2> "$tmp.adopted"
		while IFS= read -r k; do [ -n "$k" ] && adopted+=("$k"); done < "$tmp.adopted"
		rm -f "$tmp.adopted"
		# drop trailing blank lines so the block does not drift down the file
		sed -i -e ':a' -e '/^\n*$/{$d;N;ba' -e '}' "$tmp"
	fi
	# Duplicate checks on what is being kept. A name assigned twice is read
	# differently by different .env readers (parse_ini_file keeps the LAST value,
	# phpdotenv the FIRST), so say so loudly — but leave the owner's lines alone:
	# the site works with them today and guessing which one is meant could break it.
	local handkeys dup
	handkeys=$(gd_env_file_keys < "$tmp")
	dup=$(sort <<< "$handkeys" | uniq -d | grep . | tr '\n' ' ' || true)
	[ -n "$dup" ] && echo "   WARN: .env: set more than once in the lines you wrote by hand: ${dup}— different PHP libraries pick different values; remove the extra line(s)"
	# Same name in different case is a different variable to PHP — likely a typo.
	while IFS= read -r k; do
		[ -n "$k" ] || continue
		dup=$(grep -ixF -- "$k" <<< "$handkeys" | grep -vxF -- "$k" | sort -u | tr '\n' ' ' || true)
		[ -n "$dup" ] && echo "   WARN: .env: the panel sets $k and a hand-written line sets ${dup}— these are two different variables; check the spelling"
	done <<< "$keys"

	if [ -n "$keys" ]; then
		[ -s "$tmp" ] && echo >> "$tmp"
		{ echo "$DOTENV_BEGIN"; printf '%s' "$newf"; echo "$DOTENV_END"; } >> "$tmp"
	fi

	while IFS= read -r k; do
		[ -n "$k" ] || continue
		line=$(grep -m1 -E "^$k=" <<< "$newf" || true)
		if ! grep -qE "^$k=" <<< "$oldblock"; then added+=("$k")
		elif [ "$(grep -m1 -E "^$k=" <<< "$oldblock")" != "$line" ]; then changed+=("$k"); fi
	done <<< "$keys"
	while IFS= read -r line; do
		k=${line%%=*}; [ -n "$k" ] || continue
		grep -qxF "$k" <<< "$keys" || removed+=("$k")
	done <<< "$oldblock"

	# Last line of defence: no name the panel manages may appear twice in the file
	# about to be installed. If it does, keep the current .env and stop.
	dup=$(gd_env_file_keys < "$tmp" | sort | uniq -d | grep -xF -f <(grep . <<< "$keys") | tr '\n' ' ' || true)
	if [ -n "$dup" ]; then
		rm -f "$tmp"
		echo "FATAL: .env: would contain $dup more than once — the current .env was left untouched and nothing was deployed"
		exit 11
	fi
	if [ -f "$f" ] && cmp -s "$tmp" "$f"; then
		rm -f "$tmp"
		echo "== .env: up to date ($(grep -c . <<< "$keys" || true) variable(s) managed by the panel)"
		return 0
	fi
	if [ "$dry" = dry ]; then
		rm -f "$tmp"
		echo "== .env: would be $([ -f "$f" ] && echo updated || echo created) with $(grep -c . <<< "$keys" || true) variable(s) from the panel"
	else
		if [ -f "$f" ]; then
			mkdir -p "$bdir" && cp -p -- "$f" "$bdir/.env" && chmod 600 "$bdir/.env"
			chmod --reference="$f" "$tmp" 2>/dev/null || chmod 600 "$tmp"
		else
			chmod 600 "$tmp"
		fi
		mv -f -- "$tmp" "$f"
		echo "== .env: $([ -n "$oldblock$(cat "$bdir/.env" 2>/dev/null | head -c1)" ] && echo updated || echo created) from the panel ($(grep -c . <<< "$keys" || true) variable(s), values not shown)"
	fi
	[ ${#added[@]}   -gt 0 ] && echo "   added:   ${added[*]}"
	[ ${#changed[@]} -gt 0 ] && echo "   changed: ${changed[*]}"
	[ ${#removed[@]} -gt 0 ] && echo "   removed: ${removed[*]} (no longer set in the panel)"
	[ ${#adopted[@]} -gt 0 ] && echo "   now managed by the panel (the hand-written line was replaced): ${adopted[*]}"
	return 0
}

# Publish release dir $1 into the website folder with git-pull semantics:
#  - files the repository tracks are added or updated (never mirrored with --delete);
#  - files we published last time that the repository no longer has are removed,
#    but ONLY if they are still byte-identical to what we published — a file that
#    was edited on the server is left in place and reported, just as git refuses
#    to throw away local changes;
#  - .htaccess is updated when the repository has one but never deleted.
gd_pull_publish(){
	local rel="$1" prev_sha prev_rel newl f p parent real d removed=0 kept=0
	newl="$WORK/.published.list.new"
	( cd "$rel" && find . -mindepth 1 \( -type f -o -type l \) -printf '%P\0' ) | sort -z > "$newl"
	if [ ! -s "$newl" ]; then
		rm -f "$newl"
		echo "FATAL: this commit has no files to publish — refusing to touch the website folder"
		exit 11
	fi

	prev_sha=$(cat "$WORK/last_ok_sha" 2>/dev/null || true)
	case "$prev_sha" in *[!0-9a-f]*|"") prev_sha='';; esac
	prev_rel=''
	[ -n "$prev_sha" ] && [ -d "$WORK/releases/$prev_sha" ] && prev_rel="$WORK/releases/$prev_sha"

	echo "== Publishing to $PUBLISH_ROOT (files only — nothing outside the repository is touched)"
	local out="$WORK/.publish.out" rc=0 n bdir nb=0
	# The repository wins for files it tracks. Before a live file is replaced, keep
	# a copy of it in ~/.gitdeploy/<site>/backups/<time>/ — unless it is exactly
	# what the previous deploy published (that copy already sits in its release).
	# So: every live file a deploy overwrites is recoverable, whoever made it.
	bdir="$WORK/backups/$(date -u +%Y%m%d-%H%M%S)-$$"   # pid: two deploys in one second never share a folder
	# .env first, so new code never runs before the settings it expects exist
	gd_write_dotenv "$bdir"
	while IFS= read -r f; do
		p="$PUBLISH_ROOT/$f"
		{ [ -f "$p" ] || [ -L "$p" ]; } || continue
		parent=$(dirname -- "$p")
		real=$(cd -- "$parent" 2>/dev/null && pwd -P) || continue
		case "$real/" in "$PUBLISH_ROOT"/*) ;; *) continue;; esac
		if [ -n "$prev_rel" ]; then
			if [ -L "$p" ]; then
				[ -L "$prev_rel/$f" ] && [ "$(readlink -- "$p")" = "$(readlink -- "$prev_rel/$f")" ] && continue
			elif [ -f "$prev_rel/$f" ] && [ ! -L "$prev_rel/$f" ] && cmp -s -- "$p" "$prev_rel/$f"; then
				continue
			fi
			echo "   WARN: $f was changed on the server — replaced with the repository's version (old copy backed up)"
		fi
		mkdir -p -- "$bdir/$(dirname -- "$f")"
		cp -P --preserve=mode,timestamps -- "$p" "$bdir/$f" && nb=$((nb + 1))
	done < <(rsync -rlcn --out-format='%n' "$rel"/ "$PUBLISH_ROOT"/ 2>/dev/null | grep -v '/$' || true)
	if [ "$nb" -gt 0 ]; then
		echo "   backed up $nb live file(s) before replacing them: ~/.gitdeploy/$GD_SITE/backups/${bdir##*/}/"
		# keep the 10 most recent backups
		ls -1t "$WORK/backups" 2>/dev/null | tail -n +11 | while IFS= read -r old; do
			case "$old" in ''|*/*|.*) continue;; esac
			rm -rf -- "$WORK/backups/$old"
		done
	fi
	rsync -rlc --out-format='%n' "$rel"/ "$PUBLISH_ROOT"/ > "$out" || rc=$?
	n=$(grep -cv '/$' "$out" || true)
	grep -v '/$' "$out" | head -n 200 | sed 's/^/   updated /' || true
	[ "$n" -gt 200 ] && echo "   ... and $((n - 200)) more"
	echo "   $n file(s) added or changed"
	rm -f "$out"
	[ "$rc" -eq 0 ] || { echo "FATAL: copying files into the website folder failed (rsync exit $rc)"; exit 11; }

	if [ -f "$PULL_LIST" ]; then
		while IFS= read -r -d '' f; do
			case "$f" in ''|/*|..|../*|*/..|*/../*) continue;; esac
			case "$f" in .htaccess) echo "   kept .htaccess (no longer in the repository, but never deleted)"; continue;; esac
			p="$PUBLISH_ROOT/$f"
			[ -L "$p" ] || [ -f "$p" ] || continue
			# never follow a symlinked folder out of the website folder
			parent=$(dirname -- "$p")
			real=$(cd -- "$parent" 2>/dev/null && pwd -P) || continue
			case "$real/" in "$PUBLISH_ROOT"/*) ;; *) continue;; esac
			# only remove what is provably still our own, unmodified copy
			if [ -z "$prev_rel" ]; then
				echo "   kept $f (cannot verify it is unchanged — previous release was pruned)"; kept=$((kept + 1)); continue
			fi
			if [ -L "$p" ]; then
				[ -L "$prev_rel/$f" ] && [ "$(readlink -- "$p")" = "$(readlink -- "$prev_rel/$f")" ] \
					|| { echo "   kept $f (changed on the server since the last deploy)"; kept=$((kept + 1)); continue; }
			elif ! cmp -s -- "$p" "$prev_rel/$f"; then
				echo "   kept $f (changed on the server since the last deploy)"; kept=$((kept + 1)); continue
			fi
			rm -f -- "$p" && { echo "   removed $f"; removed=$((removed + 1)); }
			# tidy folders that are now empty and no longer exist in the repository
			d=$(dirname -- "$f")
			while [ "$d" != "." ] && [ ! -d "$rel/$d" ]; do
				rmdir -- "$PUBLISH_ROOT/$d" 2>/dev/null || break
				d=$(dirname -- "$d")
			done
		done < <(comm -z -23 <(sort -z "$PULL_LIST") "$newl")
	else
		echo "   first files-only deploy: nothing is removed from the website folder this time"
	fi
	[ "$removed" -gt 0 ] && echo "   removed $removed file(s) the repository no longer has"
	[ "$kept" -gt 0 ] && echo "   WARN: $kept file(s) were left in place — see above"
	mv -f "$newl" "$PULL_LIST"
}

# Rollback mode: publish a previously built release, no git/npm involved.
if [ "${GD_MODE:-}" = "rollback" ]; then
	case "${GD_SHA:-}" in
		*[!0-9a-f]*|"") echo "FATAL: rollback needs a valid commit sha"; exit 12;;
	esac
	REL="$WORK/releases/$GD_SHA"
	[ -d "$REL" ] && [ -n "$(ls -A "$REL")" ] || { echo "FATAL: release $GD_SHA not found (it may have been pruned)"; exit 12; }
	echo "== Rolling back to release $GD_SHA -> $PUBLISH_ROOT"
	if [ "$DEPLOY_MODE" = pull ]; then
		gd_pull_publish "$REL"
	else
		rsync -rlc --delete-after \
			--exclude '/.well-known/' --exclude '/cgi-bin/' --exclude '/.htaccess' \
			"$REL"/ "$PUBLISH_ROOT"/
		rm -f "$PULL_LIST"
	fi
	echo "$GD_SHA" > "$WORK/last_ok_sha"
	echo "== Rolled back to $GD_SHA at $PUBLISH_ROOT"
	exit 0
fi

echo "== Fetching $GD_REPO ($GD_BRANCH)"
if [ ! -d "$REPO/.git" ]; then
	# a dir without .git is the corpse of an interrupted clone — clear it or clone fails
	[ -e "$REPO" ] && { echo "   clearing incomplete workspace"; rm -rf "$REPO"; }
	git clone --branch "$GD_BRANCH" --single-branch "$GD_REPO" "$REPO"
else
	git -C "$REPO" remote set-url origin "$GD_REPO"
	git -C "$REPO" fetch --no-tags origin "+refs/heads/$GD_BRANCH:refs/remotes/origin/$GD_BRANCH"
	git -C "$REPO" checkout -f -B "$GD_BRANCH" "origin/$GD_BRANCH"
	git -C "$REPO" clean -ffdx -e node_modules -e .npm
fi
SHA=$(git -C "$REPO" rev-parse HEAD)
echo "== Building commit $SHA"
# One line each, control characters stripped, bounded — the panel labels builds with these.
echo "== Commit: $(git -C "$REPO" log -1 --format=%s HEAD 2>/dev/null | tr -d '\000-\037\177' | cut -c1-200)"
echo "== Author: $(git -C "$REPO" log -1 --format='%an, %cd' --date=format:'%Y-%m-%d %H:%M' HEAD 2>/dev/null | tr -d '\000-\037\177' | cut -c1-120)"

# Submodules (if any) — same auth (deploy key / app token) applies
if [ -f "$REPO/.gitmodules" ]; then
	echo "== Updating submodules"
	git -C "$REPO" submodule sync --recursive
	timeout 300 git -C "$REPO" submodule update --init --recursive
fi

# Files-only mode (PHP and other sites served straight from source): no Node, no
# install, no build, no .gitdeploy.json — publish exactly what git tracks.
if [ "$DEPLOY_MODE" = pull ]; then
	SRC="$REPO"
	if [ -n "${GD_ROOT_DIR:-}" ]; then
		case "$GD_ROOT_DIR" in /*|*..*) echo "FATAL: publish folder must be a relative path inside the repository"; exit 10;; esac
		SRC="$REPO/$GD_ROOT_DIR"
		[ -d "$SRC" ] || { echo "FATAL: folder '$GD_ROOT_DIR' does not exist in this repository"; exit 10; }
		echo "== Publishing only the folder: $GD_ROOT_DIR"
	fi
	echo "== Files only: no build step, publishing the repository as it is"

	# Copy the repository's tracked files into $1. Never shipped: git/CI tooling,
	# and paths that belong to the server (.env holds the live secrets;
	# .well-known/cgi-bin are the panel's). Filtered from the list itself because
	# rsync refuses --exclude'd names inside --files-from. Extra args go to rsync.
	gd_pull_stage(){
		local dest="$1"; shift
		git -C "$SRC" ls-files -z --recurse-submodules \
		| { grep -zvE '^(\.git(/|$)|\.github/|\.gitdeploy\.json$|\.gitignore$|\.gitattributes$|\.gitmodules$|\.env$|\.well-known/|cgi-bin/)' || true; } \
		| rsync -rlc --from0 --files-from=- "$@" "$SRC"/ "$dest"/
	}

	# First files-only deploy into a folder that already holds a site: change
	# nothing, report exactly what WOULD happen, and wait for the owner's OK in the
	# panel (which re-queues the job with GD_CONFIRM_SHA = this commit). A newer
	# push in between means a new preview, so what gets deployed is what was read.
	if [ ! -f "$PULL_LIST" ] && [ "${GD_CONFIRM_SHA:-}" != "$SHA" ] \
		&& [ -n "$(find "$PUBLISH_ROOT" -mindepth 1 -maxdepth 1 ! -name .well-known ! -name cgi-bin -print -quit)" ]; then
		PV="$WORK/preview"
		rm -rf "$PV"; mkdir -p "$PV"
		gd_pull_stage "$PV"
		( cd "$PV" && find . -mindepth 1 \( -type f -o -type l \) -printf '%P\0' ) | sort -z > "$WORK/.pv.repo"
		( cd "$PUBLISH_ROOT" && find . -mindepth 1 \( -type f -o -type l \) -printf '%P\0' ) | sort -z > "$WORK/.pv.live"
		ADD=$(comm -z -23 "$WORK/.pv.repo" "$WORK/.pv.live" | tr '\0' '\n')
		REPL=''; SAME=0
		while IFS= read -r -d '' f; do
			if [ ! -L "$PUBLISH_ROOT/$f" ] && [ ! -L "$PV/$f" ] && cmp -s -- "$PUBLISH_ROOT/$f" "$PV/$f"; then SAME=$((SAME + 1))
			else REPL+="$f"$'\n'; fi
		done < <(comm -z -12 "$WORK/.pv.repo" "$WORK/.pv.live")
		NADD=$(printf '%s' "$ADD" | grep -c . || true); NREPL=$(printf '%s' "$REPL" | grep -c . || true)
		echo
		echo "== FIRST DEPLOY PREVIEW — nothing has been changed yet"
		echo "   The website folder already holds a site. This is what deploying commit ${SHA:0:7} would do:"
		echo
		echo "== Will be ADDED from the repository: $NADD file(s)"
		printf '%s' "$ADD" | head -n 300 | sed 's/^/   + /' || true
		[ "$NADD" -gt 300 ] && echo "   ... and $((NADD - 300)) more"
		echo
		echo "== Will be REPLACED with the repository's version: $NREPL file(s) (each live copy is backed up first)"
		printf '%s' "$REPL" | head -n 300 | sed 's/^/   ~ /' || true
		[ "$NREPL" -gt 300 ] && echo "   ... and $((NREPL - 300)) more"
		echo
		echo "== Already identical: $SAME file(s)"
		echo
		echo "== Will be LEFT ALONE (in the website folder, not in the repository):"
		comm -z -13 "$WORK/.pv.repo" "$WORK/.pv.live" | tr '\0' '\n' \
			| grep -vE '^(\.well-known|cgi-bin)/' \
			| awk -F/ '{ print (NF > 1 ? $1 "/" : $0) }' | sort | uniq -c | sort -rn | head -n 60 \
			| awk '{ n=$1; $1=""; printf "   = %s%s\n", substr($0,2), (n > 1 ? "  (" n " files)" : "") }' || true
		echo
		gd_write_dotenv "" dry
		echo
		echo "== Nothing is deleted on a first deploy."
		echo "   If this looks right, press \"Looks right — deploy\" on the GitDeploy page for this site."
		rm -rf "$PV" "$WORK/.pv.repo" "$WORK/.pv.live"
		echo "$SHA" > "$WORK/review_sha"
		exit 20
	fi

	# Stage the tracked files as a release (also what rollback republishes).
	# Unchanged files are hard-linked to the previous release so five releases of
	# a large PHP project do not cost five full copies of disk.
	REL="$WORK/releases/$SHA"
	mkdir -p "$WORK/releases"
	rm -rf "$REL.new"
	LINK_DEST=()
	PREV=$(cat "$WORK/last_ok_sha" 2>/dev/null || true)
	case "$PREV" in *[!0-9a-f]*|"") ;; *) [ -d "$WORK/releases/$PREV" ] && [ "$PREV" != "$SHA" ] && LINK_DEST=(--link-dest="$WORK/releases/$PREV");; esac
	gd_pull_stage "$REL.new" "${LINK_DEST[@]}"
	rm -rf "$REL"
	mv "$REL.new" "$REL"

	gd_pull_publish "$REL"
	rm -f "$WORK/review_sha"
	echo "$SHA" > "$WORK/last_ok_sha"
	gd_prune_releases "$SHA"

	# After-deploy command runs IN THE WEBSITE FOLDER for files-only sites, so
	# things like "php artisan migrate --force" work. Never fails the deploy.
	if [ -n "${GD_POST_CMD:-}" ]; then
		gd_export_panel_env
		echo "== After-deploy command (in the website folder): $GD_POST_CMD"
		if ! ( cd "$PUBLISH_ROOT" && nice -n 10 timeout 600 bash -c "$GD_POST_CMD" ); then
			echo "   WARN: the after-deploy command failed (the site is published and live)"
		fi
	fi
	echo "== Deployed $SHA to $PUBLISH_ROOT"
	exit 0
fi

cd "$REPO"

# Repo-level config: .gitdeploy.json at the repo root may set build_cmd, output_dir
# and env. Panel env vars win over repo env on conflict (panel may hold secrets).
if [ -f "$REPO/.gitdeploy.json" ]; then
	echo "== Found .gitdeploy.json — applying repo config"
	GD_RC_ERR=0
	RC_BUILD_CMD=$(node -e '
		try{
			const c = JSON.parse(require("fs").readFileSync(process.argv[1], "utf8"));
			if(typeof c.build_cmd === "string" && c.build_cmd.length <= 300 && !/[\r\n\x00-\x08\x0b-\x1f]/.test(c.build_cmd))
				process.stdout.write(Buffer.from(c.build_cmd).toString("base64"));
		}catch(e){ process.exit(3); }' "$REPO/.gitdeploy.json") || GD_RC_ERR=1
	RC_OUTPUT_DIR=$(node -e '
		try{
			const c = JSON.parse(require("fs").readFileSync(process.argv[1], "utf8"));
			if(typeof c.output_dir === "string" && /^[A-Za-z0-9][A-Za-z0-9\/_.-]{0,100}$/.test(c.output_dir) && !c.output_dir.includes(".."))
				process.stdout.write(c.output_dir);
		}catch(e){ process.exit(3); }' "$REPO/.gitdeploy.json") || GD_RC_ERR=1
	if [ "$GD_RC_ERR" -ne 0 ]; then
		echo "WARN: .gitdeploy.json is not valid JSON — ignoring it"
	else
		# Tell the user when a field was present but rejected — silently ignoring it
		# sends people hunting for a bug that is really a typo in .gitdeploy.json.
		RC_HAS=$(node -e '
			try{
				const c = JSON.parse(require("fs").readFileSync(process.argv[1], "utf8"));
				process.stdout.write(["build_cmd","output_dir","root_dir","node_version","env"].filter(k => c[k] !== undefined).join(" "));
			}catch(e){}' "$REPO/.gitdeploy.json")
		case " $RC_HAS " in *" build_cmd "*) [ -z "$RC_BUILD_CMD" ] && echo "   WARN: build_cmd in .gitdeploy.json was ignored (must be one line, max 300 chars)";; esac
		case " $RC_HAS " in *" output_dir "*) [ -z "$RC_OUTPUT_DIR" ] && echo "   WARN: output_dir in .gitdeploy.json was ignored (relative path, no '..', letters/digits/._-/ only)";; esac
		RC_ROOT_DIR=$(node -e '
			try{
				const c = JSON.parse(require("fs").readFileSync(process.argv[1], "utf8"));
				if(typeof c.root_dir === "string" && /^[A-Za-z0-9][A-Za-z0-9\/_.-]{0,100}$/.test(c.root_dir) && !c.root_dir.includes(".."))
					process.stdout.write(c.root_dir);
			}catch(e){}' "$REPO/.gitdeploy.json")
		case " $RC_HAS " in *" root_dir "*) [ -z "$RC_ROOT_DIR" ] && echo "   WARN: root_dir in .gitdeploy.json was ignored (relative path, no '..', letters/digits/._-/ only)";; esac
		if [ -n "$RC_ROOT_DIR" ]; then GD_ROOT_DIR="$RC_ROOT_DIR"; echo "   root_dir (repo): $GD_ROOT_DIR"; fi

		# node_version: the panel setting always wins, so only apply the repo's
		# value when the panel left it blank.
		RC_NODE_VERSION=$(node -e '
			try{
				const c = JSON.parse(require("fs").readFileSync(process.argv[1], "utf8"));
				if(typeof c.node_version === "string" && /^v?[0-9]{1,2}(\.[0-9]{1,3}){0,2}$/.test(c.node_version.trim()))
					process.stdout.write(c.node_version.trim());
			}catch(e){}' "$REPO/.gitdeploy.json")
		case " $RC_HAS " in *" node_version "*) [ -z "$RC_NODE_VERSION" ] && echo "   WARN: node_version in .gitdeploy.json was ignored (use a version like \"22\", \"22.11\" or \"22.11.0\")";; esac
		if [ -n "$RC_NODE_VERSION" ]; then
			if [ -n "$PANEL_NODE_VERSION" ]; then
				echo "   node_version (repo): $RC_NODE_VERSION ignored — the panel setting $PANEL_NODE_VERSION wins"
			else
				GD_NODE_VERSION="$RC_NODE_VERSION"; echo "   node_version (repo): $GD_NODE_VERSION"
			fi
		fi

		if [ -n "$RC_BUILD_CMD" ]; then GD_BUILD_CMD=$(printf '%s' "$RC_BUILD_CMD" | base64 -d); echo "   build_cmd (repo): $GD_BUILD_CMD"; fi
		if [ -n "$RC_OUTPUT_DIR" ]; then GD_OUTPUT_DIR="$RC_OUTPUT_DIR"; echo "   output_dir (repo): $GD_OUTPUT_DIR"; fi
		# env: KEY=VALUE lines, keys validated, values single-line, exported before panel env
		while IFS= read -r line; do
			[ -n "$line" ] || continue
			if gd_env_blocked "${line%%=*}"; then echo "   env (repo): ${line%%=*} REFUSED (reserved name)"; continue; fi
			export "$line" && echo "   env (repo): ${line%%=*}"
		done < <(node -e '
			try{
				const c = JSON.parse(require("fs").readFileSync(process.argv[1], "utf8"));
				const env = (c.env && typeof c.env === "object") ? c.env : {};
				let n = 0;
				for(const k of Object.keys(env)){
					if(n++ >= 100) break;
					const v = String(env[k]);
					if(/^[A-Za-z_][A-Za-z0-9_]*$/.test(k) && v.length <= 500 && !/[\r\n\x00]/.test(v))
						process.stdout.write(k + "=" + v + "\n");
						else
							process.stderr.write("   WARN: env entry \"" + k + "\" in .gitdeploy.json was ignored (name: letters/digits/underscore starting with a letter; value: one line, max 500 chars)\n");
				}
			}catch(e){}' "$REPO/.gitdeploy.json")
	fi
fi

# Expose GD_ENV_FOO=bar to the build as FOO=bar (after repo env, so panel wins)
gd_export_panel_env

# Monorepo support: run the install/build inside a subdirectory of the repo.
# output_dir is then relative to that subdirectory.
BUILD_DIR="$REPO"
if [ -n "${GD_ROOT_DIR:-}" ]; then
	case "$GD_ROOT_DIR" in
		/*|*..*) echo "FATAL: project folder must be a relative path inside the repository"; exit 10;;
	esac
	BUILD_DIR="$REPO/$GD_ROOT_DIR"
	[ -d "$BUILD_DIR" ] || { echo "FATAL: project folder '$GD_ROOT_DIR' does not exist in this repository"; exit 10; }
	echo "== Project folder: $GD_ROOT_DIR"
	cd "$BUILD_DIR"
fi

# Remove Node toolchains this account has not used in a long while. Only ever
# touches $TOOLROOT, which we created — never anything else in the home dir.
gd_node_prune(){
	local keep="$1" d n
	n=$(ls -1d "$TOOLROOT"/node-v* 2>/dev/null | wc -l)
	[ "$n" -gt 2 ] || return 0
	for d in "$TOOLROOT"/node-v*; do
		[ -d "$d" ] || continue
		[ "$d" = "$keep" ] && continue
		if [ -e "$d/.lastused" ]; then
			[ -n "$(find "$d/.lastused" -maxdepth 0 -mtime +60 2>/dev/null)" ] || continue
		else
			[ -n "$(find "$d" -maxdepth 0 -mtime +60 2>/dev/null)" ] || continue
		fi
		rm -rf "$d" && echo "   removed unused Node toolchain $(basename "$d")"
	done
}

# Put the Node version this project asks for on PATH, installing it into the
# user's own home if the server's Node will not do. Never touches system Node.
gd_node_setup(){
	local sysver want full arch dir tarball tmp idx avail
	sysver=$(node -v 2>/dev/null | sed 's/^v//') || sysver=''
	[ -n "$sysver" ] || { echo "FATAL: node is not installed on this server"; exit 11; }

	want=$(node "$GD_BIN/detect.js" nodespec "$BUILD_DIR" "$REPO" "$sysver" "${GD_NODE_VERSION:-}" 2>/dev/null) || want=system
	[ -n "$want" ] || want=system
	if [ "$want" = system ]; then
		echo "== Node v$sysver (the server's own)"
		return 0
	fi
	echo "== This project asks for Node $want — the server has v$sysver"

	case "$(uname -m)" in
		x86_64)        arch=x64;;
		aarch64|arm64) arch=arm64;;
		*) echo "FATAL: nodejs.org publishes no build for this machine ($(uname -m))"; exit 11;;
	esac

	mkdir -p "$TOOLROOT"
	# nodejs.org's release index, cached for a day. A stale cache beats a failed
	# deploy when the network hiccups, so we only refresh it opportunistically.
	idx="$TOOLROOT/index.json"
	if [ ! -s "$idx" ] || [ -n "$(find "$idx" -maxdepth 0 -mmin +1440 2>/dev/null)" ]; then
		if curl -fsS --max-time 30 -o "$idx.new" https://nodejs.org/dist/index.json 2>/dev/null; then
			mv "$idx.new" "$idx"
		else
			rm -f "$idx.new"
			[ -s "$idx" ] && echo "   could not refresh the Node release list — using the cached copy"
		fi
	fi
	[ -s "$idx" ] || { echo "FATAL: cannot reach nodejs.org to install Node $want"; exit 11; }

	full=$(node "$GD_BIN/detect.js" pickversion "$want" < "$idx") || full=''
	# This value becomes part of a download URL and a path: allow digits only.
	if ! printf '%s' "$full" | grep -Eq '^[0-9]{1,2}\.[0-9]{1,3}\.[0-9]{1,3}$'; then
		echo "FATAL: nodejs.org lists no release matching '$want'"; exit 11
	fi

	dir="$TOOLROOT/node-v$full-$arch"
	if [ ! -x "$dir/bin/node" ]; then
		# Never let a toolchain download be the thing that fills a shared server's
		# disk — that would take every other site on the box down with it.
		avail=$(df -Pk "$TOOLROOT" 2>/dev/null | awk 'NR==2{print $4}')
		if [ -n "${avail:-}" ] && [ "$avail" -lt 1048576 ]; then
			echo "FATAL: not enough free disk space to install Node v$full"
			echo "       it needs about 250 MB and only $((avail / 1024)) MB is free"
			exit 11
		fi
		echo "   installing Node v$full into your account (one-time: ~50 MB to download, ~200 MB on disk)"
		# Two builds of the same account can want the same version at once.
		exec 9>"$TOOLROOT/.install.lock"
		flock 9
		if [ ! -x "$dir/bin/node" ]; then
			tmp="$TOOLROOT/.tmp.$$"
			rm -rf "$tmp"; mkdir -p "$tmp/x"
			tarball="node-v$full-linux-$arch.tar.xz"
			if ! curl -fsS --retry 2 --max-time 600 -o "$tmp/$tarball" "https://nodejs.org/dist/v$full/$tarball" \
			|| ! curl -fsS --retry 2 --max-time 60 -o "$tmp/SHASUMS256.txt" "https://nodejs.org/dist/v$full/SHASUMS256.txt"; then
				rm -rf "$tmp"; echo "FATAL: could not download Node v$full from nodejs.org"; exit 11
			fi
			# Refuse anything whose checksum does not match nodejs.org's manifest.
			if ! ( cd "$tmp" && grep " $tarball\$" SHASUMS256.txt | sha256sum -c - >/dev/null 2>&1 ); then
				rm -rf "$tmp"; echo "FATAL: the downloaded Node archive failed its checksum — refusing to use it"; exit 11
			fi
			if ! tar -xJf "$tmp/$tarball" -C "$tmp/x" --strip-components=1; then
				rm -rf "$tmp"; echo "FATAL: could not unpack Node v$full"; exit 11
			fi
			mv "$tmp/x" "$dir"
			rm -rf "$tmp"
		fi
		exec 9>&-
	fi
	[ -x "$dir/bin/node" ] || { echo "FATAL: Node v$full did not install correctly"; exit 11; }
	touch "$dir/.lastused" 2>/dev/null || true

	PATH="$dir/bin:$PATH"
	export PATH
	hash -r
	echo "== Node $(node -v) (installed for your account, not for the server)"
	gd_node_prune "$dir"
}
gd_node_setup

# Work out how to build this project when the build command or the output folder
# was left blank in the panel. Anything the user typed always wins.
DET=''
OUT_CANDIDATES=''
if [ -z "${GD_BUILD_CMD:-}" ] || [ -z "${GD_OUTPUT_DIR:-}" ]; then
	echo "== Working out how to build this project"
	DET=$(node "$GD_BIN/detect.js" framework "$BUILD_DIR" 2>/dev/null) || DET=''
	if [ -n "$DET" ]; then
		echo "   this looks like: $(printf '%s' "$DET" | cut -f1)"
		DET_NOTE=$(printf '%s' "$DET" | cut -f4)
		[ -n "$DET_NOTE" ] && echo "   NOTE: $DET_NOTE"
		if [ -z "${GD_BUILD_CMD:-}" ]; then
			GD_BUILD_CMD=$(printf '%s' "$DET" | cut -f2)
			echo "   build command: ${GD_BUILD_CMD:-none needed}"
		fi
		if [ -z "${GD_OUTPUT_DIR:-}" ]; then
			OUT_CANDIDATES=$(printf '%s' "$DET" | cut -f3)
			echo "   output folder: $OUT_CANDIDATES"
		fi
	else
		echo "   this project did not match anything we recognise"
	fi
fi
if [ -z "$DET" ] && [ -z "${GD_BUILD_CMD:-}" ]; then
	echo "FATAL: we could not work out how to build this project."
	if [ -f "$BUILD_DIR/composer.json" ] || compgen -G "$BUILD_DIR/*.php" >/dev/null; then
		echo "       It looks like a PHP project: open the site's settings and set Deploy type"
		echo "       to \"Files only (PHP, no build)\" — PHP sites need no build step."
	else
		echo "       Open the site in the panel, expand Build settings and fill in the"
		echo "       build command and output folder — or add a .gitdeploy.json to the repo."
	fi
	exit 11
fi
[ -n "${GD_OUTPUT_DIR:-}" ] || [ -n "$OUT_CANDIDATES" ] || OUT_CANDIDATES='dist build out public _site'

# A PHP project published "as-is" in build mode would be mirrored with
# --delete-after, wiping uploads, caches and .env from the live folder. Stop and
# point at files-only mode, which is built for exactly this.
if [ -z "${GD_BUILD_CMD:-}" ] && { [ "${GD_OUTPUT_DIR:-}" = "." ] || [ "$OUT_CANDIDATES" = "." ]; } \
	&& { [ -f "$BUILD_DIR/composer.json" ] || compgen -G "$BUILD_DIR/*.php" >/dev/null; }; then
	echo "FATAL: this looks like a PHP project. Build mode makes the website folder an exact copy of"
	echo "       the repository and would delete everything else in it (uploads, caches, .env)."
	echo "       Open the site's settings and set Deploy type to \"Files only (PHP, no build)\"."
	exit 11
fi

# Fail early and clearly when the build needs a tool this server does not have,
# rather than halfway through with "command not found".
if [ -n "${GD_BUILD_CMD:-}" ]; then
	BUILD_TOOL=${GD_BUILD_CMD%% *}
	case "$BUILD_TOOL" in
		npm|npx|node|yarn|pnpm|bun|*/*|*=*) ;;
		*) command -v "$BUILD_TOOL" >/dev/null 2>&1 || {
			echo "FATAL: this project builds with '$BUILD_TOOL', which is not installed on this server."
			echo "       Ask your host to install it, or change the build command under Build settings."
			exit 11; };;
	esac
fi

# Pick the package manager from the lockfile that is actually committed.
# pnpm/yarn/bun are used only if present; otherwise npx fetches them on demand.
install_deps(){
	if [ ! -f package.json ]; then
		echo "== No package.json — skipping dependency install"
		return 0
	fi
	local cmd=""
	if [ -f bun.lockb ] || [ -f bun.lock ]; then
		if command -v bun >/dev/null 2>&1; then cmd="bun install --frozen-lockfile"
		else echo "FATAL: this project uses bun, which is not installed on this server"; return 11; fi
	elif [ -f pnpm-lock.yaml ]; then
		if command -v pnpm >/dev/null 2>&1; then cmd="pnpm install --frozen-lockfile"
		else echo "   pnpm is not installed on the server — fetching it with npx"; cmd="npx --yes pnpm@10 install --frozen-lockfile"; fi
	elif [ -f yarn.lock ]; then
		if command -v yarn >/dev/null 2>&1; then cmd="yarn install --frozen-lockfile"
		else echo "   yarn is not installed on the server — fetching it with npx"; cmd="npx --yes yarn install --frozen-lockfile"; fi
	elif [ -f package-lock.json ]; then
		cmd="npm ci"
	else
		cmd="npm install"
	fi
	echo "== $cmd"
	nice -n 10 ionice -c2 -n7 timeout 900 bash -c "$cmd"
}
install_deps

if [ -n "${GD_BUILD_CMD:-}" ]; then
	echo "== Running build: $GD_BUILD_CMD"
	nice -n 10 ionice -c2 -n7 timeout 900 bash -c "$GD_BUILD_CMD"
else
	echo "== No build step — publishing the files as they are in the repository"
fi

# Where did the build put the site? An explicit setting is taken at its word; a
# detected one is a list of likely folders, first match wins.
if [ -n "${GD_OUTPUT_DIR:-}" ]; then
	OUT="$BUILD_DIR/$GD_OUTPUT_DIR"
	[ -d "$OUT" ] && [ -n "$(ls -A "$OUT")" ] || { echo "FATAL: output dir '$GD_OUTPUT_DIR' missing or empty after build"; exit 11; }
else
	OUT=''
	for cand in $OUT_CANDIDATES; do
		case "$cand" in /*|*..*) continue;; esac
		if [ "$cand" = "." ]; then OUT="$BUILD_DIR"; GD_OUTPUT_DIR="."; break; fi
		if [ -d "$BUILD_DIR/$cand" ] && [ -n "$(ls -A "$BUILD_DIR/$cand")" ]; then
			OUT="$BUILD_DIR/$cand"; GD_OUTPUT_DIR="$cand"; break
		fi
	done
	if [ -z "$OUT" ]; then
		echo "FATAL: the build finished but produced none of these folders: $OUT_CANDIDATES"
		echo "       Set the output folder under Build settings in the panel."
		exit 11
	fi
	echo "== Output folder: $GD_OUTPUT_DIR"
fi

# Angular and friends nest the site one level deeper (dist/<project-name>).
if [ ! -f "$OUT/index.html" ]; then
	NEST=''; NCOUNT=0
	for d in "$OUT"/*/; do
		[ -d "$d" ] || continue
		NCOUNT=$((NCOUNT + 1)); NEST="${d%/}"
	done
	if [ "$NCOUNT" = "1" ] && [ -f "$NEST/index.html" ]; then
		echo "   the build nests the site one level deeper — publishing ${NEST##*/}/"
		OUT="$NEST"
	fi
fi
[ -f "$OUT/index.html" ] || echo "WARN: no index.html in output dir"

# Publishing the repository itself (a site with no build step) must not ship the
# git metadata or the tooling files along with it.
SRC_EXCLUDES=()
if [ "$OUT" = "$BUILD_DIR" ] || [ "$OUT" = "$REPO" ]; then
	SRC_EXCLUDES=(--exclude '/.git/' --exclude '/.github/' --exclude '/node_modules/'
		--exclude '/.gitdeploy.json' --exclude '/.env' --exclude '/.gitignore'
		--exclude '/.nvmrc' --exclude '/.node-version' --exclude '/.gitmodules')
fi

# Archive this build as a release (enables one-click rollback), then publish.
# Staged into .new first so an interrupted archive can never appear as a
# (partial) release in the rollback list.
REL="$WORK/releases/$SHA"
mkdir -p "$WORK/releases"
rm -rf "$REL.new"
rsync -rlc "${SRC_EXCLUDES[@]}" "$OUT"/ "$REL.new"/
rm -rf "$REL"
mv "$REL.new" "$REL"

echo "== Publishing to $PUBLISH_ROOT"
# rsync into the existing docroot. Never delete/recreate/chown the docroot itself.
# --delete-after keeps old assets alive until new ones are fully written.
rsync -rlc --delete-after \
	--exclude '/.well-known/' --exclude '/cgi-bin/' --exclude '/.htaccess' \
	"$REL"/ "$PUBLISH_ROOT"/
# the folder is now an exact mirror, so a files-only list from before is stale
rm -f "$PULL_LIST"

echo "$SHA" > "$WORK/last_ok_sha"
gd_prune_releases "$SHA"

# Optional post-deploy command (cache purge, ping, etc). A failure here is
# reported but never fails the deploy — the site is already published.
if [ -n "${GD_POST_CMD:-}" ]; then
	echo "== Post-deploy command: $GD_POST_CMD"
	if ! ( cd "$BUILD_DIR" && nice -n 10 timeout 120 bash -c "$GD_POST_CMD" ); then
		echo "   WARN: the post-deploy command failed (the site is published and live)"
	fi
fi

echo "== Deployed $SHA to $PUBLISH_ROOT"
