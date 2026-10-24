<?php declare(strict_types=1);

namespace apps\kopiabackup;

use Page_Container;

class Page extends Page_Container
{
	public const FIELDS = [
		'endpoint', 'region', 'bucket', 'prefix', 'access_key', 'secret_key', 'lock_mode', 'lock_days', 'keep_days',
	];

	public const CHECKS = [
		'versioning'            => 'Versioning enabled',
		'object_lock'           => 'Object lock enabled',
		'default_retention'     => 'No default retention',
		'lifecycle'             => 'Lifecycle rule',
		'delete_version_denied' => 'Key denied DeleteObjectVersion',
	];

	protected ?array $testResults = null;

	public function on_postback($params)
	{
		return $this->dispatchPostback($params);
	}

	public function handleSave(array $params): bool
	{
		$values = array_intersect_key($params, array_flip(self::FIELDS));
		// a blank secret field means "keep the stored one"
		if (($values['secret_key'] ?? '') === '') {
			unset($values['secret_key']);
		}
		if (!$this->kopiabackup_set_settings($values)) {
			return false;
		}

		return success('Settings saved');
	}

	public function handleTest(array $params): bool
	{
		$this->testResults = $this->kopiabackup_test_bucket() ?: null;

		return $this->testResults !== null;
	}

	public function getSettings(): array
	{
		return (array)$this->kopiabackup_get_settings();
	}

	public function getKopiaVersion(): ?string
	{
		return $this->kopiabackup_kopia_version();
	}

	public function getTestResults(): ?array
	{
		return $this->testResults;
	}
}
