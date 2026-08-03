<?php
/*
Plugin Name: GitDeploy
Description: Deploy static sites (Vite, Astro, any dist builder) from GitHub to your domains. Powered by Digitalbkk Co., Ltd.
Version: 1.7.0
Plugin URI: https://www.digitalbkk.com
Author: Hasif Ahmed <hasif5@gmail.com> — developed by the Digitalbkk team
Author URI: https://www.digitalbkk.com
*/

if(!defined('SOFTACULOUS') && !defined('SOFTPANEL_ADMIN')) return;

// Enduser panel icon (Apps category). Icon key 'gitdeploy' must be enabled in the
// user's plan features (install.sh adds it).
if(function_exists('add_filter')){
	add_filter('enduser_cats_icons', 'gitdeploy_enduser_cats_icons', 10, 1);
}

function gitdeploy_enduser_cats_icons($icons){
	global $globals;
	try{
		// PNG icon: absolute panel URL when available, else relative to /sessXXXX/index.php
		$icon = !empty($globals['panel_url'])
			? $globals['panel_url'].'/gitdeploy/assets/gitdeploy_48.png'
			: 'gitdeploy/assets/gitdeploy_48.png';
		// In-panel page (act) when core supports it, else the standalone app URL
		$href = (function_exists('enduser_act') && !empty($globals['index']))
			? $globals['index'].'act=gitdeploy'
			: 'gitdeploy/';
		$icons['apps']['icons']['gitdeploy'] = [
			'name' => function_exists('__') ? __('GitDeploy') : 'GitDeploy',
			'icon' => $icon,
			'href' => $href,
		];
	}catch(\Throwable $t){
		error_log('[gitdeploy] icon filter: '.$t->getMessage());
	}
	return $icons;
}

// Admin panel menu entry (ports 2004/2005 — the server owner's panel). The page
// itself decides who may see what; it is deliberately NOT added to reseller_menu.
if(function_exists('add_filter')){
	add_filter('admin_menu', 'gitdeploy_admin_menu', 10, 1);
}

function gitdeploy_admin_menu($menu){
	global $globals;
	try{
		// Core's own sample uses $globals['ind']; the enduser side of this plugin has
		// always used $globals['index']. Accept either, and fall back to the plain
		// endpoint so a menu entry is never a dead link.
		$ind = '';
		if(!empty($globals['ind']))        $ind = $globals['ind'];
		elseif(!empty($globals['index']))  $ind = $globals['index'];
		$href = ($ind !== '' && function_exists('admin_act')) ? $ind.'act=gitdeploy' : 'gitdeploy/';
		$icon = !empty($globals['panel_url'])
			? $globals['panel_url'].'/gitdeploy/assets/gitdeploy_48.png'
			: 'gitdeploy/assets/gitdeploy_48.png';
		$menu['gitdeploy'] = array(
			'name' => function_exists('__') ? __('GitDeploy') : 'GitDeploy',
			'href' => $href,
			'icon' => $icon,
			'attr' => 'width=16',
		);
	}catch(\Throwable $t){
		// A broken menu filter would take the whole admin panel down with it.
		error_log('[gitdeploy] admin menu filter: '.$t->getMessage());
	}
	return $menu;
}

// Admin in-panel page. Same shape as the enduser act: core renders the chrome, and
// the act (which runs as the non-root panel user) embeds our root-FPM endpoint.
if(function_exists('admin_act') && function_exists('theme_for_act')){
	admin_act('gitdeploy', 'gitdeploy_admin');
}

function gitdeploy_admin(){
	theme_for_act('gitdeploy_admin_theme', 'gitdeploy');
}

function gitdeploy_admin_theme(){
	global $globals, $SESS, $WE, $softpanel, $act;
	// Relative src keeps the /sessXXXX/ prefix, so the path-scoped session cookie
	// is still sent. Same borderless/transparent viewport as the enduser page.
	echo '
	<iframe id="gitdeploy_admin_frame" src="gitdeploy/" scrolling="no" allowtransparency="true"
		style="display:block;width:100%;border:0;outline:0;min-height:72vh;background:transparent;overflow:hidden;"></iframe>
	<script type="text/javascript">
	(function(){
		var f = document.getElementById("gitdeploy_admin_frame");
		function fit(){
			try{
				var d = f.contentWindow.document;
				var h = Math.max(d.documentElement.scrollHeight, d.body ? d.body.scrollHeight : 0);
				if(h > 300){ f.style.height = (h + 24) + "px"; }
			}catch(e){}
		}
		f.onload = fit;
		setInterval(fit, 800);
	})();
	</script>';
}

// In-panel page: rendered by core with the full enduser header/footer.
// The act runs as the non-root panel FPM user, so the actual app (which needs
// root for key generation and root-owned config) is embedded from its own
// root-FPM endpoint via a same-origin iframe — the pattern Softaculous uses.
if(function_exists('enduser_act') && function_exists('theme_for_act')){
	enduser_act('gitdeploy', 'gitdeploy_enduser');
}

function gitdeploy_enduser(){
	theme_for_act('gitdeploy_enduser_theme', 'gitdeploy');
}

function gitdeploy_enduser_theme(){
	global $globals, $SESS, $WE, $softpanel, $act;
	// src is relative to /sessXXXX/index.php -> /sessXXXX/gitdeploy/ (session cookie path matches)
	// No card wrapper: the app renders its own panel-style cards, so an outer box
	// would double-frame it. The iframe is a transparent, borderless viewport.
	echo '
	<iframe id="gitdeploy_frame" src="gitdeploy/" scrolling="no" allowtransparency="true"
		style="display:block;width:100%;border:0;outline:0;min-height:72vh;background:transparent;overflow:hidden;"></iframe>
	<script type="text/javascript">
	(function(){
		var f = document.getElementById("gitdeploy_frame");
		function fit(){
			try{
				var d = f.contentWindow.document;
				var h = Math.max(d.documentElement.scrollHeight, d.body ? d.body.scrollHeight : 0);
				if(h > 300){ f.style.height = (h + 24) + "px"; }
			}catch(e){}
		}
		f.onload = fit;
		setInterval(fit, 800);
	})();
	</script>';
}
