<?php
/**
 * GitDeploy runner. Root, invoked by /etc/cron.d/gitdeploy every minute.
 * Stays resident ~50s polling the spool, so webhook->live latency is a few seconds.
 * All git/npm/rsync work happens in an unprivileged child (setpriv to the site user).
 */
error_reporting(E_ALL & ~E_DEPRECATED);
require '/usr/local/gitdeploy/lib/common.php';

const GD_BUILD_TIMEOUT = 2400; // hard cap per deploy, seconds
const GD_MAX_CONCURRENT = 2;   // builds running at once (protects a shared server)

if(posix_getuid() !== 0){ fwrite(STDERR, "runner must run as root\n"); exit(1); }

// Single instance across cron firings
$g = fopen(GD_LOCKS.'/runner.lock', 'c');
if(!$g || !flock($g, LOCK_EX | LOCK_NB)) exit(0);

@touch(GD_STATE.'/.heartbeat');   // proves cron is firing (see bin/check.sh)

// Re-assert the state permission model before anything reads or writes it. Drift here
// is a security bug (see the GD_STATE comment in lib/common.php), so it is corrected
// and then SHOUTED into the runner log rather than fixed silently.
foreach(gd_enforce_state_perms() as $fix){
	fwrite(STDOUT, date('c')." permission drift corrected: ".$fix."\n");
}

gd_reconcile_stale();
gd_sweep_tmp();
// Reclaiming a deleted site's build folder is still a change inside a user's home,
// so it waits too while the server owner has deploys paused.
if(!gd_is_paused()) gd_gc_orphans();

$deadline = time() + 50;
$kids = array();
$paused_logged = false;
do{
	/**
	 * Global pause (admin panel -> GitDeploy). Nothing is built and — this is the
	 * point — nothing is discarded either: job files stay in the spool exactly as
	 * they are, so every push that lands while paused deploys once it is lifted.
	 * Re-checked every pass so un-pausing takes effect within seconds, and said
	 * out loud once per run so nobody spends an hour debugging a "stuck" queue.
	 */
	if(gd_is_paused()){
		if(!$paused_logged){
			$info = gd_pause_info();
			$queued = count(glob(GD_SPOOL.'/*.json') ?: []);
			fwrite(STDOUT, date('c')." DEPLOYS ARE PAUSED by the server administrator"
				.(!empty($info['by']) ? ' ('.$info['by'].')' : '')
				.(!empty($info['ts']) ? ' since '.date('c', (int)$info['ts']) : '')
				."; ".$queued." job(s) left spooled, nothing will be built until the pause is"
				." lifted in the admin panel (or by removing ".GD_PAUSE_FILE.")\n");
			$paused_logged = true;
		}
		gd_reap($kids);
		sleep(3);
		continue;
	}
	if($paused_logged){
		fwrite(STDOUT, date('c')." pause lifted — draining the spool\n");
		$paused_logged = false;
	}

	foreach(glob(GD_SPOOL.'/*.json') ?: [] as $jobfile){
		gd_reap($kids);
		// keep a slot free rather than queueing everything into one slow chain
		while(count($kids) >= GD_MAX_CONCURRENT){ sleep(1); gd_reap($kids); if(time() > $deadline + 600) break 2; }
		$site = basename($jobfile, '.json');
		if(!gd_valid_site($site)){ @unlink($jobfile); continue; }

		$lock = fopen(GD_LOCKS.'/'.$site.'.lock', 'c');
		if(!$lock || !flock($lock, LOCK_EX | LOCK_NB)){
			// build already running for this site; leave job spooled for the next pass
			if($lock) fclose($lock);
			continue;
		}

		// Claim atomically: read-then-unlink would silently discard a webhook that
		// lands between the two calls. Renaming first means a push arriving during
		// the build simply creates a fresh job file (coalescing still works).
		$claim = $jobfile.'.claim.'.getmypid();
		if(!@rename($jobfile, $claim)){ flock($lock, LOCK_UN); fclose($lock); continue; }
		$job = gd_load_json($claim);
		@unlink($claim);

		$pid = pcntl_fork();
		if($pid === 0){
			// child: owns this site's lock (inherited fd) for the whole build
			gd_deploy($site, $job ?: []);
			exit(0);
		}elseif($pid > 0){
			$kids[$pid] = $lock;   // parent keeps the fd open until the child exits
		}else{
			gd_deploy($site, $job ?: []);   // fork failed: run inline
			flock($lock, LOCK_UN); fclose($lock);
		}
	}
	gd_reap($kids);
	sleep(3);
}while(time() < $deadline || count($kids) > 0);

// let running builds finish before the process exits (the next cron tick would
// otherwise start a second runner while these are still going)
while(count($kids) > 0){ sleep(2); gd_reap($kids); }

exit(0);

/**
 * A build killed by a reboot/OOM leaves state=building forever, which blocks
 * rollback (409) and makes the panel auto-refresh endlessly. If we can take the
 * site lock, nobody is building it — so the status is a corpse: mark it failed.
 */
function gd_reconcile_stale(){
	foreach(glob(GD_STATUS.'/*.json') ?: [] as $f){
		$site = basename($f, '.json');
		if(!gd_valid_site($site)) continue;
		$st = gd_load_json($f);
		if(!is_array($st) || ($st['state'] ?? '') !== 'building') continue;
		$lock = @fopen(GD_LOCKS.'/'.$site.'.lock', 'c');
		if(!$lock) continue;
		if(flock($lock, LOCK_EX | LOCK_NB)){
			gd_write_status($site, ['state' => 'failed', 'finished' => time(),
				'error' => 'interrupted (server restart or the build was killed)']);
			flock($lock, LOCK_UN);
		}
		fclose($lock);
	}
}

/**
 * Disk hygiene: a deleted site can leave its build folder (node_modules, releases)
 * and logs behind forever. Reclaim them once the config is gone.
 */
function gd_gc_orphans(){
	$known = array();
	foreach(glob(GD_SITES.'/*.json') ?: [] as $f) $known[basename($f, '.json')] = true;

	// orphaned per-site log dirs and status files (root-owned, safe to remove here)
	foreach(glob(GD_LOGS.'/*', GLOB_ONLYDIR) ?: [] as $d){
		$site = basename($d);
		if(gd_valid_site($site) && empty($known[$site])){
			foreach(glob($d.'/*') ?: [] as $f) @unlink($f);
			@rmdir($d);
		}
	}
	foreach(glob(GD_STATUS.'/*.json') ?: [] as $f){
		$site = basename($f, '.json');
		if(gd_valid_site($site) && empty($known[$site])) @unlink($f);
	}

	// orphaned build folders inside user homes — removed AS THE USER, never as root
	foreach(glob(GD_USERS.'/*', GLOB_ONLYDIR) ?: [] as $ud){
		$user = basename($ud);
		if(!gd_valid_user($user)) continue;
		$pw = posix_getpwnam($user);
		if(empty($pw) || $pw['uid'] < 1000) continue;
		foreach(glob($pw['dir'].'/.gitdeploy/*', GLOB_ONLYDIR) ?: [] as $wd){
			$site = basename($wd);
			// "toolchains" is a valid site name but is really the shared Node cache
			// build.sh installs; collecting it would silently force a 200 MB
			// re-download on the account's next build.
			if(in_array($site, GD_RESERVED_WORKDIRS, true)) continue;
			if(!gd_valid_site($site) || !empty($known[$site])) continue;
			// only reclaim once it has been idle a day (a site may be re-added)
			if(time() - (int)@filemtime($wd) < 86400) continue;
			$cmd = array('/usr/bin/setpriv', '--reuid='.$pw['uid'], '--regid='.$pw['gid'], '--init-groups',
				'/bin/bash', GD_BASE.'/bin/asuser.sh', 'rmwork', $site);
			$p = @proc_open($cmd, array(0 => array('file','/dev/null','r'), 1 => array('file','/dev/null','w'), 2 => array('file','/dev/null','w')),
				$pipes, $pw['dir'], array('HOME' => $pw['dir'], 'USER' => $user, 'PATH' => '/usr/local/bin:/usr/bin:/bin'));
			if(is_resource($p)) proc_close($p);
		}
	}
}

// Writers that die between write and rename leave .tmp.PID files behind forever.
function gd_sweep_tmp(){
	foreach([GD_SITES, GD_HOOKINFO, GD_SPOOL, GD_STATUS] as $dir){
		foreach(glob($dir.'/*.tmp.*') ?: [] as $t){
			if(is_file($t) && (time() - filemtime($t)) > 3600) @unlink($t);
		}
	}
}

// Collect finished build children and release their per-site locks.
function gd_reap(&$kids){
	foreach($kids as $pid => $lock){
		$r = pcntl_waitpid($pid, $st, WNOHANG);
		if($r === $pid || $r === -1){
			if(is_resource($lock)){ @flock($lock, LOCK_UN); @fclose($lock); }
			unset($kids[$pid]);
		}
	}
}

function gd_deploy($site, $job){
	$cfg = gd_load_site($site);
	if(!$cfg){
		gd_write_status($site, ['state' => 'failed', 'error' => 'invalid or missing site config', 'finished' => time()]);
		return;
	}

	$doc = gd_resolve_docroot($cfg['user'], $site);
	if(!$doc){
		gd_write_status($site, ['state' => 'failed', 'error' => 'domain no longer exists in Webuzo or docroot invalid', 'finished' => time()]);
		return;
	}

	$pw = posix_getpwnam($cfg['user']);
	if(empty($pw) || $pw['uid'] < 1000){
		gd_write_status($site, ['state' => 'failed', 'error' => 'system user missing', 'finished' => time()]);
		return;
	}

	// Never clobber other live sites: refuse shared docroots unless explicitly opted in
	$risk = gd_docroot_risk($cfg['user'], $site);
	if($risk === null || ((!empty($risk['shared_with']) || $risk['is_home_docroot']) && empty($cfg['allow_shared_docroot']))){
		$others = $risk ? implode(', ', $risk['shared_with']) : '?';
		gd_write_status($site, ['state' => 'failed', 'finished' => time(),
			'error' => 'refused: docroot is shared with other domains ('.$others.') or is the main public_html. Set allow_shared_docroot to override.']);
		return;
	}

	if(!is_dir(GD_LOGS.'/'.$site)) mkdir(GD_LOGS.'/'.$site, 0700, true);
	$logname = 'deploy-'.date('Ymd-His').'.log';
	$logpath = GD_LOGS.'/'.$site.'/'.$logname;
	$log = fopen($logpath, 'w');
	if(!$log){
		// couldn't even open a log (disk full?) — record it and re-queue so the
		// push is retried instead of vanishing without a trace
		gd_write_status($site, ['state' => 'failed', 'finished' => time(),
			'error' => 'could not open build log (disk full?)']);
		gd_queue_job($site, ($job['trigger'] ?? 'retry'));
		return;
	}
	fwrite($log, "trigger: ".($job['trigger'] ?? 'unknown')."\n");

	gd_write_status($site, ['state' => 'building', 'started' => time(), 'finished' => null,
		'error' => null, 'trigger' => $job['trigger'] ?? '', 'log' => $logname]);

	$env = [
		'HOME' => $pw['dir'], 'USER' => $cfg['user'], 'LOGNAME' => $cfg['user'],
		'PATH' => '/usr/local/bin:/usr/bin:/bin',
		'GD_SITE' => $site, 'GD_REPO' => $cfg['repo'], 'GD_BRANCH' => $cfg['branch'],
		'GD_DEPLOY_MODE' => gd_site_mode($cfg),
		// Verbatim, empty included: empty means "work it out from the repository", and
		// only build.sh can see the repository, so it is the one that decides.
		'GD_BUILD_CMD' => isset($cfg['build_cmd']) ? (string)$cfg['build_cmd'] : '',
		'GD_OUTPUT_DIR' => isset($cfg['output_dir']) ? (string)$cfg['output_dir'] : '',
		'GD_DOCROOT' => $doc, 'GD_KEY' => isset($cfg['deploy_key']) ? (string)$cfg['deploy_key'] : '',
		'GD_ROOT_DIR' => isset($cfg['root_dir']) ? (string)$cfg['root_dir'] : '',
		'GD_NODE_VERSION' => isset($cfg['node_version']) ? (string)$cfg['node_version'] : '',
		'GD_POST_CMD' => isset($cfg['post_cmd']) ? (string)$cfg['post_cmd'] : '',
	];
	foreach(($cfg['env'] ?? []) as $k => $v){
		if(preg_match('~^[A-Za-z_][A-Za-z0-9_]*$~', $k)) $env['GD_ENV_'.$k] = (string)$v;
	}

	// GitHub App site: mint a 1-hour installation token for the clone (never persisted).
	// Rollbacks publish an already-archived release and need no GitHub access at all,
	// so a disconnected app must never block them.
	if(!empty($cfg['gh_installation_id']) && ($job['mode'] ?? '') !== 'rollback'){
		require_once GD_BASE.'/lib/ghapp.php';
		$creds = ghapp_load($cfg['user']);
		$gherr = '';
		$token = $creds ? ghapp_inst_token($creds, $cfg['gh_installation_id'], $gherr) : null;
		if(!$token){
			$why = $creds ? $gherr : 'app not connected';
			fwrite($log, "FATAL: could not get GitHub App token: ".$why."\n");
			fclose($log);
			gd_write_status($site, ['state' => 'failed', 'error' => 'GitHub App token: '.$why, 'finished' => time()]);
			// transient GitHub trouble (5xx / rate limit / network) — retry rather
			// than silently dropping the push; a real misconfiguration just loops
			// harmlessly at one attempt per minute until fixed.
			if($creds && !preg_match('~API 40[0-4]~', (string)$gherr)) gd_queue_job($site, 'retry');
			return;
		}
		$env['GD_TOKEN'] = $token;
	}

	// Rollback job: publish an archived release instead of building
	if(($job['mode'] ?? '') === 'rollback'){
		if(!preg_match('~^[0-9a-f]{40}$~', $job['sha'] ?? '')){
			fwrite($log, "FATAL: rollback job without a valid sha\n");
			fclose($log);
			gd_write_status($site, ['state' => 'failed', 'error' => 'invalid rollback sha', 'finished' => time()]);
			return;
		}
		$env['GD_MODE'] = 'rollback';
		$env['GD_SHA'] = $job['sha'];
	}

	// setsid => child leads its own process group so the timeout can kill the whole tree.
	// No --reset-env: proc_open's explicit $env array IS the whitelist; --reset-env would wipe GD_*.
	$cmd = ['/usr/bin/setsid', '-w', '/usr/bin/setpriv',
		'--reuid='.$pw['uid'], '--regid='.$pw['gid'], '--init-groups',
		'/bin/bash', GD_BASE.'/bin/build.sh'];

	$proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => $log, 2 => $log], $pipes, $pw['dir'], $env);
	if(!is_resource($proc)){
		fwrite($log, "FATAL: could not start build process\n");
		fclose($log);
		gd_write_status($site, ['state' => 'failed', 'error' => 'spawn failed', 'finished' => time()]);
		return;
	}

	$start = time(); $code = -1;
	while(true){
		$st = proc_get_status($proc);
		if(!$st['running']){ $code = $st['exitcode']; break; }
		if(time() - $start > GD_BUILD_TIMEOUT){
			fwrite($log, "\nFATAL: build timed out after ".GD_BUILD_TIMEOUT."s — killing\n");
			@posix_kill(-$st['pid'], SIGKILL);
			proc_terminate($proc, SIGKILL);
			$code = 124;
			break;
		}
		sleep(2);
	}
	proc_close($proc);
	fclose($log);

	$patch = ['state' => $code === 0 ? 'success' : 'failed', 'exit' => $code, 'finished' => time()];
	// the site user owns this path, so refuse symlinks: root must not be tricked
	// into reading an arbitrary root-readable file through one
	$sha_file = $pw['dir'].'/.gitdeploy/'.$site.'/last_ok_sha';
	if($code === 0 && !is_link($sha_file) && is_file($sha_file)){
		$sha = trim((string)file_get_contents($sha_file));
		if(preg_match('~^[0-9a-f]{40}$~', $sha)){ $patch['last_sha'] = $sha; $patch['last_ok_sha'] = $sha; }
	}
	gd_write_status($site, $patch);
	gd_prune_logs($site, 10);

	// Email notification: on failure by default, or always if notify_on = always
	$email = isset($cfg['notify_email']) ? $cfg['notify_email'] : '';
	$when  = isset($cfg['notify_on']) ? $cfg['notify_on'] : 'fail';
	if($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && $when !== 'never'
		&& ($patch['state'] === 'failed' || $when === 'always')){
		gd_notify($email, $site, $patch, $logpath);
	}
}

function gd_notify($email, $site, $patch, $logpath){
	$ok = $patch['state'] === 'success';
	$host = php_uname('n');
	$subject = '[GitDeploy] '.$site.' — deploy '.($ok ? 'succeeded' : 'FAILED');

	$body = 'Site:     '.$site."\n"
		.'Result:   '.$patch['state'].(isset($patch['exit']) ? ' (exit '.$patch['exit'].')' : '')."\n"
		.(isset($patch['last_sha']) ? 'Commit:   '.$patch['last_sha']."\n" : '')
		.'Finished: '.date('Y-m-d H:i:s T', $patch['finished'])."\n"
		.'Server:   '.$host."\n\n";
	if(is_file($logpath)){
		$size = filesize($logpath);
		$fh = fopen($logpath, 'r');
		if($fh){
			if($size > 8192) fseek($fh, -8192, SEEK_END);
			$tail = stream_get_contents($fh);
			fclose($fh);
			$body .= "--- last lines of the deploy log ---\n".$tail."\n";
		}
	}
	$body .= "\nFull logs: enduser panel -> Apps -> GitDeploy -> Logs\n";

	$msg = 'To: '.$email."\r\n"
		.'From: GitDeploy <gitdeploy@'.$host.">\r\n"
		.'Subject: '.$subject."\r\n"
		.'Content-Type: text/plain; charset=UTF-8'."\r\n\r\n".$body;

	// timeout: proc_close() blocks until sendmail exits, and this runs while the
	// global runner lock is held — a wedged MTA would stop deploys server-wide.
	$proc = proc_open(['/usr/bin/timeout', '15', '/usr/sbin/sendmail', '-t', '-i'],
		[0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
	if(is_resource($proc)){
		fwrite($pipes[0], $msg);
		fclose($pipes[0]);
		proc_close($proc);
	}
}
