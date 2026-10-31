<?php
/**
 * GitDeploy — enduser panel.
 * Served by EMPS nginx via fpm-root.sock: THIS RUNS AS ROOT.
 * Every request parameter is hostile until validated. Rules obeyed here:
 *   - identity comes only from the Webuzo session SDK, never from the request
 *   - a site id must be a valid domain AND owned by the logged in user
 *   - no user string ever reaches a shell (proc_open argv arrays only)
 *   - all paths are built from GD_* constants + validated ids
 *   - every state change is POST + CSRF token
 * Look & feel: the Webuzo enduser theme's own bootstrap + style.css are loaded so
 * this page matches the panel; assets/style.css only carries small extras.
 */

error_reporting(0);

include_once('/usr/local/webuzo/sdk/sessions.php');
require '/usr/local/gitdeploy/lib/common.php';
require_once '/usr/local/gitdeploy/lib/ghapp.php';

/**
 * GitHub sends the browser back to this script WITHOUT the /sessXXXX/ URL prefix,
 * so the panel's path-scoped session cookie is not sent with that request.
 * act=ghapp_callback is therefore the ONE action handled before the session
 * prologue below. It is gated instead by the single-use, 10-minute, server-side
 * manifest state token (written seconds earlier by the logged-in admin on this
 * very server) plus GitHub's own single-use conversion code. It never touches
 * $sess, never renders anything user-controlled unescaped, and always exits.
 */
if(isset($_GET['act']) && is_string($_GET['act']) && $_GET['act'] === 'ghapp_callback'){
	gd_ghapp_callback();   // never returns
}

$sess = new Webuzo_Sessions();
$logged_in = $sess->isLogin();
if(empty($logged_in)) $sess->show_login();

$user    = isset($sess->user['user']) ? (string)$sess->user['user'] : '';
$domains = (isset($sess->user['domains']) && is_array($sess->user['domains'])) ? $sess->user['domains'] : array();

if($user === '' || $user === 'root' || !gd_valid_user($user)){
	die('GitDeploy: this panel is only available to hosting users.');
}

$GD_KEYFILE = GD_STATE.'/.csrf_key';
$csrf_key = trim((string)@file_get_contents($GD_KEYFILE));
if($csrf_key === '') die('GitDeploy: server CSRF key is missing. Contact your administrator.');
$TOK = hash_hmac('sha256', (string)(isset($sess->SESS['sid']) ? $sess->SESS['sid'] : ''), $csrf_key);

/* ------------------------------------------------------------------ helpers */

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/**
 * Cache-busting token for our stylesheet. The panel versions its own CSS; without
 * this a browser keeps a stale stylesheet against updated markup and the page
 * renders broken after every plugin update. Falls back to the plugin version.
 */
function gd_asset_v(){
	$t = @filemtime(GD_BASE.'/web/enduser/assets/style.css');
	if($t) return (int)$t;
	$v = @file_get_contents(GD_BASE.'/VERSION');
	return $v ? preg_replace('~[^0-9a-zA-Z.]~', '', trim($v)) : '1';
}

function gd_tok_field(){ global $TOK; echo '<input type="hidden" name="tok" value="'.h($TOK).'">'; }

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
	echo '<a class="flat-butt-primary" href="?act=list"><i class="fas fa-arrow-left me-1"></i>Back to sites</a>';
	gd_card_end();
	gd_foot();
	exit;
}

function gd_check_csrf(){
	global $TOK;
	$t = isset($_POST['tok']) ? $_POST['tok'] : '';
	if(!is_string($t) || !hash_equals($TOK, $t)){
		gd_stop('403 — bad form token', 'This form is stale or was not submitted from the panel. Reload the page and try again.', 403);
	}
}

// Relative redirect: keeps the /sessXXXX/ URL prefix intact in the browser.
function gd_redirect($qs){
	header('Location: '.$qs);
	echo '<!doctype html><meta http-equiv="refresh" content="0;url='.h($qs).'">'
		.'<p><a href="'.h($qs).'">Continue</a></p>';
	exit;
}

function gd_when($ts){ return empty($ts) ? '—' : date('Y-m-d H:i:s', (int)$ts); }

// "2 minutes ago" — presentation only, never used for any decision.
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

// Relative time, with the exact server timestamp in the tooltip. Returns HTML.
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
	$h = (int)floor($m / 60); $m = $m % 60;
	return $h.'h '.$m.'m';
}

// How long the last build took, '' when the status file does not say.
function gd_build_secs($st){
	$a = isset($st['started'])  ? (int)$st['started']  : 0;
	$b = isset($st['finished']) ? (int)$st['finished'] : 0;
	return ($a > 0 && $b >= $a) ? ($b - $a) : 0;
}

// "owner/repo" for display; falls back to the stored string unchanged.
// "build: npm run build" / "build: auto" / "files only" for the one-line site summaries
function gd_mode_label($cfg){
	if(gd_site_mode($cfg) === 'pull') return 'files only (no build)';
	$b = isset($cfg['build_cmd']) ? trim((string)$cfg['build_cmd']) : '';
	return 'build: '.($b !== '' ? $b : 'auto');
}

function gd_short_repo($repo){
	$r = (string)$repo;
	if(preg_match('~^https://github\.com/(.+?)(?:\.git)?/?$~', $r, $m)) return $m[1];
	if(preg_match('~^git@[^:]+:(.+?)(?:\.git)?$~', $r, $m)) return $m[1];
	return $r;
}

function gd_post($k){ return isset($_POST[$k]) && is_string($_POST[$k]) ? $_POST[$k] : ''; }
function gd_get($k){ return isset($_GET[$k]) && is_string($_GET[$k]) ? $_GET[$k] : ''; }

function gd_status($site){
	$st = gd_load_json(GD_STATUS.'/'.$site.'.json');
	return is_array($st) ? $st : array();
}

function gd_queued($site){ return is_file(GD_SPOOL.'/'.$site.'.json'); }

/**
 * A status file left in state "building" by a runner that died never gets a final
 * state, and the UI would then poll/meta-refresh for ever. Anything older than the
 * runner's own hard build cap (1200s) plus slack is treated as stalled instead.
 */
define('GD_STALE_BUILD', 1800);

function gd_stalled($st){
	if(!is_array($st) || !isset($st['state']) || $st['state'] !== 'building') return false;
	$started = isset($st['started']) ? (int)$st['started'] : 0;
	return ($started <= 0) || ((time() - $started) > GD_STALE_BUILD);
}

// True while a build really is moving (a stalled status never counts as busy).
function gd_busy($site, $st = null){
	if($st === null) $st = gd_status($site);
	if(gd_queued($site)) return true;
	return (isset($st['state']) && $st['state'] === 'building' && !gd_stalled($st));
}

/**
 * One word for what the site is doing right now. Same precedence as before:
 * stalled build > running build > queued job > last result.
 */
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
// $ticker: render a client-side elapsed counter next to "Building".
function gd_badge($st, $queued = false, $ticker = false){
	$map = array(
		'stalled'  => array('gd-pill-warn',  'fa-exclamation-triangle', 'Stalled'),
		'building' => array('gd-pill-run',   'fa-circle-notch fa-spin', 'Building'),
		'queued'   => array('gd-pill-queue', 'fa-clock',                'Queued'),
		'live'     => array('gd-pill-ok',    'fa-check-circle',         'Live'),
		'failed'   => array('gd-pill-fail',  'fa-times-circle',         'Failed'),
		'none'     => array('gd-pill-idle',  'fa-minus-circle',         'Never deployed'),
	);
	$k = gd_state_key($st, $queued);
	$m = $map[$k];
	$el = '';
	if($ticker && $k === 'building'){
		$started = isset($st['started']) ? (int)$st['started'] : 0;
		$el = '<span class="gd-elapsed" data-started="'.$started.'"></span>';
	}
	return '<span class="gd-pill '.$m[0].'"><i class="fas '.$m[1].'" aria-hidden="true"></i>'
		.'<span>'.$m[2].$el.'</span></span>';
}

// Where the repository credentials come from, as a small muted tag.
function gd_source_tag($cfg){
	if(gd_gh_is_app_site($cfg)){
		return '<span class="gd-tag"><i class="fab fa-github" aria-hidden="true"></i>GitHub App</span>';
	}
	if(gd_is_ssh_repo(isset($cfg['repo']) ? $cfg['repo'] : '')){
		return '<span class="gd-tag"><i class="fas fa-key" aria-hidden="true"></i>Deploy key</span>';
	}
	return '<span class="gd-tag"><i class="fas fa-globe" aria-hidden="true"></i>Public repo</span>';
}

function gd_short_sha($st){
	$sha = isset($st['last_sha']) ? (string)$st['last_sha'] : '';
	return preg_match('~^[0-9a-f]{7,40}$~', $sha) ? substr($sha, 0, 7) : '';
}

/**
 * Archived releases for a site: /home/<user>/.gitdeploy/<site>/releases/<40-hex-sha>/.
 * The path is built from validated parts only ($cfg comes from gd_load_site()), the
 * entry names must be a full sha, and symlinked entries are skipped — never followed.
 * Newest first, capped at 10 (the runner keeps 5).
 */
function gd_releases($cfg){
	if(!is_array($cfg) || empty($cfg['user']) || empty($cfg['site'])) return array();
	$pw = posix_getpwnam($cfg['user']);
	if(empty($pw) || $pw['uid'] < 1000) return array();
	$dir = rtrim($pw['dir'], '/').'/.gitdeploy/'.$cfg['site'].'/releases';
	if(!is_dir($dir)) return array();

	$out = array();
	foreach((scandir($dir) ?: array()) as $e){
		if(!preg_match('~^[0-9a-f]{40}$~', $e)) continue;
		$p = $dir.'/'.$e;
		if(is_link($p) || !is_dir($p)) continue;
		$out[] = array('sha' => $e, 'mtime' => (int)@filemtime($p));
	}
	usort($out, function($a, $b){ return $b['mtime'] - $a['mtime']; });
	return array_slice($out, 0, 10);
}

// Sites this user owns (config user field must match), newest first by domain name.
function gd_user_sites($user){
	$out = array();
	foreach((glob(GD_SITES.'/*.json') ?: array()) as $f){
		$site = basename($f, '.json');
		$cfg  = gd_load_site($site);
		if($cfg && $cfg['user'] === $user) $out[$site] = $cfg;
	}
	ksort($out);
	return $out;
}

// Load a site the caller is allowed to touch, or bail out.
function gd_require_site($site, $user){
	if(!gd_valid_site($site)) gd_stop('Invalid site', 'That site id is not a valid domain name.', 400);
	$cfg = gd_load_site($site);
	if(!$cfg || $cfg['user'] !== $user) gd_stop('Not found', 'No GitDeploy site "'.$site.'" belongs to your account.', 404);
	return $cfg;
}

/**
 * Hostname used to build the GitHub App's PERMANENT webhook + redirect URLs.
 * The Host header is attacker-controllable (any tenant domain, or a spoofed one),
 * and whatever lands here is baked into the app on GitHub — so prefer the server's
 * own hostname and only fall back to the sanitized Host header when that is not a
 * FQDN. The result is shown in the wizard so the admin can see what gets registered.
 */
function gd_panel_host(){
	static $cached = null;
	if($cached !== null) return $cached;

	$re = '~^[A-Za-z0-9][A-Za-z0-9.\-]{0,180}$~';
	$node = (string)@php_uname('n');
	if(strpos($node, '.') !== false && preg_match($re, $node)){
		$cached = $node;
		return $cached;
	}
	$host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : '';
	$host = preg_replace('~:\d+$~', '', $host);
	$cached = preg_match($re, $host) ? $host : 'your-server-hostname';
	return $cached;
}

function gd_hook_url($host, $site){
	return 'https://'.$host.':2003/gitdeploy/hook.php?site='.rawurlencode($site);
}

function gd_is_ssh_repo($repo){ return strpos((string)$repo, 'git@') === 0; }

/* ----------------------------------------------------------- GitHub App glue */

// "owner/repo" out of a stored https://github.com/owner/repo URL ('' when it is not one)
function gd_gh_full_name($repo){
	return preg_match('~^https://github\.com/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?)(?:\.git)?$~', (string)$repo, $m)
		? $m[1] : '';
}

// A site is app-connected when its config carries a numeric installation id.
function gd_gh_is_app_site($cfg){
	return is_array($cfg) && !empty($cfg['gh_installation_id'])
		&& preg_match('~^\d{1,15}$~', (string)$cfg['gh_installation_id']) === 1;
}

// One field of the stored app record, as a string. Only ever used for the public
// fields (app_id/name/slug/owner/html_url) — the pem and the secrets are not shown.
function gd_ghv($creds, $k){
	return (is_array($creds) && isset($creds[$k]) && is_scalar($creds[$k])) ? (string)$creds[$k] : '';
}

// Links built from the stored app record only — never from a request parameter.
function gd_ghapp_manage_url($creds){
	$html = gd_ghv($creds, 'html_url');
	if(preg_match('~^https://github\.com/[A-Za-z0-9_./-]{1,120}$~', $html)) return rtrim($html, '/');
	$slug = gd_ghv($creds, 'slug');
	if($slug !== '' && preg_match('~^[A-Za-z0-9_.-]{1,100}$~', $slug)) return 'https://github.com/apps/'.$slug;
	return 'https://github.com/settings/apps';
}

function gd_ghapp_install_url($creds){
	$base = gd_ghapp_manage_url($creds);
	return ($base === 'https://github.com/settings/apps') ? $base : $base.'/installations/new';
}

/**
 * Which installation grants access to $full_name? Walks the app installations
 * (max 10) and their repository lists. Used to re-validate every posted repo
 * against the live GitHub state — the browser's value is never trusted.
 * Returns array(installation_id, default_branch, token) or null.
 * Results are cached for this request only (never written to disk).
 */
function gd_ghapp_find_repo($creds, $full_name, &$err){
	static $cache = array();
	$err = '';
	$full_name = (string)$full_name;
	if(!preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~', $full_name)){
		$err = 'that is not a valid repository name'; return null;
	}
	if(isset($cache[$full_name])) return $cache[$full_name];

	$insts = ghapp_installations($creds, $err);
	if($insts === null) return null;

	$n = 0;
	foreach($insts as $inst){
		if(++$n > 10) break;
		$id = (isset($inst['id']) && is_scalar($inst['id'])) ? (string)$inst['id'] : '';
		if(!preg_match('~^\d{1,15}$~', $id)) continue;
		$e = ''; $tok = ghapp_inst_token($creds, $id, $e);
		if(!$tok) continue;
		$e = ''; $repos = ghapp_repos($tok, $e);
		if($repos === null) continue;
		foreach($repos as $r){
			if($r['full_name'] === $full_name){
				$cache[$full_name] = array(
					'installation_id' => $id,
					'default_branch'  => $r['default_branch'],
					'token'           => $tok,
				);
				return $cache[$full_name];
			}
		}
	}
	$err = 'that repository is not available to your GitHub App — install the app on it first';
	return null;
}

/**
 * Repository picker data for ONE hosting user: one group per installation (max 10),
 * 300 repos overall. Walking the whole GitHub API on every render of the add form is
 * both slow and rate-limit hungry, so the result (including the per-installation
 * errors, so a partial listing still explains itself) is cached per user for a few
 * minutes — "Refresh repositories" clears it.
 * Returns array('groups' => [...], 'errors' => [...], 'ts' => int, 'cached' => bool).
 */
function gd_ghapp_repo_groups($user, $creds = null){
	$hit = ghapp_cache_get($user);
	if(is_array($hit) && isset($hit['groups']) && is_array($hit['groups'])){
		$hit['errors'] = (isset($hit['errors']) && is_array($hit['errors'])) ? $hit['errors'] : array();
		$hit['cached'] = true;
		return $hit;
	}

	$data = array('groups' => array(), 'errors' => array(), 'ts' => time(), 'cached' => false);
	if($creds === null) $creds = ghapp_load($user);
	if($creds === null){
		$data['errors'][] = 'No GitHub App is connected to your account.';
		return $data;   // nothing worth caching
	}

	ghapp_budget_start(12);
	$e = ''; $insts = ghapp_installations($creds, $e);
	if($insts === null){
		$data['errors'][] = ($e !== '' ? $e : 'Could not list your GitHub App installations.');
		return $data;   // transient failure: do not cache an empty listing
	}
	$n = 0; $total = 0;
	foreach($insts as $inst){
		if(++$n > 10 || $total >= 300) break;
		$id = (isset($inst['id']) && is_scalar($inst['id'])) ? (string)$inst['id'] : '';
		if(!preg_match('~^\d{1,15}$~', $id)) continue;
		$acct = isset($inst['account']['login']) ? (string)$inst['account']['login'] : ('installation '.$id);

		if(ghapp_budget_spent()){
			$data['errors'][] = 'GitHub was too slow — the list may be incomplete. Try "Refresh repositories".';
			break;
		}
		$e = ''; $tok = ghapp_inst_token($creds, $id, $e);
		if(!$tok){ $data['errors'][] = $acct.': '.($e !== '' ? $e : 'could not get an access token'); continue; }
		$e = ''; $repos = ghapp_repos($tok, $e);
		if($repos === null){ $data['errors'][] = $acct.': '.($e !== '' ? $e : 'could not list repositories'); continue; }

		$keep = array();
		foreach($repos as $r){
			if($total >= 300) break;
			$keep[] = $r; $total++;
		}
		$data['groups'][] = array('account' => $acct, 'installation_id' => $id, 'repos' => $keep);
	}
	// A partial result is still worth caching: the page renders it with a warn note.
	ghapp_cache_put($user, array('groups' => $data['groups'], 'errors' => $data['errors']));
	return $data;
}

/* Standalone page for the pre-session callback: no panel session, no theme assets. */
function gd_ghapp_page($title, $body, $code = 200){
	http_response_code($code);
	echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
		.'<meta name="viewport" content="width=device-width, initial-scale=1">'
		.'<title>GitDeploy</title><style>'
		.'body{margin:0;background:#f4f6fb;color:#1d1d1b;font-family:Poppins,"Segoe UI",Helvetica,Arial,sans-serif}'
		.'.b{max-width:640px;margin:48px auto;background:#fff;border-radius:10px;padding:30px 34px;'
		.'box-shadow:0 2px 14px rgba(20,30,60,.08)}'
		.'h1{font-size:20px;margin:0 0 14px}p{font-size:14px;line-height:1.6;color:#3b3f51}'
		.'a.btn{display:inline-block;margin:16px 8px 0 0;padding:11px 20px;border-radius:6px;background:#4f46e5;'
		.'color:#fff;text-decoration:none;font-size:14px;font-weight:600}'
		.'a.btn.sec{background:#eef0f7;color:#3b3f51}'
		.'.mono{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px;background:#f7f9fb;'
		.'border:1px solid #dfdfdf;border-radius:5px;padding:8px 10px;word-break:break-word}'
		.'.foot{color:#71758d;font-size:12px;text-align:center;margin-top:18px}'
		.'</style></head><body><div class="b"><h1>'.h($title).'</h1>'.$body.'</div>'
		.'<div class="foot">GitDeploy &middot; Digitalbkk Co., Ltd.</div></body></html>';
	exit;
}

/**
 * GitHub App manifest flow, step 2 — runs WITHOUT a panel session (see the top of
 * this file). The single-use state token is the authorisation; the temporary code
 * is exchanged for the app credentials, which only root can read afterwards.
 */
function gd_ghapp_callback(){
	$code  = (isset($_GET['code'])  && is_string($_GET['code']))  ? $_GET['code']  : '';
	$state = (isset($_GET['state']) && is_string($_GET['state'])) ? $_GET['state'] : '';

	$again = '<p>Open <strong>GitDeploy &rarr; GitHub Connection</strong> in your Webuzo panel and press '
		.'<em>Connect GitHub App</em> again. The link is only valid for ten minutes and can be used once.</p>';

	// Nothing usable in the URL: never consume anything, just point the visitor back.
	if($code === '' || $state === ''){
		gd_ghapp_page('Nothing to connect',
			'<p>This page is the return address for GitHub\'s app-creation flow and cannot be opened directly.</p>'
			.$again, 400);
	}

	// The state token carries the hosting user it was created for — there is no
	// session on this request, so that record is the ONLY identity we have.
	$rec = ghapp_state_consume($state);
	if(!is_array($rec) || empty($rec['user']) || !gd_valid_user($rec['user'])){
		gd_ghapp_page('Connection could not be verified',
			'<p>State mismatch or expired — restart the connection from the GitDeploy panel.</p>'.$again, 403);
	}
	$cbuser = (string)$rec['user'];

	// One app per hosting user. Overwriting is only allowed when the panel asked for it.
	if(ghapp_load($cbuser) !== null && empty($rec['replace'])){
		gd_ghapp_page('A GitHub App is already connected',
			'<p>This account already has a GitHub App connected, so nothing was changed.</p>'
			.'<p>Open <strong>GitDeploy &rarr; GitHub Connection</strong> in your Webuzo panel and '
			.'<em>disconnect</em> the existing app first, then connect the new one. The app you just created on '
			.'GitHub was not stored here — delete it on GitHub if you do not want to keep it.</p>', 409);
	}

	ghapp_budget_start(15);
	$err = ''; $creds = ghapp_manifest_convert($code, $err);
	if(!$creds){
		gd_ghapp_page('GitHub App creation failed',
			'<p>GitHub did not return usable app credentials, so nothing was saved.</p>'
			.'<p class="mono">'.h($err !== '' ? $err : 'unknown error').'</p>'
			.'<p>Return to <strong>GitDeploy &rarr; GitHub Connection</strong> in your Webuzo panel and try again.</p>', 502);
	}
	if(!ghapp_save($cbuser, $creds)){
		gd_ghapp_page('Could not store the credentials',
			'<p>The GitHub App was created but its credentials could not be written on this server. '
			.'Delete the app on GitHub and start the connection again from the GitDeploy panel.</p>', 500);
	}

	ghapp_cache_clear($cbuser);   // a brand new app has a brand new repo list

	$name  = (gd_ghv($creds, 'name') !== '') ? gd_ghv($creds, 'name') : gd_ghv($creds, 'slug');
	if($name === '') $name = 'GitDeploy';
	$owner = gd_ghv($creds, 'owner');
	gd_ghapp_page('GitHub App connected',
		'<p><strong>'.h($name).'</strong> was created'
		.($owner !== '' ? ' under <strong>'.h($owner).'</strong>' : '').' and connected to GitDeploy.</p>'
		.'<p>One step left: choose which repositories GitDeploy is allowed to read.</p>'
		.'<a class="btn" href="'.h(gd_ghapp_install_url($creds)).'">Choose repositories</a>'
		.'<p>Then go back to GitDeploy in your Webuzo panel — the repositories you picked will be waiting for you in the '
		.'<em>Connect a repository</em> list.</p>');
}

/* --------------------------------------------------------------- validation */

function gd_check_build_cmd($cmd, &$err){
	$cmd = trim((string)$cmd);
	// Empty is kept empty on purpose: it is what tells the build to detect the project.
	if($cmd === '') return '';
	if(strlen($cmd) > 300){ $err = 'Build command is longer than 300 characters.'; return null; }
	if(preg_match('~[\x00-\x1f\x7f]~', $cmd)){ $err = 'Build command must be a single line without control characters.'; return null; }
	return $cmd;
}

// "KEY=VALUE" per line -> map. Keys ^[A-Za-z_][A-Za-z0-9_]*$, values <=500 chars, single line.
function gd_parse_env($text, &$err){
	$map = array();
	$lines = preg_split('~\r\n|\r|\n~', (string)$text);
	foreach($lines as $i => $line){
		$line = trim($line);
		if($line === '' || $line[0] === '#') continue;
		$pos = strpos($line, '=');
		if($pos === false || $pos === 0){
			$err = 'Environment line '.($i + 1).': expected KEY=VALUE.'; return null;
		}
		$k = trim(substr($line, 0, $pos));
		$v = trim(substr($line, $pos + 1));
		if(!preg_match('~^[A-Za-z_][A-Za-z0-9_]*$~', $k)){
			$err = 'Environment line '.($i + 1).': "'.$k.'" is not a valid variable name.'; return null;
		}
		if(strlen($v) > 500){ $err = 'Environment variable '.$k.': value is longer than 500 characters.'; return null; }
		if(preg_match('~[\x00-\x1f\x7f]~', $v)){ $err = 'Environment variable '.$k.': value must be a single line.'; return null; }
		$map[$k] = $v;
	}
	if(count($map) > 100){ $err = 'Too many environment variables (max 100).'; return null; }
	return $map;
}

function gd_env_text($env){
	$out = '';
	if(is_array($env)) foreach($env as $k => $v) $out .= $k.'='.$v."\n";
	return $out;
}

/**
 * Prefill the environment textarea. When the raw text the user typed was stored we
 * hand it back verbatim (comments, blank lines and ordering survive the round-trip);
 * otherwise the map is rendered back out as KEY=VALUE lines, as before.
 */
function gd_env_prefill($cfg){
	$raw = (is_array($cfg) && isset($cfg['env_raw']) && is_string($cfg['env_raw'])) ? $cfg['env_raw'] : '';
	if($raw !== '' && strlen($raw) <= 8000 && !preg_match('~[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]~', $raw)) return $raw;
	return gd_env_text((is_array($cfg) && isset($cfg['env'])) ? $cfg['env'] : array());
}

function gd_valid_notify_on($v){ return in_array($v, array('fail', 'always', 'never'), true); }

/* -------------------------------------------------------------- deploy keys */

/**
 * Run bin/asuser.sh AS THE HOSTING USER (setpriv, argv array, explicit env).
 * Root must never mkdir/chown/unlink inside a user-writable home: the user can swap
 * any component for a symlink between our check and our write, and a root chown then
 * lands on /etc/shadow. Dropping privileges first removes the whole class of bug —
 * the kernel does the ownership check on every path component for us.
 * Returns the exit code (0 = success); $out is stdout, $err a message on failure.
 */
function gd_asuser($user, $op, $site, &$out, &$err){
	$out = ''; $err = '';
	if(!in_array($op, array('keygen', 'keydel', 'rmwork'), true)){ $err = 'unknown helper operation'; return -1; }
	if(!gd_valid_user($user) || !gd_valid_site($site)){ $err = 'invalid user or site'; return -1; }
	$pw = posix_getpwnam($user);
	if(empty($pw) || (int)$pw['uid'] < 1000){ $err = 'system user not found'; return -1; }
	if(!is_dir($pw['dir'])){ $err = 'home directory does not exist'; return -1; }

	$cmd = array('/usr/bin/setpriv', '--reuid='.(int)$pw['uid'], '--regid='.(int)$pw['gid'], '--init-groups',
		'/bin/bash', GD_BASE.'/bin/asuser.sh', $op, $site);
	// No --reset-env: the explicit env array below IS the whitelist.
	$env  = array('HOME' => (string)$pw['dir'], 'USER' => $user, 'PATH' => '/usr/local/bin:/usr/bin:/bin');
	$desc = array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
	$pipes = array();
	$proc = @proc_open($cmd, $desc, $pipes, '/tmp', $env);
	if(!is_resource($proc)){ $err = 'could not run the privilege-drop helper'; return -1; }
	$out  = (string)stream_get_contents($pipes[1]);
	$serr = (string)stream_get_contents($pipes[2]);
	fclose($pipes[1]); fclose($pipes[2]);
	$code = proc_close($proc);
	if($code !== 0){
		$err = (trim($serr) !== '') ? trim($serr) : ('helper exited with status '.$code);
		if(strlen($err) > 300) $err = substr($err, 0, 300).'…';
	}
	return $code;
}

/**
 * Create (or reuse) /home/<user>/.ssh/gitdeploy-<site>, entirely as the user.
 * The helper prints the key path; we still re-derive it here and refuse anything else.
 */
function gd_ensure_deploy_key($user, $site, &$err){
	$err = '';
	$out = ''; $e = '';
	if(gd_asuser($user, 'keygen', $site, $out, $e) !== 0){
		$err = ($e !== '' ? $e : 'ssh-keygen failed');
		return '';
	}
	$pw = posix_getpwnam($user);
	if(empty($pw)){ $err = 'system user not found'; return ''; }
	$want = rtrim($pw['dir'], '/').'/.ssh/gitdeploy-'.$site;
	if(trim($out) !== $want){ $err = 'the deploy key helper returned an unexpected path'; return ''; }
	if(!is_file($want) || !is_file($want.'.pub')){ $err = 'the deploy key was not created'; return ''; }
	return $want;
}

/**
 * Read the public half of a deploy key for display. The file lives in the user's own
 * home, so root reading it blindly would happily follow a symlink to /etc/shadow and
 * print it in the panel: refuse symlinks, refuse anything owned by a system account,
 * and only ever emit something that actually looks like an ssh public key.
 */
function gd_pubkey($keypath){
	if(!is_string($keypath) || $keypath === '') return '';
	$pub = $keypath.'.pub';
	if(is_link($pub) || !is_file($pub)) return '';
	$own = @fileowner($pub);
	if($own === false || (int)$own < 1000) return '';
	$txt = trim((string)@file_get_contents($pub, false, null, 0, 8192));
	return preg_match('~^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp\d+) [A-Za-z0-9+/=]+( [^\r\n]*)?$~', $txt) ? $txt : '';
}

/**
 * Symlink-safe recursive delete (never descends into a symlinked directory).
 * ONLY for root-owned trees under GD_LOGS (root:root 0700, no user can plant a
 * symlink there). Anything inside a user's home is deleted by bin/asuser.sh instead.
 */
function gd_rmtree($dir){
	if(is_link($dir)){ @unlink($dir); return; }
	if(!is_dir($dir)) return;
	foreach((scandir($dir) ?: array()) as $e){
		if($e === '.' || $e === '..') continue;
		$p = $dir.'/'.$e;
		if(is_link($p) || !is_dir($p)) @unlink($p); else gd_rmtree($p);
	}
	@rmdir($dir);
}

/* -------------------------------------------------------------- page chrome */

/**
 * $refresh: seconds for a meta refresh (0 = none). $noscript wraps it in <noscript>
 * so the JS log tailer owns the live updates when scripting is available.
 */
function gd_head($subtitle = '', $refresh = 0, $refresh_url = '', $noscript = false){
	global $user;
	echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
		.'<meta name="viewport" content="width=device-width, initial-scale=1">'
		.'<title>GitDeploy</title>'
		// the Webuzo enduser theme's own assets — same order as themes/default/hf_theme.php
		.'<link rel="stylesheet" href="/themes/default/bootstrap/css/bootstrap.min.css">'
		.'<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700'
		.'&amp;family=Poppins:wght@300;400;500;600;700;800;900&amp;display=swap" rel="stylesheet">'
		.'<link rel="stylesheet" href="/themes/default/css/style.css">'
		.'<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">'
		// cache-buster: the panel versions its own CSS; without this a browser keeps
		// serving a stale stylesheet against updated markup and the page looks broken
		.'<link rel="stylesheet" href="assets/style.css?v='.gd_asset_v().'">';
	if((int)$refresh > 0){
		$meta = '<meta http-equiv="refresh" content="'.(int)$refresh.($refresh_url !== '' ? ';url='.h($refresh_url) : '').'">';
		echo $noscript ? '<noscript>'.$meta.'</noscript>' : $meta;
	}
	// When embedded in the panel's act=gitdeploy iframe, the panel provides the chrome
	echo '<script>if(window.self!==window.top)document.documentElement.className="embedded";</script>';
	echo '</head><body><div class="gd-wrap">'
		.'<div class="gd-head d-flex align-items-center justify-content-between flex-wrap mb-4">'
		.'<div class="gd-brand d-flex align-items-center">'   // hidden when embedded
		.'<img src="/gitdeploy/assets/gitdeploy_48.png" alt="" width="40" height="40" class="me-3">'
		.'<div><h1 class="panel-head mb-0">GitDeploy</h1><div class="soft-card-desc">'
		.h($subtitle !== '' ? $subtitle : 'Deploy static sites from GitHub to your domains')
		.' &middot; user '.h($user).'</div></div></div>'
		.'<div class="gd-nav"><a class="flat-butt-primary" href="?act=list"><i class="fas fa-list-ul me-1"></i>My sites</a>'
		.'<a class="flat-butt-primary" href="?act=docs"><i class="fas fa-book me-1"></i>Help</a></div></div>';
}

function gd_foot(){
	echo '<div class="gd-powered">Powered by <a href="https://www.digitalbkk.com" target="_blank" rel="noopener">Digitalbkk Co., Ltd.</a>'
		.' &middot; developed by the Digitalbkk team &middot; author Hasif Ahmed</div>'
		.'<div class="gd-foot">GitDeploy &middot; builds run as your user &middot; server time '.h(date('Y-m-d H:i:s')).'</div>'
		.'</div><script>
document.addEventListener("click",function(e){ /* copy-to-clipboard buttons */
	var b=e.target.closest?e.target.closest("[data-copy]"):null; if(!b) return;
	e.preventDefault();
	var el=document.getElementById(b.getAttribute("data-copy")); if(!el) return;
	var txt=el.innerText, done=function(){var o=b.textContent;b.textContent="Copied";setTimeout(function(){b.textContent=o;},1200);};
	if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(txt).then(done);return;}
	var t=document.createElement("textarea");t.value=txt;document.body.appendChild(t);t.select();
	try{document.execCommand("copy");done();}catch(err){}
	document.body.removeChild(t);
});
(function(){ /* live "building · 47s" counters */
	var els=document.querySelectorAll(".gd-elapsed[data-started]"); if(!els.length) return;
	function tick(){ var n=Math.floor(Date.now()/1000);
		for(var i=0;i<els.length;i++){ var s=parseInt(els[i].getAttribute("data-started"),10)||0, d=n-s;
			if(!s||d<0) d=0;
			els[i].textContent=s?" · "+(d<60?d+"s":Math.floor(d/60)+"m "+(d%60)+"s"):""; } }
	tick(); setInterval(tick,1000);
})();
</script></body></html>';
}

// Panel-style card: heading row (icon + title) then body. $title/$sub are raw html.
function gd_card($title, $sub = ''){
	echo '<div class="card soft-card mb-4">'
		.'<div class="row align-items-center panel-heading px-4 py-3"><div class="col-12">'
		.'<label class="panel-head d-inline-block mb-0">'.$title.'</label>'
		.($sub !== '' ? '<div class="soft-card-desc mt-1">'.$sub.'</div>' : '')
		.'</div></div><div class="card-body px-4 py-4">';
}

function gd_card_end(){ echo '</div></div>'; }

function gd_h3($text){ echo '<h3 class="gd-h3">'.h($text).'</h3>'; }

$GD_CB = 0;
function gd_copy_block($text, $label = 'Copy'){
	global $GD_CB;
	$id = 'cb'.(++$GD_CB);
	echo '<div class="copy"><pre class="plain" id="'.$id.'">'.h($text).'</pre>'
		.'<button type="button" class="flat-butt-primary btn-sm gd-copybtn" data-copy="'.$id.'">'.h($label).'</button></div>';
}

// Shown on both the add and the edit form.
function gd_repoconf_hint(){
	echo '<div class="alert alert-info gd-note"><i class="fab fa-github me-1"></i>'
		.'<strong>Settings can also live in the repository.</strong> Add a <code>.gitdeploy.json</code> file at the top '
		.'of your repo and it wins over the build fields above at deploy time:'
		.'<pre class="plain mt-2 mb-1">{"build_cmd": "npm run build", "output_dir": "dist", "root_dir": "", "node_version": "22", "env": {"KEY": "value"}}</pre>'
		.'<span class="hint">Environment variables you type here in the panel still win over the ones in that file.</span></div>';
}

// A collapsible section that opens itself when it already holds non-default values.
function gd_more_open($title, $sub, $open){
	echo '<details class="gd-more"'.($open ? ' open' : '').'><summary>'
		.'<span class="gd-more-t">'.h($title).'</span>'
		.($sub !== '' ? '<span class="gd-more-s">'.h($sub).'</span>' : '')
		.'</summary><div class="gd-more-body">';
}
function gd_more_end(){ echo '</div></details>'; }

// "Deploy now" — a CSRF-protected POST form, used on three screens.
function gd_deploy_form($site, $sm = false){
	echo '<form class="d-inline" method="post" action="?act=deploy">';
	gd_tok_field();
	echo '<input type="hidden" name="site" value="'.h($site).'">'
		.'<button class="flat-butt'.($sm ? ' btn-sm' : '').'" type="submit">'
		.'<i class="fas fa-rocket me-1"></i>Deploy'.($sm ? '' : ' now').'</button></form>';
}

/**
 * "Refresh repositories" — drops this user's cached GitHub repo listing and comes
 * back to $to. POST + CSRF, because it changes server-side state.
 */
function gd_refresh_form($to){
	echo '<form class="d-inline" method="post" action="?act=ghapp&amp;do=refresh&amp;to='.h(rawurlencode($to)).'">';
	gd_tok_field();
	echo '<button class="flat-butt-primary btn-sm" type="submit">'
		.'<i class="fas fa-sync-alt me-1"></i>Refresh repositories</button></form>';
}

/**
 * "Previous versions" card: the archived releases with a rollback button on each
 * one that is not currently live. Rolling back is a CSRF-protected POST that only
 * carries the sha — the handler re-checks it against gd_releases() before queueing.
 */
function gd_releases_card($cfg, $st, $busy = false){
	$site = (string)$cfg['site'];
	$rels = gd_releases($cfg);
	$live = isset($st['last_ok_sha']) ? (string)$st['last_ok_sha'] : '';
	if(!preg_match('~^[0-9a-f]{40}$~', $live)) $live = '';

	echo '<div id="versions"></div>';
	gd_card('<i class="fas fa-history panel-icon"></i>Previous versions',
		'Each successful deploy is kept, so you can put an earlier one back online in seconds. '
		.'Rolling back republishes files that were already built — nothing is rebuilt.');

	if(!$rels){
		echo '<div class="gd-empty"><i class="fas fa-history fa-2x d-block mb-2" aria-hidden="true"></i>'
			.'<div class="gd-empty-t">No earlier versions saved yet</div>'
			.'<p>The last 5 successful deploys are saved automatically. Once this site has deployed '
			.'successfully, you will be able to switch back to an earlier version from here.</p></div>';
		gd_card_end();
		return;
	}

	echo '<ul class="gd-rels">';
	foreach($rels as $r){
		$short  = substr($r['sha'], 0, 7);
		$is_live = ($live !== '' && hash_equals($live, $r['sha']));
		echo '<li class="gd-rel'.($is_live ? ' is-live' : '').'">'
			.'<div class="gd-rel-main"><span class="mono gd-rel-sha" title="'.h($r['sha']).'">'.h($short).'</span>'
			.($is_live ? ' <span class="gd-pill gd-pill-ok"><i class="fas fa-check-circle" aria-hidden="true"></i>'
				.'<span>Live now</span></span>' : '')
			.'<div class="gd-sub">Built '.gd_rel($r['mtime']).'</div></div>'
			.'<div class="gd-rel-act">';
		if($is_live){
			echo '<span class="hint mb-0">This is what visitors see</span>';
		}elseif($busy){
			echo '<span class="hint mb-0">A build is running — rollback available when it finishes</span>';
		}else{
			// h() turns the JS string quotes into &#039; — safe inside the double-quoted attribute
			$q = "'Put ".$site.' back to version '.$short."?'";
			echo '<form class="d-inline" method="post" action="?act=rollback">';
			gd_tok_field();
			echo '<input type="hidden" name="site" value="'.h($site).'">'
				.'<input type="hidden" name="sha" value="'.h($r['sha']).'">'
				.'<button class="flat-butt-warning btn-sm" type="submit" onclick="return confirm('.h($q).')">'
				.'<i class="fas fa-history me-1"></i>Restore this version</button></form>';
		}
		echo '</div></li>';
	}
	echo '</ul>';
	gd_card_end();
}

/**
 * Repository + build fields — identical on the add and the edit form.
 * $repo_block / $branch_block replace the plain repo URL / branch inputs when the
 * caller renders its own (GitHub App picker on add, read-only line on edit).
 */
function gd_build_fields($f, $repo_hint, $repo_block = null, $branch_block = null, $with_notify = false){
	if($repo_block !== null){
		echo $repo_block;
	}else{
		echo '<label class="form-label" for="repo">Repository address</label>'
			.'<input type="text" class="form-control" name="repo" id="repo" required maxlength="200" value="'.h($f['repo']).'" placeholder="git@github.com:owner/repo.git">'
			.'<div class="hint">'.$repo_hint.'</div>';
	}
	echo '<div class="row mt-3"><div class="col-md-6">';
	if($branch_block !== null){
		echo $branch_block;
	}else{
		echo '<label class="form-label" for="branch">Branch</label>'
			.'<input type="text" class="form-control" name="branch" id="branch" maxlength="100" value="'.h($f['branch']).'">'
			.'<div class="hint">Only pushes to this branch publish your site.</div>';
	}
	echo '</div></div>';

	/* --- Deploy type: build (Node) or files only (PHP / served straight from source) --- */
	$mode = (isset($f['deploy_mode']) && $f['deploy_mode'] === 'pull') ? 'pull' : 'build';
	echo '<label class="form-label mt-3">Deploy type</label><div class="gd-src">'
		.'<div class="form-check"><input class="form-check-input" type="radio" name="deploy_mode" id="dm_build" value="build"'
		.($mode === 'build' ? ' checked' : '').'>'
		.'<label class="form-check-label" for="dm_build"><i class="fab fa-node-js me-1"></i><strong>Build the site</strong> '
		.'&mdash; React, Vue, Vite, Astro, Hugo, plain HTML&hellip; The website folder becomes an exact copy of the build '
		.'output: anything else in it is deleted.</label></div>'
		.'<div class="form-check"><input class="form-check-input" type="radio" name="deploy_mode" id="dm_pull" value="pull"'
		.($mode === 'pull' ? ' checked' : '').'>'
		.'<label class="form-check-label" for="dm_pull"><i class="fab fa-php me-1"></i><strong>Files only (PHP, no build)</strong> '
		.'&mdash; like <code>git pull</code>: on every push the files in your repository are copied into the website folder '
		.'as they are. Uploads, caches, <code>.env</code> and anything else not in the repository are never touched.</label></div>'
		.'</div>';
	if(!empty($f['was_pull'])){
		// switching an existing files-only site to build mode would mirror-delete its uploads
		echo '<div id="gd_mode_ack" class="mt-2"'.($mode === 'build' ? '' : ' style="display:none"').'>';
		gd_note('<i class="fas fa-exclamation-triangle me-1"></i>This site currently deploys <strong>files only</strong>. '
			.'Switching it to <strong>Build the site</strong> makes the next deploy <strong>delete everything in the website '
			.'folder that the build does not produce</strong> &mdash; uploads, caches, <code>.env</code>, config files.', 'warn');
		echo '<div class="form-check"><input class="form-check-input" type="checkbox" name="ack_mode" id="ack_mode" value="yes"'
			.(!empty($f['ack_mode']) ? ' checked' : '').'>'
			.'<label class="form-check-label" for="ack_mode">I understand &mdash; switch to build mode anyway</label></div></div>';
	}

	echo '<div id="gd_mode_pull"'.($mode === 'pull' ? '' : ' style="display:none"').'>';
	gd_more_open('Files-only settings', 'Publish only part of the repository', trim((string)$f['root_dir']) !== '' && $mode === 'pull');
	echo '<label class="form-label" for="pull_dir">Publish only this folder (optional)</label>'
		.'<input type="text" class="form-control mono" name="pull_dir" id="pull_dir" maxlength="100" value="'.h($f['root_dir']).'" placeholder="public">'
		.'<div class="hint">Leave empty to publish the whole repository. Set it when only one folder of the repository '
		.'belongs in the website folder, e.g. <code>public</code>.</div>'
		.'<div class="hint mt-2"><strong>How files-only deploys work</strong><ul class="mb-0">'
		.'<li>Files your repository tracks are added or updated. When a file is deleted from the repository it is removed '
		.'from the website too &mdash; unless someone changed it on the server, then it is left in place and the deploy log says so.</li>'
		.'<li>Files that are not in the repository (<code>.gitignore</code>d uploads, <code>vendor/</code>, caches) are '
		.'never touched. <code>.env</code> is never copied from the repository, so the live one is always kept. '
		.'<code>.htaccess</code> is updated when the repository has one, never deleted.</li>'
		.'<li>The <code>.git</code> folder is never put in the website folder.</li>'
		.'<li>Need a step after the files land (a database migration, clearing a cache)? Put it in <em>After-deploy command</em> under Advanced '
		.'&mdash; for files-only sites it runs inside the website folder.</li></ul></div>';
	gd_more_end();
	echo '</div><div id="gd_mode_build"'.($mode === 'build' ? '' : ' style="display:none"').'>';

	/* --- Build settings: empty means "detect it", which is the normal case, so stay
	       folded unless this site has actually overridden something --- */
	$bc = trim((string)$f['build_cmd']);
	$od = trim((string)$f['output_dir']);
	$rd = trim((string)$f['root_dir']);
	$nv = isset($f['node_version']) ? trim((string)$f['node_version']) : '';
	$open_build = ($bc !== '' || $od !== '' || $rd !== '' || $nv !== '') && $mode === 'build';
	gd_more_open('Build settings', 'Project folder (monorepos), build command, output folder, Node version', $open_build);
	echo '<label class="form-label" for="root_dir">Project folder (optional)</label>'
		.'<input type="text" class="form-control mono" name="root_dir" id="root_dir" maxlength="100" value="'.h($f['root_dir']).'" placeholder="apps/website">'
		.'<div class="hint">Leave empty unless this is a monorepo. Example: <code>apps/website</code> — the build runs in that '
		.'folder and the output folder is relative to it.</div>'
		.'<label class="form-label mt-3" for="build_cmd">Build command</label>'
		.'<input type="text" class="form-control" name="build_cmd" id="build_cmd" maxlength="300" value="'.h($f['build_cmd']).'" placeholder="Auto-detect">'
		.'<div class="hint">Best left empty — we look at your repository and work out the right command for it. '
		.'Only fill this in to override what we found. The build command is what turns your source into a finished '
		.'website: it runs in the repository root, as your hosting user, right after <code>npm ci</code> / '
		.'<code>npm install</code>. One line, up to 300 characters.</div>'
		.'<label class="form-label mt-3" for="output_dir">Output folder</label>'
		.'<input type="text" class="form-control" name="output_dir" id="output_dir" maxlength="100" value="'.h($f['output_dir']).'" placeholder="Auto-detect">'
		.'<div class="hint">Best left empty as well — we find this together with the build command, and you only need to '
		.'type it to override what we found. It is the folder your build produces: <code>dist</code> for Vite and Astro, '
		.'<code>build</code> for Create React App. Its contents become your website.</div>'
		.'<label class="form-label mt-3" for="node_version">Node version (optional)</label>'
		.'<input type="text" class="form-control mono" name="node_version" id="node_version" maxlength="10" value="'.h($nv).'" placeholder="Auto-detect">'
		.'<div class="hint">Leave this empty and we use the version your repository asks for — an <code>.nvmrc</code> or '
		.'<code>.node-version</code> file, or <code>engines</code> in your <code>package.json</code> — falling back to the '
		.'server&rsquo;s Node 20 when it does not ask for one. Type a version such as <code>22</code> only if you need to '
		.'force one. It is installed just for your account the first time it is used, so the first build with a new version '
		.'takes a minute or so longer.</div>';
	gd_repoconf_hint();
	echo '<div class="hint">Need help? See the <a href="?act=docs">Help page</a></div>';
	gd_more_end();
	echo '</div>';   // #gd_mode_build
	echo '<script>(function(){var b=document.getElementById("dm_build"),p=document.getElementById("dm_pull");'
		.'if(!b||!p)return;function sync(){var pull=p.checked;'
		.'document.getElementById("gd_mode_pull").style.display=pull?"":"none";'
		.'document.getElementById("gd_mode_build").style.display=pull?"none":"";'
		.'var a=document.getElementById("gd_mode_ack");if(a){a.style.display=pull?"none":"";'
		.'document.getElementById("ack_mode").required=!pull;}}'
		.'b.addEventListener("change",sync);p.addEventListener("change",sync);sync();})();</script>';

	/* --- Advanced: environment variables, after-deploy command and (optionally) notifications --- */
	$open_adv = (trim((string)$f['env']) !== '')
		|| (trim((string)$f['post_cmd']) !== '')
		|| ($with_notify && isset($f['notify_email']) && trim((string)$f['notify_email']) !== '');
	gd_more_open('Advanced', $with_notify ? 'Environment variables, after-deploy command and email notifications'
		: 'Environment variables and after-deploy command', $open_adv);
	echo '<label class="form-label" for="env">Environment variables (optional)</label>'
		.'<textarea class="form-control mono" name="env" id="env" rows="4" placeholder="VITE_API_URL=https://api.example.com">'.h($f['env']).'</textarea>'
		.'<div class="hint">Values your build needs, one <code>KEY=VALUE</code> per line. Names may use letters, digits and '
		.'underscores; each value is a single line of up to 500 characters. Lines starting with <code>#</code> are notes and '
		.'are kept exactly as you type them.</div>'
		.'<label class="form-label mt-3" for="post_cmd">After-deploy command (optional)</label>'
		.'<input type="text" class="form-control mono" name="post_cmd" id="post_cmd" maxlength="300" value="'.h($f['post_cmd']).'">'
		.'<div class="hint">Runs after the site is published — for example to clear a cache. It runs as your user and cannot '
		.'fail the deploy. <strong>Build</strong> sites: it runs in your project folder. <strong>Files-only</strong> sites: it '
		.'runs inside the website folder, so e.g. <code>php artisan migrate --force</code> works (up to 10 minutes).</div>';
	if($with_notify){
		echo '<hr class="gd-hr">';
		gd_notify_fields(isset($f['notify_email']) ? $f['notify_email'] : '', isset($f['notify_on']) ? $f['notify_on'] : 'fail');
	}
	gd_more_end();
}

function gd_notify_fields($email, $on){
	echo '<div class="row">'
		.'<div class="col-md-7"><label class="form-label" for="notify_email">Email me about deploys (optional)</label>'
		.'<input type="text" class="form-control" name="notify_email" id="notify_email" maxlength="254" '
		.'value="'.h($email).'" placeholder="you@example.com">'
		.'<div class="hint">Leave this empty and no email is ever sent.</div></div>'
		.'<div class="col-md-5"><label class="form-label" for="notify_on">When to email</label>'
		.'<select class="form-select" name="notify_on" id="notify_on">';
	$opts = array('fail' => 'Only when a deploy fails (default)', 'always' => 'After every deploy', 'never' => 'Never');
	foreach($opts as $k => $lbl){
		echo '<option value="'.h($k).'"'.($on === $k ? ' selected' : '').'>'.h($lbl).'</option>';
	}
	echo '</select></div></div>'
		.'<div class="hint"><i class="fas fa-envelope me-1"></i>The message is sent by this server as soon as a build '
		.'finishes and includes the last lines of the deploy log.</div>';
}

/* ------------------------------------------------------------ domain lookup */

// Domains that can still be connected: in this account, not already configured.
function gd_available_domains($user, $domains){
	$out = array();
	foreach($domains as $d => $info){
		if(!is_string($d) || !gd_valid_site($d)) continue;
		if(is_file(GD_SITES.'/'.$d.'.json')) continue;
		$risk = gd_docroot_risk($user, $d);
		$out[$d] = array(
			'path'   => (is_array($info) && isset($info['path'])) ? (string)$info['path'] : '',
			'type'   => (is_array($info) && isset($info['type'])) ? (string)$info['type'] : '',
			'shared' => ($risk && !empty($risk['shared_with'])) ? $risk['shared_with'] : array(),
			'home'   => ($risk && !empty($risk['is_home_docroot'])),
			'ok'     => ($risk !== null),
		);
		$out[$d]['risky'] = (!empty($out[$d]['shared']) || $out[$d]['home']);
	}
	ksort($out);
	return $out;
}

function gd_risk_warning($user, $site){
	$risk = gd_docroot_risk($user, $site);
	if($risk === null) return null;
	if(empty($risk['shared_with']) && empty($risk['is_home_docroot'])) return '';
	$bits = array();
	if(!empty($risk['shared_with'])) $bits[] = 'also serves: '.implode(', ', $risk['shared_with']);
	if(!empty($risk['is_home_docroot'])) $bits[] = 'it is your main public_html';
	return implode('; ', $bits);
}

/* ------------------------------------------------------------ POST handlers */

$act = gd_get('act');
if(!preg_match('~^[a-z_]{1,16}$~', $act)) $act = 'list';
$is_post = (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST');

/* ---- logtail: JSON tail of one deploy log (read-only, GET, ownership checked) ---- */
if($act === 'logtail' && !$is_post){
	header('Content-Type: application/json');
	header('Cache-Control: no-store');
	header('X-Content-Type-Options: nosniff');
	$site = gd_get('site');
	$file = gd_get('file');
	$off  = (int)gd_get('off');
	if($off < 0) $off = 0;

	$cfg = gd_valid_site($site) ? gd_load_site($site) : null;
	if(!$cfg || $cfg['user'] !== $user){
		http_response_code(404);
		echo json_encode(array('off' => 0, 'data' => '', 'state' => 'unknown', 'size' => 0, 'reset' => false));
		exit;
	}

	$st    = gd_status($site);
	$state = isset($st['state']) ? (string)$st['state'] : 'unknown';
	// a "building" status nobody finished must not keep the browser polling for ever
	if($state === 'building' && gd_stalled($st)) $state = 'stalled';
	if(gd_queued($site) && $state !== 'building') $state = 'queued';

	// file may be empty: caller only wants the state (no file is read at all)
	$path = '';
	if($file !== ''){
		$logs = gd_site_logs($site);
		$cand = GD_LOGS.'/'.$site.'/'.$file;
		if(preg_match('~^deploy-[0-9-]+\.log$~', $file) && in_array($cand, $logs, true)) $path = $cand;
	}
	if($path === '' || !is_file($path)){
		echo json_encode(array('off' => 0, 'data' => '', 'state' => $state, 'size' => 0, 'reset' => false));
		exit;
	}

	clearstatcache(true, $path);
	$size  = (int)filesize($path);
	$reset = false;
	// log was rotated/truncated: start over and tell the client to REPLACE, not append
	if($off > $size){ $off = 0; $reset = true; }
	$data = '';
	$fh = @fopen($path, 'rb');
	if($fh){
		if($off > 0) fseek($fh, $off);
		// up to 256 KB per response: a chatty npm build writes far more than 64 KB
		// between two polls, and a 64 KB cap makes the tail fall further behind.
		$max = 262144;
		while(strlen($data) < $max){
			$chunk = fread($fh, min(65536, $max - strlen($data)));
			if($chunk === false || $chunk === '') break;
			$data .= $chunk;
		}
		$off = (int)ftell($fh);
		fclose($fh);
	}
	echo json_encode(array('off' => $off, 'data' => $data, 'state' => $state, 'size' => $size, 'reset' => $reset),
		JSON_INVALID_UTF8_SUBSTITUTE);
	exit;
}

/* ---- ghbranches: branches of one GitHub App repo (read-only, GET, session-checked) ---- */
if($act === 'ghbranches' && !$is_post){
	header('Content-Type: application/json');
	header('Cache-Control: no-store');
	header('X-Content-Type-Options: nosniff');
	ghapp_budget_start(12);

	$creds = ghapp_load($user);   // this user's own app only
	if($creds === null){
		http_response_code(409);
		echo json_encode(array('error' => 'No GitHub App is connected to your account.'));
		exit;
	}
	$full = gd_get('repo');
	$err  = '';
	$m    = gd_ghapp_find_repo($creds, $full, $err);   // also re-validates the name
	if(!$m){
		http_response_code(404);
		echo json_encode(array('error' => ($err !== '' ? $err : 'repository not available')));
		exit;
	}
	// Same call ghapp_branches() makes, but we keep the raw count so the picker can
	// say how many branches our own name filter dropped instead of silently hiding them.
	$err = ''; $raw = null;
	if(preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~', $full)){
		$raw = ghapp_api('GET', '/repos/'.$full.'/branches?per_page=100', $m['token'], null, $err);
	}
	if(!is_array($raw)){
		http_response_code(502);
		echo json_encode(array('error' => ($err !== '' ? $err : 'could not list branches')));
		exit;
	}
	$branches = array(); $seen = 0;
	foreach($raw as $b){
		if(empty($b['name']) || !is_string($b['name'])) continue;
		$seen++;
		if(gd_valid_branch($b['name'])) $branches[] = (string)$b['name'];
	}
	echo json_encode(array(
		'branches' => $branches,
		'default'  => $m['default_branch'],
		'hidden'   => max(0, $seen - count($branches)),
	));
	exit;
}

$errors  = array();   // strings shown at the top of a form
$form    = array();   // sticky form values
$view    = $act;      // which screen to render
$vdata   = array();   // extra data for the rendered screen

if($is_post){
	gd_check_csrf();

	if($act === 'deploy'){
		$site = gd_post('site');
		gd_require_site($site, $user);
		if(!gd_queue_job($site, 'manual')) gd_stop('Queue failed', 'Could not write the job to the spool directory.', 500);
		gd_redirect('?act=list&msg=queued');

	}elseif($act === 'rollback'){
		$site = gd_post('site');
		$cfg  = gd_require_site($site, $user);
		$sha  = gd_post('sha');
		if(!preg_match('~^[0-9a-f]{40}$~', $sha)){
			gd_stop('Invalid release', 'That is not a valid commit id.', 400);
		}
		// never trust the posted sha: it must still be one of the stored releases
		$known = false;
		foreach(gd_releases($cfg) as $r){ if(hash_equals($r['sha'], $sha)){ $known = true; break; } }
		if(!$known){
			gd_stop('Release not available', 'That release is no longer stored for this site — only the last few builds are kept.', 404);
		}
		$st = gd_status($site);
		// gd_stalled(): a status left in "building" by a runner that died (the runner's
		// own hard cap is 1200s) must not block rollbacks for ever.
		if((isset($st['state']) && $st['state'] === 'building' && !gd_stalled($st)) || gd_queued($site)){
			gd_stop('Build in progress', 'A deploy for '.$site.' is already running or queued. Wait for it to finish, then roll back.', 409);
		}
		if(!gd_queue_job($site, 'rollback', array('mode' => 'rollback', 'sha' => $sha))){
			gd_stop('Queue failed', 'Could not write the job to the spool directory.', 500);
		}
		gd_redirect('?act=list&msg=rollback_queued');

	}elseif($act === 'delete'){
		$site = gd_post('site');
		$cfg  = gd_require_site($site, $user);
		if(gd_post('confirm') !== 'yes'){
			$view = 'delete'; $vdata['cfg'] = $cfg;
			$errors[] = 'Tick the confirmation box to remove this site.';
		}else{
			// Same guard as rollback: deleting the config out from under a running
			// build leaves the runner writing status/logs for a site that is gone.
			$st = gd_status($site);
			if((isset($st['state']) && $st['state'] === 'building' && !gd_stalled($st)) || gd_queued($site)){
				gd_stop('Build in progress', 'A deploy for '.$site.' is already running or queued. Wait for it to finish, then delete the site.', 409);
			}

			@unlink(GD_SITES.'/'.$site.'.json');
			@unlink(GD_HOOKINFO.'/'.$site.'.json');
			@unlink(GD_SPOOL.'/'.$site.'.json');
			gd_rmtree(GD_LOGS.'/'.$site);        // root-owned tree, safe to walk as root
			// Status file LAST: a runner finishing right now writes it one more time,
			// and we would otherwise leave a stray status for a site that no longer exists.
			@unlink(GD_STATUS.'/'.$site.'.json');

			// Everything below lives inside the user's home — done as the user, never as root.
			$o = ''; $e = '';
			if(gd_post('drop_work') === 'yes') gd_asuser($cfg['user'], 'rmwork', $site, $o, $e);
			if(gd_post('drop_key')  === 'yes') gd_asuser($cfg['user'], 'keydel', $site, $o, $e);

			gd_redirect('?act=list&msg=deleted');
		}

	}elseif($act === 'ghapp'){
		$do = gd_get('do');
		if($do === '') $do = gd_post('do');

		if($do === 'start'){
			// '@' can never come out of the act regex, so this view is reachable by POST only
			$view = '@ghapp_start';       // renders the manifest hand-off below

		}elseif($do === 'refresh'){
			ghapp_cache_clear($user);
			$to = gd_get('to'); if($to === '') $to = gd_post('to');
			gd_redirect($to === 'add' ? '?act=add&msg=gh_refreshed' : '?act=ghapp&msg=gh_refreshed');

		}elseif($do === 'disconnect'){
			if(gd_post('confirm') !== 'yes'){
				$view = 'ghapp';
				$errors[] = 'Tick the confirmation box to disconnect the GitHub App.';
			}else{
				ghapp_delete($user);         // only ever this user's own app
				ghapp_cache_clear($user);
				gd_redirect('?act=ghapp&msg=gh_disconnected');
			}
		}else{
			gd_stop('Unsupported action', 'That GitHub connection action does not exist.', 400);
		}

	}elseif($act === 'add' || $act === 'edit'){
		$editing = ($act === 'edit');
		$cfg = null;

		if($editing){
			$site = gd_post('site');
			$cfg  = gd_require_site($site, $user);
		}else{
			$site = gd_post('domain');
			if(!gd_valid_site($site) || !isset($domains[$site])){
				gd_stop('Unknown domain', 'That domain is not one of yours.', 400);
			}
			if(is_file(GD_SITES.'/'.$site.'.json')){
				gd_stop('Already connected', 'A GitDeploy site already exists for '.$site.'.', 409);
			}
		}

		$ghc = ghapp_load($user);   // this user's own GitHub App, or none
		$form = array(
			'domain'       => $site,
			'repo'         => trim(gd_post('repo')),
			'gh_repo'      => trim(gd_post('gh_repo')),
			'source'       => (gd_post('source') === 'ghapp' && $ghc !== null) ? 'ghapp' : 'manual',
			'branch'       => trim(gd_post('branch')),
			'build_cmd'    => gd_post('build_cmd'),
			'output_dir'   => trim(gd_post('output_dir')),
			'root_dir'     => trim(gd_post('root_dir')),
			'node_version' => trim(gd_post('node_version')),
			'post_cmd'     => trim(gd_post('post_cmd')),
			'env'          => gd_post('env'),
			'notify_email' => trim(gd_post('notify_email')),
			'notify_on'    => gd_post('notify_on'),
			'ack'          => (gd_post('ack_shared') === 'yes'),
			'deploy_mode'  => gd_post('deploy_mode'),
			'ack_mode'     => (gd_post('ack_mode') === 'yes'),
			'was_pull'     => ($editing && gd_site_mode($cfg) === 'pull'),
		);
		if(!gd_valid_notify_on($form['notify_on'])) $form['notify_on'] = 'fail';
		if(!gd_valid_deploy_mode($form['deploy_mode'])) $form['deploy_mode'] = 'build';
		// Files-only sites have their own "publish only this folder" field; it is
		// stored as root_dir, which build.sh reads as the folder to publish.
		if($form['deploy_mode'] === 'pull') $form['root_dir'] = trim(gd_post('pull_dir'));
		if($form['was_pull'] && $form['deploy_mode'] === 'build' && !$form['ack_mode']){
			$errors[] = 'Switching this site from files only to build mode deletes everything in the website folder that '
				.'the build does not produce (uploads, caches, .env). Tick the box to confirm.';
		}

		/**
		 * GitHub App sites. The posted repository is never trusted: it is looked up
		 * again against the live installation listings, and the installation id and
		 * default branch come from that lookup — not from the browser.
		 * On edit the repository is fixed: it is read back from the stored config.
		 */
		$app_mode = false;
		$gh_full  = '';   // owner/repo
		$gh_inst  = '';   // numeric installation id

		if($editing && gd_gh_is_app_site($cfg)){
			$app_mode     = true;
			$gh_inst      = (string)$cfg['gh_installation_id'];
			$form['repo'] = (string)$cfg['repo'];
			$gh_full      = gd_gh_full_name($form['repo']);
			if($gh_full === '') $errors[] = 'The stored repository of this GitHub App site is not a valid github.com URL.';

		}elseif(!$editing && $ghc !== null && $form['source'] === 'ghapp'){
			$app_mode = true;
			ghapp_budget_start(12);
			$e = ''; $m = gd_ghapp_find_repo($ghc, $form['gh_repo'], $e);
			if(!$m){
				$errors[] = 'Repository: '.($e !== '' ? $e : 'not available to your GitHub App.');
			}else{
				$gh_full      = $form['gh_repo'];
				$gh_inst      = $m['installation_id'];
				$form['repo'] = 'https://github.com/'.$gh_full;
				if($form['branch'] === '') $form['branch'] = $m['default_branch'];
			}
		}
		if($form['branch'] === '') $form['branch'] = 'main';

		if($app_mode){
			if($gh_full !== '' && !gd_valid_repo($form['repo'])){
				$errors[] = 'The selected repository name is not usable as a github.com URL.';
			}
		}elseif(!gd_valid_repo($form['repo'])){
			$errors[] = 'Repository must look like git@github.com:owner/repo.git or https://github.com/owner/repo';
		}
		if(!gd_valid_branch($form['branch'])) $errors[] = 'Branch name is not valid.';
		// empty output folder = detect it, so only a value the user typed is checked
		if($form['output_dir'] !== '' && !gd_valid_output_dir($form['output_dir'])){
			$errors[] = 'Output folder must be a relative path inside the repo, e.g. dist — leave it empty to have it detected.';
		}
		if(!gd_valid_root_dir($form['root_dir'])){
			$errors[] = ($form['deploy_mode'] === 'pull')
				? 'Publish folder must be a relative path inside the repo, e.g. public — leave it empty to publish the whole repository.'
				: 'Project folder must be a relative path inside the repo, e.g. apps/website — leave it empty unless this is a monorepo.';
		}
		if(!gd_valid_node_version($form['node_version'])){
			$errors[] = 'Node version must be a version number such as 22, 22.11 or 18.20.4 — leave it empty to use the version your repository asks for.';
		}
		if(!gd_valid_post_cmd($form['post_cmd'])){
			$errors[] = 'After-deploy command must be a single line of up to 300 characters.';
		}

		if($form['notify_email'] !== '' &&
			(strlen($form['notify_email']) > 254 || !filter_var($form['notify_email'], FILTER_VALIDATE_EMAIL))){
			$errors[] = 'Notification email address is not a valid address.';
		}

		$e = ''; $build = gd_check_build_cmd($form['build_cmd'], $e);
		if($build === null) $errors[] = $e; else $form['build_cmd'] = $build;

		$e = ''; $env = gd_parse_env($form['env'], $e);
		if($env === null) $errors[] = $e;

		$warn = gd_risk_warning($user, $site);
		if($warn === null){
			$errors[] = 'The document root for '.$site.' could not be resolved. Create the domain in Webuzo first.';
		}elseif($warn !== '' && !$form['ack']){
			$errors[] = 'This document root is shared ('.$warn.'). Tick the box to confirm deploys may overwrite it.';
		}

		if(!$errors){
			$deploy_key = ($cfg && !empty($cfg['deploy_key'])) ? (string)$cfg['deploy_key'] : '';
			if($app_mode){
				$deploy_key = '';   // the installation token is the credential — no ssh key at all
			}elseif(gd_is_ssh_repo($form['repo'])){
				if($deploy_key === '' || !is_file($deploy_key)){
					$kerr = '';
					$deploy_key = gd_ensure_deploy_key($user, $site, $kerr);
					if($deploy_key === '') $errors[] = 'Could not create the deploy key: '.$kerr;
				}
			}else{
				$deploy_key = '';   // https repos need no key (the key file is left on disk)
			}
		}

		if(!$errors){
			$hook   = gd_load_json(GD_HOOKINFO.'/'.$site.'.json');
			$secret = (is_array($hook) && !empty($hook['secret']) && preg_match('~^[0-9a-f]{32,128}$~', $hook['secret']))
				? $hook['secret'] : bin2hex(random_bytes(32));

			// Keep the textarea exactly as typed (comments, blank lines, ordering) so an
			// edit round-trip does not silently rewrite it. Only 'env' is ever executed;
			// 'env_raw' is display-only, is bounded, and holds no control characters.
			$env_raw = (string)$form['env'];
			if(strlen($env_raw) > 8000 || preg_match('~[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]~', $env_raw)) $env_raw = '';

			// merge over the stored config so unknown/future keys survive an edit
			$new = array_merge(is_array($cfg) ? $cfg : array(), array(
				'site'                 => $site,
				'user'                 => $user,
				'repo'                 => $form['repo'],
				'branch'               => $form['branch'],
				'deploy_mode'          => $form['deploy_mode'],
				'build_cmd'            => $form['build_cmd'],
				'output_dir'           => $form['output_dir'],
				'root_dir'             => $form['root_dir'],
				'node_version'         => $form['node_version'],
				'post_cmd'             => $form['post_cmd'],
				'env'                  => $env,
				'env_raw'              => $env_raw,
				'notify_email'         => $form['notify_email'],
				'notify_on'            => $form['notify_on'],
				'deploy_key'           => $deploy_key,
				'allow_shared_docroot' => ($warn !== '' && $form['ack']),
				'created'              => ($cfg && !empty($cfg['created'])) ? (int)$cfg['created'] : time(),
			));
			if($app_mode){
				$new['gh_installation_id'] = (string)$gh_inst;   // runner/hook switch on this
			}else{
				unset($new['gh_installation_id']);
			}
			if(!gd_save_json(GD_SITES.'/'.$site.'.json', $new, 0600)){
				gd_stop('Write failed', 'Could not save the site configuration.', 500);
			}

			// hook.php matches the push by repo full name for app sites — keep it in sync
			$hookinfo = array('secret' => $secret, 'branch' => $new['branch'], 'user' => $user);
			if($app_mode && $gh_full !== ''){
				$hookinfo['gh_full_name'] = $gh_full;
			}elseif(is_array($hook) && !empty($hook['gh_full_name'])
				&& preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~', (string)$hook['gh_full_name'])){
				$hookinfo['gh_full_name'] = (string)$hook['gh_full_name'];
			}
			gd_save_json(GD_HOOKINFO.'/'.$site.'.json', $hookinfo, 0640, 'webuzo');

			if($editing) gd_redirect('?act=edit&site='.rawurlencode($site).'&msg=saved');
			$view  = 'added';
			$vdata = array('cfg' => $new, 'secret' => $secret, 'app' => $app_mode, 'gh_full' => $gh_full);
		}else{
			$view = $editing ? 'edit' : 'add';
			$vdata['cfg'] = $cfg;
		}

	}else{
		gd_stop('Unsupported action', 'That action does not accept POST requests.', 400);
	}
}

/* --------------------------------------------------------------- GET screens */

/**
 * The manifest hand-off page mints a single-use state token every time it renders,
 * so a plain GET (a bookmark, a prefetch, a refresh) would burn tokens and could be
 * triggered cross-site. It is reachable through the CSRF-checked POST above only.
 */
if($view === '@ghapp_start' && !$is_post) gd_redirect('?act=ghapp');

$msgmap = array(
	'queued'  => 'Deploy queued — the build starts in a few seconds. Open the logs to watch it run.',
	'rollback_queued' => 'Restoring that version — it goes live in a few seconds.',
	'deleted' => 'Site disconnected. Your published files were left exactly as they were.',
	'saved'   => 'Settings saved. They apply to the next deploy.',
	'gh_disconnected' => 'GitHub disconnected from your account.',
	'gh_refreshed'    => 'Reading your repository list from GitHub again.',
);
$msg = gd_get('msg');
$flash = isset($msgmap[$msg]) ? $msgmap[$msg] : '';

$host = gd_panel_host();

/* ---- docs / help (read-only page, no state touched) ---- */
if($view === 'docs'){
	gd_head('Help');
	$docs = GD_BASE.'/web/enduser/docs_content.php';
	if(is_file($docs)){
		require_once $docs;
		gd_docs_html(array('user' => $user, 'panel_host' => $host, 'has_ghapp' => (ghapp_load($user) !== null)));
	}else{
		gd_note('The help page is not installed on this server.', 'warn');
	}
	gd_foot();
	exit;
}

/* ---- list ---- */
if($view === 'list' || $view === 'deploy'){
	$sites = gd_user_sites($user);
	$busy = false;
	foreach($sites as $s => $c){
		if(gd_busy($s)) $busy = true;   // a stalled "building" status is NOT busy
	}

	// the client-side elapsed ticker carries the liveness; the reload can be lazy
	gd_head('Deploy static sites from GitHub to your domains', $busy ? 10 : 0, '?act=list');
	if($flash) gd_note('<i class="fas fa-check-circle me-1"></i>'.h($flash), 'ok');

	$ghc = ghapp_load($user);   // your own app only — never another tenant's

	if(!$sites){
		/* ---- first run: explain the whole thing, then one single call to action ---- */
		gd_card('<i class="fab fa-github panel-icon"></i>Welcome to GitDeploy',
			'Publish a website straight from GitHub — no server to run, nothing to upload by hand.');
		echo '<ol class="gd-onboard">'
			.'<li><span class="gd-onboard-n">1</span><div><strong>Connect a GitHub repository to one of your domains.</strong>'
			.'<div class="gd-sub">Pick the domain, pick the repository, choose the branch to publish from.</div></div></li>'
			.'<li><span class="gd-onboard-n">2</span><div><strong>We build it every time you push.</strong>'
			.'<div class="gd-sub">Your project is fetched and built here on the server, exactly as it would be on your laptop.</div></div></li>'
			.'<li><span class="gd-onboard-n">3</span><div><strong>Your site updates itself.</strong>'
			.'<div class="gd-sub">The finished files replace what is on the domain — usually a minute after you push.</div></div></li>'
			.'</ol>';
		echo '<p class="soft-card-desc gd-onboard-p">'
			.($ghc === null
				? 'You can connect GitHub once and then choose repositories from a list, or add a single repository by its '
					.'address and paste a webhook into GitHub yourself. Connecting GitHub is easier, and you can change your mind later.'
				: 'Your GitHub account is already connected, so your repositories will appear in a list on the next screen.')
			.'</p>';
		echo '<div class="gd-cta"><a class="flat-butt" href="?act=add"><i class="fas fa-plus me-1"></i>Connect a repository</a>'
			.($ghc === null ? ' <a class="flat-butt-primary" href="?act=ghapp"><i class="fab fa-github me-1"></i>Connect GitHub first</a>' : '')
			.'</div>';
		gd_card_end();
	}else{
		gd_card('<i class="fab fa-github panel-icon"></i>Your sites',
			'Each push to the branch you chose rebuilds the site and publishes it to that domain.');
		echo '<ul class="gd-sites">';
		foreach($sites as $s => $c){
			$st   = gd_status($s);
			$sha  = gd_short_sha($st);
			$key  = gd_state_key($st, gd_queued($s));
			$secs = gd_build_secs($st);
			echo '<li class="gd-site gd-st-'.h($key).'">'
				.'<div class="gd-site-main">'
				.'<div class="gd-site-top">'
				.'<a class="gd-domain" href="https://'.h($s).'" target="_blank" rel="noopener">'.h($s)
				.'<i class="fas fa-external-link-alt gd-ext" aria-hidden="true"></i></a>'
				.gd_badge($st, gd_queued($s), true);
			$whenlbl = ($key === 'live') ? 'Deployed ' : (($key === 'failed') ? 'Failed ' : '');
			if(!empty($st['finished']) && ($key === 'live' || $key === 'failed')){
				echo '<span class="gd-site-when">'.h($whenlbl).gd_rel($st['finished'])
					.($secs > 0 ? ' <span class="gd-dot">&middot;</span> took '.h(gd_dur($secs)) : '').'</span>';
			}
			echo '</div>';

			echo '<div class="gd-site-meta">'
				.'<span class="mono" title="'.h($c['repo']).'">'.h(gd_short_repo($c['repo'])).'</span>'
				.'<span class="gd-dot">&middot;</span><span class="mono"><i class="fas fa-code-branch me-1" aria-hidden="true"></i>'
				.h($c['branch']).'</span>';
			$rdir = isset($c['root_dir']) ? trim((string)$c['root_dir']) : '';
			if($rdir !== '') echo '<span class="gd-dot">&middot;</span><span class="mono">folder: '.h($rdir).'</span>';
			echo '<span class="gd-dot">&middot;</span><span class="mono">'.h(gd_mode_label($c)).'</span>';
			if($sha !== '') echo '<span class="gd-dot">&middot;</span><span class="mono">'.h($sha).'</span>';
			echo '<span class="gd-dot">&middot;</span>'.gd_source_tag($c)
				.'</div>';

			if(!isset($domains[$s])){
				echo '<div class="gd-line gd-line-err"><i class="fas fa-exclamation-triangle me-1" aria-hidden="true"></i>'
					.'This domain is no longer in your account, so deploys will fail. Remove the site or add the domain back in Webuzo.</div>';
			}
			if($key === 'failed' && !empty($st['error'])){
				echo '<div class="gd-line gd-line-err"><i class="fas fa-times-circle me-1" aria-hidden="true"></i>'
					.h(substr((string)$st['error'], 0, 200)).' <a href="?act=log&amp;site='.h(rawurlencode($s)).'">See the full log</a></div>';
			}
			if($key === 'stalled'){
				echo '<div class="gd-line gd-line-warn"><i class="fas fa-exclamation-triangle me-1" aria-hidden="true"></i>'
					.'This build never reported a result. Deploy again to retry.</div>';
			}
			if($key === 'none'){
				echo '<div class="gd-line gd-line-muted">Not published yet — press <strong>Deploy</strong> to build it for the first time.</div>';
			}
			echo '</div><div class="gd-site-actions gd-actions">';
			gd_deploy_form($s, true);
			echo ' <a class="flat-butt-primary btn-sm" href="?act=log&amp;site='.h(rawurlencode($s)).'"><i class="fas fa-file-alt me-1"></i>Logs</a> '
				.'<a class="flat-butt-primary btn-sm" href="?act=log&amp;site='.h(rawurlencode($s)).'#versions"><i class="fas fa-history me-1"></i>Versions</a> '
				.'<a class="flat-butt-primary btn-sm gd-iconbtn" href="?act=edit&amp;site='.h(rawurlencode($s)).'" '
				.'title="Settings for '.h($s).'" aria-label="Settings for '.h($s).'"><i class="fas fa-cog"></i></a>'
				.'</div></li>';
		}
		echo '</ul>';
		echo '<div class="gd-cta"><a class="flat-butt" href="?act=add"><i class="fas fa-plus me-1"></i>Connect a repository</a></div>';
		gd_card_end();
	}

	gd_card('<i class="fab fa-github panel-icon"></i>GitHub connection',
		'Connect your GitHub account once and you can pick repositories from a list — access and push notifications are set up for you.');
	if($ghc === null){
		echo '<div class="hint mb-3"><i class="fas fa-unlink me-1" aria-hidden="true"></i>Not connected yet. Until you connect, '
			.'each repository is added by its address, with a read-only key and a webhook you paste into GitHub yourself.</div>';
	}else{
		$ghname = (gd_ghv($ghc, 'name') !== '') ? gd_ghv($ghc, 'name') : gd_ghv($ghc, 'slug');
		echo '<div class="hint mb-3"><i class="fas fa-check-circle me-1" aria-hidden="true"></i>Connected as <span class="mono">'
			.h($ghname !== '' ? $ghname : 'GitHub App').'</span>'
			.(gd_ghv($ghc, 'owner') !== '' ? ' &middot; owner <span class="mono">'.h(gd_ghv($ghc, 'owner')).'</span>' : '').'</div>';
	}
	echo '<a class="flat-butt-primary" href="?act=ghapp"><i class="fab fa-github me-1"></i>'
		.($ghc === null ? 'Connect GitHub' : 'Manage GitHub connection').'</a>';
	gd_card_end();

	if($busy) gd_note('<i class="fas fa-sync-alt me-1"></i>A build is running. The elapsed time counts up live and this page refreshes itself every 10 seconds.');
	gd_foot();
	exit;
}

/* ---- ghapp: GitHub App connection management ---- */
if($view === 'ghapp'){
	$ghc = ghapp_load($user);   // per hosting user: no cross-tenant app is ever shown

	gd_head('GitHub connection');
	echo '<nav class="gd-crumbs"><a href="?act=list">My sites</a> / GitHub connection</nav>';
	if($flash) gd_note('<i class="fas fa-check-circle me-1"></i>'.h($flash), 'ok');
	foreach($errors as $er) gd_note(h($er), 'err');

	if($ghc === null){
		gd_card('<i class="fab fa-github panel-icon"></i>Connect GitHub', 'A one-time setup, and then adding a site takes seconds.');
		echo '<ol class="gd-onboard">'
			.'<li><span class="gd-onboard-n">1</span><div><strong>GitHub asks you to confirm.</strong>'
			.'<div class="gd-sub">We send you to GitHub, where you approve a private connection owned by your own '
			.'GitHub account. Nothing is shared with anyone else.</div></div></li>'
			.'<li><span class="gd-onboard-n">2</span><div><strong>You choose the repositories.</strong>'
			.'<div class="gd-sub">Only the ones you pick are visible to GitDeploy, and only for reading. You can change '
			.'the list, or revoke it entirely, at any time on GitHub.</div></div></li>'
			.'<li><span class="gd-onboard-n">3</span><div><strong>Adding a site becomes a two-click job.</strong>'
			.'<div class="gd-sub">Repositories appear in a list, and push notifications are set up for you — no key and '
			.'no secret to copy anywhere.</div></div></li>'
			.'</ol>';
		echo '<div class="gd-summary"><div class="gd-summary-t">What gets registered</div>'
			.'<p>This server\'s own name, <span class="mono">'.h($host).'</span>, is stored in the connection so GitHub '
			.'knows where to send your pushes. It is fixed once created — if that name looks wrong, ask your administrator '
			.'before continuing.</p></div>';
		echo '<form method="post" action="?act=ghapp&amp;do=start" class="gd-cta">';
		gd_tok_field();
		echo '<button class="flat-butt" type="submit"><i class="fab fa-github me-1"></i>Connect GitHub</button> '
			.'<a class="flat-butt-primary" href="?act=list">Not now</a></form>';
		gd_card_end();
		gd_foot();
		exit;
	}

	$manage  = gd_ghapp_manage_url($ghc);
	$install = gd_ghapp_install_url($ghc);

	gd_card('<i class="fab fa-github panel-icon"></i>GitHub is connected',
		'Your repositories can be picked from a list, and pushes reach this server on their own.');
	echo '<div class="table-responsive"><table class="table webuzo-table mb-0">'
		.'<tr><th>Name on GitHub</th><td class="mono">'.h(gd_ghv($ghc, 'name') !== '' ? gd_ghv($ghc, 'name') : '—').'</td></tr>'
		.'<tr><th>Owned by</th><td class="mono">'.h(gd_ghv($ghc, 'owner') !== '' ? gd_ghv($ghc, 'owner') : '—').'</td></tr>'
		.'<tr><th>Connected</th><td>'.gd_rel(isset($ghc['created']) ? $ghc['created'] : 0).'</td></tr>'
		.'<tr><th class="gd-faint">Reference</th><td class="mono gd-faint">'
		.h(gd_ghv($ghc, 'slug') !== '' ? gd_ghv($ghc, 'slug') : '—').' &middot; #'.h(gd_ghv($ghc, 'app_id')).'</td></tr>'
		.'</table></div>';
	echo '<div class="gd-cta"><a class="flat-butt" href="'.h($install).'" target="_blank" rel="noopener">'
		.'<i class="fas fa-plus me-1"></i>Choose repositories<i class="fas fa-external-link-alt gd-ext"></i></a> '
		.'<a class="flat-butt-primary" href="'.h($manage).'" target="_blank" rel="noopener">'
		.'<i class="fas fa-cog me-1"></i>Manage on GitHub<i class="fas fa-external-link-alt gd-ext"></i></a> '
		.'<a class="flat-butt-primary" href="?act=list"><i class="fas fa-list-ul me-1"></i>My sites</a></div>';
	gd_card_end();

	gd_card('<i class="fas fa-plug panel-icon"></i>Where you are connected',
		'The GitHub accounts that have shared repositories with GitDeploy, read live from GitHub.');
	ghapp_budget_start(12);
	$e = ''; $insts = ghapp_installations($ghc, $e);
	if($insts === null){
		gd_note('GitHub did not answer just now, so this list is unavailable: '.h($e !== '' ? $e : 'unknown error')
			.'. Try again in a moment.', 'warn');
	}elseif(!$insts){
		gd_note('No repositories have been shared with GitDeploy yet. Use <strong>Choose repositories</strong> above, '
			.'then come back and press <strong>Refresh repositories</strong>.', 'warn');
	}else{
		$rows = array();
		foreach($insts as $inst){
			$login = isset($inst['account']['login']) ? (string)$inst['account']['login'] : '—';
			$type  = isset($inst['account']['type']) ? (string)$inst['account']['type'] : '';
			$rows[] = '<li><span class="mono">'.h($login).'</span>'.($type !== '' ? ' <span class="hint">'.h($type).'</span>' : '').'</li>';
		}
		echo '<div class="hint mb-2">Shared from '.count($rows).' GitHub account'.(count($rows) === 1 ? '' : 's').':</div>'
			.'<ul class="gd-logs">'.implode('', $rows).'</ul>';
	}
	$rc = ghapp_cache_get($user);
	echo '<div class="gd-cta gd-actions">';
	gd_refresh_form('ghapp');
	echo '<span class="hint ms-2 mb-0">Your repository list is remembered for five minutes'
		.(is_array($rc) && !empty($rc['ts']) ? ', last read '.gd_rel($rc['ts']) : '')
		.'. Refresh it after sharing new repositories on GitHub.</span></div>';
	gd_card_end();

	gd_card('<i class="fas fa-exclamation-triangle panel-icon"></i>Disconnect GitHub',
		'Only do this if you want to stop using GitHub with GitDeploy.');
	gd_note('Sites you connected this way will <strong>stop deploying until you connect GitHub again</strong>. '
		.'Their settings are kept and their published files are untouched — deploys simply fail in the meantime. '
		.'The connection also stays on GitHub until you remove it there.', 'warn');
	echo '<form method="post" action="?act=ghapp&amp;do=disconnect">';
	gd_tok_field();
	echo '<div class="gd-confirm"><div class="form-check"><input class="form-check-input" type="checkbox" name="confirm" id="gh_confirm" value="yes" required>'
		.'<label class="form-check-label" for="gh_confirm"><strong>Yes, disconnect GitHub from my account.</strong></label></div></div>'
		.'<div class="gd-cta"><button class="flat-butt-danger" type="submit"><i class="fas fa-unlink me-1"></i>Disconnect</button> '
		.'<a class="flat-butt-primary" href="?act=list">Cancel</a></div></form>';
	gd_card_end();
	gd_foot();
	exit;
}

/* ---- ghapp_start: hand off to GitHub's app-manifest flow ---- */
if($view === '@ghapp_start'){
	$state = ghapp_state_new($user);          // the user is carried inside the token
	if($state === null) gd_stop('Could not start', 'The connection token could not be created. Try again.', 500);
	$hostn = gd_panel_host();

	/*
	 * GitHub App names are globally unique: "GitDeploy example-com" is taken the
	 * moment a second server tries it, and the manifest flow then fails with a bare
	 * error. Six hex characters of entropy make a collision practically impossible.
	 * Budget: 'GitDeploy ' (10) + host (<=17) + '-' + 6 hex = 34, GitHub's limit.
	 */
	$hslug = trim(preg_replace('~-+~', '-', preg_replace('~[^A-Za-z0-9-]~', '-', $hostn)), '-');
	$hslug = trim(substr($hslug, 0, 17), '-');
	$appname = 'GitDeploy '.($hslug !== '' ? $hslug.'-' : '').bin2hex(random_bytes(3));

	$manifest = array(
		'name'                => $appname,
		'url'                 => 'https://www.digitalbkk.com',
		'hook_attributes'     => array('url' => 'https://'.$hostn.':2003/gitdeploy/hook.php?app=1', 'active' => true),
		'redirect_url'        => 'https://'.$hostn.':2003/gitdeploy/index.php?act=ghapp_callback',
		'public'              => false,
		'default_permissions' => array('contents' => 'read', 'metadata' => 'read'),
		'default_events'      => array('push'),
	);
	$mjson = json_encode($manifest, JSON_UNESCAPED_SLASHES);

	gd_head('Create the GitHub App');
	echo '<nav class="gd-crumbs"><a href="?act=list">My sites</a> / <a href="?act=ghapp">GitHub connection</a> / create</nav>';
	gd_card('<i class="fab fa-github panel-icon"></i>Taking you to GitHub');
	echo '<p class="soft-card-desc">In a moment you will be on GitHub, where you review the name below, choose which of '
		.'your GitHub accounts should own the connection, and confirm. GitHub then brings you straight back here.</p>';
	echo '<div class="gd-summary"><div class="gd-summary-t">What will be registered</div>'
		.'<div class="table-responsive"><table class="table webuzo-table mb-2">'
		.'<tr><th>Name</th><td class="mono">'.h($appname).'</td></tr>'
		.'<tr><th>This server</th><td class="mono">'.h($hostn).'</td></tr>'
		.'<tr><th class="gd-faint">Push notifications go to</th><td class="mono gd-faint">'.h($manifest['hook_attributes']['url']).'</td></tr>'
		.'<tr><th class="gd-faint">GitHub returns you to</th><td class="mono gd-faint">'.h($manifest['redirect_url']).'</td></tr>'
		.'</table></div>'
		.'<p>Only read access to the repositories you pick is requested. These addresses are stored permanently, and they '
		.'use this server\'s own name rather than whatever is in your browser bar. If the name above looks wrong, ask your '
		.'administrator before continuing.</p></div>';
	// target=_top: this page can be inside the panel's iframe, and github.com refuses to be framed
	echo '<form id="ghmanifest" method="post" target="_top" '
		.'action="https://github.com/settings/apps/new?state='.h(rawurlencode($state)).'">'
		.'<input type="hidden" name="manifest" value="'.h($mjson).'">'
		.'<button class="flat-butt" type="submit"><i class="fab fa-github me-1"></i>Continue to GitHub</button> '
		.'<a class="flat-butt-primary" href="?act=ghapp">Cancel</a></form>';
	echo '<div class="hint mt-3">This link works once and expires after ten minutes. If it does, just start again '
		.'from the GitHub connection page.</div>';
	gd_card_end();
	echo '<script>setTimeout(function(){var f=document.getElementById("ghmanifest");if(f)f.submit();},500);</script>';
	gd_foot();
	exit;
}

/* ---- add (form) ---- */
if($view === 'add'){
	$avail = gd_available_domains($user, $domains);
	$sel   = isset($form['domain']) ? $form['domain'] : gd_get('site');
	$f = array_merge(array(
		// build_cmd/output_dir start empty: empty is the recommended "detect it" setting
		'repo' => '', 'gh_repo' => '', 'source' => '', 'branch' => 'main', 'build_cmd' => '',
		'output_dir' => '', 'root_dir' => '', 'node_version' => '', 'post_cmd' => '', 'env' => '',
		'notify_email' => '', 'notify_on' => 'fail', 'ack' => false, 'deploy_mode' => 'build',
	), $form);

	// GitHub App repository picker (only when an app is connected)
	$ghc      = ghapp_load($user);   // this user's own app only
	$gherrs   = array();
	$ghgroups = array();
	$ghcount  = 0;
	$ghts     = 0;
	if($ghc !== null){
		$gh       = gd_ghapp_repo_groups($user, $ghc);
		$ghgroups = $gh['groups'];
		$gherrs   = $gh['errors'];
		$ghts     = isset($gh['ts']) ? (int)$gh['ts'] : 0;
		foreach($ghgroups as $g) $ghcount += count($g['repos']);
	}
	// default source: the app picker when it actually has something to pick
	$src = ($f['source'] === 'ghapp' || $f['source'] === 'manual') ? $f['source'] : (($ghcount > 0) ? 'ghapp' : 'manual');
	if($ghc === null) $src = 'manual';

	$riskjs = array();
	foreach($avail as $d => $a){
		if($a['risky']){
			$bits = array();
			if($a['shared']) $bits[] = 'it also serves '.implode(', ', $a['shared']);
			if($a['home']) $bits[] = 'it is your account\'s main website folder (public_html)';
			$riskjs[$d] = implode(' and ', $bits);
		}
	}

	gd_head('Connect a GitHub repository');
	echo '<nav class="gd-crumbs"><a href="?act=list">My sites</a> / Connect a repository</nav>';
	if($flash) gd_note('<i class="fas fa-check-circle me-1"></i>'.h($flash), 'ok');
	foreach($errors as $er) gd_note(h($er), 'err');

	if(!$avail){
		gd_card('<i class="fas fa-globe panel-icon"></i>No domain is available');
		gd_note('Every domain in your account is already connected to a repository — or you have not added a domain yet. '
			.'Add a domain in Webuzo first, then come back here to connect a repository to it.', 'warn');
		echo '<div class="gd-cta"><a class="flat-butt-primary" href="?act=list"><i class="fas fa-arrow-left me-1"></i>Back to my sites</a></div>';
		gd_card_end();
		gd_foot(); exit;
	}

	echo '<form method="post" action="?act=add">';
	gd_card('<span class="gd-step">1</span>Choose a domain', 'This is the address visitors will use.');
	gd_tok_field();
	echo '<label class="form-label" for="domain">Domain</label>'
		.'<select class="form-select" name="domain" id="domain" required>'
		.'<option value="">— select a domain —</option>';
	foreach($avail as $d => $a){
		echo '<option value="'.h($d).'"'.($sel === $d ? ' selected' : '').'>'
			.h($d).' — '.h($a['path'] !== '' ? $a['path'] : 'no website folder')
			.($a['risky'] ? '  ⚠ shared folder' : '').'</option>';
	}
	echo '</select><div class="hint">Each deploy replaces the contents of this domain\'s website folder. '
		.'Your <code>.well-known</code>, <code>cgi-bin</code> and <code>.htaccess</code> are always left alone.</div>'
		.'<div id="riskbox" style="display:none"><div class="alert alert-warning gd-note mt-3" id="risktext"></div>'
		.'<div class="form-check"><input class="form-check-input" type="checkbox" name="ack_shared" id="ack" value="yes"'
		.(!empty($f['ack']) ? ' checked' : '').'>'
		.'<label class="form-check-label" for="ack">I understand that deploys will overwrite this folder, which also serves: '
		.'<span id="risklist" class="mono"></span></label></div></div>';
	gd_card_end();

	gd_card('<span class="gd-step">2</span>Choose a repository', 'Pick what to publish and which branch to publish from.');

	$manual_hint = 'Private repository: use <code>git@github.com:owner/repo.git</code> and we create a read-only key for you. '
		.'Public repository: use <code>https://github.com/owner/repo</code> — nothing else needed.';
	$repo_block = null; $branch_block = null;

	if($ghc !== null){
		foreach($gherrs as $ge) gd_note('GitHub: '.h($ge).' — you can still add the repository by its address below.', 'warn');
		if($ghcount === 0 && !$gherrs){
			gd_note('GitHub has not given GitDeploy access to any repository yet. '
				.'<a href="'.h(gd_ghapp_install_url($ghc)).'" target="_blank" rel="noopener">Choose the repositories to share</a>, '
				.'then press <strong>Refresh repositories</strong> below — the list is remembered for a few minutes, so a plain '
				.'page reload may still look empty.', 'warn');
		}

		// Inactive pane inputs are disabled (and stay disabled without JS) so exactly
		// one repo/branch pair is ever submitted.
		$dA = ($src === 'ghapp')  ? '' : ' disabled';
		$dM = ($src === 'manual') ? '' : ' disabled';

		$repo_block = '<div class="mb-3 gd-src">'
			.'<div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="source" '
			.'id="src_ghapp" value="ghapp"'.($src === 'ghapp' ? ' checked' : '').'>'
			.'<label class="form-check-label" for="src_ghapp"><i class="fab fa-github me-1"></i>From my GitHub list (recommended)</label></div>'
			.'<div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="source" '
			.'id="src_manual" value="manual"'.($src === 'manual' ? ' checked' : '').'>'
			.'<label class="form-check-label" for="src_manual"><i class="fas fa-link me-1"></i>By repository address</label></div>'
			.'</div>';

		$opts = '<option value="">— select a repository —</option>';
		foreach($ghgroups as $g){
			if(!$g['repos']) continue;
			$opts .= '<optgroup label="'.h($g['account']).'">';
			foreach($g['repos'] as $r){
				$opts .= '<option value="'.h($r['full_name']).'" data-default="'.h($r['default_branch']).'"'
					.($f['gh_repo'] === $r['full_name'] ? ' selected' : '').'>'
					.h($r['full_name']).($r['private'] ? ' (private)' : '').'</option>';
			}
			$opts .= '</optgroup>';
		}

		// formaction/formnovalidate: a nested <form> would be invalid HTML inside the
		// add form, so this button re-targets the surrounding (CSRF-carrying) form.
		$refresh_btn = '<button type="submit" class="flat-butt-primary btn-sm ms-2" formnovalidate '
			.'formmethod="post" formaction="?act=ghapp&amp;do=refresh&amp;to=add">'
			.'<i class="fas fa-sync-alt me-1"></i>Refresh repositories</button>';

		$repo_block .= '<div id="pane_ghapp" class="gd-pane"'.($src === 'ghapp' ? '' : ' style="display:none"').'>'
			.'<label class="form-label" for="gh_repo">Repository</label>'
			.'<select class="form-select" name="gh_repo" id="gh_repo" required'.$dA.'>'.$opts.'</select>'
			.'<div class="hint">'.h($ghcount).' repositor'.($ghcount === 1 ? 'y is' : 'ies are').' shared with GitDeploy. '
			.'Access and push notifications are set up for you — there is nothing to paste into GitHub.</div>'
			.'<div class="hint">List last read '.gd_rel($ghts).'. Shared a new repository since then?'.$refresh_btn.'</div></div>'
			.'<div id="pane_manual" class="gd-pane"'.($src === 'manual' ? '' : ' style="display:none"').'>'
			.'<label class="form-label" for="repo">Repository address</label>'
			.'<input type="text" class="form-control" name="repo" id="repo" required maxlength="200" value="'.h($f['repo']).'" '
			.'placeholder="git@github.com:owner/repo.git"'.$dM.'>'
			.'<div class="hint">'.$manual_hint.'</div></div>';

		$branch_block = '<label class="form-label" for="gh_branch">Branch</label>'
			.'<div id="bpane_ghapp" class="gd-pane"'.($src === 'ghapp' ? '' : ' style="display:none"').'>'
			.'<select class="form-select" name="branch" id="gh_branch"'.$dA.'>'
			.'<option value="'.h($f['branch']).'" selected>'.h($f['branch']).'</option></select>'
			.'<input type="text" class="form-control mt-2" name="branch" id="gh_branch_text" maxlength="100" data-fb="1" '
			.'value="'.h($f['branch']).'" style="display:none" disabled>'
			.'<div class="hint" id="gh_branch_hint">Choose a repository above and its branches will appear here.</div>'
			// always reachable, not only when the listing failed
			.'<div class="hint"><a href="#" id="gh_branch_toggle">type a branch name instead</a></div>'
			.'<noscript><div class="hint">The branch list needs JavaScript — choose <em>By repository address</em> above if it stays empty.</div></noscript></div>'
			.'<div id="bpane_manual" class="gd-pane"'.($src === 'manual' ? '' : ' style="display:none"').'>'
			.'<input type="text" class="form-control" name="branch" id="branch" maxlength="100" value="'.h($f['branch']).'"'.$dM.'>'
			.'<div class="hint">Only pushes to this branch publish your site.</div></div>';
	}

	gd_build_fields($f, $manual_hint, $repo_block, $branch_block, true);
	echo '<div class="gd-cta"><button class="flat-butt" type="submit"><i class="fas fa-plus me-1"></i>Connect repository</button> '
		.'<a class="flat-butt-primary" href="?act=list">Cancel</a>'
		.'<div class="hint mt-2">Nothing is published yet — you deploy for the first time on the next screen.</div></div>';
	gd_card_end();
	echo '</form>';

	echo '<script>var GDRISK='.json_encode($riskjs, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_FORCE_OBJECT).';'
		.'function gdRisk(){var d=document.getElementById("domain").value,b=document.getElementById("riskbox"),a=document.getElementById("ack");'
		.'if(GDRISK[d]){b.style.display="block";document.getElementById("risktext").textContent="Careful — this domain shares its website folder: "+GDRISK[d]+". Deploying will overwrite it.";'
		.'document.getElementById("risklist").textContent=GDRISK[d];a.required=true;}else{b.style.display="none";a.required=false;a.checked=false;}}'
		.'document.getElementById("domain").addEventListener("change",gdRisk);gdRisk();</script>';

	if($ghc !== null){
		// Source toggle: exactly one pane is enabled at a time, so exactly one
		// repo/branch pair is ever posted. Branches load from ?act=ghbranches;
		// if that fails the branch select is swapped for a plain text input.
		echo '<script>(function(){
var radios=document.querySelectorAll("input[name=source]"); if(!radios.length) return;
var sel=document.getElementById("gh_branch"), tx=document.getElementById("gh_branch_text"),
    repo=document.getElementById("gh_repo"), hint=document.getElementById("gh_branch_hint"),
    tg=document.getElementById("gh_branch_toggle"), fb=false,
    touched='.($f['gh_repo'] !== '' ? 'true' : 'false').';
function src(){ for(var i=0;i<radios.length;i++) if(radios[i].checked) return radios[i].value; return "manual"; }
function pane(id,on){ var p=document.getElementById(id); if(!p) return;
	p.style.display=on?"":"none";
	var e=p.querySelectorAll("input,select,textarea");
	for(var i=0;i<e.length;i++){ if(e[i].getAttribute("data-fb")==="1") continue; e[i].disabled=!on; } }
function branchMode(){ if(!sel||!tx) return; var app=(src()==="ghapp");
	tx.style.display=(fb&&app)?"":"none"; tx.disabled=!(fb&&app);
	sel.style.display=fb?"none":""; sel.disabled=(fb||!app);
	if(tg) tg.textContent=fb?"choose from the branch list":"type a branch name instead"; }
function apply(){ var v=src();
	pane("pane_ghapp",v==="ghapp"); pane("bpane_ghapp",v==="ghapp");
	pane("pane_manual",v==="manual"); pane("bpane_manual",v==="manual");
	branchMode(); }
function fallback(def,msg){ if(tx&&!tx.value) tx.value=def||"main"; fb=true; branchMode();
	if(hint) hint.textContent=msg||"Could not load the branch list — type the branch name."; }
function load(){ if(!repo||!sel||!repo.value) return;
	var o=repo.options[repo.selectedIndex], def=o?o.getAttribute("data-default"):"main";
	if(!window.fetch){ fallback(def,"Type the branch name."); return; }
	if(hint) hint.textContent="Loading branches…";
	fetch("?act=ghbranches&repo="+encodeURIComponent(repo.value),{credentials:"same-origin",cache:"no-store"})
	.then(function(r){return r.json();}).then(function(d){
		if(!d||!d.branches||!d.branches.length){ fallback(def,d&&d.error?("GitHub: "+d.error):null); return; }
		var hid=(typeof d.hidden==="number"&&d.hidden>0)?(" "+d.hidden+" branch"+(d.hidden===1?" is":"es are")
			+" hidden (unsupported characters) — use “type a branch name instead” if you need one of them."):"";
		var want=d["default"]||def||"main", cur=sel.value, keep;
		if(touched&&d.branches.indexOf(cur)>=0) keep=cur;
		else keep=(d.branches.indexOf(want)>=0)?want:d.branches[0];
		sel.innerHTML="";
		for(var i=0;i<d.branches.length;i++){
			var op=document.createElement("option"); op.value=d.branches[i]; op.textContent=d.branches[i];
			if(d.branches[i]===keep) op.selected=true;
			sel.appendChild(op); }
		fb=false; branchMode();
		if(hint) hint.textContent="Only pushes to this branch trigger a deploy."+hid;
	}).catch(function(){ fallback(def,null); });
}
for(var i=0;i<radios.length;i++) radios[i].addEventListener("change",apply);
if(sel) sel.addEventListener("change",function(){ touched=true; });
if(repo) repo.addEventListener("change",load);
if(tg) tg.addEventListener("click",function(e){ e.preventDefault();
	if(!fb&&tx&&!tx.value) tx.value=(sel&&sel.value)?sel.value:"main";
	fb=!fb; branchMode(); });
apply(); if(repo&&repo.value) load();
})();</script>';
	}
	gd_foot();
	exit;
}

/* ---- added (success screen) ---- */
if($view === 'added'){
	// this screen only exists as the result of a successful POST — ?act=added has no data
	if(empty($vdata['cfg']) || !is_array($vdata['cfg'])) gd_redirect('?act=list');
	$cfg = $vdata['cfg']; $site = $cfg['site'];
	$app = !empty($vdata['app']);
	$pub = $app ? '' : gd_pubkey($cfg['deploy_key']);

	gd_head('Site connected');
	gd_card('<i class="fas fa-check-circle panel-icon"></i>'.h($site).' is connected');

	if($app){
		gd_note('<strong>You are all set.</strong> Press <em>Deploy now</em> below to publish for the first time. '
			.'After that, every push to <span class="mono">'.h($cfg['branch']).'</span> updates '
			.h($site).' on its own, usually within a minute.', 'ok');
		gd_h3('How this site gets your code');
		echo '<p class="soft-card-desc"><i class="fab fa-github me-1"></i>Through your connected GitHub account: pushes to '
			.'<span class="mono">'.h($cfg['branch']).'</span> of '
			.'<span class="mono">'.h(!empty($vdata['gh_full']) ? $vdata['gh_full'] : gd_gh_full_name($cfg['repo'])).'</span> '
			.'start a deploy, and the code is fetched with a short-lived access token that is created fresh each time. '
			.'There is no key and no secret for you to manage.</p>';
	}else{
		gd_note('<strong>Two short steps on GitHub</strong> and this site is live. Do them below, then press '
			.'<em>Deploy now</em> — after that, every push to <span class="mono">'.h($cfg['branch']).'</span> '
			.'updates '.h($site).' automatically.', 'ok');

		if($pub !== ''){
			gd_h3('Step 1 — let GitDeploy read the repository');
			echo '<ol class="gd-steps"><li>In your GitHub repository, open <strong>Settings</strong> → <strong>Deploy keys</strong> → <strong>Add deploy key</strong>.</li>'
				.'<li>Title: <span class="mono">gitdeploy-'.h($site).'</span>. Paste the key below and leave '
				.'<em>Allow write access</em> unticked — read access is all we need.</li></ol>';
			gd_copy_block($pub, 'Copy key');
		}else{
			gd_h3('Step 1 — repository access');
			echo '<p class="soft-card-desc"><i class="fas fa-check me-1"></i>Nothing to do: this is a public repository, '
				.'so no key is needed.</p>';
		}

		gd_h3('Step 2 — tell GitHub to notify us on every push');
		echo '<ol class="gd-steps"><li>In the same repository, open <strong>Settings</strong> → <strong>Webhooks</strong> → <strong>Add webhook</strong>.</li>'
			.'<li>Paste this into <strong>Payload URL</strong>:</li></ol>';
		gd_copy_block(gd_hook_url($site, $site), 'Copy');
		gd_note('If '.h($site).' does not point at this server yet, use any working domain of yours on this server instead — '
			.'<span class="mono">'.h(gd_hook_url($host, $site)).'</span> reaches exactly the same place.');
		echo '<ol class="gd-steps" start="3"><li>Set <strong>Content type</strong> to <span class="mono">application/json</span>.</li>'
			.'<li>Paste this into <strong>Secret</strong> — it is what proves the push really came from GitHub:</li></ol>';
		gd_copy_block($vdata['secret'], 'Copy');
		echo '<ol class="gd-steps" start="5"><li>Leave <strong>Just the push event</strong> selected, keep <strong>Active</strong> ticked, and save.</li></ol>';
	}

	gd_h3('What you set up');
	echo '<div class="table-responsive"><table class="table webuzo-table mb-0">'
		.'<tr><th>Repository</th><td class="mono">'.h(gd_short_repo($cfg['repo'])).'</td></tr>'
		.'<tr><th>Branch</th><td class="mono">'.h($cfg['branch']).'</td></tr>'
		.(gd_site_mode($cfg) === 'pull'
			? '<tr><th>Deploy type</th><td>Files only &mdash; no build, the repository is copied as it is</td></tr>'
			.'<tr><th>Published from</th><td class="mono">'
			.h(isset($cfg['root_dir']) && trim((string)$cfg['root_dir']) !== '' ? $cfg['root_dir'].'/' : 'the whole repository')
			.' &rarr; '.h($site).'</td></tr>'
			// empty = detected at build time, so say that rather than showing a blank row
			: '<tr><th>Build command</th><td class="mono">'
			.h(isset($cfg['build_cmd']) && trim((string)$cfg['build_cmd']) !== '' ? $cfg['build_cmd'] : 'auto').'</td></tr>'
			.'<tr><th>Published from</th><td class="mono">'
			.h(isset($cfg['output_dir']) && trim((string)$cfg['output_dir']) !== '' ? $cfg['output_dir'].'/' : 'the folder we detect')
			.' &rarr; '.h($site).'</td></tr>')
		.'<tr><th>Email</th><td class="mono">'
		.h($cfg['notify_email'] !== '' ? $cfg['notify_email'].' ('.$cfg['notify_on'].')' : 'off').'</td></tr>'
		.'</table></div>';

	echo '<div class="gd-cta">';
	gd_deploy_form($site);
	echo ' <a class="flat-butt-primary" href="?act=list"><i class="fas fa-list-ul me-1"></i>My sites</a>'
		.'<div class="hint mt-2">The first build takes a little longer because everything is downloaded from scratch. '
		.'You can watch it run on the deploy log page.</div></div>';
	gd_card_end();
	gd_foot();
	exit;
}

/* ---- log ---- */
if($view === 'log'){
	$site = gd_get('site');
	$cfg  = gd_require_site($site, $user);
	$logs = gd_site_logs($site);
	$want = gd_get('file');
	$path = isset($logs[0]) ? $logs[0] : '';
	if($want !== ''){
		if(!preg_match('~^deploy-[0-9-]+\.log$~', $want) || !in_array(GD_LOGS.'/'.$site.'/'.$want, $logs, true)){
			gd_stop('Unknown log', 'That log file does not belong to this site.', 404);
		}
		$path = GD_LOGS.'/'.$site.'/'.$want;
	}
	$st       = gd_status($site);
	$queued   = gd_queued($site);
	$stalled  = gd_stalled($st);
	$building = (isset($st['state']) && $st['state'] === 'building' && !$stalled);
	$busy     = ($building || $queued);   // a stalled build stops the refresh loop

	// JS tails the log while a build runs; meta refresh is only the noscript fallback
	gd_head('Deploy log — '.$site, $busy ? 5 : 0, '', $busy);
	echo '<nav class="gd-crumbs"><a href="?act=list">My sites</a> / '.h($site).' / deploys</nav>';

	$key   = gd_state_key($st, $queued);
	$secs  = gd_build_secs($st);
	$trig  = isset($st['trigger']) ? (string)$st['trigger'] : '';
	// the runner's own trigger words, spelled out for people
	$trigl = array('manual' => 'started by you', 'webhook' => 'started by a push to GitHub',
		'rollback' => 'restored an earlier version', 'retry' => 'an automatic retry');

	gd_card('<i class="fas fa-rocket panel-icon"></i>'.h($site));
	echo '<div class="gd-status">'
		.'<div class="gd-status-pill">'.gd_badge($st, $queued, true).'</div>'
		.'<div class="gd-status-facts">';
	$facts = array();
	if($building){
		$facts[] = 'Started '.gd_rel(isset($st['started']) ? $st['started'] : 0);
	}elseif($queued && !$building){
		$facts[] = 'Waiting for a free build slot — this normally takes a few seconds';
	}elseif(!empty($st['finished'])){
		$facts[] = (($key === 'failed') ? 'Failed ' : 'Finished ').gd_rel($st['finished']);
	}elseif(!empty($st['started'])){
		$facts[] = 'Started '.gd_rel($st['started']);
	}
	if($secs > 0 && !$building) $facts[] = 'took '.h(gd_dur($secs));
	if(gd_short_sha($st) !== '') $facts[] = 'commit <span class="mono">'.h(gd_short_sha($st)).'</span>';
	if(isset($trigl[$trig])) $facts[] = h($trigl[$trig]);
	echo implode(' <span class="gd-dot">&middot;</span> ', $facts);
	$rdir = isset($cfg['root_dir']) ? trim((string)$cfg['root_dir']) : '';
	echo '</div><div class="gd-status-side"><span class="mono">'
		.h(gd_short_repo($cfg['repo'])).'</span> <span class="gd-dot">&middot;</span> <span class="mono">'
		.'<i class="fas fa-code-branch me-1" aria-hidden="true"></i>'.h($cfg['branch']).'</span> '
		.($rdir !== '' ? '<span class="gd-dot">&middot;</span> <span class="mono">folder: '.h($rdir).'</span> ' : '')
		.'<span class="gd-dot">&middot;</span> <span class="mono">'.h(gd_mode_label($cfg)).'</span> '
		.'<a href="https://'.h($site).'" target="_blank" rel="noopener">Open the site<i class="fas fa-external-link-alt gd-ext" aria-hidden="true"></i></a>'
		.'</div></div>';

	if(!empty($st['error'])) gd_note('<i class="fas fa-times-circle me-1"></i>'.h($st['error']), 'err');
	if($stalled) gd_note('<i class="fas fa-exclamation-triangle me-1"></i>This build never told us how it ended. '
		.'It started more than 30 minutes ago and nothing has updated it since, so it will not finish on its own. '
		.'The output below is as far as it got — press <strong>Deploy</strong> to try again.', 'warn');
	if($busy) gd_note('<i class="fas fa-sync-alt me-1"></i>'
		.($building ? 'Building right now — the output below updates as it runs.'
			: 'Queued — the build starts in a few seconds and the output appears here.'));
	if(!$logs && !$busy && empty($st['error'])){
		echo '<div class="hint">This site has not been deployed yet. Press <strong>Deploy</strong> below to publish it '
			.'for the first time.</div>';
	}
	echo '<div class="gd-cta">';
	gd_deploy_form($site);
	echo ' <a class="flat-butt-primary" href="?act=edit&amp;site='.h(rawurlencode($site)).'"><i class="fas fa-cog me-1"></i>Settings</a> '
		.'<a class="flat-butt-primary" href="?act=list"><i class="fas fa-list-ul me-1"></i>My sites</a></div>';
	gd_card_end();

	gd_releases_card($cfg, $st, $busy);

	gd_card('<i class="fas fa-terminal panel-icon"></i>Build output',
		'Exactly what the build printed — the place to look when something did not work.');
	if($logs){
		gd_h3('Recent builds');
		echo '<ul class="gd-logs">';
		foreach(array_slice($logs, 0, 10) as $i => $l){
			$b   = basename($l);
			$cur = ($l === $path);
			echo '<li'.($cur ? ' class="cur"' : '').'><a href="?act=log&amp;site='.h(rawurlencode($site)).'&amp;file='.h(rawurlencode($b)).'">'
				.gd_rel(@filemtime($l)).'</a>'
				.($i === 0 ? ' <span class="gd-tag">latest</span>' : '')
				.($cur ? ' <span class="gd-tag">you are here</span>' : '')
				.' <span class="hint">'.h($b).'</span></li>';
		}
		echo '</ul>';
	}

	gd_h3('Output');
	echo '<div class="gd-logbar">'
		.'<span>'.($path !== '' ? 'Showing the build from '.gd_rel(@filemtime($path)) : 'No build output yet').'</span>';
	if($logs && $path !== '' && $path !== $logs[0]){
		echo '<a href="?act=log&amp;site='.h(rawurlencode($site)).'"><i class="fas fa-angle-double-right me-1"></i>Jump to the latest build</a>';
	}
	if($busy) echo '<span class="gd-logbar-live"><i class="fas fa-circle-notch fa-spin me-1" aria-hidden="true"></i>'
		.'Updating live &middot; scrolls with the build</span>';
	echo '</div>';
	if($path === '' || !is_file($path)){
		if($busy){
			echo '<pre id="gdlog" data-site="'.h($site).'" data-file="" data-off="0" data-mode="'
				.($building ? 'building' : 'queued').'">(waiting for the build to start…)</pre>';
		}else{
			echo '<div class="gd-empty"><i class="fas fa-terminal fa-2x d-block mb-2" aria-hidden="true"></i>'
				.'<div class="gd-empty-t">Nothing to show yet</div>'
				.'<p>Once this site has been deployed, everything the build prints appears here, '
				.'line by line, while it runs.</p></div>';
		}
	}else{
		clearstatcache(true, $path);
		$size = (int)filesize($path);
		$max  = 262144;
		$off  = $size > $max ? $size - $max : 0;
		$txt  = (string)file_get_contents($path, false, null, $off, $max);
		if($off > 0) $txt = "…truncated, showing the last 256 KB…\n".$txt;
		echo '<pre id="gdlog" data-site="'.h($site).'" data-file="'.h(basename($path)).'" data-off="'.$size.'" data-mode="'
			.($building ? 'building' : ($queued ? 'queued' : 'static')).'">'
			.h($txt !== '' ? $txt : '(empty)').'</pre>';
	}

	if(!$busy){
		echo '<div class="gd-cta gd-actions">';
		gd_deploy_form($site, true);
		echo ' <a class="flat-butt-primary btn-sm" href="?act=edit&amp;site='.h(rawurlencode($site)).'"><i class="fas fa-cog me-1"></i>Settings</a></div>';
	}
	gd_card_end();

	if($busy){
		echo '<div id="gdstop" class="hint" style="display:none"></div>';
		/*
		 * Poll ?act=logtail, append (or REPLACE when the server says the log was
		 * rotated), and reload once the build reaches a final state. The loop is
		 * bounded on purpose: an expired panel session answers with the login HTML,
		 * json() throws, and the old code then retried for ever — as did a status
		 * left in "building" by a runner that died.
		 */
		echo '<script>(function(){
var p=document.getElementById("gdlog"); if(!p||!window.fetch) return;
var note=document.getElementById("gdstop");
var site=p.getAttribute("data-site"), file=p.getAttribute("data-file"), mode=p.getAttribute("data-mode");
var off=parseInt(p.getAttribute("data-off"),10)||0, last=false, tries=0, errs=0;
var MAXTRIES=450, MAXERR=5;   /* ~15 min of 2s polls */
function bottom(){ p.scrollTop=p.scrollHeight; }
function stop(msg){ if(!note) return;
	note.textContent=(msg||"Stopped updating")+" — reload the page to continue watching.";
	note.style.display=""; }
function step(){
	if(++tries>MAXTRIES){ stop("Stopped updating after 15 minutes"); return; }
	fetch("?act=logtail&site="+encodeURIComponent(site)+"&file="+encodeURIComponent(file)+"&off="+off,
		{credentials:"same-origin",cache:"no-store"}).then(function(r){return r.json();}).then(function(d){
		errs=0;
		if(typeof d.off==="number") off=d.off;
		if(d.reset){ p.textContent=d.data||""; bottom(); }
		else if(d.data){ p.textContent+=d.data; bottom(); }
		if(d.state==="stalled"){ stop("This build stopped reporting"); return; }
		var done=(d.state==="success"||d.state==="failed");
		if(mode==="queued"){ if(done||d.state==="building"){ location.reload(); return; } }
		else if(done){ if(last){ location.reload(); return; }
			last=true; setTimeout(step,400); return; }   /* one final poll, then reload */
		setTimeout(step,2000);
	}).catch(function(){
		if(++errs>=MAXERR){ stop("Lost contact with the panel (your session may have expired)"); return; }
		setTimeout(step,5000);
	});
}
bottom(); setTimeout(step,2000);
})();</script>';
	}
	gd_foot();
	exit;
}

/* ---- edit ---- */
if($view === 'edit'){
	$site = $is_post ? gd_post('site') : gd_get('site');
	$cfg  = isset($vdata['cfg']) && $vdata['cfg'] ? $vdata['cfg'] : gd_require_site($site, $user);
	$site = $cfg['site'];
	$non  = isset($cfg['notify_on']) ? (string)$cfg['notify_on'] : 'fail';
	$f = array_merge(array(
		'repo'         => $cfg['repo'],
		'branch'       => $cfg['branch'],
		'build_cmd'    => isset($cfg['build_cmd']) ? (string)$cfg['build_cmd'] : '',
		'output_dir'   => isset($cfg['output_dir']) ? (string)$cfg['output_dir'] : '',
		'root_dir'     => isset($cfg['root_dir']) ? (string)$cfg['root_dir'] : '',
		'node_version' => isset($cfg['node_version']) ? (string)$cfg['node_version'] : '',
		'post_cmd'     => isset($cfg['post_cmd']) ? (string)$cfg['post_cmd'] : '',
		'env'          => gd_env_prefill($cfg),
		'notify_email' => isset($cfg['notify_email']) ? (string)$cfg['notify_email'] : '',
		'notify_on'    => gd_valid_notify_on($non) ? $non : 'fail',
		'ack'          => !empty($cfg['allow_shared_docroot']),
		'deploy_mode'  => gd_site_mode($cfg),
		'was_pull'     => (gd_site_mode($cfg) === 'pull'),
	), $form);

	$warn = gd_risk_warning($user, $site);
	$hook = gd_load_json(GD_HOOKINFO.'/'.$site.'.json');
	$pub  = gd_pubkey(isset($cfg['deploy_key']) ? $cfg['deploy_key'] : '');

	gd_head('Settings — '.$site);
	echo '<nav class="gd-crumbs"><a href="?act=list">My sites</a> / '.h($site).' / settings</nav>';
	$st_e = gd_status($site);
	if($flash) gd_note('<i class="fas fa-check-circle me-1"></i>'.h($flash), 'ok');
	foreach($errors as $er) gd_note(h($er), 'err');

	echo '<form method="post" action="?act=edit">';
	gd_card('<i class="fas fa-cog panel-icon"></i>'.h($site).' <span class="ms-2">'.gd_badge($st_e, gd_queued($site), false).'</span>',
		'Changes take effect on the next deploy.');
	gd_tok_field();
	echo '<input type="hidden" name="site" value="'.h($site).'">';

	// App-connected sites: the repository is fixed (no repo input is posted at all);
	// the branch stays editable and is kept in sync with the hook info on save.
	$app = gd_gh_is_app_site($cfg);
	$repo_block = null;
	if($app){
		$full = gd_gh_full_name($cfg['repo']);
		$repo_block = '<label class="form-label">Repository</label>'
			.'<div class="gd-ro mono"><i class="fab fa-github me-1"></i>'.h($full !== '' ? $full : $cfg['repo']).'</div>'
			.'<div class="hint">Connected through your GitHub account'
			.($full !== '' ? ' &middot; <a href="https://github.com/'.h($full).'" target="_blank" rel="noopener">open on GitHub</a>' : '')
			.'. To publish a different repository, connect it to another domain as a new site. '
			.'<span class="gd-faint">(connection #'.h((string)$cfg['gh_installation_id']).')</span></div>';
	}
	gd_build_fields($f, 'Change this to a <span class="mono">git@github.com:</span> address and we create a read-only key for you when you save.',
		$repo_block, null, true);

	if($warn === null){
		gd_note('The website folder for this domain can no longer be found, so deploys will fail. '
			.'Check the domain in Webuzo, or remove this site.', 'err');
	}elseif($warn !== ''){
		gd_note('This domain shares its website folder — '.h($warn).'. Deploys overwrite it.', 'warn');
		echo '<div class="form-check"><input class="form-check-input" type="checkbox" name="ack_shared" id="ack_edit" value="yes"'
			.(!empty($f['ack']) ? ' checked' : '').'>'
			.'<label class="form-check-label" for="ack_edit">I understand that deploys will overwrite this folder, which also serves: '
			.'<span class="mono">'.h($warn).'</span></label></div>';
	}
	echo '<div class="gd-cta"><button class="flat-butt" type="submit"><i class="fas fa-save me-1"></i>Save settings</button> '
		.'<a class="flat-butt-primary" href="?act=log&amp;site='.h(rawurlencode($site)).'"><i class="fas fa-file-alt me-1"></i>Deploys &amp; logs</a> '
		.'<a class="flat-butt-primary" href="?act=list">Cancel</a> '
		.'<a class="flat-butt-danger" href="?act=delete&amp;site='.h(rawurlencode($site)).'"><i class="fas fa-trash me-1"></i>Remove site</a></div>';
	gd_card_end();
	echo '</form>';

	if($app){
		gd_card('<i class="fab fa-github panel-icon"></i>GitHub setup',
			'Nothing to configure for this site.');
		echo '<p class="soft-card-desc"><i class="fas fa-check-circle me-1"></i>Because this site came from your connected '
			.'GitHub account, access and push notifications are handled for you — there is no secret and no key to look '
			.'after here. Choose which repositories GitDeploy can see under '
			.'<a href="?act=ghapp">GitHub connection</a>.</p>';
		gd_card_end();
		gd_foot();
		exit;
	}

	gd_card('<i class="fas fa-plug panel-icon"></i>GitHub setup',
		'The two values you paste into your repository under Settings &rarr; Webhooks.');
	gd_h3('Payload URL');
	gd_copy_block(gd_hook_url($site, $site), 'Copy');
	echo '<p class="hint">Content type <span class="mono">application/json</span>, and just the push event. '
		.'If '.h($site).' does not point at this server yet, use <span class="mono">'.h(gd_hook_url($host, $site)).'</span> instead.</p>';
	gd_h3('Secret');
	gd_copy_block((is_array($hook) && !empty($hook['secret'])) ? $hook['secret'] : '(missing — save the settings above to create a new one)', 'Copy');
	echo '<p class="hint">This is how we know a push really came from GitHub. Keep it to yourself.</p>';
	if($pub !== ''){
		gd_h3('Read-only key');
		echo '<p class="hint">Add this under Settings &rarr; Deploy keys in your repository, without write access. '
			.'It lets us read your code and nothing else.</p>';
		gd_copy_block($pub, 'Copy');
	}
	gd_card_end();
	gd_foot();
	exit;
}

/* ---- delete ---- */
if($view === 'delete'){
	$site = $is_post ? gd_post('site') : gd_get('site');
	$cfg  = isset($vdata['cfg']) && $vdata['cfg'] ? $vdata['cfg'] : gd_require_site($site, $user);
	$site = $cfg['site'];
	$pw   = posix_getpwnam($cfg['user']);
	$home = (!empty($pw)) ? rtrim($pw['dir'], '/') : '/home/'.$cfg['user'];

	gd_head('Delete — '.$site);
	echo '<nav class="gd-crumbs"><a href="?act=list">My sites</a> / '.h($site).' / delete</nav>';
	foreach($errors as $er) gd_note(h($er), 'err');

	gd_card('<i class="fas fa-trash panel-icon"></i>Stop deploying '.h($site),
		'This disconnects the repository. It does not take your website offline.');
	gd_note('<strong>Your website stays exactly as it is.</strong> The files already published to '.h($site)
		.' are not touched — they just stop being updated. What is removed is this site\'s GitDeploy setup: '
		.'its settings, its secret, its status and its deploy logs.', 'warn');
	echo '<form method="post" action="?act=delete">';
	gd_tok_field();
	echo '<input type="hidden" name="site" value="'.h($site).'">'
		.'<div class="gd-h3">Optional clean-up</div>'
		.'<div class="form-check"><input class="form-check-input" type="checkbox" name="drop_work" id="drop_work" value="yes">'
		.'<label class="form-check-label" for="drop_work">Also delete the build folder — the copy of your code and its '
		.'downloaded packages. It frees disk space; reconnecting later just downloads them again. '
		.'<span class="gd-faint mono">'.h($home.'/.gitdeploy/'.$site).'</span></label></div>';
	if(!empty($cfg['deploy_key'])){
		echo '<div class="form-check"><input class="form-check-input" type="checkbox" name="drop_key" id="drop_key" value="yes">'
			.'<label class="form-check-label" for="drop_key">Also delete the read-only key for this site. '
			.'Remember to remove it in GitHub as well, under Settings &rarr; Deploy keys. '
			.'<span class="gd-faint mono">'.h($cfg['deploy_key']).'</span></label></div>';
	}
	echo '<div class="gd-confirm"><div class="form-check"><input class="form-check-input" type="checkbox" name="confirm" id="confirm" value="yes" required>'
		.'<label class="form-check-label" for="confirm"><strong>Yes, stop deploying '.h($site).'.</strong></label></div></div>'
		.'<div class="gd-cta"><button class="flat-butt-danger" type="submit"><i class="fas fa-trash me-1"></i>Remove site</button> '
		.'<a class="flat-butt-primary" href="?act=list">Keep it</a></div></form>';
	gd_card_end();
	gd_foot();
	exit;
}

gd_stop('Unknown page', 'That action does not exist.', 404);
