<?php declare(strict_types=1);

namespace apps\kopiabackup;

use KopiaBackup\BucketCheck;
use Page_Container;

class Page extends Page_Container
{
	public const FIELDS = [
		'endpoint', 'region', 'bucket', 'prefix', 'access_key', 'secret_key', 'lock_mode', 'lock_days', 'keep_days',
		'kopia_version', 'parallel', 'verify_day', 'verify_percent', 'ignore',
	];

	public const GROUPS = [
		'bucket' => [
			'Bucket configuration',
			'Fix these in Wasabi with an admin key. The backup key only reads them.',
		],
		'allow'  => [
			'Permissions the key needs',
			'A failure here means the policy is missing an action Kopia needs. Add it to the policy.',
		],
		'deny'   => [
			'Permissions the key must not have',
			'These operations are supposed to be refused. A failure means the key can do more than the generated policy allows: it is a root key, or the user has another policy attached.',
		],
	];

	/** check => [group, label, what it means] */
	public const CHECKS = [
		'versioning'            => ['bucket', 'Versioning enabled', 'Object lock requires versioning; overwrites keep the old version.'],
		'object_lock'           => ['bucket', 'Object lock enabled', 'Must be turned on when the bucket is created. Kopia locks each object it writes.'],
		'default_retention'     => ['bucket', 'No default retention', 'Kopia sets its own retention. A bucket default would lock everything, including test probes.'],
		'lifecycle'             => ['bucket', 'Lifecycle rule', 'Removes old versions after the lock expires. Must not expire current versions, and no overlapping rule may run shorter than lock + 1 days.'],
		'key_access'            => ['allow', 'Read, list versions, delete', 'PutObject, GetObjectVersion, ListBucketVersions and DeleteObject: backups, maintenance and point-in-time recovery.'],
		'delete_version_denied' => ['deny', 'Delete a specific version', 'Deleting a version destroys data permanently. Expected: AccessDenied.'],
		'retention_locked'      => ['deny', 'Shorten or bypass a lock', 'Extending a lock must work (Kopia renews locks nightly). Shortening it or deleting the locked version with the bypass flag must be refused.'],
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
		// posted token preferred; the cookie (core's own criterion) is accepted so a rotated session token
		// does not strand a form that was rendered before the rotation
		$session = (string)\Session::token();
		$posted = (string)($_POST[\Auth_UI::CSRF_KEY] ?? '');
		$cookie = (string)($_COOKIE[\Auth_UI::CSRF_COOKIE] ?? '');
		if ($session === '' || !(($posted !== '' && hash_equals($session, $posted)) || ($cookie !== '' && hash_equals($session, $cookie)))) {
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
		if (array_key_exists('timer_form', $params) && !$this->kopiabackup_timer_set(!empty($params['timer_enabled']))) {
			return false;
		}

		return success('Settings saved');
	}

	/**
	 * Queue a backup of one site as a panel job
	 */
	public function handleBackup(array $params): bool
	{
		return $this->kopiabackup_schedule((string)$params['backup']);
	}

	protected ?array $kit = null;

	/**
	 * Show the recovery kit on this request only; it is never rendered unasked
	 */
	public function handleReveal(array $params): bool
	{
		$kit = $this->kopiabackup_recovery_kit();
		if (!is_array($kit)) {
			return false;
		}
		$this->kit = $kit;

		return true;
	}

	public function getRecoveryKit(): ?array
	{
		return $this->kit;
	}

	public function handleBackupAll(array $params): bool
	{
		return $this->kopiabackup_schedule_all();
	}

	public function handleVerify(array $params): bool
	{
		return $this->kopiabackup_schedule_verify((string)$params['verify']);
	}

	public function handleVerifyAll(array $params): bool
	{
		return $this->kopiabackup_schedule_verify_all();
	}

	protected ?string $editSite = null;

	public function handleEditIgnores(array $params): bool
	{
		$this->editSite = (string)$params['edit_ignores'];

		return true;
	}

	public function handleSaveSiteIgnores(array $params): bool
	{
		$domain = (string)($params['site_ignore_domain'] ?? '');
		$map = (array)($this->getSettings()['site_ignores'] ?? []);
		$map[$domain] = (string)($params['site_ignore_text'] ?? '');
		if (!$this->kopiabackup_set_settings(['site_ignores' => $map])) {
			$this->editSite = $domain;

			return false;
		}

		return success('Ignore rules saved for %s', $domain);
	}

	public function getEditSite(): ?string
	{
		return $this->editSite;
	}

	public function getSites(): array
	{
		return (array)$this->kopiabackup_status();
	}

	public function isTimerEnabled(): bool
	{
		return (bool)$this->kopiabackup_timer_enabled();
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

	public function getKopiaVersions(): array
	{
		return (array)$this->kopiabackup_kopia_versions() + ['installed' => null, 'latest' => null, 'available' => []];
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
