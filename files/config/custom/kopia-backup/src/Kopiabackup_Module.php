<?php declare(strict_types=1);

use KopiaBackup\BucketCheck;
use KopiaBackup\Settings;

class Kopiabackup_Module extends \Module_Skeleton
{
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
}
