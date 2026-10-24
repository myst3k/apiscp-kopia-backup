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
	 * Installed Kopia version
	 */
	public function kopia_version(): ?string
	{
		if (!IS_CLI) {
			return $this->query('kopiabackup_kopia_version');
		}
		$ret = \Util_Process_Safe::exec(['kopia', '--version']);
		if (!$ret['success']) {
			return null;
		}

		return strtok(trim($ret['stdout']), ' ') ?: null;
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
