<?php
/**
 * GitDeploy — in-panel documentation.
 * Pure content: defines one function, outputs nothing at include time.
 * gd_docs_html($ctx) returns/echoes the docs body using the same card/markup
 * idiom as web/enduser/index.php (soft-card panels, .hint, .mono, pre.plain,
 * .gd-steps, .gd-note, .gd-h3, .gd-tag, badges). No <style> blocks, no JS
 * beyond the copy-to-clipboard buttons the panel already wires up globally
 * via [data-copy].
 */

function gd_docs_html($ctx = array()){

	$user = (isset($ctx['user']) && is_string($ctx['user']) && $ctx['user'] !== '') ? $ctx['user'] : '';
	$host = (isset($ctx['panel_host']) && is_string($ctx['panel_host']) && $ctx['panel_host'] !== '')
		? $ctx['panel_host'] : 'your-server-hostname';
	$has_ghapp = !empty($ctx['has_ghapp']);

	$user_esc = htmlspecialchars($user, ENT_QUOTES, 'UTF-8');
	$host_esc = htmlspecialchars($host, ENT_QUOTES, 'UTF-8');

	$hook_url_txt     = 'https://'.$host.':2003/gitdeploy/hook.php?site=your-domain.com';
	$hook_url_esc     = htmlspecialchars($hook_url_txt, ENT_QUOTES, 'UTF-8');

	$json_basic = '{' . "\n"
		. '  "build_cmd": "npm run build",' . "\n"
		. '  "output_dir": "dist",' . "\n"
		. '  "env": {' . "\n"
		. '    "VITE_API_URL": "https://api.example.com"' . "\n"
		. '  }' . "\n"
		. '}';
	$json_basic_esc = htmlspecialchars($json_basic, ENT_QUOTES, 'UTF-8');

	$json_node = '{' . "\n"
		. '  "node_version": "22"' . "\n"
		. '}';
	$json_node_esc = htmlspecialchars($json_node, ENT_QUOTES, 'UTF-8');

	$json_mono = '{' . "\n"
		. '  "build_cmd": "npm run build --workspace=apps/site",' . "\n"
		. '  "output_dir": "apps/site/dist"' . "\n"
		. '}';
	$json_mono_esc = htmlspecialchars($json_mono, ENT_QUOTES, 'UTF-8');

	$json_env = '{' . "\n"
		. '  "build_cmd": "npm run build",' . "\n"
		. '  "output_dir": "dist",' . "\n"
		. '  "env": {' . "\n"
		. '    "VITE_API_URL": "https://api.example.com",' . "\n"
		. '    "VITE_ANALYTICS_ID": "G-XXXXXXX"' . "\n"
		. '  }' . "\n"
		. '}';
	$json_env_esc = htmlspecialchars($json_env, ENT_QUOTES, 'UTF-8');

	$ghapp_note = $has_ghapp
		? 'You already have a GitHub App connected on this account. To add a new site, use '
			. '<strong>Add a site</strong> and pick the repository and branch from the dropdowns &mdash; '
			. 'no key or webhook to copy anywhere.'
		: 'You have not connected a GitHub App yet. Do that once from '
			. '<strong>GitDeploy &rarr; GitHub Connection</strong>, then every site you add afterwards can use '
			. 'the repo/branch dropdowns instead of a manual key and webhook.';

	$user_line = ($user_esc !== '') ? ' (<code class="mono">'.$user_esc.'</code>)' : '';

	echo <<<HTML
<div class="gd-docs">

<div class="card soft-card mb-4">
<div class="row align-items-center panel-heading px-4 py-3"><div class="col-12">
<label class="panel-head d-inline-block mb-0"><i class="fas fa-book panel-icon"></i>What GitDeploy does</label>
</div></div>
<div class="card-body px-4 py-4">
<p>GitDeploy watches a branch of a GitHub repository. When you push to it, the server clones or
fetches your code, runs your build command as your own hosting user, and copies the result straight
into a domain's website folder &mdash; no manual upload, no FTP.</p>
<p>It is for <strong>static sites only</strong>: anything that ends up as plain HTML/CSS/JS in an output
folder &mdash; Vite, Astro, a Next.js static export, Hugo, Jekyll, or hand-written HTML. Node.js is only
used <em>during the build</em>; nothing keeps running afterwards, and there is no Node process serving
your site. You normally don't have to tell GitDeploy how to build anything &mdash; it recognises the
framework from the repository and works the build command and output folder out on its own.</p>
<p>If your project needs a persistent server process (an API, SSR that isn't statically exported, a
database-backed app), GitDeploy is not the right tool for that part of it.</p>
</div>
</div>

<div class="card soft-card mb-4">
<div class="row align-items-center panel-heading px-4 py-3"><div class="col-12">
<label class="panel-head d-inline-block mb-0"><i class="fas fa-plug panel-icon"></i>Getting started &mdash; two ways to connect</label>
</div></div>
<div class="card-body px-4 py-4">

<div class="alert alert-info gd-note"><i class="fab fa-github me-1"></i>{$ghapp_note}</div>

<h3 class="gd-h3">A. GitHub App (recommended)</h3>
<p>Connect a GitHub App to your account once. After that, adding a site is just picking a repository
and branch from dropdowns &mdash; GitDeploy already has read access and the webhook is registered for
you automatically. This is <strong>per hosting user</strong>: each account on the server connects its
own app, so one user's repositories are never visible to another.</p>
<ol class="gd-steps">
<li>Open <strong>GitDeploy &rarr; GitHub Connection</strong> and click <strong>Connect GitHub App</strong>.
You'll be sent to GitHub to create the app (a manifest does this in one click) and come straight back.</li>
<li>On GitHub, choose which repositories the app can see (all of them, or a hand-picked list).</li>
<li>Back in GitDeploy, click <strong>Add a site</strong>, choose your domain, then pick the repository
and branch from the dropdowns.</li>
</ol>
<p class="hint">Only one GitHub App can be connected per hosting user at a time. To connect a different
one, disconnect the existing app first.</p>

<h3 class="gd-h3">B. Manual (SSH key or public HTTPS URL)</h3>
<p>Use this if you don't want to grant a GitHub App any access, or you're deploying from a repository
under an account you don't control the App installations for.</p>
<ol class="gd-steps">
<li><strong>Public repository:</strong> use the <code>https://github.com/owner/repo.git</code> URL. No
key is needed &mdash; GitDeploy simply clones it anonymously.</li>
<li><strong>Private repository:</strong> use the SSH URL instead, e.g.
<code class="mono">git@github.com:owner/repo.git</code>. Saving the site generates a dedicated SSH
deploy key for it. Copy the public key GitDeploy shows you into the repository's
<strong>Settings &rarr; Deploy keys &rarr; Add deploy key</strong>. Leave <em>Allow write access</em>
unticked &mdash; GitDeploy only ever fetches, it never pushes.</li>
<li>Add the webhook so pushes trigger a build immediately, instead of waiting for you to click
<strong>Deploy now</strong>: in the repository, go to <strong>Settings &rarr; Webhooks &rarr; Add
webhook</strong> and fill in:</li>
</ol>
<div class="row">
<div class="col-md-8">
<div class="copy"><pre class="plain" id="gddoc-hookurl">{$hook_url_esc}</pre>
<button type="button" class="flat-butt-primary btn-sm gd-copybtn" data-copy="gddoc-hookurl">Copy</button></div>
</div>
</div>
<ul class="gd-steps">
<li><strong>Content type:</strong> <code>application/json</code> (the signature check requires the raw
JSON body &mdash; the default form-encoded type will not work).</li>
<li><strong>Secret:</strong> the per-site secret shown on the site's settings page &mdash; paste it
exactly, it's used to verify every push is really from GitHub.</li>
<li><strong>Which events:</strong> <em>Just the push event</em>. GitDeploy ignores everything else.</li>
</ul>
<p class="hint">The exact webhook URL (with the right domain in <code>site=</code>) is shown on each
site's own settings page &mdash; the one above is just the shape of it, using this server's hostname
(<span class="mono">{$host_esc}</span>).</p>
</div>
</div>

<div class="card soft-card mb-4">
<div class="row align-items-center panel-heading px-4 py-3"><div class="col-12">
<label class="panel-head d-inline-block mb-0"><i class="fas fa-hammer panel-icon"></i>Build settings explained</label>
</div></div>
<div class="card-body px-4 py-4">
<p><strong>The best setting for most sites is no setting at all.</strong> Leave <strong>Build
command</strong> and <strong>Output folder</strong> empty and GitDeploy works them out for you: on every
deploy it looks inside your repository, recognises what the site was built with, and uses the right
command and folder for it. Anything you do type in the panel always wins over what was detected.</p>
<p><strong>Build command</strong> &mdash; the shell command that turns your source into a finished
site. It runs in your project folder, as your hosting user, right after dependencies are installed.
Fill it in only to override what was detected.</p>
<p><strong>Output folder</strong> &mdash; the folder your build writes the finished site into. Leave it
empty too; type it only when your build writes somewhere unexpected.</p>
<p><strong>Project folder</strong> &mdash; leave empty unless the repository is a monorepo. Set it to
the folder that actually holds the site (for example <code>apps/website</code>): the build runs there,
and the output folder is read relative to it.</p>
<p><strong>Branch</strong> &mdash; only pushes to this branch trigger a build and get published. Pushes
to any other branch are ignored by the webhook.</p>

<h3 class="gd-h3">What gets detected</h3>
<div class="table-responsive">
<table class="table table-sm align-middle mb-2">
<thead><tr><th>If your repository looks like</th><th>Build command used</th><th>Output folder</th></tr></thead>
<tbody>
<tr><td>Astro</td><td><code>npm run build</code></td><td><code>dist</code></td></tr>
<tr><td>Vite</td><td><code>npm run build</code></td><td><code>dist</code></td></tr>
<tr><td>Next.js (static export only)</td><td><code>npm run build</code></td><td><code>out</code></td></tr>
<tr><td>Nuxt</td><td><code>npm run generate</code></td><td><code>.output/public</code>, else <code>dist</code></td></tr>
<tr><td>SvelteKit</td><td><code>npm run build</code></td><td><code>build</code></td></tr>
<tr><td>Gatsby</td><td><code>npm run build</code></td><td><code>public</code></td></tr>
<tr><td>Docusaurus</td><td><code>npm run build</code></td><td><code>build</code></td></tr>
<tr><td>VitePress</td><td><code>npm run docs:build</code></td><td><code>docs/.vitepress/dist</code></td></tr>
<tr><td>Eleventy</td><td><code>npm run build</code></td><td><code>_site</code></td></tr>
<tr><td>Create React App</td><td><code>npm run build</code></td><td><code>build</code></td></tr>
<tr><td>Angular</td><td><code>npm run build</code></td><td><code>dist</code></td></tr>
<tr><td>Vue CLI</td><td><code>npm run build</code></td><td><code>dist</code></td></tr>
<tr><td>Parcel</td><td><code>npm run build</code></td><td><code>dist</code></td></tr>
<tr><td>Hugo</td><td><code>hugo --minify</code></td><td><code>public</code></td></tr>
<tr><td>Jekyll</td><td><code>bundle exec jekyll build</code></td><td><code>_site</code></td></tr>
<tr><td>Plain HTML (no <code>package.json</code>)</td><td>none &mdash; no build step at all</td><td>the repository itself</td></tr>
</tbody>
</table>
</div>
<p>Angular and a few others put the finished site one level deeper, in
<code>dist/&lt;project-name&gt;</code>. GitDeploy notices that and publishes the inner folder, so you
don't have to name it yourself.</p>

<h3 class="gd-h3">Worth knowing before you rely on detection</h3>
<ul class="gd-steps">
<li><strong>Next.js only works if the project sets <code>output: 'export'</code>.</strong> A Next.js app
in server mode needs a Node process running all the time and cannot be hosted this way. The build log
says so plainly when it spots one.</li>
<li><strong>SvelteKit needs <code>@sveltejs/adapter-static</code></strong>, for exactly the same reason:
without it there is no folder of plain files to publish.</li>
<li><strong>Hugo and Jekyll are recognised, but their tools have to be installed on this server
already.</strong> If the tool is missing the build stops straight away and names it, rather than failing
halfway through.</li>
<li><strong>A repository with no <code>package.json</code> but an <code>index.html</code></strong> is
published exactly as it is, with no build. These are never published with it:
<code>.git</code>, <code>.github</code>, <code>node_modules</code>, <code>.env</code>,
<code>.gitignore</code>, <code>.nvmrc</code>, <code>.node-version</code>, <code>.gitmodules</code> and
<code>.gitdeploy.json</code>.</li>
<li><strong>If nothing is recognised</strong> and you left the build command empty, the deploy stops and
tells you to fill in Build settings. GitDeploy never guesses.</li>
</ul>

<h3 class="gd-h3">Node version</h3>
<p>The optional <strong>Node version</strong> field under Build settings decides which Node your build
runs with. Leave it empty &mdash; that is the normal case &mdash; and the version comes from the
repository itself: an <code>.nvmrc</code> file, then <code>.node-version</code>, then
<code>engines.node</code> in <code>package.json</code>. If the repository asks for nothing, or the
server's own Node (currently <strong>v20</strong>) already satisfies what it asks for, that is what is
used and nothing is downloaded.</p>
<ul class="gd-steps">
<li><code>engines.node</code> is read as a <em>range</em>: <code>&gt;=18</code> is satisfied by Node 20,
so nothing is installed; <code>^22</code> is not, so Node 22 is installed.</li>
<li><code>.nvmrc</code>, <code>.node-version</code> and the panel field are read as a <em>pin</em>:
<code>22</code>, <code>22.11</code>, <code>22.11.0</code> and <code>v22</code> all work, and a
<code>.nvmrc</code> saying <code>lts/jod</code> works too.</li>
<li>When a different version really is needed, the official build is downloaded from nodejs.org,
<strong>checked against nodejs.org's own checksum list</strong>, and unpacked inside your own home
folder (<code class="mono">~/.gitdeploy/toolchains/</code>). It is put on the path for that build only:
the server's Node is never changed and no other site on the server is affected.</li>
<li>It costs about 50&nbsp;MB to download and 200&nbsp;MB on disk, is shared by all of your account's
sites, and is reused by every later build &mdash; so only the first build with a new version is slow.
Versions you stop using are removed automatically after 60 days.</li>
<li>The install is refused if the disk has under 1&nbsp;GB free, so a download can never be the thing
that fills the server.</li>
</ul>
<p class="hint">Which one wins, highest first: the panel's <strong>Node version</strong> field, then
<code>node_version</code> in <code>.gitdeploy.json</code>, then <code>.nvmrc</code>, then
<code>.node-version</code>, then <code>engines.node</code>, then the server's own Node.</p>

<p class="hint"><i class="fas fa-circle-info me-1"></i>It's the <em>contents</em> of the output
folder that get published &mdash; not the folder itself. If the output folder is
<code>dist</code> and it contains <code>index.html</code>, your domain serves
<code>index.html</code> directly at its root, not at <code>/dist/index.html</code>.</p>
</div>
</div>

<div class="card soft-card mb-4">
<div class="row align-items-center panel-heading px-4 py-3"><div class="col-12">
<label class="panel-head d-inline-block mb-0"><i class="fas fa-file-code panel-icon"></i>The .gitdeploy.json file</label>
</div></div>
<div class="card-body px-4 py-4">
<p>Drop a <code>.gitdeploy.json</code> file in the <strong>root of your repository</strong>, committed
to the branch GitDeploy deploys. It lets your build configuration travel with the code itself, so the
panel's Build settings become optional (or a fallback) rather than the single source of truth &mdash;
useful if the same repo is ever deployed from a different server, or you just don't want build details
living only in a web form.</p>

<div class="copy"><pre class="plain" id="gddoc-json-basic">{$json_basic_esc}</pre>
<button type="button" class="flat-butt-primary btn-sm gd-copybtn" data-copy="gddoc-json-basic">Copy</button></div>

<h3 class="gd-h3">Fields</h3>
<div class="table-responsive">
<table class="table table-sm align-middle mb-2">
<thead><tr><th>Field</th><th>Type</th><th>Rules</th></tr></thead>
<tbody>
<tr><td><code>build_cmd</code></td><td>string, optional</td>
<td>Up to 300 characters, a single line (no newlines or control characters). Overrides the panel's
build command when present and valid.</td></tr>
<tr><td><code>output_dir</code></td><td>string, optional</td>
<td>A relative path inside the repository, matching
<code class="mono">^[A-Za-z0-9][A-Za-z0-9/_.-]{0,100}$</code>. Cannot contain <code>..</code> and
cannot start with <code>/</code>. Overrides the panel's output folder when present and valid.</td></tr>
<tr><td><code>root_dir</code></td><td>string, optional</td>
<td>The project folder inside the repository for a monorepo, e.g. <code>apps/website</code>. Same path
rules as <code>output_dir</code>. Overrides the panel's project folder when present and valid.</td></tr>
<tr><td><code>node_version</code></td><td>string, optional</td>
<td>The Node version to build with: <code>"22"</code>, <code>"22.11"</code>, <code>"22.11.0"</code> or
<code>"v22"</code>. Overrides <code>.nvmrc</code>, <code>.node-version</code> and
<code>engines.node</code> &mdash; but the panel's <strong>Node version</strong> field beats it.</td></tr>
<tr><td><code>env</code></td><td>object, optional</td>
<td>Up to 100 <code>"KEY": "value"</code> entries. Keys must match
<code class="mono">^[A-Za-z_][A-Za-z0-9_]*$</code>; values are strings up to 500 characters, single
line. Merged into the build environment.</td></tr>
</tbody>
</table>
</div>

<h3 class="gd-h3">Precedence</h3>
<p>Values in <code>.gitdeploy.json</code> override the panel's <strong>Build command</strong>,
<strong>Output folder</strong> and <strong>Project folder</strong> fields. Two things work the other way
round: <strong>panel environment variables win over repo <code>env</code> values of the same
name</strong>, and the panel's <strong>Node version</strong> field wins over
<code>node_version</code> in the file &mdash; when both are set, the build log says which one it used.
Keep secrets (API keys, tokens) in the panel's Environment variables field, not committed to the repo
&mdash; anyone with read access to the repository can read <code>.gitdeploy.json</code>.</p>
<p class="hint">A field only overrides when it is present <em>and</em> passes validation. If a field in
the file fails validation (too long, bad characters, bad path, an unrecognisable version), that one
field is ignored &mdash; the build log says which one and why &mdash; the panel's value is used
instead, and the rest of the file still applies. The same is true per environment-variable entry: an entry
with an invalid name or an over-long value is dropped without changing anything else.</p>

<h3 class="gd-h3">Reserved variable names</h3>
<p>These names can never be set from either <code>.gitdeploy.json</code> or the panel's Environment
variables field, for safety &mdash; they control how the build authenticates to git and where the
result gets published, and letting a repository (or a typo in the panel) override them could redirect
a deploy into the wrong place. If you try to set one, the build log records it as
<span class="mono">REFUSED (reserved name)</span> and the value is skipped:</p>
<p>
<span class="gd-tag">GD_*</span>
<span class="gd-tag">HOME</span>
<span class="gd-tag">USER</span>
<span class="gd-tag">LOGNAME</span>
<span class="gd-tag">PATH</span>
<span class="gd-tag">IFS</span>
<span class="gd-tag">SHELL</span>
<span class="gd-tag">BASH_ENV</span>
<span class="gd-tag">ENV</span>
<span class="gd-tag">LD_*</span>
<span class="gd-tag">GIT_SSH*</span>
<span class="gd-tag">GIT_CONFIG*</span>
<span class="gd-tag">GIT_DIR</span>
<span class="gd-tag">GIT_WORK_TREE</span>
<span class="gd-tag">GIT_INDEX_FILE</span>
<span class="gd-tag">GIT_EXEC_PATH</span>
<span class="gd-tag">PUBLISH_*</span>
</p>

<p class="hint">If <code>.gitdeploy.json</code> is not valid JSON at all, the whole file is ignored
&mdash; the build log gets a warning and the panel's Build command / Output folder / Environment
variables are used exactly as if the file didn't exist.</p>

<h3 class="gd-h3">Examples</h3>
<p class="hint">Let the build command and output folder be detected, but build with Node 22:</p>
<div class="copy"><pre class="plain" id="gddoc-json-node">{$json_node_esc}</pre>
<button type="button" class="flat-butt-primary btn-sm gd-copybtn" data-copy="gddoc-json-node">Copy</button></div>

<p class="hint">A monorepo, building one workspace into a nested output folder:</p>
<div class="copy"><pre class="plain" id="gddoc-json-mono">{$json_mono_esc}</pre>
<button type="button" class="flat-butt-primary btn-sm gd-copybtn" data-copy="gddoc-json-mono">Copy</button></div>

<p class="hint">With build-time environment variables:</p>
<div class="copy"><pre class="plain" id="gddoc-json-env">{$json_env_esc}</pre>
<button type="button" class="flat-butt-primary btn-sm gd-copybtn" data-copy="gddoc-json-env">Copy</button></div>
</div>
</div>

<div class="card soft-card mb-4">
<div class="row align-items-center panel-heading px-4 py-3"><div class="col-12">
<label class="panel-head d-inline-block mb-0"><i class="fas fa-list-ol panel-icon"></i>What happens on each deploy</label>
</div></div>
<div class="card-body px-4 py-4">
<ol class="gd-steps">
<li><strong>Fetch the branch.</strong> First deploy: a fresh clone of just that branch. Every deploy
after that: fetch and hard-reset to the branch's latest commit, then remove untracked files (build
artifacts from the previous run) &mdash; <code>node_modules</code> and the npm cache are kept between
builds so installs stay fast.</li>
<li><strong>Update submodules</strong>, if the repository has a <code>.gitmodules</code> file
(capped at 5 minutes).</li>
<li><strong>Read <code>.gitdeploy.json</code></strong> at the repository root, if present, and apply
its <code>build_cmd</code> / <code>output_dir</code> / <code>root_dir</code> /
<code>node_version</code> / <code>env</code> per the rules above.</li>
<li><strong>Choose the Node version</strong> the project asks for, installing it into your account the
first time it is needed. Most builds use the server's Node and skip this in a second.</li>
<li><strong>Work out how to build it</strong> &mdash; only when the build command or the output folder
was left blank. The repository is inspected, the framework named in the build log, and the missing
value filled in.</li>
<li><strong>Install dependencies:</strong> <code>npm ci</code> if <code>package-lock.json</code> is
present, <code>npm install</code> if it isn't, or skipped entirely if there's no
<code>package.json</code> at all.</li>
<li><strong>Run the build command.</strong></li>
<li><strong>Check the output.</strong> The build fails if the output folder doesn't exist or is empty
afterwards &mdash; and when the folder was detected rather than typed, the log lists every folder that
was looked for.</li>
<li><strong>Archive the result</strong> as a release, named by commit, so it can be rolled back later
without rebuilding.</li>
<li><strong>Publish</strong> with rsync: only changed files are copied into the domain's website
folder, and files that no longer exist in the new build are removed from it &mdash; <strong>except</strong>
a top-level <code>.well-known/</code>, <code>cgi-bin/</code> and <code>.htaccess</code>, which
GitDeploy never touches, so SSL certificate renewals and any rules you've added by hand survive every
deploy.</li>
<li><strong>Record the deployed commit</strong>, shown in the panel and used for notifications.</li>
</ol>
</div>
</div>

<div class="card soft-card mb-4">
<div class="row align-items-center panel-heading px-4 py-3"><div class="col-12">
<label class="panel-head d-inline-block mb-0"><i class="fas fa-history panel-icon"></i>Rollback &amp; releases</label>
</div></div>
<div class="card-body px-4 py-4">
<p>The last <strong>5 successful builds</strong> of each site are kept as releases. The
<strong>Previous versions</strong> list on a site's Logs page shows them; clicking <strong>Restore this
version</strong> republishes that exact build straight from the archived copy &mdash; nothing is rebuilt,
git and npm are never invoked. That also means a rollback works even if GitHub is unreachable.</p>
</div>
</div>

<div class="card soft-card mb-4">
<div class="row align-items-center panel-heading px-4 py-3"><div class="col-12">
<label class="panel-head d-inline-block mb-0"><i class="fas fa-envelope panel-icon"></i>Notifications</label>
</div></div>
<div class="card-body px-4 py-4">
<p>Each site can have its own notification email, set on the site's Advanced settings. Choose when it
fires:</p>
<ul class="gd-steps">
<li><span class="badge bg-secondary">Only on failure</span> &mdash; the default.</li>
<li><span class="badge bg-success">Every deploy</span> &mdash; success and failure both.</li>
<li><span class="badge bg-warning">Never</span> &mdash; leave the email field empty and nothing is
ever sent.</li>
</ul>
<p>The email includes the result, the deployed commit (on success), and the last part of the build log
&mdash; enough to see what went wrong without opening the panel.</p>
</div>
</div>

<div class="card soft-card mb-4">
<div class="row align-items-center panel-heading px-4 py-3"><div class="col-12">
<label class="panel-head d-inline-block mb-0"><i class="fas fa-stopwatch panel-icon"></i>Limits &amp; timeouts</label>
</div></div>
<div class="card-body px-4 py-4">
<ul class="gd-steps">
<li>Dependency install and the build command are each capped at <strong>15 minutes</strong>.</li>
<li>A whole deploy &mdash; fetch through publish &mdash; is capped at <strong>40 minutes</strong>; past
that it's killed and marked failed.</li>
<li>Builds run as your own hosting user{$user_line}, with the same filesystem permissions you have
everywhere else on the account &mdash; not as root, and not as a shared build user.</li>
<li>Pushing several times in quick succession doesn't queue several builds: while a build is running,
further pushes to the same site are coalesced into a single extra build that runs right after.</li>
</ul>
</div>
</div>

<div class="card soft-card mb-4">
<div class="row align-items-center panel-heading px-4 py-3"><div class="col-12">
<label class="panel-head d-inline-block mb-0"><i class="fas fa-wrench panel-icon"></i>Troubleshooting</label>
</div></div>
<div class="card-body px-4 py-4">
<div class="table-responsive">
<table class="table table-sm align-middle mb-0">
<thead><tr><th>Symptom</th><th>Likely cause</th><th>Fix</th></tr></thead>
<tbody>
<tr><td>Build fails: <span class="mono">the build finished but produced none of these folders</span></td>
<td>Your build writes somewhere other than the folders that were tried, or the Output folder setting
doesn't match what your build actually produces.</td>
<td>Check the build log for where files were written, and type that into Output folder (or
<code>output_dir</code> in <code>.gitdeploy.json</code>).</td></tr>
<tr><td>Build fails: <span class="mono">we could not work out how to build this project</span></td>
<td>The repository matches none of the frameworks above and you left the build fields empty.</td>
<td>Open the site, expand <strong>Build settings</strong> and fill in the build command and output
folder yourself.</td></tr>
<tr><td>Build fails naming a tool that is <span class="mono">not installed on this server</span></td>
<td>The project builds with something like Hugo or Jekyll that isn't on the server.</td>
<td>Ask your host to install it, or change the build command to one that uses Node.</td></tr>
<tr><td>Next.js site fails, or the log warns about <span class="mono">output: "export"</span></td>
<td>The project runs in server mode, which needs a Node process running permanently.</td>
<td>Set <code>output: 'export'</code> in <code>next.config.js</code> (or
<code>@sveltejs/adapter-static</code> for SvelteKit) so the project produces plain files.</td></tr>
<tr><td>Webhook shows a 403 on GitHub's Recent Deliveries tab</td>
<td>The webhook secret doesn't match the one GitDeploy has stored for this site.</td>
<td>Re-copy the secret from the site's settings page into the GitHub webhook and save it again.</td></tr>
<tr><td><span class="mono">Permission denied (publickey)</span> in the build log</td>
<td>The deploy key was never added to the repository, or was added to the wrong repository.</td>
<td>Copy the public key shown on the site's settings page into that exact repository's
Settings &rarr; Deploy keys.</td></tr>
<tr><td>Site refused: "shared website folder"</td>
<td>This domain's website folder is shared with other domains, or is your account's main
public_html &mdash; publishing there with delete-on-sync would overwrite other live sites.</td>
<td>Point the site at a domain (or subdomain) with its own dedicated folder, or explicitly accept the
risk when adding the site if you understand the consequences.</td></tr>
<tr><td>Nothing happens after a push</td>
<td>You pushed to a different branch than the one configured, or the webhook was never added
(manual sites only).</td>
<td>Confirm the branch in the site's settings matches the branch you pushed, and check the webhook
exists under the repository's Settings &rarr; Webhooks.</td></tr>
<tr><td><span class="mono">npm ci</span> fails</td>
<td><code>package-lock.json</code> is out of date relative to <code>package.json</code>.</td>
<td>Run <code>npm install</code> locally to refresh the lockfile, commit it, and push again.</td></tr>
</tbody>
</table>
</div>
</div>
</div>

<div class="card soft-card mb-4">
<div class="row align-items-center panel-heading px-4 py-3"><div class="col-12">
<label class="panel-head d-inline-block mb-0"><i class="fas fa-lightbulb panel-icon"></i>Tips</label>
</div></div>
<div class="card-body px-4 py-4">
<ul class="gd-steps">
<li>Commit your lockfile (<code>package-lock.json</code>) &mdash; it's what lets GitDeploy use
<code>npm ci</code>, which is faster and more reproducible than <code>npm install</code>.</li>
<li>Keep secrets in the panel's Environment variables, not in <code>.gitdeploy.json</code> or anywhere
else in the repository. Panel values always win over repo values of the same name.</li>
<li>Test changes on a staging subdomain before pushing to the branch your production domain deploys
from.</li>
<li>The first build on a fresh site is the slowest one &mdash; every dependency is installed from
scratch, and a Node version your repository asks for is downloaded once. Later builds are faster
because <code>node_modules</code> and that Node version are both reused between deploys.</li>
<li>Leave the build fields empty and let detection do the work. It is re-checked on every deploy, so a
project that switches framework keeps building without you touching the panel.</li>
</ul>
</div>
</div>

<div class="hint gd-powered text-center">Powered by Digitalbkk Co., Ltd. &mdash; <a href="https://www.digitalbkk.com" target="_blank" rel="noopener">www.digitalbkk.com</a></div>

</div>
HTML;
}
