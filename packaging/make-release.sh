#!/usr/bin/env bash
#
# GitDeploy — release builder.
#
# Powered by Digitalbkk Co., Ltd. (www.digitalbkk.com)
# Developed by the Digitalbkk team. Author: Hasif Ahmed <hasif5@gmail.com>
# Licensed under the Apache License, Version 2.0. See ../LICENSE.
#
# Produces gitdeploy-<version>.tar.gz from the working tree. The tarball's top
# level directory is `gitdeploy/`, so it unpacks straight to /usr/local/gitdeploy
# and `sudo ./gitdeploy/install.sh` works from wherever it was extracted.
#
# Usage:
#   packaging/make-release.sh [output-directory]
#
#   output-directory   where to write the artifact. Default: /root
#                      (deliberately outside the source tree).
#
# The file list is discovered from the tree at build time and filtered through
# .gitignore — nothing is hardcoded, so files added to the project are picked up
# automatically. packaging/ and .git/ are always excluded.
#
# Nothing is written outside the output directory and a private temp dir, and
# nothing on the running server is modified. No prompts, no network.

set -euo pipefail

# ------------------------------------------------------------------ paths --
HERE=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
SRC=$(cd -- "$HERE/.." && pwd -P)
PHP=/usr/local/emps/bin/php

OUT_DIR=${1:-/root}

say()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
ok()   { printf '    \033[1;32mok\033[0m   %s\n' "$*"; }
bad()  { printf '    \033[1;31mFAIL\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31mFATAL\033[0m %s\n' "$*" >&2; exit 1; }

FAILURES=0
fail() { bad "$*"; FAILURES=$((FAILURES+1)); }

# ---------------------------------------------------------------- version --
say "Reading version"
[[ -s "$SRC/VERSION" ]] || die "$SRC/VERSION is missing or empty — cannot name the artifact."
VERSION=$(tr -d ' \t\r\n' < "$SRC/VERSION")
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] \
	|| die "VERSION is '$VERSION' — expected a semantic version like 1.6.0."
ok "version $VERSION"

# info.json must agree with VERSION, or the Webuzo registry and the artifact
# name disagree the moment one of them is bumped and the other is not.
if [[ -f "$SRC/info.json" ]]; then
	if [[ -x "$PHP" ]]; then
		INFO_VER=$("$PHP" -r '
			$j = json_decode(@file_get_contents($argv[1]), true);
			if(!is_array($j)){ fwrite(STDERR, "not valid JSON\n"); exit(1); }
			echo isset($j["version"]) ? $j["version"] : "";
		' "$SRC/info.json") || die "info.json is not valid JSON — fix it before releasing."
		if [[ "$INFO_VER" != "$VERSION" ]]; then
			die "info.json says version '$INFO_VER' but VERSION says '$VERSION'. Bump both."
		fi
		ok "info.json parses and agrees ($INFO_VER)"
	else
		printf '    \033[1;33mwarn\033[0m %s\n' "$PHP not found — skipped the info.json check" >&2
	fi
fi

# ---------------------------------------------------------------- staging --
say "Staging tree from $SRC"
command -v rsync >/dev/null 2>&1 || die "rsync is required to stage the release."
command -v tar   >/dev/null 2>&1 || die "tar is required to build the release."

TMP=$(mktemp -d -t gitdeploy-release.XXXXXXXX)
cleanup() { rm -rf -- "$TMP"; }
trap cleanup EXIT

STAGE="$TMP/gitdeploy"      # this basename becomes the tarball's top-level dir
mkdir -p "$STAGE"

# --exclude-from applies every pattern in .gitignore (rsync skips blank lines and
# lines starting with '#', so the comments in that file are harmless here).
RSYNC_ARGS=(-a --exclude '.git/' --exclude '.gitignore' --exclude '/packaging/')
if [[ -f "$SRC/.gitignore" ]]; then
	RSYNC_ARGS+=(--exclude-from="$SRC/.gitignore")
	ok "applying .gitignore exclusions"
else
	printf '    \033[1;33mwarn\033[0m %s\n' "no .gitignore found — shipping everything but .git/ and packaging/" >&2
fi

rsync "${RSYNC_ARGS[@]}" "$SRC"/ "$STAGE"/
STAGED_FILES=$(find "$STAGE" -type f | wc -l)
ok "$STAGED_FILES file(s) staged"
(( STAGED_FILES > 0 )) || die "nothing was staged — refusing to build an empty release."

# ------------------------------------------------------------- sanity check -
say "Sanity checks"

# 1. Required entry points. Mirrors bin/check.sh's package-files list plus the
#    tooling and legal files a distributable artifact must carry.
for f in \
	VERSION \
	LICENSE \
	NOTICE \
	install.sh \
	uninstall.sh \
	info.json \
	lib/common.php \
	lib/ghapp.php \
	bin/runner.php \
	bin/build.sh \
	bin/asuser.sh \
	bin/check.sh \
	bin/detect.js \
	web/enduser/index.php \
	web/enduser/hook.php \
	web/enduser/docs_content.php \
	webuzo/plugin/gitdeploy.php \
	webuzo/enduser_locations_gitdeploy.conf \
	conf/github_known_hosts
do
	if [[ -s "$STAGE/$f" ]]; then ok "present  $f"
	else fail "required file missing or empty in the release: $f"; fi
done

# 2. Nothing from packaging/ or .git/ leaked in.
if [[ -e "$STAGE/packaging" ]]; then fail "packaging/ leaked into the release"; fi
if [[ -e "$STAGE/.git" ]];     then fail ".git leaked into the release"; fi

# 3. Every shell script parses. Discovered, not listed.
SH_N=0; SH_BAD=0
while IFS= read -r -d '' f; do
	SH_N=$((SH_N+1))
	bash -n "$f" 2>/dev/null || { fail "shell syntax error: ${f#$STAGE/}"; SH_BAD=$((SH_BAD+1)); }
done < <(find "$STAGE" -type f -name '*.sh' -print0)
if (( SH_BAD )); then bad "$SH_BAD of $SH_N shell script(s) failed bash -n"
else ok "$SH_N shell script(s) passed bash -n"; fi

# 4. Every PHP file parses, under the interpreter that will actually run it
#    (EMPS PHP 7.4 — a 8.x-only construct must not slip through).
if [[ -x "$PHP" ]]; then
	PHP_N=0; PHP_BAD=0
	while IFS= read -r -d '' f; do
		PHP_N=$((PHP_N+1))
		"$PHP" -l "$f" >/dev/null 2>&1 || { fail "PHP syntax error: ${f#$STAGE/}"; PHP_BAD=$((PHP_BAD+1)); }
	done < <(find "$STAGE" -type f -name '*.php' -print0)
	if (( PHP_BAD )); then bad "$PHP_BAD of $PHP_N PHP file(s) failed $PHP -l"
	else ok "$PHP_N PHP file(s) passed $PHP -l"; fi
else
	fail "$PHP not found — cannot verify PHP 7.4 syntax. Refusing to ship unverified PHP."
fi

# 5. The plugin header version must match VERSION, or the panel reports a lie.
if [[ -s "$STAGE/webuzo/plugin/gitdeploy.php" ]]; then
	HDR=$(sed -n 's/^Version:[[:space:]]*//p' "$STAGE/webuzo/plugin/gitdeploy.php" | head -1 | tr -d ' \r')
	if [[ -n "$HDR" && "$HDR" != "$VERSION" ]]; then
		fail "webuzo/plugin/gitdeploy.php header says Version: $HDR but VERSION says $VERSION"
	else
		ok "plugin header version matches ($VERSION)"
	fi
fi

# 6. github_known_hosts must be usable — install.sh aborts on an empty one.
if [[ -s "$STAGE/conf/github_known_hosts" ]]; then
	grep -q '^github\.com ' "$STAGE/conf/github_known_hosts" \
		|| fail "conf/github_known_hosts has no github.com entry — SSH deploys would trust any host"
fi

if (( FAILURES > 0 )); then
	printf '\n\033[1;31m==> %d check(s) failed. No artifact was written.\033[0m\n' "$FAILURES" >&2
	exit 1
fi
printf '    \033[1;32mall checks passed\033[0m\n'

# ------------------------------------------------------------------ build --
say "Building artifact"
mkdir -p "$OUT_DIR"
OUT_DIR=$(cd -- "$OUT_DIR" && pwd -P)
case "$OUT_DIR" in
	"$SRC"|"$SRC"/*) die "refusing to write the artifact inside the source tree ($OUT_DIR)." ;;
esac

ARTIFACT="$OUT_DIR/gitdeploy-$VERSION.tar.gz"

# Ship as root:root regardless of who builds, and sort for a reproducible order.
tar -czf "$ARTIFACT" \
	-C "$TMP" \
	--owner=root --group=root --numeric-owner \
	--sort=name \
	gitdeploy

[[ -s "$ARTIFACT" ]] || die "tar produced nothing at $ARTIFACT."

# Verify what we just wrote actually has the expected top-level directory.
# Listed once into a variable rather than piped into head: `tar | head` makes tar
# die of SIGPIPE, which `set -o pipefail` would turn into a spurious build failure.
LISTING=$(tar -tzf "$ARTIFACT")
TOP=${LISTING%%$'\n'*}
[[ "$TOP" == "gitdeploy/" ]] \
	|| die "unexpected tarball layout: top-level entry is '$TOP', expected 'gitdeploy/'."
# Every entry must live under that one directory — no stray sibling paths.
if printf '%s\n' "$LISTING" | grep -qv '^gitdeploy/'; then
	die "tarball contains entries outside gitdeploy/ — refusing to ship it."
fi

if command -v sha256sum >/dev/null 2>&1; then
	( cd "$OUT_DIR" && sha256sum "gitdeploy-$VERSION.tar.gz" > "gitdeploy-$VERSION.tar.gz.sha256" )
	ok "checksum $ARTIFACT.sha256"
fi

SIZE=$(du -h "$ARTIFACT" | cut -f1)
ENTRIES=$(printf '%s\n' "$LISTING" | wc -l)

cat <<EOF

$(printf '\033[1;32m==> GitDeploy %s packaged.\033[0m' "$VERSION")

  Artifact   $ARTIFACT  ($SIZE, $ENTRIES entries)
  Top level  gitdeploy/
  Contents   $STAGED_FILES file(s), .gitignore and packaging/ excluded

  Install it on a target server with:
    tar xzf gitdeploy-$VERSION.tar.gz
    sudo ./gitdeploy/install.sh
    sudo /usr/local/gitdeploy/install.sh --check

EOF
