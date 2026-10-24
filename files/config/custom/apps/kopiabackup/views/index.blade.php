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
			<a href="#sites" data-toggle="pill" class="nav-link active" data-parent="#menu" role="tab">Sites</a>
		</li>
		<li class="nav-item block">
			<a href="#storage" data-toggle="pill" class="nav-link" data-parent="#menu" role="tab">Storage</a>
		</li>
		<li class="nav-item block">
			<a href="#retention" data-toggle="pill" class="nav-link" data-parent="#menu" role="tab">Retention</a>
		</li>
		<li class="nav-item block">
			<a href="#kopia" data-toggle="pill" class="nav-link" data-parent="#menu" role="tab">Kopia</a>
		</li>
		<li class="nav-item block">
			<a href="#setup" data-toggle="pill" class="nav-link" data-parent="#menu" role="tab">Bucket setup</a>
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
		<div class="tab-pane active" id="sites" role="tabpanel">
			@php $sites = $Page->getSites(); @endphp
			@if (!$s['bucket'] || !$s['access_key'])
				<div class="alert alert-info">Configure Storage and pass the bucket test before running backups.</div>
			@endif
			<table class="table">
				<thead>
					<tr><th>Site</th><th>Last backup</th><th>Result</th><th>Databases</th><th>Size</th><th></th></tr>
				</thead>
				<tbody>
					@foreach ($sites as $siteId => $st)
						<tr>
							<td>{{ $st['domain'] }}</td>
							<td>{{ $st['finished'] ? date('M j, Y H:i', $st['finished']) : 'never' }}</td>
							<td>
								@if ($st['running'])
									<span class="badge badge-info">running</span>
								@elseif ($st['ok'] === true)
									<span class="badge badge-success">ok</span>
								@elseif ($st['ok'] === false)
									<span class="badge badge-danger" title="{{ $st['error'] }}">failed</span>
								@endif
							</td>
							<td>{{ $st['finished'] ? $st['databases'] : '' }}</td>
							<td>{{ $st['size'] ? \Formatter::reduceBytes($st["size"]) : '' }}</td>
							<td class="text-right">
								<button type="submit" name="backup" value="{{ $st['domain'] }}" class="btn btn-secondary btn-sm"
									{{ $st['running'] || !$s['bucket'] ? 'disabled' : '' }}>Back up now</button>
							</td>
						</tr>
					@endforeach
				</tbody>
			</table>
			<p class="text-muted">Back up now queues a job; watch it in the job indicator at the top of the panel.</p>
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
			<input type="hidden" name="timer_form" value="1"/>
			<div class="form-group custom-control custom-checkbox">
				<input type="checkbox" class="custom-control-input" id="timer_enabled" name="timer_enabled" value="1" {{ $Page->isTimerEnabled() ? 'checked' : '' }}/>
				<label class="custom-control-label" for="timer_enabled">Nightly backup of every site at 02:30</label>
			</div>
			<p class="text-muted">Every repository password derives from one master secret in the keyring. Save it in a password manager:
				<code>cpcmd keyring:get kopiabackup.master</code></p>
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
	</div>
</form>
