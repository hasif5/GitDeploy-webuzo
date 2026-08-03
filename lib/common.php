<?php
/**
 * GitDeploy — shared helpers.
 * Used by bin/runner.php (root), web/enduser/index.php (root FPM) and hook.php (webuzo FPM).
 * hook.php can only read GD_HOOKINFO and write GD_SPOOL — permissions enforce that, not code.
 */

define('GD_BASE',     '/usr/local/gitdeploy');
/*
 * State root — /var/lib/gitdeploy, NOT /var/webuzo/conf/gitdeploy. Do not move it back.
 *
 * Webuzo's own daily cron (/etc/cron.d/webuzo -> /usr/local/webuzo/cron.php) walks
 * /var/webuzo and forces everything under it to webuzo:webuzo 0770. Anything we keep
 * there therefore loses its permissions within 24 hours of install, which would make
 * sites/ writable and .csrf_key readable by the `webuzo` uid — the same uid that runs
 * the public webhook endpoint (web/enduser/hook.php on fpm-webuzo.sock). That turns a
 * web hit into "rewrite a site config the root runner then acts on": privilege
 * escalation to root-orchestrated builds, forgeable panel CSRF tokens, and (once
 * GitHub Apps are connected) a webuzo-writable home for RSA private keys.
 *
 * /var/lib is safe and verified for this: Webuzo's cron never touches it, the EMPS FPM
 * pools set no open_basedir and no chroot, and /var/lib is traversable by `webuzo` so
 * the 0711 root:root state root still lets the webhook reach hookinfo/ and spool/.
 */
define('GD_STATE',    '/var/lib/gitdeploy');
define('GD_SITES',    GD_STATE.'/sites');
define('GD_HOOKINFO', GD_STATE.'/hookinfo');
define('GD_SPOOL',    GD_STATE.'/spool');
define('GD_LOCKS',    GD_STATE.'/locks');
define('GD_STATUS',   GD_STATE.'/status');
define('GD_LOGS',     GD_STATE.'/logs');
define('GD_USERS',    '/var/webuzo/users');

// Folders under ~/.gitdeploy/ that are NOT per-site build workspaces and must
// never be reclaimed as orphans. "toolchains" holds the Node versions build.sh
// installs for the account and is shared by every one of that user's sites.
define('GD_RESERVED_WORKDIRS', array('toolchains'));

/*
 * Global pause flag (set from the admin panel page, honoured by bin/runner.php).
 * A plain file in the state root, so gd_enforce_state_perms() already keeps it
 * root-only 0600 like every other file sitting directly in GD_STATE.
 */
define('GD_PAUSE_FILE', GD_STATE.'/.paused');

// Cached per-account disk usage, so rendering the admin page never has to walk
// a home directory itself. Written by the admin page, read by anyone root.
define('GD_DISK_CACHE', GD_STATE.'/.diskcache.json');

// Site ids are domain names: lowercase alnum, dots, hyphens. No slashes, no dotdot by construction.
function gd_valid_site($site){
	return is_string($site) && preg_match('~^[a-z0-9][a-z0-9.-]{0,80}$~', $site) && strpos($site, '..') === false;
}

function gd_valid_user($user){
	return is_string($user) && preg_match('~^[a-z_][a-z0-9_-]{0,31}$~', $user);
}

// SSH URL (private repos, needs deploy key) or HTTPS URL (public repos, keyless)
function gd_valid_repo($repo){
	return is_string($repo) && (
		preg_match('~^git@github\.com:[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(\.git)?$~', $repo) ||
		preg_match('~^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(\.git)?$~', $repo)
	);
}

function gd_valid_branch($branch){
	return is_string($branch) && preg_match('~^[A-Za-z0-9][A-Za-z0-9/_.-]{0,100}$~', $branch)
		&& strpos($branch, '..') === false && substr($branch, -1) !== '/' && strpos($branch, '//') === false;
}

// Optional project subfolder for monorepos, e.g. "apps/website" (empty = repo root)
function gd_valid_root_dir($dir){
	if($dir === '' || $dir === null) return true;
	return gd_valid_output_dir($dir);
}

// Optional command run after a successful publish (cache purge, ping, ...)
function gd_valid_post_cmd($cmd){
	if($cmd === '' || $cmd === null) return true;
	return is_string($cmd) && strlen($cmd) <= 300 && !preg_match('~[\x00-\x1f\x7f]~', $cmd);
}

// Optional Node version for the build: "20", "22.11", "18.20.4", "v22" (empty = auto).
// Kept strict because build.sh pastes it into an official nodejs.org download path.
function gd_valid_node_version($v){
	if($v === '' || $v === null) return true;
	// \z, not $: a trailing newline must not slip into the download path build.sh builds
	return is_string($v) && preg_match('~^v?[0-9]{1,2}(\.[0-9]{1,3}){0,2}\z~', $v);
}

// Relative dir inside the repo, e.g. "dist", "build", "packages/site/dist"
function gd_valid_output_dir($dir){
	return is_string($dir) && preg_match('~^[A-Za-z0-9][A-Za-z0-9/_.-]{0,100}$~', $dir)
		&& strpos($dir, '..') === false && $dir[0] !== '/';
}

function gd_load_json($file){
	if(!is_file($file)) return null;
	$data = json_decode(file_get_contents($file), true);
	return is_array($data) ? $data : null;
}

// Atomic write: tmp in same dir + rename. Optional chmod/chgrp applied to tmp before rename.
function gd_save_json($file, $data, $mode = 0600, $group = null){
	$tmp = $file.'.tmp.'.getmypid();
	$json = json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
	if($json === false) return false;
	// A short write (ENOSPC/quota) must NOT be renamed over good state: PHP returns
	// the byte count, not false, so compare lengths before committing.
	$n = @file_put_contents($tmp, $json);
	if($n === false || $n !== strlen($json)){ @unlink($tmp); return false; }
	@chmod($tmp, $mode);
	if($group !== null) @chgrp($tmp, $group);
	return rename($tmp, $file);
}

function gd_load_site($site){
	if(!gd_valid_site($site)) return null;
	$cfg = gd_load_json(GD_SITES.'/'.$site.'.json');
	if(empty($cfg['site']) || $cfg['site'] !== $site) return null;
	if(!gd_valid_user($cfg['user'] ?? '') || !gd_valid_repo($cfg['repo'] ?? '')) return null;
	if(!gd_valid_branch($cfg['branch'] ?? '')) return null;
	// An empty output_dir is legal now: it means "let the build work the folder out".
	if(!gd_valid_root_dir($cfg['output_dir'] ?? '')) return null;
	if(!gd_valid_node_version($cfg['node_version'] ?? '')) return null;
	return $cfg;
}

/**
 * Resolve a site's docroot from Webuzo's own records (read-only).
 * Returns the realpath'd docroot, or null if the domain is gone / path escapes the user's home.
 */
function gd_resolve_docroot($user, $site){
	if(!gd_valid_user($user) || !gd_valid_site($site)) return null;
	$info = gd_load_json(GD_USERS.'/'.$user.'/info');
	if(empty($info['domains'][$site]['path'])) return null;
	$pw = posix_getpwnam($user);
	if(empty($pw) || $pw['uid'] < 1000) return null;
	$home = rtrim($pw['dir'], '/');
	$doc = realpath($info['domains'][$site]['path']);
	if($doc === false) return null;
	if(strpos($doc.'/', $home.'/') !== 0 || $doc === $home) return null;
	return $doc;
}

/**
 * Safety: how many Webuzo domains point at this site's docroot, and is it the
 * user's top-level public_html? Deploys sync with --delete-after, so publishing
 * into a shared docroot would clobber other live sites.
 * Returns ['shared_with' => [other domains], 'is_home_docroot' => bool] or null.
 */
function gd_docroot_risk($user, $site){
	$doc = gd_resolve_docroot($user, $site);
	if($doc === null) return null;
	$pw = posix_getpwnam($user);
	$info = gd_load_json(GD_USERS.'/'.$user.'/info');
	$shared = [];
	foreach(($info['domains'] ?? []) as $d => $v){
		if($d === $site) continue;
		$p = realpath($v['path'] ?? '');
		if($p !== false && $p === $doc) $shared[] = $d;
	}
	return [
		'shared_with'     => $shared,
		'is_home_docroot' => $doc === realpath($pw['dir'].'/public_html'),
	];
}

// One job file per site => bursts of pushes coalesce into one build.
function gd_queue_job($site, $trigger, $extra = []){
	if(!gd_valid_site($site)) return false;
	$job = array_merge(['site' => $site, 'trigger' => $trigger, 'ts' => time()], $extra);
	return gd_save_json(GD_SPOOL.'/'.$site.'.json', $job, 0660, 'webuzo');
}

/**
 * Webhook cooldown: a misconfigured CI loop can push dozens of times a minute.
 * Coalescing already collapses them into one build, but this stops the spool file
 * being rewritten (and the runner re-reading it) more often than makes sense.
 * Returns true when a job was queued within the last $secs.
 */
function gd_recently_queued($site, $secs = 5){
	$f = GD_SPOOL.'/'.$site.'.json';
	if(!is_file($f)) return false;
	$job = gd_load_json($f);
	return is_array($job) && isset($job['ts']) && (time() - (int)$job['ts']) < $secs;
}

function gd_write_status($site, $patch){
	if(!gd_valid_site($site)) return false;
	$cur = gd_load_json(GD_STATUS.'/'.$site.'.json') ?: [];
	return gd_save_json(GD_STATUS.'/'.$site.'.json', array_merge($cur, $patch), 0640, 'webuzo');
}

function gd_site_logs($site){
	if(!gd_valid_site($site)) return [];
	$logs = glob(GD_LOGS.'/'.$site.'/deploy-*.log') ?: [];
	rsort($logs);
	return $logs;
}

function gd_prune_logs($site, $keep = 10){
	$logs = gd_site_logs($site);
	foreach(array_slice($logs, $keep) as $old) @unlink($old);
}

/**
 * Apply one path's expected owner/group/mode, recording what had to change.
 * lstat + symlink skip: spool/ is group-writable by `webuzo`, so nothing here may
 * ever be tempted to follow a link out of the state tree and chmod it.
 */
function gd_fix_perms($path, $mode, $uid, $gid, &$fixed){
	clearstatcache(true, $path);
	$st = @lstat($path);
	if($st === false) return;
	if(($st['mode'] & 0170000) === 0120000) return;   // symlink: not ours to touch
	$have = $st['mode'] & 07777;
	if($have !== $mode && @chmod($path, $mode)){
		$fixed[] = sprintf('%s mode %04o -> %04o', $path, $have, $mode);
	}
	if($st['uid'] !== $uid || $st['gid'] !== $gid){
		$okuid = ($st['uid'] === $uid) || @chown($path, $uid);
		$okgid = ($st['gid'] === $gid) || @chgrp($path, $gid);
		if($okuid && $okgid){
			$fixed[] = sprintf('%s owner %d:%d -> %d:%d', $path, $st['uid'], $st['gid'], $uid, $gid);
		}
	}
}

/**
 * Re-assert the whole state tree's permission model, every runner tick.
 *
 * This exists because the original state root lived under /var/webuzo, where Webuzo's
 * daily cron silently reset it to webuzo:webuzo 0770 — the install-time permissions
 * were correct and then quietly stopped being correct. Moving to /var/lib removes the
 * known cause; this function is the belt-and-braces that would have *caught* it, and
 * catches the next thing (a hand-run chmod, a restored backup, a bad tarball extract).
 *
 * Cheap: a handful of lstat()s, no recursion into logs/ (root:root 0700 already seals
 * it) and no writes at all unless something actually drifted.
 * Returns a list of correction strings; empty means the tree was already correct.
 */
function gd_enforce_state_perms(){
	$fixed = array();
	// chown/chgrp are root-only — the panel and webhook pools just no-op here.
	if(posix_getuid() !== 0) return $fixed;

	$pw = posix_getpwnam('root');
	$gr = posix_getgrnam('webuzo');
	$uid  = $pw ? (int)$pw['uid'] : 0;
	$rgid = $pw ? (int)$pw['gid'] : 0;
	// No 'webuzo' group => this is not a working Webuzo box; leave the shared dirs
	// alone rather than chgrp'ing them to root and locking the panel out.
	$wgid = $gr ? (int)$gr['gid'] : null;

	$dirs = array(
		GD_STATE    => array(0711, $rgid),
		GD_SITES    => array(0700, $rgid),
		GD_LOCKS    => array(0700, $rgid),
		GD_LOGS     => array(0700, $rgid),
		GD_HOOKINFO => array(0750, $wgid),
		GD_STATUS   => array(0750, $wgid),
		GD_SPOOL    => array(0770, $wgid),
	);
	foreach($dirs as $dir => $spec){
		if($spec[1] === null) continue;
		gd_fix_perms($dir, $spec[0], $uid, $spec[1], $fixed);
	}

	// Every plain file sitting directly in the state root is root-only secret
	// material: .csrf_key, ghapp-<user>.json (holds an RSA private key), and the
	// GitHub App scratch files. One rule covers them, including ones added later.
	$rootfiles = array_merge(glob(GD_STATE.'/*') ?: array(), glob(GD_STATE.'/.[!.]*') ?: array());
	foreach($rootfiles as $f){
		if(is_file($f)) gd_fix_perms($f, 0600, $uid, $rgid, $fixed);
	}

	// Per-site files. sites/ is root-only; hookinfo/ and status/ are read by the
	// webhook and panel pools, so they stay group-readable and never group-writable.
	foreach(glob(GD_SITES.'/*') ?: array() as $f){
		if(is_file($f)) gd_fix_perms($f, 0600, $uid, $rgid, $fixed);
	}
	if($wgid !== null){
		foreach(array(GD_HOOKINFO, GD_STATUS) as $dir){
			$files = array_merge(glob($dir.'/*') ?: array(), glob($dir.'/.[!.]*') ?: array());
			foreach($files as $f){
				if(is_file($f)) gd_fix_perms($f, 0640, $uid, $wgid, $fixed);
			}
		}
	}

	return $fixed;
}

/* ------------------------------------------------------------ global pause */

/**
 * Is the whole server paused? While paused the runner builds nothing and leaves
 * every queued job in the spool, so pushes are not lost — they just wait.
 * Deliberately a file test: the flag has to be readable/checkable even if the
 * JSON inside it is garbage, and "the file exists" is the whole contract.
 */
function gd_is_paused(){
	return is_file(GD_PAUSE_FILE);
}

// Who paused it and when, as ['ts' => int, 'by' => string]; empty when not paused.
function gd_pause_info(){
	if(!gd_is_paused()) return array();
	$d = gd_load_json(GD_PAUSE_FILE);
	if(!is_array($d)) $d = array();
	return array(
		'ts' => isset($d['ts']) ? (int)$d['ts'] : (int)@filemtime(GD_PAUSE_FILE),
		'by' => (isset($d['by']) && is_string($d['by'])) ? $d['by'] : '',
	);
}

/**
 * Turn the pause on or off. Root only (the flag lives in a root-only directory).
 * $by is recorded for the log/UI and is never used to make a decision.
 */
function gd_set_paused($on, $by = ''){
	if($on){
		return gd_save_json(GD_PAUSE_FILE, array(
			'ts' => time(),
			'by' => gd_valid_user($by) ? $by : '',
		), 0600);
	}
	if(!is_file(GD_PAUSE_FILE)) return true;
	return @unlink(GD_PAUSE_FILE);
}

/* --------------------------------------------------------- hosting accounts */

// True when <user> is a real Webuzo hosting/reseller account.
function gd_account_exists($user){
	return gd_valid_user($user) && is_file(GD_USERS.'/'.$user.'/info');
}

/**
 * The reseller who owns a hosting account ('' for accounts owned by the server
 * owner directly, or when the field is missing/not a valid username).
 */
function gd_account_owner($user){
	if(!gd_valid_user($user)) return '';
	$info = gd_load_json(GD_USERS.'/'.$user.'/info');
	$o = (is_array($info) && isset($info['owner']) && is_string($info['owner'])) ? $info['owner'] : '';
	return gd_valid_user($o) ? $o : '';
}

/* ------------------------------------------------------ per-account disk use */

/**
 * Measure one account's ~/.gitdeploy, AS THAT USER.
 *
 * Root never walks a user's home itself: the user owns every path component and
 * can swap one for a symlink between our stat and our read. bin/asuser.sh runs
 * behind setpriv (same pattern as gd_gc_orphans() in bin/runner.php) and prints
 * plain "key=kilobytes" lines, which is all we parse. `timeout` bounds it so a
 * slow or huge home can never wedge the caller.
 *
 * Returns array('total','work','releases','toolchains','ts') in KiB, or null.
 */
function gd_disk_measure($user, $timeout = 20){
	if(posix_getuid() !== 0) return null;               // only root can drop privileges
	if(!gd_valid_user($user)) return null;
	$pw = posix_getpwnam($user);
	if(empty($pw) || (int)$pw['uid'] < 1000) return null;
	if(!is_dir($pw['dir'])) return null;

	$timeout = (int)$timeout;
	if($timeout < 2)   $timeout = 2;
	if($timeout > 120) $timeout = 120;

	$cmd = array('/usr/bin/timeout', '-k', '5', (string)$timeout,
		'/usr/bin/setpriv', '--reuid='.(int)$pw['uid'], '--regid='.(int)$pw['gid'], '--init-groups',
		'/bin/bash', GD_BASE.'/bin/asuser.sh', 'disk');
	// No --reset-env: the explicit env array below IS the whitelist.
	$env  = array('HOME' => (string)$pw['dir'], 'USER' => $user, 'PATH' => '/usr/local/bin:/usr/bin:/bin');
	$desc = array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w'));
	$pipes = array();
	$proc = @proc_open($cmd, $desc, $pipes, '/tmp', $env);
	if(!is_resource($proc)) return null;
	$out = (string)stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	$code = proc_close($proc);
	if($code !== 0) return null;

	$vals = array('total' => 0, 'work' => 0, 'releases' => 0, 'toolchains' => 0);
	foreach(preg_split('~\r?\n~', $out) as $line){
		if(!preg_match('~^([a-z]+)=([0-9]{1,15})$~', trim($line), $m)) continue;
		if(array_key_exists($m[1], $vals)) $vals[$m[1]] = (int)$m[2];
	}
	$vals['ts'] = time();
	return $vals;
}

/**
 * Cached disk usage for a list of accounts.
 *
 * Rendering a web page must never sit and wait on du(1), so this returns whatever
 * is cached immediately and only re-measures entries older than $ttl — at most
 * until $budget seconds have been spent in total. Anything not measured in time
 * simply comes back stale (or missing) and the page says so.
 *
 * Returns array(user => array('total','work','releases','toolchains','ts','stale')).
 */
function gd_disk_usage($users, $ttl = 300, $budget = 6, $timeout = 6){
	$cache = gd_load_json(GD_DISK_CACHE);
	if(!is_array($cache)) $cache = array();

	$now     = time();
	$started = microtime(true);
	$dirty   = false;
	$out     = array();

	foreach($users as $u){
		if(!gd_valid_user($u)) continue;
		$have = (isset($cache[$u]) && is_array($cache[$u])) ? $cache[$u] : null;
		$age  = $have ? ($now - (int)(isset($have['ts']) ? $have['ts'] : 0)) : PHP_INT_MAX;

		if($age > $ttl && (microtime(true) - $started) < $budget){
			$fresh = gd_disk_measure($u, $timeout);
			if($fresh !== null){ $have = $fresh; $age = 0; $cache[$u] = $fresh; $dirty = true; }
		}
		if($have === null) continue;
		$have['stale'] = ($age > $ttl);
		$out[$u] = $have;
	}

	// Drop accounts that no longer exist so the cache cannot grow forever.
	foreach(array_keys($cache) as $u){
		if(!gd_valid_user($u) || !gd_account_exists($u)){ unset($cache[$u]); $dirty = true; }
	}
	if($dirty && posix_getuid() === 0) gd_save_json(GD_DISK_CACHE, $cache, 0600);

	return $out;
}
