<?php
/**
 * GitDeploy — server administrator overview (Webuzo admin panel, ports 2004/2005).
 * Served by EMPS nginx via fpm-root.sock: THIS RUNS AS ROOT.
 *
 * Read-mostly on purpose. The server owner can see every account's deployments,
 * pause deploys server-wide, re-run a deploy and read a build log. Site
 * configuration (repository, branch, build command, keys, deletion) stays with
 * the account that owns it — this page never edits or removes a site.
 *
 * Access rules, enforced in this order and nowhere else:
 *   1. the request must be on the admin panel (Webuzo_Sessions::is_admin_panel())
 *   2. there must be a valid panel session (isLogin())
 *   3. user 'root' sees every hosting account
 *   4. any other admin-panel user is treated as a reseller and sees ONLY the
 *      accounts whose /var/webuzo/users/<u>/info says owner = that user
 *   5. anything else is refused
 *
 * Identity comes only from the session SDK, never from the request. No user
 * string ever reaches a shell (proc_open argv arrays only, in lib/common.php).
 * Every state change is POST + CSRF token.
 */

error_reporting(0);

include_once('/usr/local/webuzo/sdk/sessions.php');
require '/usr/local/gitdeploy/lib/common.php';

/* ------------------------------------------------------------ access control */

$sess = new Webuzo_Sessions();

/*
 * Rule 1. A copy of this file reachable on the enduser port (2002/2003) must not
 * run at all: it is a root endpoint that reports on every account on the box.
 * Checked before isLogin(), so an enduser session can never even get as far as
 * the session lookup.
 */
if(!method_exists($sess, 'is_admin_panel') || !$sess->is_admin_panel()){
	gd_refuse('Not available here',
		'The GitDeploy server overview only runs in the administrator panel (port 2004/2005). '
		.'If you are a hosting user, open GitDeploy from your own panel under Apps.', 403);
}

$logged_in = $sess->isLogin();
if(empty($logged_in)) $sess->show_login();

$user = isset($sess->user['user']) ? (string)$sess->user['user'] : '';
if($user === '' || !gd_valid_user($user)){
	gd_refuse('Not signed in', 'We could not work out who you are. Please sign in to the administrator panel again.', 403);
}

$is_root = ($user === 'root');

// Rule 5: a non-root panel user we cannot find in Webuzo's own records gets nothing.
if(!$is_root && !gd_account_exists($user)){
	gd_refuse('No access', 'This page is for the server owner and for resellers with their own accounts. '
		.'Your account was not found in the server records, so there is nothing to show you.', 403);
}

$GD_KEYFILE = GD_STATE.'/.csrf_key';
$csrf_key = trim((string)@file_get_contents($GD_KEYFILE));
if($csrf_key === ''){
	gd_refuse('Not set up yet', 'The GitDeploy server key is missing. Run /usr/local/gitdeploy/install.sh --repair on the server.', 500);
}
$TOK = hash_hmac('sha256', (string)(isset($sess->SESS['sid']) ? $sess->SESS['sid'] : ''), $csrf_key);

/* ------------------------------------------------------------------ helpers */

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function gd_post($k){ return isset($_POST[$k]) && is_string($_POST[$k]) ? $_POST[$k] : ''; }
function gd_get($k){ return isset($_GET[$k]) && is_string($_GET[$k]) ? $_GET[$k] : ''; }

/**
 * Refusal page. Deliberately standalone — no session, no scope, no chrome that
 * could itself fail — because it is what a request that got past nothing sees.
 */
function gd_refuse($title, $msg, $code = 403){
	http_response_code($code);
	header('Content-Type: text/html; charset=UTF-8');
	echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
		.'<meta name="viewport" content="width=device-width, initial-scale=1">'
		.'<title>GitDeploy</title>'
		.'<link rel="stylesheet" href="/themes/default/bootstrap/css/bootstrap.min.css">'
		.'<link rel="stylesheet" href="/themes/default/css/style.css">'
		.'</head><body><div style="max-width:640px;margin:40px auto;padding:0 18px">'
		.'<h1 style="font-size:20px;margin-bottom:10px">GitDeploy — '.h($title).'</h1>'
		.'<div class="alert alert-warning" role="alert">'.h($msg).'</div>'
		.'</div></body></html>';
	exit;
}

function gd_asset_v(){
	$t = @filemtime(GD_BASE.'/web/enduser/assets/style.css');
	if($t) return (int)$t;
	$v = @file_get_contents(GD_BASE.'/VERSION');
	return $v ? preg_replace('~[^0-9a-zA-Z.]~', '', trim($v)) : '1';
}

function gd_tok_field(){ global $TOK; echo '<input type="hidden" name="tok" value="'.h($TOK).'">'; }

function gd_check_csrf(){
	global $TOK;
	$t = isset($_POST['tok']) ? $_POST['tok'] : '';
	if(!is_string($t) || !hash_equals($TOK, $t)){
		gd_stop('403 — bad form token',
			'This form is stale or was not submitted from the panel. Reload the page and try again.', 403);
	}
}

// Relative redirect: keeps the /sessXXXX/ URL prefix intact in the browser.
function gd_redirect($qs){
	header('Location: '.$qs);
	echo '<!doctype html><meta http-equiv="refresh" content="0;url='.h($qs).'">'
		.'<p><a href="'.h($qs).'">Continue</a></p>';
	exit;
}

function gd_note($html, $type = 'info'){
	$map = array('info' => 'alert-info', 'ok' => 'alert-success', 'warn' => 'alert-warning', 'err' => 'alert-danger');
	$cls = isset($map[$type]) ? $map[$type] : 'alert-info';
	echo '<div class="alert '.$cls.' gd-note" role="alert">'.$html.'</div>';
}

function gd_stop($title, $msg, $code = 400){
	http_response_code($code);
	gd_head($title);
	gd_card('<i class="fas fa-exclamation-triangle panel-icon"></i>'.h($title));
	gd_note(h($msg), 'err');
	echo '<a class="flat-butt-primary" href="?act=list"><i class="fas fa-arrow-left me-1"></i>Back to the overview</a>';
	gd_card_end();
	gd_foot();
	exit;
}

function gd_when($ts){ return empty($ts) ? '—' : date('Y-m-d H:i:s', (int)$ts); }

function gd_ago($ts){
	$ts = (int)$ts;
	if($ts <= 0) return '';
	$d = time() - $ts;
	if($d < 0) $d = 0;
	if($d < 45)      return 'just now';
	if($d < 90)      return 'a minute ago';
	if($d < 3600)   { $n = (int)round($d / 60);      return $n.' minute'.($n === 1 ? '' : 's').' ago'; }
	if($d < 86400)  { $n = (int)round($d / 3600);    return $n.' hour'.($n === 1 ? '' : 's').' ago'; }
	if($d < 2592000){ $n = (int)round($d / 86400);   return $n.' day'.($n === 1 ? '' : 's').' ago'; }
	if($d < 31536000){$n = (int)round($d / 2592000); return $n.' month'.($n === 1 ? '' : 's').' ago'; }
	$n = (int)round($d / 31536000); return $n.' year'.($n === 1 ? '' : 's').' ago';
}

function gd_rel($ts, $empty = '—'){
	$ts = (int)$ts;
	if($ts <= 0) return h($empty);
	return '<time title="'.h(gd_when($ts)).'">'.h(gd_ago($ts)).'</time>';
}

function gd_dur($secs){
	$secs = (int)$secs;
	if($secs <= 0) return '';
	if($secs < 60) return $secs.'s';
	$m = (int)floor($secs / 60); $s = $secs % 60;
	if($m < 60) return $m.'m '.$s.'s';
	$hh = (int)floor($m / 60); $m = $m % 60;
	return $hh.'h '.$m.'m';
}

// KiB -> a size people read. Anything under a megabyte is not worth a decimal.
function gd_size($kb){
	$kb = (int)$kb;
	if($kb <= 0) return '0';
	if($kb < 1024) return $kb.' KB';
	$mb = $kb / 1024;
	if($mb < 1024) return number_format($mb, $mb < 10 ? 1 : 0).' MB';
	return number_format($mb / 1024, 2).' GB';
}

function gd_short_repo($repo){
	$r = (string)$repo;
	if(preg_match('~^https://github\.com/(.+?)(?:\.git)?/?$~', $r, $m)) return $m[1];
	if(preg_match('~^git@[^:]+:(.+?)(?:\.git)?$~', $r, $m)) return $m[1];
	return $r;
}

function gd_status($site){
	$st = gd_load_json(GD_STATUS.'/'.$site.'.json');
	return is_array($st) ? $st : array();
}

function gd_queued($site){ return is_file(GD_SPOOL.'/'.$site.'.json'); }

// Same rule as the enduser page: a "building" status nothing has touched for
// longer than the runner's own build cap is a corpse, not a running build.
define('GD_STALE_BUILD', 1800);

function gd_stalled($st){
	if(!is_array($st) || !isset($st['state']) || $st['state'] !== 'building') return false;
	$started = isset($st['started']) ? (int)$st['started'] : 0;
	return ($started <= 0) || ((time() - $started) > GD_STALE_BUILD);
}

function gd_state_key($st, $queued = false){
	$state = isset($st['state']) ? (string)$st['state'] : '';
	if($state === 'building' && gd_stalled($st)) return 'stalled';
	if($state === 'building') return 'building';
	if($queued) return 'queued';
	if($state === 'success') return 'live';
	if($state === 'failed')  return 'failed';
	return 'none';
}

// Status pill: colour AND an icon AND a word — never colour alone.
function gd_badge($st, $queued = false){
	$map = array(
		'stalled'  => array('gd-pill-warn',  'fa-exclamation-triangle', 'Stalled'),
		'building' => array('gd-pill-run',   'fa-circle-notch fa-spin', 'Building'),
		'queued'   => array('gd-pill-queue', 'fa-clock',                'Queued'),
		'live'     => array('gd-pill-ok',    'fa-check-circle',         'Live'),
		'failed'   => array('gd-pill-fail',  'fa-times-circle',         'Failed'),
		'none'     => array('gd-pill-idle',  'fa-minus-circle',         'Never deployed'),
	);
	$m = $map[gd_state_key($st, $queued)];
	return '<span class="gd-pill '.$m[0].'"><i class="fas '.$m[1].'" aria-hidden="true"></i><span>'.$m[2].'</span></span>';
}

function gd_short_sha($st){
	$sha = isset($st['last_sha']) ? (string)$st['last_sha'] : '';
	return preg_match('~^[0-9a-f]{7,40}$~', $sha) ? substr($sha, 0, 7) : '';
}

/* -------------------------------------------------------------------- scope */

/**
 * Every site this admin-panel user is allowed to see.
 *   root      -> all of them
 *   reseller  -> only sites belonging to accounts whose info['owner'] is them
 * Returns array('sites' => site => cfg, 'users' => [account, ...], 'broken' => int).
 */
function gd_scope_sites($viewer, $is_root){
	$sites = array();
	$users = array();
	$broken = 0;
	foreach(glob(GD_SITES.'/*.json') ?: array() as $f){
		$site = basename($f, '.json');
		if(!gd_valid_site($site)) continue;
		$cfg = gd_load_site($site);
		if(!$cfg){ if($is_root) $broken++; continue; }
		$owner_acct = (string)$cfg['user'];
		// Deliberately strict: ownership is ONLY info['owner'] == the reseller. A
		// reseller's own hosting account is not matched unless someone set owner on
		// it, which errs towards showing less. Widening this is a one-line change,
		// but it is a security boundary, so it does not get widened by accident.
		if(!$is_root && gd_account_owner($owner_acct) !== $viewer) continue;
		$sites[$site] = $cfg;
		$users[$owner_acct] = true;
	}
	ksort($sites);
	$users = array_keys($users);
	sort($users);
	return array('sites' => $sites, 'users' => $users, 'broken' => $broken);
}

// A site the viewer is allowed to act on, or a hard stop. Never trusts $_GET/$_POST.
function gd_require_scoped($site, $scope){
	if(!gd_valid_site($site) || !isset($scope['sites'][$site])){
		gd_stop('Site not found', 'That site is not one of the sites you can see from here.', 404);
	}
	return $scope['sites'][$site];
}

/* -------------------------------------------------------------- page chrome */

function gd_head($subtitle = '', $refresh = 0){
	global $user, $is_root;
	echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
		.'<meta name="viewport" content="width=device-width, initial-scale=1">'
		.'<title>GitDeploy — server overview</title>'
		// the Webuzo theme's own assets, same order as themes/default/hf_theme.php
		.'<link rel="stylesheet" href="/themes/default/bootstrap/css/bootstrap.min.css">'
		.'<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700'
		.'&amp;family=Poppins:wght@300;400;500;600;700;800;900&amp;display=swap" rel="stylesheet">'
		.'<link rel="stylesheet" href="/themes/default/css/style.css">'
		.'<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">'
		.'<link rel="stylesheet" href="assets/style.css?v='.gd_asset_v().'">'
		// admin-only extras. The shared stylesheet is the enduser page's and is not
		// edited from here, so the handful of table/tile rules this page needs live
		// with the page that uses them.
		.'<style>'
		.'.gd-tiles{display:flex;flex-wrap:wrap;gap:12px}'
		.'.gd-tile{flex:1 1 150px;min-width:140px;padding:12px 14px;border:1px solid #eff2f7;border-radius:8px;background:#fafbfd}'
		.'.gd-tile-n{font-size:22px;font-weight:700;line-height:1.1;color:#1d1d1b}'
		.'.gd-tile-l{font-size:12px;text-transform:uppercase;letter-spacing:.4px;color:#71758d;font-weight:600;margin-top:4px}'
		.'.gd-tile-s{font-size:12px;color:#71758d;margin-top:2px}'
		.'.gd-tile-bad .gd-tile-n{color:#a92b2b}.gd-tile-run .gd-tile-n{color:#3c40b8}.gd-tile-ok .gd-tile-n{color:#1a7040}'
		.'.gd-tbl{width:100%;font-size:13px;border-collapse:collapse}'
		.'.gd-tbl th{font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#71758d;font-weight:600;'
		.'text-align:left;padding:8px 10px;border-bottom:1px solid #eff2f7;white-space:nowrap}'
		.'.gd-tbl td{padding:10px;border-bottom:1px solid #eff2f7;vertical-align:top}'
		.'.gd-tbl tr:last-child td{border-bottom:0}'
		.'.gd-tbl td.num{text-align:right;white-space:nowrap}.gd-tbl th.num{text-align:right}'
		.'.gd-scroll{overflow-x:auto}'
		.'.gd-dom{font-weight:600;color:#1d1d1b;text-decoration:none;word-break:break-all}'
		.'.gd-dom:hover{color:#4f46e5}'
		.'.gd-err{color:#a92b2b;font-size:12px;margin-top:4px;line-height:1.45}'
		.'.gd-paused{border-left:4px solid #8a6100}'
		.'</style>';
	if((int)$refresh > 0) echo '<meta http-equiv="refresh" content="'.(int)$refresh.'">';
	echo '<script>if(window.self!==window.top)document.documentElement.className="embedded";</script>';
	echo '</head><body><div class="gd-wrap">'
		.'<div class="gd-head d-flex align-items-center justify-content-between flex-wrap mb-4">'
		.'<div class="gd-brand d-flex align-items-center">'
		.'<img src="/gitdeploy/assets/gitdeploy_48.png" alt="" width="40" height="40" class="me-3">'
		.'<div><h1 class="panel-head mb-0">GitDeploy</h1><div class="soft-card-desc">'
		.h($subtitle !== '' ? $subtitle : 'Every deployment on this server, in one place')
		.' &middot; '.h($is_root ? 'server owner' : 'reseller '.$user).'</div></div></div>'
		.'<div class="gd-nav"><a class="flat-butt-primary" href="?act=list">'
		.'<i class="fas fa-server me-1"></i>Overview</a></div></div>';
}

function gd_foot(){
	echo '<div class="gd-powered">Powered by <a href="https://www.digitalbkk.com" target="_blank" rel="noopener">Digitalbkk Co., Ltd.</a>'
		.' &middot; developed by the Digitalbkk team &middot; author Hasif Ahmed</div>'
		.'<div class="gd-foot">GitDeploy &middot; builds run as each site\'s own user &middot; server time '.h(date('Y-m-d H:i:s')).'</div>'
		.'</div></body></html>';
}

function gd_card($title, $sub = ''){
	echo '<div class="card soft-card mb-4">'
		.'<div class="row align-items-center panel-heading px-4 py-3"><div class="col-12">'
		.'<label class="panel-head d-inline-block mb-0">'.$title.'</label>'
		.($sub !== '' ? '<div class="soft-card-desc mt-1">'.$sub.'</div>' : '')
		.'</div></div><div class="card-body px-4 py-4">';
}

function gd_card_end(){ echo '</div></div>'; }

function gd_h3($text){ echo '<h3 class="gd-h3">'.h($text).'</h3>'; }

// "Deploy now" — a CSRF-protected POST. The handler re-checks scope and ownership.
function gd_deploy_form($site, $sm = true){
	echo '<form class="d-inline" method="post" action="?act=deploy">';
	gd_tok_field();
	echo '<input type="hidden" name="site" value="'.h($site).'">'
		.'<button class="flat-butt'.($sm ? ' btn-sm' : '').'" type="submit">'
		.'<i class="fas fa-rocket me-1"></i>Deploy now</button></form>';
}

/* ------------------------------------------------------------------ routing */

$scope = gd_scope_sites($user, $is_root);

$act     = gd_get('act');
$is_post = (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST');
$msg     = gd_get('msg');
$msgsite = gd_get('site');

/* ---- POST: deploy one site ---- */
if($act === 'deploy' && $is_post){
	gd_check_csrf();
	$site = gd_post('site');
	gd_require_scoped($site, $scope);
	if(gd_is_paused()) gd_redirect('?act=list&msg=pausedjob');
	if(gd_queued($site)) gd_redirect('?act=list&msg=already&site='.rawurlencode($site));
	if(!gd_queue_job($site, 'manual')) gd_stop('Could not queue the deploy',
		'The job file could not be written. Check the disk and /var/lib/gitdeploy/spool.', 500);
	gd_redirect('?act=list&msg=queued&site='.rawurlencode($site));
}

/* ---- POST: pause / resume every deploy on the server (root only) ---- */
if($act === 'pause' && $is_post){
	gd_check_csrf();
	// A reseller pausing the whole server would take out other people's sites.
	if(!$is_root) gd_stop('Not allowed', 'Only the server owner can pause deploys server-wide.', 403);
	$want = (gd_post('to') === 'on');
	if(!gd_set_paused($want, $user)) gd_stop('Could not change the pause setting',
		'The flag file under /var/lib/gitdeploy could not be written or removed.', 500);
	gd_redirect('?act=list&msg='.($want ? 'paused' : 'resumed'));
}

/* ---- POST: re-measure disk usage now (bounded, still runs as each user) ---- */
if($act === 'disk' && $is_post){
	gd_check_csrf();
	// ttl 0 = measure everything in scope, with a generous but finite budget.
	gd_disk_usage($scope['users'], 0, 45, 20);
	gd_redirect('?act=list&msg=measured');
}

/* ---- one site's build log (read-only) ---- */
if($act === 'log' && !$is_post){
	$site = gd_get('site');
	$cfg  = gd_require_scoped($site, $scope);
	$logs = gd_site_logs($site);
	$want = gd_get('file');
	$path = isset($logs[0]) ? $logs[0] : '';
	if($want !== ''){
		// only a file this site actually owns, matched against the real listing
		if(!preg_match('~^deploy-[0-9-]+\.log$~', $want) || !in_array(GD_LOGS.'/'.$site.'/'.$want, $logs, true)){
			gd_stop('Unknown log', 'That log file does not belong to this site.', 404);
		}
		$path = GD_LOGS.'/'.$site.'/'.$want;
	}
	$st     = gd_status($site);
	$queued = gd_queued($site);
	$busy   = ($queued || (isset($st['state']) && $st['state'] === 'building' && !gd_stalled($st)));

	gd_head('Build log — '.$site, $busy ? 10 : 0);
	echo '<nav class="gd-crumbs"><a href="?act=list">Server overview</a> / '.h($cfg['user']).' / '.h($site).'</nav>';

	gd_card('<i class="fas fa-rocket panel-icon"></i>'.h($site),
		'Account <strong>'.h($cfg['user']).'</strong> &middot; this is the account owner\'s site — '
		.'settings are changed from their own panel, not here.');
	echo '<div class="gd-status">'
		.'<div class="gd-status-pill">'.gd_badge($st, $queued).'</div>'
		.'<div class="gd-status-facts">';
	$facts = array();
	if(!empty($st['finished']))     $facts[] = 'Finished '.gd_rel($st['finished']);
	elseif(!empty($st['started']))  $facts[] = 'Started '.gd_rel($st['started']);
	$secs = (isset($st['started'], $st['finished']) && (int)$st['finished'] >= (int)$st['started'])
		? ((int)$st['finished'] - (int)$st['started']) : 0;
	if($secs > 0) $facts[] = 'took '.h(gd_dur($secs));
	if(gd_short_sha($st) !== '') $facts[] = 'commit <span class="mono">'.h(gd_short_sha($st)).'</span>';
	echo implode(' <span class="gd-dot">&middot;</span> ', $facts);
	echo '</div><div class="gd-status-side"><span class="mono">'.h(gd_short_repo($cfg['repo'])).'</span> '
		.'<span class="gd-dot">&middot;</span> <span class="mono"><i class="fas fa-code-branch me-1" aria-hidden="true"></i>'
		.h($cfg['branch']).'</span></div></div>';
	if(!empty($st['error'])) gd_note('<i class="fas fa-times-circle me-1"></i>'.h($st['error']), 'err');
	if(gd_is_paused()) gd_note('<i class="fas fa-pause-circle me-1"></i>Deploys are paused server-wide, '
		.'so a new deploy would sit in the queue until you resume them.', 'warn');
	echo '<div class="gd-cta">';
	gd_deploy_form($site, false);
	echo ' <a class="flat-butt-primary" href="?act=list"><i class="fas fa-arrow-left me-1"></i>Back to the overview</a></div>';
	gd_card_end();

	gd_card('<i class="fas fa-terminal panel-icon"></i>Build output',
		'Exactly what the build printed. Read-only.');
	if($logs){
		gd_h3('Recent builds');
		echo '<ul class="gd-logs">';
		foreach(array_slice($logs, 0, 10) as $i => $l){
			$b   = basename($l);
			$cur = ($l === $path);
			echo '<li'.($cur ? ' class="cur"' : '').'><a href="?act=log&amp;site='.h(rawurlencode($site))
				.'&amp;file='.h(rawurlencode($b)).'">'.gd_rel(@filemtime($l)).'</a>'
				.($i === 0 ? ' <span class="gd-tag">latest</span>' : '')
				.($cur ? ' <span class="gd-tag">you are here</span>' : '')
				.' <span class="hint">'.h($b).'</span></li>';
		}
		echo '</ul>';
	}
	gd_h3('Output');
	if($path === '' || !is_file($path)){
		echo '<div class="gd-empty"><i class="fas fa-terminal fa-2x d-block mb-2" aria-hidden="true"></i>'
			.'<div class="gd-empty-t">Nothing to show yet</div>'
			.'<p>This site has not produced any build output on this server yet.</p></div>';
	}else{
		clearstatcache(true, $path);
		$size = (int)filesize($path);
		$max  = 262144;
		$off  = $size > $max ? $size - $max : 0;
		$txt  = (string)file_get_contents($path, false, null, $off, $max);
		if($off > 0) $txt = "…truncated, showing the last 256 KB…\n".$txt;
		echo '<div class="gd-logbar"><span>Showing the build from '.gd_rel(@filemtime($path)).'</span></div>'
			.'<pre id="gdlog">'.h($txt !== '' ? $txt : '(empty)').'</pre>';
	}
	gd_card_end();
	gd_foot();
	exit;
}

/* ------------------------------------------------------------------ overview */

$rows = array();
$counts = array('live' => 0, 'failed' => 0, 'building' => 0, 'queued' => 0, 'stalled' => 0, 'none' => 0);
$anybusy = false;

foreach($scope['sites'] as $site => $cfg){
	$st  = gd_status($site);
	$q   = gd_queued($site);
	$key = gd_state_key($st, $q);
	$counts[$key]++;
	if($key === 'building' || $key === 'queued') $anybusy = true;
	$last = 0;
	if(!empty($st['finished']))     $last = (int)$st['finished'];
	elseif(!empty($st['started']))  $last = (int)$st['started'];
	$rows[] = array('site' => $site, 'cfg' => $cfg, 'st' => $st, 'q' => $q, 'key' => $key, 'last' => $last);
}

// Problems first, then whatever is moving, then everything that is simply fine.
// Within a group the most recent activity comes first.
$rank = array('failed' => 0, 'stalled' => 1, 'building' => 2, 'queued' => 3, 'none' => 4, 'live' => 5);
usort($rows, function($a, $b) use ($rank){
	if($rank[$a['key']] !== $rank[$b['key']]) return $rank[$a['key']] - $rank[$b['key']];
	if($a['last'] !== $b['last']) return $b['last'] - $a['last'];
	return strcmp($a['site'], $b['site']);
});

$paused  = gd_is_paused();
$pinfo   = gd_pause_info();
$hb      = @filemtime(GD_STATE.'/.heartbeat');
$hb_age  = $hb ? (time() - (int)$hb) : -1;
$hb_ok   = ($hb_age >= 0 && $hb_age < 180);   // same threshold as bin/check.sh

gd_head('', $anybusy ? 20 : 0);

$notes = array(
	'queued'    => array('ok',   'Deploy queued for <strong>'.h($msgsite).'</strong>. It starts within a few seconds.'),
	'already'   => array('info', '<strong>'.h($msgsite).'</strong> already has a deploy waiting, so nothing was added.'),
	'pausedjob' => array('warn', 'Deploys are paused, so nothing was queued. Resume them first.'),
	'paused'    => array('warn', 'Deploys are now paused server-wide. Pushes still arrive and wait in the queue.'),
	'resumed'   => array('ok',   'Deploys have been resumed. Anything that was waiting starts within a minute.'),
	'measured'  => array('ok',   'Disk usage has been re-measured.'),
);
if(isset($notes[$msg])) gd_note('<i class="fas fa-info-circle me-1"></i>'.$notes[$msg][1], $notes[$msg][0]);

if($paused){
	gd_note('<i class="fas fa-pause-circle me-1"></i><strong>Deploys are paused server-wide.</strong> '
		.'Nothing is being built. Pushes and manual deploys are still recorded and stay in the queue until you resume — '
		.'nothing is lost.'
		.(!empty($pinfo['ts']) ? ' Paused '.gd_ago($pinfo['ts']).(!empty($pinfo['by']) ? ' by '.h($pinfo['by']) : '').'.' : ''),
		'warn');
}

/* ---- summary ---- */
gd_card('<i class="fas fa-chart-line panel-icon"></i>At a glance',
	$is_root
		? 'Every GitDeploy site on this server.'
		: 'The GitDeploy sites belonging to the accounts you own.');

$building_total = $counts['building'] + $counts['queued'];
echo '<div class="gd-tiles">'
	.'<div class="gd-tile"><div class="gd-tile-n">'.count($rows).'</div><div class="gd-tile-l">Sites</div>'
	.'<div class="gd-tile-s">'.count($scope['users']).' account'.(count($scope['users']) === 1 ? '' : 's').'</div></div>'
	.'<div class="gd-tile gd-tile-ok"><div class="gd-tile-n">'.$counts['live'].'</div><div class="gd-tile-l">Live</div>'
	.'<div class="gd-tile-s">last deploy succeeded</div></div>'
	.'<div class="gd-tile'.($counts['failed'] + $counts['stalled'] > 0 ? ' gd-tile-bad' : '').'">'
	.'<div class="gd-tile-n">'.($counts['failed'] + $counts['stalled']).'</div><div class="gd-tile-l">Failed</div>'
	.'<div class="gd-tile-s">'.($counts['stalled'] > 0 ? $counts['stalled'].' never finished' : 'need attention').'</div></div>'
	.'<div class="gd-tile'.($building_total > 0 ? ' gd-tile-run' : '').'"><div class="gd-tile-n">'.$building_total.'</div>'
	.'<div class="gd-tile-l">Building</div><div class="gd-tile-s">'.$counts['queued'].' waiting in the queue</div></div>'
	.'<div class="gd-tile'.($hb_ok ? '' : ' gd-tile-bad').'"><div class="gd-tile-n">'
	.($hb_age < 0 ? '—' : h(gd_dur($hb_age) !== '' ? gd_dur($hb_age) : '0s')).'</div>'
	.'<div class="gd-tile-l">Runner</div><div class="gd-tile-s">'
	.($hb_age < 0 ? 'has never run' : ($hb_ok ? 'healthy — last tick' : 'stalled — last tick')).'</div></div>'
	.'</div>';

if($hb_age < 0){
	gd_note('<i class="fas fa-exclamation-triangle me-1"></i>The deploy runner has never checked in. '
		.'Nothing will ever deploy until it does — check <code>/etc/cron.d/gitdeploy</code> and <code>systemctl status crond</code>.', 'err');
}elseif(!$hb_ok){
	gd_note('<i class="fas fa-exclamation-triangle me-1"></i>The deploy runner last checked in '.h(gd_ago($hb))
		.'. It should tick every minute, so deploys are probably not running — check '
		.'<code>/etc/cron.d/gitdeploy</code> and <code>systemctl status crond</code>.', 'err');
}
if($scope['broken'] > 0){
	gd_note('<i class="fas fa-exclamation-triangle me-1"></i>'.(int)$scope['broken'].' site configuration file(s) under '
		.'<code>/var/lib/gitdeploy/sites</code> could not be read and are not listed below. '
		.'Run <code>/usr/local/gitdeploy/bin/check.sh</code> for details.', 'warn');
}

if($is_root){
	gd_h3('Pause deploys');
	echo '<p class="hint" style="font-size:13px;margin-bottom:10px">'
		.'Pausing stops every build on the server — useful during maintenance, a migration or a disk problem. '
		.'Queued jobs are kept, not thrown away, and start again as soon as you resume.</p>';
	echo '<form class="d-inline" method="post" action="?act=pause">';
	gd_tok_field();
	echo '<input type="hidden" name="to" value="'.($paused ? 'off' : 'on').'">'
		.'<button class="'.($paused ? 'flat-butt' : 'flat-butt-warning').'" type="submit">'
		.'<i class="fas '.($paused ? 'fa-play' : 'fa-pause').' me-1"></i>'
		.($paused ? 'Resume deploys' : 'Pause all deploys').'</button></form>';
}
gd_card_end();

/* ---- sites ---- */
gd_card('<i class="fas fa-list-ul panel-icon"></i>All sites',
	'Sorted so anything that needs attention is at the top. Settings belong to the account owner — '
	.'from here you can re-run a deploy and read the log.');

if(!$rows){
	echo '<div class="gd-empty"><i class="fas fa-inbox fa-2x d-block mb-2" aria-hidden="true"></i>'
		.'<div class="gd-empty-t">No sites yet</div><p>'
		.h($is_root
			? 'Nobody on this server has connected a repository to a domain yet. Hosting users set this up from their own panel under Apps → GitDeploy.'
			: 'None of the accounts you own has connected a repository to a domain yet.')
		.'</p></div>';
}else{
	echo '<div class="gd-scroll"><table class="gd-tbl"><thead><tr>'
		.'<th>Domain</th><th>Account</th><th>Repository</th><th>Branch</th><th>State</th>'
		.'<th>Last deploy</th><th>Commit</th><th class="num">Actions</th>'
		.'</tr></thead><tbody>';
	foreach($rows as $r){
		$site = $r['site']; $cfg = $r['cfg']; $st = $r['st'];
		$err  = isset($st['error']) ? (string)$st['error'] : '';
		echo '<tr>'
			.'<td><a class="gd-dom" href="https://'.h($site).'" target="_blank" rel="noopener">'.h($site)
			.'<i class="fas fa-external-link-alt gd-ext" aria-hidden="true"></i></a>'
			.($err !== '' && ($r['key'] === 'failed' || $r['key'] === 'stalled')
				? '<div class="gd-err">'.h(mb_substr($err, 0, 160)).'</div>' : '')
			.'</td>'
			.'<td class="mono">'.h($cfg['user']).'</td>'
			.'<td class="mono">'.h(gd_short_repo($cfg['repo']))
			.(gd_site_mode($cfg) === 'pull' ? '<div class="small text-muted">files only (no build)</div>' : '').'</td>'
			.'<td class="mono">'.h($cfg['branch']).'</td>'
			.'<td>'.gd_badge($st, $r['q']).'</td>'
			.'<td>'.gd_rel($r['last'], 'never').'</td>'
			.'<td class="mono">'.(gd_short_sha($st) !== '' ? h(gd_short_sha($st)) : '—').'</td>'
			.'<td class="num"><div class="gd-actions" style="justify-content:flex-end">';
		gd_deploy_form($site);
		echo ' <a class="flat-butt-primary btn-sm" href="?act=log&amp;site='.h(rawurlencode($site)).'">'
			.'<i class="fas fa-file-alt me-1"></i>Log</a></div></td></tr>';
	}
	echo '</tbody></table></div>';
}
gd_card_end();

/* ---- disk ---- */
gd_card('<i class="fas fa-hdd panel-icon"></i>Disk used by GitDeploy',
	'What GitDeploy keeps inside each account\'s home, in <code>~/.gitdeploy</code>. '
	.'Node toolchains are around 200 MB each and are shared by all of that account\'s sites.');

$disk = gd_disk_usage($scope['users'], 300, 6, 6);
if(!$scope['users']){
	echo '<p class="hint" style="font-size:13px">No accounts to measure yet.</p>';
}else{
	$tot = array('work' => 0, 'releases' => 0, 'toolchains' => 0, 'total' => 0);
	echo '<div class="gd-scroll"><table class="gd-tbl"><thead><tr>'
		.'<th>Account</th><th class="num">Build workspaces</th><th class="num">Release archives</th>'
		.'<th class="num">Node toolchains</th><th class="num">Total</th><th>Measured</th>'
		.'</tr></thead><tbody>';
	foreach($scope['users'] as $u){
		if(!isset($disk[$u])){
			echo '<tr><td class="mono">'.h($u).'</td><td class="num" colspan="4">'
				.'<span class="gd-faint">not measured yet</span></td>'
				.'<td><span class="gd-faint">—</span></td></tr>';
			continue;
		}
		$d = $disk[$u];
		foreach(array('work', 'releases', 'toolchains', 'total') as $k) $tot[$k] += (int)$d[$k];
		echo '<tr><td class="mono">'.h($u).'</td>'
			.'<td class="num">'.h(gd_size($d['work'])).'</td>'
			.'<td class="num">'.h(gd_size($d['releases'])).'</td>'
			.'<td class="num">'.h(gd_size($d['toolchains'])).'</td>'
			.'<td class="num"><strong>'.h(gd_size($d['total'])).'</strong></td>'
			.'<td>'.gd_rel($d['ts']).(!empty($d['stale']) ? ' <span class="gd-tag">stale</span>' : '').'</td></tr>';
	}
	echo '</tbody><tfoot><tr><th>All accounts</th>'
		.'<th class="num">'.h(gd_size($tot['work'])).'</th>'
		.'<th class="num">'.h(gd_size($tot['releases'])).'</th>'
		.'<th class="num">'.h(gd_size($tot['toolchains'])).'</th>'
		.'<th class="num">'.h(gd_size($tot['total'])).'</th><th></th></tr></tfoot></table></div>';
	echo '<p class="hint" style="font-size:12.5px;margin-top:10px">'
		.'Measured by running <code>du</code> as each account, never as root, and cached for five minutes so this page '
		.'always loads quickly. Nothing here is deleted automatically except the build folders of sites that no longer '
		.'exist, which the runner reclaims after a day.</p>';
	echo '<div class="gd-cta"><form class="d-inline" method="post" action="?act=disk">';
	gd_tok_field();
	echo '<button class="flat-butt-primary btn-sm" type="submit"><i class="fas fa-sync-alt me-1"></i>'
		.'Measure again now</button></form></div>';
}
gd_card_end();

gd_foot();
