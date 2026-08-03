#!/usr/bin/env node
/*
 * GitDeploy repository inspection. Runs as the SITE USER, never as root, and only
 * ever reads from the checked-out repository — it must not write anything.
 *
 *   node detect.js framework <build_dir>
 *       -> "<name>\t<build_cmd>\t<out1 out2 ...>\t<note>"   (empty line = unknown)
 *
 *   node detect.js nodespec <build_dir> <repo_dir> <system_version> [panel_spec]
 *       -> "system"        the Node already on the server satisfies the repo
 *       -> "22" | "22.11" | "22.11.0"    version (or prefix) that must be installed
 *       -> "system" is also the answer when the repo asks for nothing at all
 *
 * Output is consumed by bin/build.sh. Keep every field free of tabs and newlines.
 */
'use strict';
const fs = require('fs');
const path = require('path');

const cmd = process.argv[2];

/* ---------------------------------------------------------------- helpers */

function readJSON(dir, name){
	try{ return JSON.parse(fs.readFileSync(path.join(dir, name), 'utf8')); }
	catch(e){ return null; }
}
function readText(dir, name){
	try{ return fs.readFileSync(path.join(dir, name), 'utf8').trim(); }
	catch(e){ return ''; }
}
function exists(dir, name){
	try{ fs.accessSync(path.join(dir, name)); return true; }
	catch(e){ return false; }
}
function existsAny(dir, names){ return names.some(n => exists(dir, n)); }

/* ------------------------------------------------------- framework detect */

function detectFramework(dir){
	const pkg = readJSON(dir, 'package.json');
	const deps = pkg ? Object.assign({}, pkg.dependencies, pkg.devDependencies) : {};
	const scripts = (pkg && pkg.scripts && typeof pkg.scripts === 'object') ? pkg.scripts : {};
	const has = d => Object.prototype.hasOwnProperty.call(deps, d);
	const script = s => typeof scripts[s] === 'string' && scripts[s].trim() !== '';
	// "npm run build" only works if a build script exists; otherwise call the tool.
	const run = (name, fallback) => script(name) ? ('npm run ' + name) : fallback;

	// Non-Node generators first: they are identified by their own config file and
	// would otherwise be missed entirely (they have no package.json at all).
	if(!pkg){
		if(existsAny(dir, ['hugo.toml', 'hugo.yaml', 'hugo.json', 'config.toml', 'config.yaml']) && exists(dir, 'content'))
			return ['Hugo', 'hugo --minify', ['public'], ''];
		if(exists(dir, '_config.yml'))
			return exists(dir, 'Gemfile')
				? ['Jekyll', 'bundle exec jekyll build', ['_site'], '']
				: ['Jekyll', 'jekyll build', ['_site'], ''];
		if(exists(dir, 'index.html'))
			return ['Plain HTML', '', ['.'], 'no build step — the repository is published as-is'];
		return null;
	}

	// Hugo/Jekyll can also carry a package.json (for asset pipelines); still theirs.
	if(existsAny(dir, ['hugo.toml', 'hugo.yaml', 'hugo.json']) && exists(dir, 'content'))
		return ['Hugo', run('build', 'hugo --minify'), ['public'], ''];

	// Order matters: the frameworks below all depend on vite/webpack internally,
	// so the specific ones have to be tested before the generic bundlers.
	if(has('@docusaurus/core'))
		return ['Docusaurus', run('build', 'npx docusaurus build'), ['build'], ''];

	if(has('vitepress'))
		return ['VitePress', script('docs:build') ? 'npm run docs:build' : run('build', 'npx vitepress build docs'),
			['docs/.vitepress/dist', '.vitepress/dist'], ''];

	if(has('astro'))
		return ['Astro', run('build', 'npx astro build'), ['dist'], ''];

	if(has('next')){
		// Only a statically exported Next.js site can be served without Node running.
		const cfg = ['next.config.js', 'next.config.mjs', 'next.config.ts', 'next.config.cjs']
			.map(f => readText(dir, f)).join('\n');
		const isExport = /output\s*:\s*['"]export['"]/.test(cfg) || script('export');
		return ['Next.js', run('build', 'npx next build'), ['out'],
			isExport ? '' : 'this project does not set output: "export" — a Next.js site in server mode cannot be hosted here'];
	}

	if(has('nuxt') || has('nuxt3'))
		return ['Nuxt', script('generate') ? 'npm run generate' : 'npx nuxt generate',
			['.output/public', 'dist'], ''];

	if(has('@sveltejs/kit'))
		return ['SvelteKit', run('build', 'npx vite build'), ['build', '.svelte-kit/output/client'],
			'needs @sveltejs/adapter-static to produce a hostable site'];

	if(has('gatsby'))
		return ['Gatsby', run('build', 'npx gatsby build'), ['public'], ''];

	if(has('@11ty/eleventy'))
		return ['Eleventy', run('build', 'npx @11ty/eleventy'), ['_site'], ''];

	if(has('@angular/cli') || exists(dir, 'angular.json'))
		return ['Angular', run('build', 'npx ng build'), ['dist'], ''];

	if(has('@vue/cli-service'))
		return ['Vue CLI', run('build', 'npx vue-cli-service build'), ['dist'], ''];

	if(has('react-scripts'))
		return ['Create React App', run('build', 'npx react-scripts build'), ['build'], ''];

	if(has('parcel'))
		return ['Parcel', run('build', 'npx parcel build'), ['dist'], ''];

	if(has('vite') || existsAny(dir, ['vite.config.js', 'vite.config.ts', 'vite.config.mjs', 'vite.config.cjs']))
		return ['Vite', run('build', 'npx vite build'), ['dist'], ''];

	// Nothing recognised, but there is a build script — trust it and look around
	// afterwards for whichever folder it produced.
	if(script('build'))
		return ['npm build script', 'npm run build', ['dist', 'build', 'out', 'public', '_site'], ''];

	if(exists(dir, 'index.html'))
		return ['Plain HTML', '', ['.'], 'no build step — the repository is published as-is'];

	return null;
}

/* ------------------------------------------------------ node version spec */

const LTS_CODENAMES = {
	argon: 4, boron: 6, carbon: 8, dubnium: 10, erbium: 12, fermium: 14,
	gallium: 16, hydrogen: 18, iron: 20, jod: 22, krypton: 24
};

// "18.20.4" -> [18,20,4]; missing parts become 0.
function parts(v){
	const m = String(v).trim().replace(/^v/i, '').match(/^(\d+)(?:\.(\d+))?(?:\.(\d+))?/);
	if(!m) return null;
	return [Number(m[1]), Number(m[2] || 0), Number(m[3] || 0)];
}
function cmpv(a, b){
	for(let i = 0; i < 3; i++){ if(a[i] !== b[i]) return a[i] < b[i] ? -1 : 1; }
	return 0;
}

/*
 * Turn one comparator ("^20.9.0", ">=18", "20.x") into an inclusive lower bound
 * and an exclusive upper bound. Returns null for anything we do not understand,
 * which makes the whole alternative unusable rather than silently wrong.
 */
function comparatorRange(tok){
	tok = tok.trim();
	if(tok === '' || tok === '*' || tok === 'x') return [[0,0,0], null];
	let m;
	if((m = tok.match(/^(>=|>|<=|<|=|\^|~)?\s*v?(\d+|x|\*)(?:\.(\d+|x|\*))?(?:\.(\d+|x|\*))?/))){
		const op = m[1] || '=';
		const wild = s => s === undefined || s === 'x' || s === '*';
		const maj = wild(m[2]) ? null : Number(m[2]);
		if(maj === null) return [[0,0,0], null];
		const minr = wild(m[3]) ? null : Number(m[3]);
		const pat  = wild(m[4]) ? null : Number(m[4]);
		const lo = [maj, minr || 0, pat || 0];
		switch(op){
			case '>':  return [[lo[0], lo[1], lo[2] + 1], null];
			case '>=': return [lo, null];
			case '<':  return [[0,0,0], lo];
			case '<=': return [[0,0,0], [lo[0], lo[1], lo[2] + 1]];
			case '^':  return [lo, maj > 0 ? [maj + 1, 0, 0] : [0, (minr || 0) + 1, 0]];
			case '~':  return [lo, minr === null ? [maj + 1, 0, 0] : [maj, minr + 1, 0]];
			default:
				// "20" means 20.x, "20.9" means 20.9.x, "20.9.1" is exact
				if(minr === null) return [lo, [maj + 1, 0, 0]];
				if(pat === null)  return [lo, [maj, minr + 1, 0]];
				return [lo, [maj, minr, pat + 1]];
		}
	}
	return null;
}

// Evaluate a full range against the system version. Returns {ok, target} where
// target is the major we would have to install when ok is false.
function evalRange(range, sys){
	const alts = String(range).split('||');
	let best = null;
	for(const alt of alts){
		let lo = [0,0,0], hi = null, usable = true;
		for(const tok of alt.trim().split(/[\s,]+/)){
			if(tok === '') continue;
			const r = comparatorRange(tok);
			if(!r){ usable = false; break; }
			if(cmpv(r[0], lo) > 0) lo = r[0];
			if(r[1] && (hi === null || cmpv(r[1], hi) < 0)) hi = r[1];
		}
		if(!usable) continue;
		if(cmpv(sys, lo) >= 0 && (hi === null || cmpv(sys, hi) < 0)) return {ok: true};
		// Not satisfied by the server's Node: remember the highest floor, which is
		// the version most likely to be what the author actually targets.
		if(best === null || cmpv(lo, best.lo) > 0) best = {lo: lo, hi: hi};
	}
	if(best === null) return {ok: true};          // unparseable range: do not guess
	let major = best.lo[0];
	if(major < 4){
		// An upper bound with no meaningful floor ("<21"): install the newest major
		// that range still allows.
		if(best.hi) major = best.hi[1] === 0 && best.hi[2] === 0 ? best.hi[0] - 1 : best.hi[0];
		else return {ok: true};
	}
	if(major < 4 || major > 99) return {ok: true};
	return {ok: false, target: String(major)};
}

function enginesNode(dir){
	const pkg = readJSON(dir, 'package.json');
	if(pkg && pkg.engines && typeof pkg.engines.node === 'string') return pkg.engines.node.trim();
	return '';
}

function nodespec(buildDir, repoDir, sysver, panel){
	const sys = parts(sysver);
	if(!sys) return 'system';

	const dirs = buildDir === repoDir ? [buildDir] : [buildDir, repoDir];
	let spec = '', kind = 'pin';

	if(panel && panel.trim() !== ''){
		spec = panel.trim();
	}else{
		for(const d of dirs){
			const a = readText(d, '.nvmrc');
			if(a){ spec = a.split(/\r?\n/)[0].trim(); break; }
			const b = readText(d, '.node-version');
			if(b){ spec = b.split(/\r?\n/)[0].trim(); break; }
		}
		if(!spec){
			for(const d of dirs){
				const e = enginesNode(d);
				if(e){ spec = e; kind = 'range'; break; }
			}
		}
	}
	if(!spec) return 'system';

	if(kind === 'range') {
		const r = evalRange(spec, sys);
		return r.ok ? 'system' : r.target;
	}

	// Pin semantics (.nvmrc / .node-version / the panel field)
	const low = spec.toLowerCase().replace(/^v/, '');
	if(low === 'node' || low === 'latest' || low === 'current' || low === 'stable' || low === '*')
		return 'system';
	if(low === 'lts' || low === 'lts/*') return 'system';
	const lts = low.match(/^lts\/([a-z]+)$/);
	if(lts){
		const maj = LTS_CODENAMES[lts[1]];
		if(!maj) return 'system';
		return maj === sys[0] ? 'system' : String(maj);
	}
	const m = low.match(/^(\d{1,2})(?:\.(\d{1,3}))?(?:\.(\d{1,3}))?$/);
	if(!m) return 'system';                       // unrecognised: never guess a URL
	if(m[3] !== undefined) return sysver === m[1] + '.' + m[2] + '.' + m[3] ? 'system' : m[1] + '.' + m[2] + '.' + m[3];
	if(m[2] !== undefined) return (sys[0] === Number(m[1]) && sys[1] === Number(m[2])) ? 'system' : m[1] + '.' + m[2];
	return sys[0] === Number(m[1]) ? 'system' : m[1];
}

/* --------------------------------------------------------- entry point */

if(cmd === 'framework'){
	const r = detectFramework(process.argv[3] || '.');
	if(r) process.stdout.write(r[0] + '\t' + r[1] + '\t' + r[2].join(' ') + '\t' + r[3] + '\n');
}else if(cmd === 'nodespec'){
	process.stdout.write(nodespec(process.argv[3] || '.', process.argv[4] || '.',
		process.argv[5] || '', process.argv[6] || '') + '\n');
}else if(cmd === 'pickversion'){
	// pickversion <prefix> — reads nodejs.org's index.json on stdin, prints the
	// newest release matching the prefix ("22" or "22.11" or an exact version).
	let raw = '';
	process.stdin.on('data', d => raw += d);
	process.stdin.on('end', () => {
		let list;
		try{ list = JSON.parse(raw); }catch(e){ process.exit(3); }
		if(!Array.isArray(list)) process.exit(3);
		const want = String(process.argv[3] || '').replace(/^v/, '');
		const exact = /^\d+\.\d+\.\d+$/.test(want);
		let best = null;
		for(const rel of list){
			const v = String(rel.version || '').replace(/^v/, '');
			if(!/^\d+\.\d+\.\d+$/.test(v)) continue;
			if(exact ? v !== want : !(v === want || v.startsWith(want + '.'))) continue;
			if(best === null || cmpv(parts(v), parts(best)) > 0) best = v;
		}
		if(!best) process.exit(4);
		process.stdout.write(best + '\n');
	});
}else{
	process.stderr.write('usage: detect.js framework|nodespec|pickversion ...\n');
	process.exit(2);
}
