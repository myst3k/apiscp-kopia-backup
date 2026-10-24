@php
	$s = $Page->getSettings();
	$version = $Page->getKopiaVersion();
	$results = $Page->getTestResults();
	$badge = ['pass' => 'success', 'fail' => 'danger', 'skip' => 'secondary'];
	$setupTab = isset($_GET['tab']) && $_GET['tab'] === 'setup';
@endphp

<div class="row mb-3">
	<div class="col-12">
		<h5>
			Kopia
			@if ($version)
				<span class="badge badge-success">{{ $version }}</span>
			@else
				<span class="badge badge-danger">not installed</span>
			@endif
		</h5>
	</div>
</div>

<ul class="nav nav-tabs mb-3" role="tablist">
	<li class="nav-item">
		<a class="nav-link {{ $setupTab ? '' : 'active' }}" data-toggle="tab" href="#tab-settings" role="tab">Settings</a>
	</li>
	<li class="nav-item">
		<a class="nav-link {{ $setupTab ? 'active' : '' }}" data-toggle="tab" href="#tab-setup" role="tab">Bucket setup</a>
	</li>
</ul>

<div class="tab-content">
<div class="tab-pane {{ $setupTab ? '' : 'active' }}" id="tab-settings" role="tabpanel">
<form method="post">
	<h4>Storage</h4>
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
		<div class="col-12">
			<small class="form-text text-muted mb-3">Save the bucket first, then create the key from the policy on the <a href="#tab-setup" data-toggle="tab">Bucket setup</a> tab.</small>
		</div>
	</div>

	<h4>Retention</h4>
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
		<div class="col-12">
			<small class="form-text text-muted mb-3">Lock settings apply to repositories when they are created; existing repositories keep theirs.</small>
		</div>
	</div>

	<div class="btn-group mb-4">
		<button type="submit" name="save" value="1" class="btn btn-primary">Save</button>
		<button type="submit" name="test" value="1" class="btn btn-secondary" {{ $s['access_key'] && $s['secret_key'] ? '' : 'disabled' }}>Save &amp; test bucket</button>
	</div>
</form>

@if ($results)
	<h4>Bucket test: {{ $s['bucket'] }}</h4>
	<table class="table table-sm table-responsive-md mb-4">
		<tbody>
			@foreach ($Page::CHECKS as $key => $label)
				@php $r = $results[$key] ?? ['status' => 'skip', 'detail' => 'not run']; @endphp
				<tr>
					<td class="w-25">{{ $label }}</td>
					<td><span class="badge badge-{{ $badge[$r['status']] ?? 'secondary' }} text-uppercase">{{ $r['status'] }}</span></td>
					<td>{{ $r['detail'] }}</td>
				</tr>
			@endforeach
		</tbody>
	</table>
@endif
</div>

<div class="tab-pane {{ $setupTab ? 'active' : '' }}" id="tab-setup" role="tabpanel">
@if (!$s['bucket'])
	<p class="text-muted">Save a bucket name on the Settings tab first. The policy and lifecycle rule below are generated for it.</p>
@else
	<h4>1. Bucket</h4>
	<p>Create <code>{{ $s['bucket'] }}</code> with object lock enabled. Leave default retention off; Kopia locks each object itself.</p>

	<h4>2. Backup key policy</h4>
	<p>Policies &rarr; Create Policy, paste this. Then create a user with programmatic access only, attach the policy, generate a key and enter it on the Settings tab.</p>
	<div class="position-relative mb-4">
		<button type="button" class="btn btn-sm btn-secondary position-absolute" style="top:.5rem;right:.5rem"
		        onclick="navigator.clipboard.writeText(document.getElementById('key-policy').textContent).then(()=>{this.textContent='Copied';setTimeout(()=>this.textContent='Copy',1500)})">Copy</button>
		<pre class="p-3 border rounded bg-light"><code id="key-policy">{{ $Page->getKeyPolicy($s['bucket']) }}</code></pre>
	</div>

	<h4>3. Lifecycle rule</h4>
	<p>Apply with an admin key, not the backup key. Noncurrent versions expire after lock + 1 days ({{ (int)$s['lock_days'] + 1 }}). Never add a rule that expires current versions.</p>
	<div class="position-relative mb-4">
		<button type="button" class="btn btn-sm btn-secondary position-absolute" style="top:.5rem;right:.5rem"
		        onclick="navigator.clipboard.writeText(document.getElementById('lifecycle').textContent).then(()=>{this.textContent='Copied';setTimeout(()=>this.textContent='Copy',1500)})">Copy</button>
		<pre class="p-3 border rounded bg-light"><code id="lifecycle">{{ $Page->getLifecycleRule((string)$s['prefix'], (int)$s['lock_days']) }}</code></pre>
	</div>
	<pre class="mb-4"><code>aws s3api put-bucket-lifecycle-configuration --endpoint-url {{ $s['endpoint'] }} --bucket {{ $s['bucket'] }} --lifecycle-configuration file://lifecycle.json</code></pre>

	<h4>4. Verify</h4>
	<p>Back on Settings, <b>Save &amp; test bucket</b> checks all of the above with the stored key.</p>
@endif
</div>
</div>
