@php
	$s = $Page->getSettings();
	$kopia = $Page->getKopiaVersions();
	$results = $Page->getTestResults();
	$badge = ['pass' => 'success', 'fail' => 'danger', 'skip' => 'secondary'];
@endphp

<form method="post">
	<input type="hidden" name="{{ \Auth_UI::CSRF_KEY }}" value="{{ \Session::token() }}"/>
	<ul class="col-12 col-sm-4 col-lg-3 col-xl-2 mb-3 list-group float-left nav nav-pills" id="menu" role="tablist"
	    aria-multiselectable="true">
		<li class="nav-item block">
			<a href="#sites" data-toggle="pill" class="nav-link {{ $Page->getRecoveryKit() ? '' : 'active' }}" data-parent="#menu" role="tab">Sites</a>
		</li>
		<li class="nav-item block">
			<a href="#storage" data-toggle="pill" class="nav-link" data-parent="#menu" role="tab">Storage</a>
		</li>
		<li class="nav-item block">
			<a href="#retention" data-toggle="pill" class="nav-link" data-parent="#menu" role="tab">Retention</a>
		</li>
		<li class="nav-item block">
			<a href="#ignore" data-toggle="pill" class="nav-link" data-parent="#menu" role="tab">Ignore rules</a>
		</li>
		<li class="nav-item block">
			<a href="#kopia" data-toggle="pill" class="nav-link" data-parent="#menu" role="tab">Kopia</a>
		</li>
		<li class="nav-item block">
			<a href="#setup" data-toggle="pill" class="nav-link" data-parent="#menu" role="tab">Bucket setup</a>
		</li>
		<li class="nav-item block">
			<a href="#recovery" data-toggle="pill" class="nav-link {{ $Page->getRecoveryKit() ? 'active' : '' }}" data-parent="#menu" role="tab">Recovery</a>
		</li>
		<li class="block mt-3 text-right">
			<button type="submit" class="btn btn-primary" name="save" value="1">Save</button>
		</li>
		<li class="block mt-2 text-right">
			<button type="submit" class="btn btn-secondary" name="test" value="1"
				{{ $s['access_key'] && $s['secret_key'] ? '' : 'disabled' }}>Save &amp; test bucket</button>
		</li>
	</ul>

	<div class="col-12 col-sm-8 col-lg-9 col-xl-10 float-left tab-content">
		<div class="tab-pane {{ $Page->getRecoveryKit() ? '' : 'active' }}" id="sites" role="tabpanel">
			@php $sites = $Page->getSites(); $editSite = $Page->getEditSite(); $jobs = $Page->getJobs(); @endphp
			<div id="kb-activity" class="alert alert-info {{ $jobs ? '' : 'd-none' }}">
				Running: <span id="kb-activity-list">{{ implode(', ', array_map(static fn($j) => str_replace('-', ' ', $j), $jobs)) }}</span>
			</div>
			@if ($editSite)
				<div class="card mb-3">
					<div class="card-body">
						@php $editStatus = current(array_filter($sites, static fn($x) => $x['domain'] === $editSite)) ?: null; @endphp
						<h5 class="card-title">{{ $editSite }}</h5>
						@if ($editStatus && $editStatus['ok'] === false && $editStatus['error'])
							<div class="alert alert-danger">Last run failed: {{ $editStatus['error'] }}</div>
						@endif
						<h6>Ignore rules</h6>
						<p class="text-muted">Added to the global list on the Ignore rules section. Gitignore syntax, relative to the site root, e.g. <code>/var/www/example.com/wp-content/uploads/videos</code>.</p>
						<input type="hidden" name="site_ignore_domain" value="{{ $editSite }}"/>
						<textarea class="form-control mb-2" name="site_ignore_text" rows="5">{{ $s['site_ignores'][$editSite] ?? '' }}</textarea>
						<button type="submit" name="save_site_ignores" value="1" class="btn btn-primary">Save rules</button>

						<h6 class="mt-4">Reset local state</h6>
						<p class="text-muted">Forgets this site's Kopia connection, cache and reports on this server. Nothing in the bucket is deleted.
							Use it after removing or replacing the site's repository in Wasabi; the next backup starts a fresh repository.</p>
						<button type="submit" name="reset" value="{{ $editSite }}" class="btn btn-secondary warn"
							data-prompt="Forget local backup state for {{ $editSite }}? Nothing in the bucket is deleted."
							{{ $editStatus && ($editStatus['running'] || !$editStatus['finished']) ? 'disabled' : '' }}>Reset {{ $editSite }}</button>
					</div>
				</div>
			@endif
			@if (!$s['bucket'] || !$s['access_key'])
				<div class="alert alert-info">Configure Storage and pass the bucket test before running backups.</div>
			@endif
			<div class="mb-3">
				<button type="submit" name="backup_all" value="1" class="btn btn-primary" {{ $s['bucket'] ? '' : 'disabled' }}>Back up all sites</button>
				<button type="submit" name="verify_all" value="1" class="btn btn-secondary" {{ $s['bucket'] ? '' : 'disabled' }}>Verify all sites</button>
				<span class="text-muted ml-2">{{ (int)$s['parallel'] }} at a time, queued as one job</span>
			</div>
			<table class="table">
				<thead>
					<tr><th>Site</th><th>Last backup</th><th>Result</th><th>Databases</th><th>Size</th><th>Verified</th><th></th></tr>
				</thead>
				<tbody>
					@foreach ($sites as $siteId => $st)
						<tr data-site="{{ $siteId }}">
							<td>{{ $st['domain'] }}</td>
							<td class="kb-last">{{ $st['finished'] ? date('M j, Y H:i', $st['finished']) : 'never' }}</td>
							<td class="kb-result">
								@if ($st['running'])
									<span class="badge badge-info">running{{ $st['started'] ? ' since ' . date('H:i', $st['started']) : '' }}</span>
								@elseif ($st['ok'] === true)
									<span class="badge badge-success">ok</span>
								@elseif ($st['ok'] === 'interrupted')
									<span class="badge badge-warning">interrupted</span>
								@elseif ($st['ok'] === false)
									<span class="badge badge-danger" title="{{ $st['error'] }}">failed</span>
								@endif
								@if ($st['verifying'])
									<span class="badge badge-info">verifying</span>
								@endif
							</td>
							<td class="kb-dbs">{{ $st['finished'] ? $st['databases'] : '' }}</td>
							<td class="kb-size">{{ $st['size'] ? \Formatter::reduceBytes($st["size"]) : '' }}</td>
							<td class="kb-verified">
								@if ($st['verified'])
									{{ date('M j', $st['verified']) }}
									<span class="badge badge-{{ $st['verify_ok'] ? 'success' : 'danger' }}">{{ $st['verify_ok'] ? 'ok' : 'failed' }}</span>
								@endif
							</td>
							<td class="text-right text-nowrap">
								<button type="submit" name="backup" value="{{ $st['domain'] }}" class="btn btn-secondary btn-sm"
									{{ $st['running'] || !$s['bucket'] ? 'disabled' : '' }}>Back up now</button>
								<button type="submit" name="verify" value="{{ $st['domain'] }}" class="btn btn-secondary btn-sm"
									{{ $st['running'] || !$st['finished'] ? 'disabled' : '' }}>Verify</button>
								<button type="submit" name="edit_ignores" value="{{ $st['domain'] }}" class="btn btn-secondary btn-sm">Manage{{ !empty($s['site_ignores'][$st['domain']]) ? ' *' : '' }}</button>
							</td>
						</tr>
					@endforeach
				</tbody>
			</table>
			<dl class="row text-muted mb-0">
				<dt class="col-sm-2">Back up now</dt>
				<dd class="col-sm-10">Dumps the site's databases and snapshots its files into its repository. Runs on the server in the background; the table updates while it runs.</dd>
				<dt class="col-sm-2">Verify</dt>
				<dd class="col-sm-10">Checks that every object the snapshots reference still exists, re-downloads {{ (int)$s['verify_percent'] }}% of file contents to re-hash it, and reads the object-lock retention back from the bucket for a sample of objects.</dd>
				<dt class="col-sm-2">Manage</dt>
				<dd class="col-sm-10">Per-site ignore rules, the last error, and the reset action.</dd>
			</dl>
		</div>

		<div class="tab-pane" id="storage" role="tabpanel">
			<div class="row">
				<div class="form-group col-md-6">
					<label for="endpoint">Endpoint</label>
					<input type="url" class="form-control" id="endpoint" name="endpoint" value="{{ $s['endpoint'] }}" required/>
				</div>
				<div class="form-group col-md-3">
					<label for="region">Region</label>
					<input type="text" class="form-control" id="region" name="region" value="{{ $s['region'] }}" required/>
				</div>
				<div class="form-group col-md-3">
					<label for="bucket">Bucket</label>
					<input type="text" class="form-control" id="bucket" name="bucket" value="{{ $s['bucket'] }}" required/>
				</div>
				<div class="form-group col-md-6">
					<label for="prefix">Prefix</label>
					<input type="text" class="form-control" id="prefix" name="prefix" value="{{ $s['prefix'] }}"/>
					<small class="form-text text-muted">Each site's repository lives under &lt;prefix&gt;&lt;site&gt;/</small>
				</div>
				<div class="form-group col-md-3">
					<label for="access_key">Access key</label>
					<input type="text" class="form-control" id="access_key" name="access_key" value="{{ $s['access_key'] }}" autocomplete="off"/>
				</div>
				<div class="form-group col-md-3">
					<label for="secret_key">Secret key</label>
					<input type="password" class="form-control" id="secret_key" name="secret_key" value="" autocomplete="new-password"
					       placeholder="{{ $s['secret_key'] ? 'stored, leave blank to keep' : '' }}"/>
				</div>
			</div>
			<p class="text-muted">Save the bucket first, then create the key from the policy under <b>Bucket setup</b>.</p>

			@if ($results)
				@php
					$failedGroups = [];
					foreach ($Page::CHECKS as $key => [$group]) {
						if (($results[$key]['status'] ?? 'skip') !== 'pass') {
							$failedGroups[$group] = true;
						}
					}
				@endphp
				<h4>Bucket test: {{ $s['bucket'] }}</h4>
				@if (!$failedGroups)
					<div class="alert alert-success">All checks passed. The bucket is locked correctly and the key can run backups but cannot destroy them.</div>
				@else
					<div class="alert alert-danger">
						<ul class="mb-0 pl-3">
							@foreach ($failedGroups as $group => $_)
								<li><b>{{ $Page::GROUPS[$group][0] }}:</b> {{ $Page::GROUPS[$group][1] }}</li>
							@endforeach
						</ul>
					</div>
				@endif
				@foreach ($Page::GROUPS as $group => [$title, $explain])
					<h5 class="mt-3">{{ $title }}</h5>
					<p class="text-muted">{{ $explain }}</p>
					<table class="table">
						<tbody>
							@foreach ($Page::CHECKS as $key => [$g, $label, $help])
								@continue($g !== $group)
								@php $r = $results[$key] ?? ['status' => 'skip', 'detail' => 'not run']; @endphp
								<tr>
									<td class="w-25">{{ $label }}<br/><small class="text-muted">{{ $help }}</small></td>
									<td class="text-nowrap"><span class="badge badge-{{ $badge[$r['status']] ?? 'secondary' }} text-uppercase">{{ $r['status'] }}</span></td>
									<td>{{ $r['detail'] }}</td>
								</tr>
							@endforeach
						</tbody>
					</table>
				@endforeach
			@endif
		</div>

		<div class="tab-pane" id="retention" role="tabpanel">
			<div class="row">
				<div class="form-group col-md-4">
					<label for="lock_mode">Object lock mode</label>
					<select class="form-control custom-select" id="lock_mode" name="lock_mode">
						@foreach (['COMPLIANCE', 'GOVERNANCE'] as $mode)
							<option value="{{ $mode }}" {{ $s['lock_mode'] === $mode ? 'selected' : '' }}>{{ $mode }}</option>
						@endforeach
					</select>
					<small class="form-text text-muted">COMPLIANCE locks cannot be shortened or removed by anyone. Use GOVERNANCE while testing.</small>
				</div>
				<div class="form-group col-md-4">
					<label for="lock_days">Object lock (days)</label>
					<input type="number" min="2" class="form-control" id="lock_days" name="lock_days" value="{{ $s['lock_days'] }}" required/>
				</div>
				<div class="form-group col-md-4">
					<label for="keep_days">Keep daily snapshots (days)</label>
					<input type="number" min="2" class="form-control" id="keep_days" name="keep_days" value="{{ $s['keep_days'] }}" required/>
				</div>
			</div>
			<p class="text-muted">Lock settings apply to repositories when they are created; existing repositories keep theirs.</p>
		</div>

		<div class="tab-pane" id="ignore" role="tabpanel">
			<p>Applied to every site on each run. Gitignore syntax, relative to the site root (<code>/var/www/...</code>, <code>/home/...</code>). Lines starting with <code>#</code> are comments.
				Per-site additions are under <b>Manage</b> in the Sites table. Leave empty to restore the defaults.</p>
			<div class="form-group">
				<textarea class="form-control" name="ignore" rows="12" spellcheck="false">{{ $s['ignore'] }}</textarea>
			</div>
			<p class="text-muted">Defaults skip temp files, logs, page caches, Wordfence logs, backup-plugin archives (UpdraftPlus, BackWPup, All-in-One, Duplicator) and ApisCP's own compressed database exports.</p>
		</div>

		<div class="tab-pane" id="kopia" role="tabpanel">
			<table class="table w-auto">
				<tbody>
					<tr><th class="pr-4">Installed</th><td>{{ $kopia['installed'] ?? 'not installed' }}</td></tr>
					<tr><th class="pr-4">Latest</th><td>{{ $kopia['latest'] ?? 'unknown' }}</td></tr>
				</tbody>
			</table>
			<div class="form-group col-md-4 px-0">
				<label for="kopia_version">Version to install</label>
				@php
					$choices = array_values(array_unique(array_merge(['latest'], $kopia['available'], $s['kopia_version'] === 'latest' ? [] : [$s['kopia_version']])));
				@endphp
				<select class="form-control custom-select" id="kopia_version" name="kopia_version">
					@foreach ($choices as $v)
						<option value="{{ $v }}" {{ $s['kopia_version'] === $v ? 'selected' : '' }}>{{ $v === 'latest' ? 'latest' . ($kopia['latest'] ? " ({$kopia['latest']})" : '') : $v }}</option>
					@endforeach
				</select>
				<small class="form-text text-muted">Applied on the next <code>upcp -b</code> or add-in run.</small>
			</div>
			<div class="form-group col-md-4 px-0">
				<label for="parallel">Sites backed up at once</label>
				<select class="form-control custom-select" id="parallel" name="parallel">
					@foreach (range(1, 8) as $n)
						<option value="{{ $n }}" {{ (int)$s['parallel'] === $n ? 'selected' : '' }}>{{ $n }}</option>
					@endforeach
				</select>
				<small class="form-text text-muted">Used by Back up all sites and the nightly timer.</small>
			</div>
			<div class="row">
				<div class="form-group col-md-4">
					<label for="verify_day">Weekly verification</label>
					<select class="form-control custom-select" id="verify_day" name="verify_day">
						@foreach (['never' => 'Never', '0' => 'Sunday', '1' => 'Monday', '2' => 'Tuesday', '3' => 'Wednesday', '4' => 'Thursday', '5' => 'Friday', '6' => 'Saturday'] as $v => $label)
							<option value="{{ $v }}" {{ (string)$s['verify_day'] === $v ? 'selected' : '' }}>{{ $label }}</option>
						@endforeach
					</select>
					<small class="form-text text-muted">Runs after that night's backups.</small>
				</div>
				<div class="form-group col-md-4">
					<label for="verify_percent">File contents re-checked (%)</label>
					<input type="number" min="0" max="100" class="form-control" id="verify_percent" name="verify_percent" value="{{ $s['verify_percent'] }}"/>
					<small class="form-text text-muted">Downloaded and re-hashed. 100 reads the whole repository.</small>
				</div>
			</div>
			<input type="hidden" name="timer_form" value="1"/>
			<div class="form-group custom-control custom-checkbox">
				<input type="checkbox" class="custom-control-input" id="timer_enabled" name="timer_enabled" value="1" {{ $Page->isTimerEnabled() ? 'checked' : '' }}/>
				<label class="custom-control-label" for="timer_enabled">Nightly backup of every site at 02:30</label>
			</div>
		</div>

		<div class="tab-pane" id="setup" role="tabpanel">
			@if (!$s['bucket'])
				<div class="alert alert-info">Save a bucket name under Storage first. The policy and lifecycle rule are generated for it.</div>
			@else
				<h4>1. Bucket</h4>
				<p>Create <code>{{ $s['bucket'] }}</code> with object lock enabled. Leave default retention off; Kopia locks each object itself.</p>

				<h4>2. Backup key policy</h4>
				<p>Policies &rarr; Create Policy, paste this. Then create a user with programmatic access only, attach the policy, generate a key and enter it under Storage.</p>
				<pre>{{ $Page->getKeyPolicy($s['bucket'], (string)$s['prefix']) }}</pre>

				<h4>3. Lifecycle rule</h4>
				<p>Apply with an admin key, not the backup key. Noncurrent versions expire after lock + 1 days ({{ (int)$s['lock_days'] + 1 }}). Never add a rule that expires current versions.
					<code>put-bucket-lifecycle-configuration</code> replaces every rule on the bucket; if the bucket already has rules, add this one to them instead.</p>
				<pre>{{ $Page->getLifecycleRule((string)$s['prefix'], (int)$s['lock_days']) }}</pre>
				<pre class="command-line" data-host="{{ SERVER_NAME_SHORT }}" data-user="{{ \Session::get('username', 'root') }}">aws s3api put-bucket-lifecycle-configuration --endpoint-url {{ $s['endpoint'] }} --bucket {{ $s['bucket'] }} --lifecycle-configuration file://lifecycle.json</pre>

				<h4>4. Verify</h4>
				<p><b>Save &amp; test bucket</b> checks all of the above with the stored key.</p>
			@endif
		</div>

		<div class="tab-pane {{ $Page->getRecoveryKit() ? 'active' : '' }}" id="recovery" role="tabpanel">
			<p>Every repository password derives from one master secret kept in the ApisCP keyring. The S3 key can be replaced in Wasabi; the master cannot.
				Store the kit below in a password manager. Without it nothing in the bucket can be read.</p>
			@if (!$Page->getRecoveryKit())
				<button type="submit" name="reveal" value="1" class="btn btn-secondary warn" data-prompt="Show the master secret on screen?">Show recovery kit</button>
			@else
				@php $kit = $Page->getRecoveryKit(); @endphp
				<div class="alert alert-warning">Shown for this page view only. Copy it into 1Password now.</div>
				<table class="table w-auto">
					<tbody>
						<tr><th class="pr-4">Master secret</th><td><code>{{ $kit['master'] }}</code></td></tr>
						<tr><th class="pr-4">Password derivation</th><td>{{ $kit['derivation'] }}</td></tr>
						<tr><th class="pr-4">Endpoint</th><td>{{ $kit['endpoint'] }}</td></tr>
						<tr><th class="pr-4">Region</th><td>{{ $kit['region'] }}</td></tr>
						<tr><th class="pr-4">Bucket</th><td>{{ $kit['bucket'] }}</td></tr>
						<tr><th class="pr-4">Prefix</th><td>{{ $kit['prefix'] }}&lt;domain&gt;/</td></tr>
					</tbody>
				</table>
				<h4>Read a site's repository from any machine</h4>
				<p>Needs only <code>kopia</code> and an S3 key with read access. Example for {{ $kit['example_domain'] }}:</p>
<pre>DOMAIN={{ $kit['example_domain'] }}
MASTER={{ $kit['master'] }}
export KOPIA_PASSWORD=$(printf %s "$DOMAIN" | openssl dgst -sha256 -hmac "$MASTER" | awk '{print $NF}')
export AWS_ACCESS_KEY_ID=... AWS_SECRET_ACCESS_KEY=...
kopia repository connect s3 --bucket {{ $kit['bucket'] }} --endpoint {{ parse_url($kit['endpoint'], PHP_URL_HOST) }} --region {{ $kit['region'] }} --prefix {{ $kit['prefix'] }}$DOMAIN/
kopia snapshot list
kopia restore &lt;snapshot-id&gt; /root/restore/$DOMAIN</pre>
				<p>Derived password for {{ $kit['example_domain'] }}, to check the formula: <code>{{ $kit['example_password'] }}</code></p>
				<h4>Rebuilt server</h4>
				<p>Install the add-in, enter the same Storage settings, then <code>cpcmd kopiabackup:import-master {{ $kit['master'] }}</code>. The next run reconnects to each existing repository.</p>
			@endif
		</div>
	</div>
</form>
