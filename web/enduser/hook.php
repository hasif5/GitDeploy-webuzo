<?php
/**
 * GitDeploy — GitHub webhook receiver.
 * Served by EMPS nginx via fpm-webuzo.sock (user webuzo, never root).
 * Auth = HMAC-SHA256 of the raw body with the per-site secret (X-Hub-Signature-256).
 * Never builds inline — writes a spool job the root runner picks up.
 */
error_reporting(0);
header('Content-Type: text/plain');

require '/usr/local/gitdeploy/lib/common.php';

function gd_die($code, $msg){ http_response_code($code); die($msg."\n"); }

if(($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') gd_die(405, 'POST only');

/*
 * GitHub App mode (?app=1): one app-level webhook for all repos.
 * Signature is verified with the app webhook secret; the push is routed to every
 * site whose hookinfo declares that repo (gh_full_name) and branch.
 */
if(($_GET['app'] ?? '') === '1'){
	$app = gd_load_json(GD_HOOKINFO.'/.ghapp.json');
	if(empty($app['secret'])) gd_die(404, 'app not configured');

	// 10MB cap (nginx enforces the same): pushes with many commits carry big payloads,
	// and a truncated read would break the HMAC and reject a legitimate deploy.
	$raw = file_get_contents('php://input', false, null, 0, 10485760);
	if($raw === false || $raw === '') gd_die(400, 'empty body');

	$sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
	if(!hash_equals('sha256='.hash_hmac('sha256', $raw, $app['secret']), $sig)) gd_die(403, 'bad signature');

	$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
	if($event === 'ping') die("pong\n");
	if($event !== 'push') die("ignored event ".$event."\n");

	$p = json_decode($raw, true);
	if(!is_array($p)) gd_die(400, 'bad json');
	$full = $p['repository']['full_name'] ?? '';
	$rid  = (string)($p['repository']['id'] ?? '');
	$ref  = $p['ref'] ?? '';
	if(!preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~', $full) || strpos($ref, 'refs/heads/') !== 0) die("ignored\n");
	if(!empty($p['deleted'])) die("ignored: branch delete\n");
	$branch = substr($ref, 11);

	$queued = 0;
	foreach(glob(GD_HOOKINFO.'/*.json') ?: [] as $f){
		$b = basename($f, '.json');
		if($b === '' || $b[0] === '.') continue;
		$info = gd_load_json($f);
		if(!is_array($info) || empty($info['gh_full_name'])) continue;
		// match by immutable repo id when we have it (survives renames), else by name
		$id_match   = $rid !== '' && !empty($info['gh_repo_id']) && (string)$info['gh_repo_id'] === $rid;
		$name_match = strcasecmp($info['gh_full_name'], $full) === 0;
		if(!$id_match && !$name_match) continue;
		if(($info['branch'] ?? '') !== $branch) continue;
		if(!gd_valid_site($b)) continue;
		if(gd_recently_queued($b)){ $queued++; continue; }   // already queued a moment ago
		if(gd_queue_job($b, 'webhook', ['after' => (string)($p['after'] ?? '')])) $queued++;
	}
	die("queued ".$queued." site(s)\n");
}

$site = $_GET['site'] ?? '';
if(!gd_valid_site($site)) gd_die(404, 'unknown site');

$info = gd_load_json(GD_HOOKINFO.'/'.$site.'.json');
if(empty($info['secret'])) gd_die(404, 'unknown site');

$raw = file_get_contents('php://input', false, null, 0, 1048576);
if($raw === false || $raw === '') gd_die(400, 'empty body');

$sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$expected = 'sha256='.hash_hmac('sha256', $raw, $info['secret']);
if(!hash_equals($expected, $sig)) gd_die(403, 'bad signature');

$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
if($event === 'ping') die("pong\n");
if($event !== 'push') die("ignored: event ".$event."\n");

$payload = json_decode($raw, true);
if(!is_array($payload)) gd_die(400, 'bad json');

$ref = $payload['ref'] ?? '';
if($ref !== 'refs/heads/'.$info['branch']) die("ignored: ref ".$ref."\n");
if(!empty($payload['deleted'])) die("ignored: branch delete\n");

if(gd_recently_queued($site)) die("already queued\n");
if(!gd_queue_job($site, 'webhook', ['after' => (string)($payload['after'] ?? '')])){
	gd_die(500, 'could not queue');
}
die("queued\n");
