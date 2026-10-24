<?php declare(strict_types=1);

use KopiaBackup\BucketCheck;
use KopiaBackup\Runner;
use KopiaBackup\Settings;
use Opcenter\Account\Enumerate;
use Opcenter\Crypto\Keyring;

class Kopiabackup_Module extends \Module_Skeleton
{
	private const MASTER_KEY = 'kopiabackup.master';
	private const TIMER = 'kopia-backup.timer';

	protected $exportedFunctions = [
		'*' => PRIVILEGE_ADMIN,
	];

	/**
	 * Show settings, secrets masked
	 */
	public function get_settings(): array
	{
		if (!IS_CLI) {
			return $this->query('kopiabackup_get_settings');
		}
		$s = Settings::load();
		$s['secret_key'] = $s['secret_key'] ? '********' : null;

		return $s;
	}

	/**
	 * Store settings; empty value reverts a key to its default
	 *
	 * @param array $values setting => value
	 */
	public function set_settings(array $values): bool
	{
		if (!IS_CLI) {
			return $this->query('kopiabackup_set_settings', $values);
		}

		return Settings::save($values);
	}

	public function set_setting(string $key, mixed $value): bool
	{
		return $this->set_settings([$key => $value]);
	}

	/**
	 * Installed Kopia version plus what the repo offers
	 *
	 * @return array{installed: ?string, latest: ?string, available: string[]}
	 */
	public function kopia_versions(): array
	{
		if (!IS_CLI) {
			return $this->query('kopiabackup_kopia_versions');
		}
		$installed = null;
		$ret = \Util_Process_Safe::exec(['kopia', '--version']);
		if ($ret['success']) {
			$installed = strtok(trim($ret['stdout']), ' ') ?: null;
		}
		$available = $this->availableKopiaVersions();

		return ['installed' => $installed, 'latest' => $available[0] ?? null, 'available' => $available];
	}

	/**
	 * Versions in the Kopia repo, newest first, cached for an hour since dnf may refresh metadata
	 */
	private function availableKopiaVersions(): array
	{
		$cache = '/var/cache/kopia-backup/versions.json';
		if (is_file($cache) && filemtime($cache) > time() - 3600) {
			return (array)json_decode((string)file_get_contents($cache), true);
		}
		// "dnf list" output is parsed rather than repoquery --qf because the % in a query format
		// collides with Util_Process's printf-style argument handling
		$ret = \Util_Process_Safe::exec(['dnf', '-q', '--disablerepo=*', '--enablerepo=kopia', 'list', '--available', '--showduplicates', 'kopia']);
		if (!$ret['success']) {
			warn('could not query Kopia repo: %s', trim($ret['stderr']));

			return [];
		}
		$versions = [];
		foreach (preg_split('/\R/', $ret['stdout']) as $line) {
			$cols = preg_split('/\s+/', trim($line));
			if (str_starts_with($cols[0], 'kopia.') && isset($cols[1])) {
				$versions[] = strtok($cols[1], '-');
			}
		}
		$versions = array_values(array_unique($versions));
		usort($versions, static fn($a, $b) => version_compare($b, $a));
		file_put_contents($cache, json_encode($versions));

		return $versions;
	}

	/**
	 * Verify bucket versioning, object lock, default retention, lifecycle and key permissions.
	 * Raises an error when any check fails so cpcmd exits non-zero; the per-check detail is still returned.
	 *
	 * @return array|bool per-check status/detail
	 */
	public function test_bucket()
	{
		if (!IS_CLI) {
			return $this->query('kopiabackup_test_bucket');
		}
		$s = Settings::load();
		if ($missing = Settings::missing($s)) {
			return error('missing settings: %s', implode(', ', $missing));
		}

		try {
			$results = (new BucketCheck(
				$s['endpoint'], $s['region'], $s['bucket'], $s['prefix'],
				$s['access_key'], $s['secret_key'], (int)$s['lock_days']
			))->run();
		} catch (\Throwable $e) {
			return error('bucket check aborted: %s', $e->getMessage());
		}

		if ($failed = BucketCheck::failures($results)) {
			foreach ($failed as $name) {
				warn('%s: %s', $name, $results[$name]['detail']);
			}
			error('bucket verification failed: %s', implode(', ', $failed));
		}

		return $results;
	}

	/**
	 * Back up one site now, in the foreground
	 *
	 * @param string $site domain or siteN
	 * @return array|bool run report
	 */
	public function run(string $site)
	{
		if (!IS_CLI) {
			return $this->query('kopiabackup_run', $site);
		}
		if (null === ($id = \Auth::get_site_id_from_anything($site))) {
			return error("unknown site `%s'", $site);
		}
		$s = Settings::load();
		if ($missing = Settings::missing($s)) {
			return error('missing settings: %s', implode(', ', $missing));
		}

		try {
			$report = (new Runner($s, "site{$id}", $this->master()))->run();
		} catch (\Throwable $e) {
			return error('%s: %s', $site, $e->getMessage());
		}
		info('%s: backup complete, %d database(s), %d snapshot(s)', $report['domain'], count($report['databases'] ?? []), count($report['snapshots'] ?? []));

		return $report;
	}

	/**
	 * Back up every active site, one after another. Errors are collected so one site cannot stop the rest.
	 *
	 * @return array site => ok|failed
	 */
	public function run_all(): array
	{
		if (!IS_CLI) {
			return $this->query('kopiabackup_run_all');
		}
		$out = [];
		foreach (Enumerate::active() as $site) {
			$report = $this->run($site);
			$out[$site] = is_array($report) && !empty($report['ok']) ? 'ok' : 'failed';
		}
		if ($failed = array_keys($out, 'failed', true)) {
			error('backup failed for: %s', implode(', ', $failed));
		}

		return $out;
	}

	/**
	 * Queue a site backup as a panel job so the UI returns at once
	 */
	public function schedule(string $site): bool
	{
		if (null === \Auth::get_site_id_from_anything($site)) {
			return error("unknown site `%s'", $site);
		}
		$job = \Lararia\Jobs\Job::create(\Lararia\Jobs\SimpleCommandJob::class, $this->getAuthContext(), 'kopiabackup_run', $site);
		$job->timeout = 6 * 3600;
		$job->setTags(['kopia-backup', $site]);
		$job->dispatch();

		return info('backup queued for %s', $site);
	}

	/**
	 * Per-site last run summary
	 *
	 * @return array siteN => {domain, running, ok, finished, started, size, databases, error}
	 */
	public function status(): array
	{
		if (!IS_CLI) {
			return $this->query('kopiabackup_status');
		}
		$out = [];
		foreach (Enumerate::active() as $site) {
			$st = Runner::status($site);
			$size = 0;
			foreach ((array)($st['snapshots'] ?? []) as $snap) {
				$size += (int)($snap['size'] ?? 0);
			}
			$out[$site] = [
				'domain'    => (string)(new \Opcenter\SiteConfiguration($site))->getServiceValue('siteinfo', 'domain'),
				'running'   => $st['running'],
				'ok'        => $st['ok'],
				'started'   => $st['started'] ?? null,
				'finished'  => $st['finished'],
				'size'      => $size,
				'databases' => count((array)($st['databases'] ?? [])),
				'error'     => $st['error'] ?? null,
			];
		}

		return $out;
	}

	public function timer_enabled(): bool
	{
		if (!IS_CLI) {
			return $this->query('kopiabackup_timer_enabled');
		}

		return \Util_Process_Safe::exec(['systemctl', 'is-enabled', '--quiet', self::TIMER])['success'];
	}

	/**
	 * Enable or disable the nightly timer
	 */
	public function timer_set(bool $enabled): bool
	{
		if (!IS_CLI) {
			return $this->query('kopiabackup_timer_set', $enabled);
		}
		if ($enabled === $this->timer_enabled()) {
			return true;
		}
		$ret = \Util_Process_Safe::exec(['systemctl', $enabled ? 'enable' : 'disable', '--now', self::TIMER]);
		if (!$ret['success']) {
			return error('systemctl failed: %s', trim($ret['stderr']));
		}

		return info('nightly backup timer %s', $enabled ? 'enabled' : 'disabled');
	}

	/**
	 * Everything needed to read the repositories from another machine. Store it in a password manager.
	 *
	 * @return array{master: string, derivation: string, endpoint: string, region: string, bucket: string, prefix: string, example_domain: ?string, example_password: ?string}
	 */
	public function recovery_kit(): array
	{
		if (!IS_CLI) {
			return $this->query('kopiabackup_recovery_kit');
		}
		$s = Settings::load();
		$master = $this->master();
		$sites = Enumerate::active();
		$domain = $sites ? (string)(new \Opcenter\SiteConfiguration($sites[0]))->getServiceValue('siteinfo', 'domain') : null;

		return [
			'master'           => $master,
			'derivation'       => 'repository password = hex(HMAC-SHA256(key: master, message: domain))',
			'endpoint'         => (string)$s['endpoint'],
			'region'           => (string)$s['region'],
			'bucket'           => (string)$s['bucket'],
			'prefix'           => (string)$s['prefix'],
			'example_domain'   => $domain,
			'example_password' => $domain ? hash_hmac('sha256', $domain, $master) : null,
		];
	}

	/**
	 * Install a master secret from a recovery kit, e.g. on a rebuilt server. Refuses to overwrite one that exists.
	 */
	public function import_master(string $master): bool
	{
		if (!IS_CLI) {
			return $this->query('kopiabackup_import_master', $master);
		}
		if (!preg_match('/^[0-9a-f]{64}$/', $master)) {
			return error('master must be 64 hex characters');
		}
		if ($this->keyring_get(self::MASTER_KEY)) {
			return error('a master secret already exists; remove it first with cpcmd keyring:forget %s', self::MASTER_KEY);
		}

		return $this->keyring_set(self::MASTER_KEY, $master);
	}

	/**
	 * Master secret every repository password derives from. Created on first use.
	 * Reveal with `cpcmd keyring:get kopiabackup.master` and store it in a password manager: without it
	 * nothing in the bucket can be read.
	 */
	private function master(): string
	{
		if ($ref = $this->keyring_get(self::MASTER_KEY)) {
			return (string)Keyring::decode($ref);
		}
		$new = bin2hex(random_bytes(32));
		if (!$this->keyring_set(self::MASTER_KEY, $new)) {
			throw new \RuntimeException('failed to store master secret in keyring');
		}
		warn('created master backup secret; save it now: cpcmd keyring:get %s', self::MASTER_KEY);

		return $new;
	}
}
