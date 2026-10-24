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

	public const CHECKS = [
		'versioning', 'object_lock', 'default_retention', 'lifecycle',
		'key_access', 'delete_version_denied', 'retention_locked',
	];

	private const PROBE_BODY = 'kopia-backup probe';

	private S3Client $s3;
	private ?array $lockConfig = null;

	public function __construct(
		string $endpoint,
		string $region,
		private string $bucket,
		private string $prefix,
		string $accessKey,
		string $secretKey,
		private int $lockDays
	) {
		$this->s3 = new S3Client([
			'version'                      => '2006-03-01',
			'region'                       => $region,
			'endpoint'                     => $endpoint,
			'use_path_style_endpoint'      => true,
			'credentials'                  => ['key' => $accessKey, 'secret' => $secretKey],
			// SDK 3.337+ sends CRC32 trailers by default, which non-AWS S3 implementations may reject
			'request_checksum_calculation' => 'when_required',
			'response_checksum_validation' => 'when_required',
			'retries'                      => 2,
			'http'                         => ['connect_timeout' => 10, 'timeout' => 30],
		]);
	}

	/**
	 * Retention on a sample of objects under a repo prefix: every object Kopia wrote must carry a lock
	 * in the configured mode that reaches at least lock_days from when it was written.
	 *
	 * @return array{prefix: string, objects: int, sampled: int, locked: int, unlocked: string[], wrong_mode: string[], short: string[], earliest_retain_until: ?string, mode: ?string}
	 */
	public function verifyLocks(string $repoPrefix, string $expectMode, int $sample = 25): array
	{
		$out = ['prefix' => $repoPrefix, 'objects' => 0, 'sampled' => 0, 'locked' => 0, 'exempt' => 0, 'unlocked' => [], 'wrong_mode' => [], 'short' => [], 'earliest_retain_until' => null, 'mode' => null];
		$objects = [];
		$token = null;
		do {
			$page = $this->s3->listObjectsV2(array_filter(['Bucket' => $this->bucket, 'Prefix' => $repoPrefix, 'ContinuationToken' => $token]));
			foreach ((array)($page['Contents'] ?? []) as $o) {
				$objects[] = $o;
			}
			$token = $page['NextContinuationToken'] ?? null;
		} while ($token);
		$out['objects'] = count($objects);
		if (!$objects) {
			return $out;
		}
		// newest objects first so a recent run is what gets checked, plus the format blob which is the oldest
		usort($objects, static fn($a, $b) => $b['LastModified'] <=> $a['LastModified']);
		$pick = array_slice($objects, 0, $sample);
		foreach ($objects as $o) {
			if (str_ends_with($o['Key'], 'kopia.repository')) {
				$pick[] = $o;
				break;
			}
		}

		$earliest = null;
		foreach ($pick as $o) {
			// Kopia writes its uploaded CLI logs and the maintenance schedule blob without retention;
			// neither is needed to restore data
			$base = basename($o['Key']);
			if (str_starts_with($base, '_log_') || $base === 'kopia.maintenance') {
				$out['exempt']++;
				continue;
			}
			$out['sampled']++;
			try {
				$r = $this->s3->getObjectRetention(['Bucket' => $this->bucket, 'Key' => $o['Key']])['Retention'] ?? null;
			} catch (S3Exception $e) {
				$out['unlocked'][] = $o['Key'] . ' (' . ($e->getAwsErrorCode() ?? 'error') . ')';
				continue;
			}
			if (!$r || empty($r['RetainUntilDate'])) {
				$out['unlocked'][] = $o['Key'];
				continue;
			}
			$out['locked']++;
			$out['mode'] ??= $r['Mode'] ?? null;
			if (($r['Mode'] ?? '') !== $expectMode) {
				$out['wrong_mode'][] = $o['Key'] . ' (' . ($r['Mode'] ?? '?') . ')';
			}
			$until = $r['RetainUntilDate'] instanceof \DateTimeInterface ? $r['RetainUntilDate'] : new \DateTimeImmutable((string)$r['RetainUntilDate']);
			$written = $o['LastModified'] instanceof \DateTimeInterface ? $o['LastModified'] : new \DateTimeImmutable((string)$o['LastModified']);
			// a day of slack: Kopia stamps the lock from its own clock, listing gives the server's
			if ($until->getTimestamp() < $written->getTimestamp() + ($this->lockDays - 1) * 86400) {
				$out['short'][] = $o['Key'] . ' until ' . $until->format('Y-m-d');
			}
			if ($earliest === null || $until < $earliest) {
				$earliest = $until;
			}
		}
		$out['earliest_retain_until'] = $earliest?->format('Y-m-d H:i');

		return $out;
	}

	/**
	 * @return array<string, array{status: string, detail: string}>
	 */
	public function run(): array
	{
		$r = [
			'versioning'        => $this->checkVersioning(),
			'object_lock'       => $this->checkObjectLock(),
			'default_retention' => $this->checkDefaultRetention(),
			'lifecycle'         => $this->checkLifecycle(),
		];
		// a bucket default would lock the probes, so a refused delete would prove nothing about the key
		if ($r['default_retention']['status'] === self::PASS) {
			$r += $this->checkUnlockedProbe();
		} else {
			$r['key_access'] = $r['delete_version_denied'] = $this->result(self::SKIP, 'requires no default retention');
		}
		$r['retention_locked'] = $r['object_lock']['status'] === self::PASS
			? $this->checkRetentionLocked()
			: $this->result(self::SKIP, 'requires object lock');

		return $r;
	}

	public static function passed(array $results): bool
	{
		foreach (self::CHECKS as $name) {
			if (($results[$name]['status'] ?? null) !== self::PASS) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @return string[] names of checks that did not pass
	 */
	public static function failures(array $results): array
	{
		return array_values(array_filter(self::CHECKS, static fn($n) => ($results[$n]['status'] ?? null) !== self::PASS));
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

	/**
	 * Every enabled rule touching the repo prefix is inspected: S3 applies the shortest expiration
	 * when rules overlap, and a rule on a child prefix can delete repo data on its own.
	 */
	private function checkLifecycle(): array
	{
		try {
			$rules = $this->s3->getBucketLifecycleConfiguration(['Bucket' => $this->bucket])['Rules'] ?? [];
		} catch (S3Exception $e) {
			return $e->getAwsErrorCode() === 'NoSuchLifecycleConfiguration'
				? $this->result(self::FAIL, 'no lifecycle configuration')
				: $this->awsFail($e);
		}

		$want = $this->lockDays + 1;
		$coveringDays = null;
		$markers = false;
		foreach ($rules as $rule) {
			if (($rule['Status'] ?? '') !== 'Enabled') {
				continue;
			}
			$filter = $rule['Filter'] ?? [];
			$rulePrefix = $filter['Prefix'] ?? $filter['And']['Prefix'] ?? $rule['Prefix'] ?? '';
			if (!str_starts_with($this->prefix, $rulePrefix) && !str_starts_with($rulePrefix, $this->prefix)) {
				continue;
			}
			$id = $rule['ID'] ?? '(unnamed)';
			$exp = $rule['Expiration'] ?? [];
			// expiring current versions delete-markers live pack blobs and breaks the repo once locks lapse
			if (isset($exp['Days']) || isset($exp['Date'])) {
				return $this->result(self::FAIL, "rule `{$id}' (prefix `{$rulePrefix}') expires current versions; remove that action");
			}
			$days = isset($rule['NoncurrentVersionExpiration']['NoncurrentDays'])
				? (int)$rule['NoncurrentVersionExpiration']['NoncurrentDays'] : null;
			if ($days !== null && $days < $want) {
				return $this->result(self::FAIL, "rule `{$id}' (prefix `{$rulePrefix}') expires noncurrent versions after {$days}d; need >= {$want}d (lock + 1)");
			}

			$filtered = !empty($filter['Tag']) || !empty($filter['And']['Tags'])
				|| isset($filter['ObjectSizeGreaterThan']) || isset($filter['ObjectSizeLessThan'])
				|| isset($filter['And']['ObjectSizeGreaterThan']) || isset($filter['And']['ObjectSizeLessThan']);
			if ($filtered || !str_starts_with($this->prefix, $rulePrefix)) {
				continue;
			}
			// unfiltered rule covering every repo object: counts toward cleanup
			if ($days !== null) {
				$coveringDays = $coveringDays === null ? $days : min($coveringDays, $days);
			}
			$markers = $markers || !empty($exp['ExpiredObjectDeleteMarker']);
		}

		if ($coveringDays === null) {
			return $this->result(self::FAIL, "no unfiltered NoncurrentVersionExpiration covering `{$this->prefix}'; want {$want} days");
		}
		if (!$markers) {
			return $this->result(self::FAIL, 'ExpiredObjectDeleteMarker not enabled on a rule covering the prefix');
		}

		return $this->result(self::PASS, "noncurrent versions expire after {$coveringDays}d, delete markers cleaned");
	}

	/**
	 * Write an unlocked probe, exercise the reads Kopia and point-in-time recovery need,
	 * confirm its version cannot be deleted, then hide it with a plain delete.
	 *
	 * @return array{key_access: array, delete_version_denied: array}
	 */
	private function checkUnlockedProbe(): array
	{
		$key = $this->probeKey();
		try {
			$version = $this->putProbe($key);
		} catch (S3Exception $e) {
			$fail = $this->result(self::FAIL, 'probe write failed: ' . $this->awsError($e));

			return ['key_access' => $fail, 'delete_version_denied' => $this->result(self::SKIP, 'probe write failed')];
		}

		$access = [];
		try {
			$this->s3->getObject(['Bucket' => $this->bucket, 'Key' => $key, 'VersionId' => $version]);
		} catch (S3Exception $e) {
			$access[] = 'GetObjectVersion (' . $this->awsError($e) . ')';
		}
		try {
			$this->s3->listObjectVersions(['Bucket' => $this->bucket, 'Prefix' => $key]);
		} catch (S3Exception $e) {
			$access[] = 'ListBucketVersions (' . $this->awsError($e) . ')';
		}

		try {
			$this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $key, 'VersionId' => $version]);
			$denied = $this->result(self::FAIL, 'key can delete object versions; remove s3:DeleteObjectVersion');
		} catch (S3Exception $e) {
			$denied = $e->getAwsErrorCode() === 'AccessDenied'
				? $this->result(self::PASS, 'key denied DeleteObjectVersion')
				: $this->result(self::FAIL, 'unexpected delete error: ' . $this->awsError($e));
		}

		// a plain delete only adds a marker; Kopia maintenance relies on it
		try {
			$this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $key]);
		} catch (S3Exception $e) {
			$access[] = 'DeleteObject (' . $this->awsError($e) . ')';
		}

		return [
			'key_access'            => $access
				? $this->result(self::FAIL, 'key lacks: ' . implode('; ', $access))
				: $this->result(self::PASS, 'put, get by version, list versions, delete'),
			'delete_version_denied' => $denied,
		];
	}

	/**
	 * Lock a probe in GOVERNANCE mode, confirm the key can extend retention (Kopia renews locks
	 * this way) and cannot shorten it or delete the version even with the bypass flag.
	 */
	private function checkRetentionLocked(): array
	{
		$key = $this->probeKey();
		try {
			$version = $this->putProbe($key, new \DateTimeImmutable('+1 day'));
		} catch (S3Exception $e) {
			return $this->result(self::FAIL, 'locked probe write failed: ' . $this->awsError($e));
		}

		$base = ['Bucket' => $this->bucket, 'Key' => $key, 'VersionId' => $version];
		try {
			$this->s3->putObjectRetention($base + [
				'Retention' => ['Mode' => 'GOVERNANCE', 'RetainUntilDate' => new \DateTimeImmutable('+2 days')],
			]);
		} catch (S3Exception $e) {
			return $this->result(self::FAIL, 'key cannot extend retention (PutObjectRetention); lock renewal needs it: ' . $this->awsError($e));
		}

		try {
			$this->s3->putObjectRetention($base + [
				'BypassGovernanceRetention' => true,
				'Retention'                 => ['Mode' => 'GOVERNANCE', 'RetainUntilDate' => new \DateTimeImmutable('+1 minute')],
			]);

			return $this->result(self::FAIL, 'key shortened a locked version\'s retention; remove s3:BypassGovernanceRetention');
		} catch (S3Exception $e) {
			if ($e->getAwsErrorCode() !== 'AccessDenied') {
				return $this->result(self::FAIL, 'unexpected retention error: ' . $this->awsError($e));
			}
		}

		try {
			$this->s3->deleteObject($base + ['BypassGovernanceRetention' => true]);

			return $this->result(self::FAIL, 'key deleted a locked version; remove s3:BypassGovernanceRetention and s3:DeleteObjectVersion');
		} catch (S3Exception $e) {
			if ($e->getAwsErrorCode() !== 'AccessDenied') {
				return $this->result(self::FAIL, 'unexpected delete error: ' . $this->awsError($e));
			}
		}

		// locked for two days; the lifecycle rule removes it afterwards
		try {
			$this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $key]);
		} catch (S3Exception) {
		}

		return $this->result(self::PASS, 'retention extended; shortening and deleting the locked version denied');
	}

	private function probeKey(): string
	{
		return $this->prefix . '.kopia-backup-probe/' . bin2hex(random_bytes(8));
	}

	/**
	 * @throws S3Exception
	 */
	private function putProbe(string $key, ?\DateTimeImmutable $retainUntil = null): string
	{
		$args = [
			'Bucket'     => $this->bucket,
			'Key'        => $key,
			'Body'       => self::PROBE_BODY,
			// S3 requires an integrity header on object-lock uploads and when_required disables the automatic one
			'ContentMD5' => base64_encode(md5(self::PROBE_BODY, true)),
		];
		if ($retainUntil) {
			$args['ObjectLockMode'] = 'GOVERNANCE';
			$args['ObjectLockRetainUntilDate'] = $retainUntil;
		}
		$version = $this->s3->putObject($args)['VersionId'] ?? null;
		if (!$version) {
			throw new \RuntimeException('probe write returned no VersionId; is versioning on?');
		}

		return $version;
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
