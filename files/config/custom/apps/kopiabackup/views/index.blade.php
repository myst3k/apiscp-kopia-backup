@php
	$s = $Page->getSettings();
	$version = $Page->getKopiaVersion();
	$results = $Page->getTestResults();
	$badge = ['pass' => 'success', 'fail' => 'danger', 'skip' => 'secondary'];
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
			<input type="text" class="form-control" id="access_key" name="access_key" value="{{ $s['access_key'] }}" autocomplete="off" required/>
		</div>
		<div class="form-group col-md-3">
			<label for="secret_key">Secret key</label>
			<input type="password" class="form-control" id="secret_key" name="secret_key" value="" autocomplete="new-password"
			       placeholder="{{ $s['secret_key'] ? 'stored, leave blank to keep' : '' }}" {{ $s['secret_key'] ? '' : 'required' }}/>
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
		<button type="submit" name="test" value="1" class="btn btn-secondary" formnovalidate>Test bucket</button>
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

<h4>Bucket setup</h4>
<p class="mb-2">Create the bucket with object lock enabled and leave default retention off. Apply this lifecycle rule with an admin key:</p>
<pre class="mb-2"><code>{{ json_encode(['Rules' => [[
	'ID' => 'kopia-cleanup',
	'Status' => 'Enabled',
	'Filter' => ['Prefix' => (string)$s['prefix']],
	'NoncurrentVersionExpiration' => ['NoncurrentDays' => (int)$s['lock_days'] + 1],
	'Expiration' => ['ExpiredObjectDeleteMarker' => true],
	'AbortIncompleteMultipartUpload' => ['DaysAfterInitiation' => 7],
]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</code></pre>
<p class="text-muted">The backup key needs Get/Put/List/DeleteObject, PutObjectRetention and GetObjectRetention, plus read access to the bucket's
	versioning, object lock and lifecycle configuration. Deny DeleteObjectVersion and every Put on bucket configuration.</p>
