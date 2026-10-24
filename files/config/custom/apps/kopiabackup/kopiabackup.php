<?php declare(strict_types=1);

namespace apps\kopiabackup;

use KopiaBackup\BucketCheck;
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
		'key_access'            => 'Key can read, list versions, delete',
		'delete_version_denied' => 'Key denied DeleteObjectVersion',
		'retention_locked'      => 'Key extends but cannot shorten or bypass locks',
	];

	protected ?array $testResults = null;

	public function __construct()
	{
		parent::__construct();
		// the menu only lists this app for admins, but the URL resolves for any login
		if (!($this->getAuthContext()->level & PRIVILEGE_ADMIN)) {
			fatal('Kopia Backup is restricted to the server administrator');
		}
	}

	/**
	 * Page_Container treats query-string parameters as a postback and accepts the CSRF cookie alone,
	 * so a crafted link could change settings. Only act on a POST carrying the session token.
	 */
	public function on_postback($params)
	{
		if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
			return true;
		}
		$token = (string)($_POST[\Auth_UI::CSRF_KEY] ?? '');
		if ($token === '' || !hash_equals((string)\Session::token(), $token)) {
			return error('CSRF token mismatch; reload the page and try again');
		}

		return $this->dispatchPostback($_POST);
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

	/**
	 * Save, then test with what was saved so the result matches the form
	 */
	public function handleTest(array $params): bool
	{
		if (!$this->handleSave($params)) {
			return false;
		}
		$results = $this->kopiabackup_test_bucket();
		if (!is_array($results)) {
			return false;
		}
		$this->testResults = $results;

		return BucketCheck::passed($results);
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

	/**
	 * IAM policy for the backup key: bucket-level reads, and objects only under this server's prefix
	 */
	public function getKeyPolicy(string $bucket, string $prefix): string
	{
		$arn = "arn:aws:s3:::{$bucket}";

		return $this->json([
			'Version'   => '2012-10-17',
			'Statement' => [
				[
					'Sid'      => 'BucketRead',
					'Effect'   => 'Allow',
					'Action'   => [
						's3:ListBucket', 's3:ListBucketVersions', 's3:ListBucketMultipartUploads', 's3:GetBucketLocation',
						's3:GetBucketVersioning', 's3:GetBucketObjectLockConfiguration', 's3:GetLifecycleConfiguration',
					],
					'Resource' => $arn,
				],
				[
					'Sid'      => 'ObjectReadWrite',
					'Effect'   => 'Allow',
					'Action'   => [
						's3:GetObject', 's3:GetObjectVersion', 's3:PutObject', 's3:DeleteObject',
						's3:AbortMultipartUpload', 's3:ListMultipartUploadParts',
						's3:GetObjectRetention', 's3:PutObjectRetention',
					],
					'Resource' => "{$arn}/{$prefix}*",
				],
			],
		]);
	}

	public function getLifecycleRule(string $prefix, int $lockDays): string
	{
		return $this->json(['Rules' => [[
			'ID'                             => 'kopia-cleanup-' . rtrim(str_replace('/', '-', $prefix), '-'),
			'Status'                         => 'Enabled',
			'Filter'                         => ['Prefix' => $prefix],
			'NoncurrentVersionExpiration'    => ['NoncurrentDays' => $lockDays + 1],
			'Expiration'                     => ['ExpiredObjectDeleteMarker' => true],
			'AbortIncompleteMultipartUpload' => ['DaysAfterInitiation' => 7],
		]]]);
	}

	private function json(array $data): string
	{
		return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	}
}
