<?php
/**
 * GitDeploy — GitHub App integration helpers.
 * Pure PHP 7.4 + curl + openssl; no external libraries.
 *
 * Credentials (root-only, 0600): GD_STATE/ghapp.json
 *   {app_id, slug, name, owner, html_url, pem, webhook_secret, client_secret, created}
 * Webhook secret copy for hook.php (root:webuzo 0640): GD_HOOKINFO/.ghapp.json  {secret}
 * Manifest-flow state (root 0600, 10 min TTL): GD_STATE/.ghapp_state
 */

require_once __DIR__.'/common.php';

define('GD_GH_API', 'https://api.github.com');

/*
 * App credentials are PER HOSTING USER: on a multi-tenant server one tenant must
 * never see, use, or clobber another tenant's GitHub App (their private repos are
 * reachable through its installation tokens). Every accessor takes $user.
 */
function ghapp_file($user){
	return gd_valid_user($user) ? GD_STATE.'/ghapp-'.$user.'.json' : null;
}

function ghapp_hookfile($user){
	return gd_valid_user($user) ? GD_HOOKINFO.'/.ghapp-'.$user.'.json' : null;
}

function ghapp_statefile($user){
	return gd_valid_user($user) ? GD_STATE.'/.ghapp_state-'.$user : null;
}

function ghapp_load($user){
	$f = ghapp_file($user);
	if($f === null) return null;
	$c = gd_load_json($f);
	if(!is_array($c) || empty($c['app_id']) || empty($c['pem'])) return null;
	if(!empty($c['owner_user']) && $c['owner_user'] !== $user) return null; // belt and braces
	return $c;
}

function ghapp_save($user, $creds){
	$f = ghapp_file($user); $hf = ghapp_hookfile($user);
	if($f === null || $hf === null) return false;
	$creds['owner_user'] = $user;
	// Hook copy FIRST: if the second write fails we unlink it again, so the two
	// files are never out of step (a "connected" app whose pushes all 403).
	if(!gd_save_json($hf, ['secret' => (string)$creds['webhook_secret'], 'user' => $user], 0640, 'webuzo')) return false;
	if(!gd_save_json($f, $creds, 0600)){ @unlink($hf); return false; }
	return true;
}

function ghapp_delete($user){
	$f = ghapp_file($user); $hf = ghapp_hookfile($user);
	if($f !== null) @unlink($f);
	if($hf !== null) @unlink($hf);
}

/* ------------------------------------------------- manifest-flow state token */

function ghapp_state_new($user, $replace = false){
	$f = ghapp_statefile($user);
	if($f === null) return null;
	$state = bin2hex(random_bytes(20));
	// the user is carried IN the token: the callback has no session to ask
	gd_save_json($f, ['state' => $state, 'user' => $user, 'replace' => (bool)$replace, 'ts' => time(), 'tries' => 0], 0600);
	return $state;
}

/**
 * Validate a callback state token. Returns the state record (with 'user') or null.
 * The token is consumed ONLY on success — an unauthenticated attacker hitting the
 * public callback URL must not be able to delete a legitimate pending token and
 * strand a half-created GitHub App. A tries counter still expires brute-force noise.
 */
function ghapp_state_consume($state){
	if(!is_string($state) || !preg_match('~^[0-9a-f]{40}$~', $state)) return null;
	foreach(glob(GD_STATE.'/.ghapp_state-*') ?: [] as $f){
		$s = gd_load_json($f);
		if(!is_array($s) || empty($s['state']) || empty($s['user'])) continue;
		if((time() - (int)$s['ts']) >= 600){ @unlink($f); continue; }
		if(hash_equals($s['state'], $state)){
			@unlink($f); // consumed on success only
			return $s;
		}
		$s['tries'] = (int)($s['tries'] ?? 0) + 1;
		if($s['tries'] > 50) @unlink($f); else gd_save_json($f, $s, 0600);
	}
	return null;
}

/* ------------------------------------------------------------------ JWT auth */

function ghapp_b64url($bin){
	return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

// RS256 app JWT, valid ~9 minutes (GitHub max 10), 60s clock-skew backdate
function ghapp_jwt($creds, &$err = null){
	$err = '';
	$key = openssl_pkey_get_private((string)$creds['pem']);
	if(!$key){ $err = 'invalid app private key'; return null; }
	$now = time();
	$head = ghapp_b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
	$body = ghapp_b64url(json_encode(['iat' => $now - 60, 'exp' => $now + 540, 'iss' => (string)$creds['app_id']]));
	$sig = '';
	if(!openssl_sign($head.'.'.$body, $sig, $key, OPENSSL_ALGO_SHA256)){ $err = 'jwt signing failed'; return null; }
	return $head.'.'.$body.'.'.ghapp_b64url($sig);
}

/* ------------------------------------------------------------------ REST API */

/*
 * Wall-clock budget for one HTTP request cycle. The UI runs on the panel's ROOT
 * FPM pool (10 workers, request_terminate_timeout = 0s) which also serves the
 * Webuzo panel itself — so a slow/blackholed GitHub must never be able to pin
 * workers. Short timeouts + a per-request budget keep page loads bounded.
 */
function ghapp_budget_start($seconds = 12){
	$GLOBALS['gd_gh_deadline'] = microtime(true) + $seconds;
}

function ghapp_budget_left(){
	if(!isset($GLOBALS['gd_gh_deadline'])) return 8.0;
	return $GLOBALS['gd_gh_deadline'] - microtime(true);
}

function ghapp_budget_spent(){ return ghapp_budget_left() <= 0.5; }

function ghapp_api($method, $path, $auth = null, $body = null, &$err = null){
	$err = '';
	if(ghapp_budget_spent()){ $err = 'GitHub is slow right now — listing stopped early'; return null; }
	$timeout = (int)max(3, min(8, ghapp_budget_left()));
	$ch = curl_init((strpos($path, 'https://') === 0 ? $path : GD_GH_API.$path));
	$headers = [
		'Accept: application/vnd.github+json',
		'X-GitHub-Api-Version: 2022-11-28',
		'User-Agent: GitDeploy/1.3 (Webuzo plugin)',
	];
	if($auth !== null) $headers[] = 'Authorization: Bearer '.$auth;
	curl_setopt_array($ch, [
		CURLOPT_CUSTOMREQUEST => $method,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HTTPHEADER => $headers,
		CURLOPT_TIMEOUT => $timeout,
		CURLOPT_CONNECTTIMEOUT => 4,
		CURLOPT_SSL_VERIFYPEER => true,
	]);
	if($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
	$resp = curl_exec($ch);
	$code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	$cerr = curl_error($ch);
	curl_close($ch);
	if($resp === false){ $err = 'network error: '.$cerr; return null; }
	$data = json_decode($resp, true);
	if($code < 200 || $code >= 300){
		$msg = is_array($data) && isset($data['message']) ? $data['message'] : ('HTTP '.$code);
		$err = 'GitHub API '.$code.': '.$msg;
		return null;
	}
	return is_array($data) ? $data : [];
}

// Exchange the manifest-flow temporary code for the app credentials
function ghapp_manifest_convert($code, &$err = null){
	if(!preg_match('~^[A-Za-z0-9_.-]{8,120}$~', (string)$code)){ $err = 'bad code'; return null; }
	$r = ghapp_api('POST', '/app-manifests/'.rawurlencode($code).'/conversions', null, null, $err);
	if(!$r || empty($r['id']) || empty($r['pem']) || empty($r['webhook_secret'])){
		if(!$err) $err = 'incomplete response from GitHub';
		return null;
	}
	return [
		'app_id'         => (string)$r['id'],
		'slug'           => isset($r['slug']) ? (string)$r['slug'] : '',
		'name'           => isset($r['name']) ? (string)$r['name'] : '',
		'owner'          => isset($r['owner']['login']) ? (string)$r['owner']['login'] : '',
		'html_url'       => isset($r['html_url']) ? (string)$r['html_url'] : '',
		'pem'            => (string)$r['pem'],
		'webhook_secret' => (string)$r['webhook_secret'],
		'client_secret'  => isset($r['client_secret']) ? (string)$r['client_secret'] : '',
		'created'        => time(),
	];
}

/* --------------------------------------------------------- repo list caching */

/**
 * Cached repo listing per user: {ts, groups:[{account, repos:[...], installation_id}], errors:[]}
 * The add-form must not re-walk the whole GitHub API on every render.
 */
function ghapp_cache_file($user){
	return gd_valid_user($user) ? GD_STATE.'/.ghapp_repos-'.$user.'.json' : null;
}

function ghapp_cache_get($user, $max_age = 300){
	$f = ghapp_cache_file($user);
	if($f === null) return null;
	$c = gd_load_json($f);
	if(!is_array($c) || empty($c['ts']) || (time() - (int)$c['ts']) > $max_age) return null;
	return $c;
}

function ghapp_cache_put($user, $data){
	$f = ghapp_cache_file($user);
	if($f === null) return false;
	$data['ts'] = time();
	return gd_save_json($f, $data, 0600);
}

function ghapp_cache_clear($user){
	$f = ghapp_cache_file($user);
	if($f !== null) @unlink($f);
}

function ghapp_installations($creds, &$err = null){
	$jwt = ghapp_jwt($creds, $err);
	if(!$jwt) return null;
	return ghapp_api('GET', '/app/installations?per_page=100', $jwt, null, $err);
}

// Installation access token (1h TTL). Never persist it.
function ghapp_inst_token($creds, $installation_id, &$err = null){
	if(!preg_match('~^\d{1,15}$~', (string)$installation_id)){ $err = 'bad installation id'; return null; }
	$jwt = ghapp_jwt($creds, $err);
	if(!$jwt) return null;
	$r = ghapp_api('POST', '/app/installations/'.$installation_id.'/access_tokens', $jwt, null, $err);
	return ($r && !empty($r['token'])) ? (string)$r['token'] : null;
}

// All repos an installation grants access to (paginated)
function ghapp_repos($inst_token, &$err = null){
	$out = [];
	for($page = 1; $page <= 5; $page++){
		$r = ghapp_api('GET', '/installation/repositories?per_page=100&page='.$page, $inst_token, null, $err);
		if($r === null) return null;
		foreach(($r['repositories'] ?? []) as $repo){
			if(!empty($repo['full_name']) && preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~', $repo['full_name'])){
				$out[] = [
					'full_name'      => (string)$repo['full_name'],
					'private'        => !empty($repo['private']),
					'default_branch' => isset($repo['default_branch']) ? (string)$repo['default_branch'] : 'main',
				];
			}
		}
		if(count($r['repositories'] ?? []) < 100) break;
	}
	return $out;
}

function ghapp_branches($inst_token, $full_name, &$err = null){
	if(!preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~', (string)$full_name)){ $err = 'bad repo name'; return null; }
	$r = ghapp_api('GET', '/repos/'.$full_name.'/branches?per_page=100', $inst_token, null, $err);
	if($r === null) return null;
	$out = [];
	foreach($r as $b){
		if(!empty($b['name']) && gd_valid_branch($b['name'])) $out[] = (string)$b['name'];
	}
	return $out;
}
