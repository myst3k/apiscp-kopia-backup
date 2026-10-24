<?php declare(strict_types=1);

namespace KopiaBackup;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;

/**
 * Verifies a bucket is safe to hold object-locked Kopia repos, using the backup key itself
 * so the result reflects what that key can and cannot do.
 */
class BucketCheck
{
	public const PASS = 'pass';
	public const FAIL = 'fail';
	public const SKIP = 'skip';

	private S3Client $s3;

	public function __construct(
		private string $endpoint,
		private string $region,
		private string $bucket,
		private string $prefix,
		string $accessKey,
		string $secretKey,
		private int $lockDays
	) {
		$this->s3 = new S3Client([
			'version'                       => '2006-03-01',
			'region'                        => $region,
			'endpoint'                      => $endpoint,
			'use_path_style_endpoint'       => true,
			'credentials'                   => ['key' => $accessKey, 'secret' => $secretKey],
			// SDK 3.337+ sends CRC32 trailers by default, which non-AWS S3 implementations may reject
			'request_checksum_calculation'  => 'when_required',
			'response_checksum_validation'  => 'when_required',
		]);
	}

	/**
	 * @return array<string, array{status: string, detail: string}>
	 */
	public function run(): array
	{
		$results = [
			'versioning'     => $this->checkVersioning(),
			'object_lock'    => $this->checkObjectLock(),
		];
		$results['default_retention'] = $this->checkDefaultRetention();
		$results['lifecycle'] = $this->checkLifecycle();
		// a bucket default would lock the probe, so a refused delete would prove nothing about the key
		$results['delete_version_denied'] = $results['default_retention']['status'] === self::PASS
			? $this->checkDeleteVersionDenied()
			: $this->result(self::SKIP, 'requires no default retention');
		$results['bypass_governance_denied'] = $results['object_lock']['status'] === self::PASS
			? $this->checkBypassGovernanceDenied()
			: $this->result(self::SKIP, 'requires object lock');

		return $results;
	}

	public static function passed(array $results): bool
	{
		foreach ($results as $r) {
			if ($r['status'] !== self::PASS) {
				return false;
			}
		}

		return true;
	}

	private function checkVersioning(): array
	{
		try {
			$status = $this->s3->getBucketVersioning(['Bucket' => $this->bucket])['Status'] ?? null;
		} catch (S3Exception $e) {
			return $this->awsFail($e);
		}

		return $status === 'Enabled'
			? $this->result(self::PASS, 'versioning enabled')
			: $this->result(self::FAIL, 'versioning status: ' . ($status ?? 'never enabled'));
	}

	private ?array $lockConfig = null;

	private function lockConfig(): array
	{
		return $this->lockConfig ??= (array)($this->s3->getObjectLockConfiguration(['Bucket' => $this->bucket])['ObjectLockConfiguration'] ?? []);
	}

	private function checkObjectLock(): array
	{
		try {
			$enabled = $this->lockConfig()['ObjectLockEnabled'] ?? null;
		} catch (S3Exception $e) {
			if ($e->getAwsErrorCode() === 'ObjectLockConfigurationNotFoundError') {
				return $this->result(self::FAIL, 'object lock not enabled; bucket must be recreated with object lock');
			}

			return $this->awsFail($e);
		}

		return $enabled === 'Enabled'
			? $this->result(self::PASS, 'object lock enabled')
			: $this->result(self::FAIL, 'object lock not enabled; bucket must be recreated with object lock');
	}

	private function checkDefaultRetention(): array
	{
		try {
			$retention = $this->lockConfig()['Rule']['DefaultRetention'] ?? null;
		} catch (S3Exception $e) {
			return $e->getAwsErrorCode() === 'ObjectLockConfigurationNotFoundError'
				? $this->result(self::SKIP, 'object lock not enabled')
				: $this->awsFail($e);
		}

		if (!$retention) {
			return $this->result(self::PASS, 'no default retention');
		}
		$span = isset($retention['Days']) ? $retention['Days'] . 'd' : ($retention['Years'] ?? '?') . 'y';

		return $this->result(self::FAIL, "default retention set ({$retention['Mode']} {$span}); remove it, Kopia locks each object itself");
	}

	private function checkLifecycle(): array
	{
		try {
			$rules = $this->s3->getBucketLifecycleConfiguration(['Bucket' => $this->bucket])['Rules'] ?? [];
		} catch (S3Exception $e) {
			return $e->getAwsErrorCode() === 'NoSuchLifecycleConfiguration'
				? $this->result(self::FAIL, 'no lifecycle configuration')
				: $this->awsFail($e);
		}

		$noncurrent = null;
		$markers = false;
		foreach ($rules as $rule) {
			if (($rule['Status'] ?? '') !== 'Enabled' || !$this->ruleCovers($rule)) {
				continue;
			}
			$exp = $rule['Expiration'] ?? [];
			// expiring current versions would delete-marker live pack blobs and break the repo once locks lapse
			if (isset($exp['Days']) || isset($exp['Date'])) {
				$id = $rule['ID'] ?? '(unnamed)';

				return $this->result(self::FAIL, "rule `{$id}' expires current versions; remove that action");
			}
			$markers = $markers || !empty($exp['ExpiredObjectDeleteMarker']);
			if (isset($rule['NoncurrentVersionExpiration']['NoncurrentDays'])) {
				$noncurrent = max($noncurrent ?? 0, (int)$rule['NoncurrentVersionExpiration']['NoncurrentDays']);
			}
		}

		$want = $this->lockDays + 1;
		if ($noncurrent === null) {
			return $this->result(self::FAIL, "no NoncurrentVersionExpiration covering {$this->prefix}; want {$want} days");
		}
		if ($noncurrent < $want) {
			return $this->result(self::FAIL, "NoncurrentDays {$noncurrent} < {$want} (lock + 1)");
		}
		if (!$markers) {
			return $this->result(self::FAIL, 'ExpiredObjectDeleteMarker not enabled');
		}

		return $this->result(self::PASS, "noncurrent versions expire after {$noncurrent}d, delete markers cleaned");
	}

	private function ruleCovers(array $rule): bool
	{
		$prefix = $rule['Filter']['Prefix'] ?? $rule['Filter']['And']['Prefix'] ?? $rule['Prefix'] ?? '';
		if (!empty($rule['Filter']['Tag']) || !empty($rule['Filter']['And']['Tags'])) {
			return false;
		}

		return str_starts_with($this->prefix, $prefix);
	}

	private function checkDeleteVersionDenied(): array
	{
		$key = $this->prefix . '.kopia-backup-probe/' . bin2hex(random_bytes(8));
		try {
			$version = $this->s3->putObject(['Bucket' => $this->bucket, 'Key' => $key, 'Body' => 'probe'])['VersionId'] ?? null;
		} catch (S3Exception $e) {
			return $this->result(self::FAIL, 'probe write failed: ' . $this->awsError($e));
		}
		if (!$version) {
			return $this->result(self::FAIL, 'probe write returned no VersionId');
		}

		try {
			$this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $key, 'VersionId' => $version]);

			return $this->result(self::FAIL, 'key can delete object versions; deny s3:DeleteObjectVersion');
		} catch (S3Exception $e) {
			if ($e->getAwsErrorCode() !== 'AccessDenied') {
				return $this->result(self::FAIL, 'unexpected delete error: ' . $this->awsError($e));
			}
		}

		// hide the probe; its version is removed by the noncurrent lifecycle rule
		try {
			$this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $key]);
		} catch (S3Exception) {
		}

		return $this->result(self::PASS, 'key denied DeleteObjectVersion');
	}

	/**
	 * Lock a probe for one day in GOVERNANCE mode, then try to delete it with the bypass flag
	 */
	private function checkBypassGovernanceDenied(): array
	{
		$key = $this->prefix . '.kopia-backup-probe/' . bin2hex(random_bytes(8));
		try {
			$version = $this->s3->putObject([
				'Bucket'                    => $this->bucket,
				'Key'                       => $key,
				'Body'                      => 'probe',
				'ObjectLockMode'            => 'GOVERNANCE',
				'ObjectLockRetainUntilDate' => new \DateTimeImmutable('+1 day'),
			])['VersionId'] ?? null;
		} catch (S3Exception $e) {
			return $this->result(self::FAIL, 'locked probe write failed: ' . $this->awsError($e));
		}
		if (!$version) {
			return $this->result(self::FAIL, 'probe write returned no VersionId');
		}

		try {
			$this->s3->deleteObject([
				'Bucket'                    => $this->bucket,
				'Key'                       => $key,
				'VersionId'                 => $version,
				'BypassGovernanceRetention' => true,
			]);

			return $this->result(self::FAIL, 'key deleted a locked version; remove s3:BypassGovernanceRetention and s3:DeleteObjectVersion');
		} catch (S3Exception $e) {
			if ($e->getAwsErrorCode() !== 'AccessDenied') {
				return $this->result(self::FAIL, 'unexpected delete error: ' . $this->awsError($e));
			}
		}

		// the locked version expires in a day; the lifecycle rule removes it afterwards
		try {
			$this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $key]);
		} catch (S3Exception) {
		}

		return $this->result(self::PASS, 'locked version could not be deleted');
	}

	private function result(string $status, string $detail): array
	{
		return ['status' => $status, 'detail' => $detail];
	}

	private function awsFail(S3Exception $e): array
	{
		return $this->result(self::FAIL, $this->awsError($e));
	}

	private function awsError(S3Exception $e): string
	{
		return ($e->getAwsErrorCode() ?? 'error') . ': ' . ($e->getAwsErrorMessage() ?: $e->getMessage());
	}
}
