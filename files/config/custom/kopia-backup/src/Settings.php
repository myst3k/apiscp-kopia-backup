<?php declare(strict_types=1);

namespace KopiaBackup;

use Opcenter\Crypto\Keyring;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class Settings
{
	public const DEFAULTS = [
		'endpoint'   => 'https://s3.us-east-1.wasabisys.com',
		'region'     => 'us-east-1',
		'bucket'     => null,
		'prefix'     => null,
		'access_key' => null,
		'secret_key' => null,
		'lock_mode'  => 'COMPLIANCE',
		'lock_days'  => 90,
		'keep_days'  => 180,
	];

	private const SECRETS = ['secret_key'];
	private const MAX_DAYS = 36500;

	public static function path(): string
	{
		return config_path('custom/kopia-backup/settings.yaml');
	}

	public static function load(): array
	{
		$raw = self::raw();
		$s = self::DEFAULTS;
		foreach (array_intersect_key($raw, self::DEFAULTS) as $k => $v) {
			if (in_array($k, self::SECRETS, true)) {
				$s[$k] = is_string($v) && Keyring::is($v) ? Keyring::decode($v) : null;
				continue;
			}
			// stored values are re-validated so a hand-edited file cannot feed bad types downstream
			$s[$k] = self::validate($k, $v, false) ?? self::DEFAULTS[$k];
		}
		$s['prefix'] ??= strtok(gethostname(), '.') . '/';

		return $s;
	}

	/**
	 * Validate and store several settings at once; an empty value reverts that key to its default
	 */
	public static function save(array $values): bool
	{
		$path = self::path();
		$lock = fopen("{$path}.lock", 'c');
		if (!$lock || !flock($lock, LOCK_EX)) {
			return error('failed to lock %s', $path);
		}
		try {
			$raw = self::raw();
			foreach ($values as $key => $value) {
				if (!array_key_exists($key, self::DEFAULTS)) {
					return error("unknown setting `%s'", $key);
				}
				if ($value === null || $value === '') {
					unset($raw[$key]);
					continue;
				}
				if (null === ($value = self::validate($key, $value))) {
					return false;
				}
				$raw[$key] = in_array($key, self::SECRETS, true) ? Keyring::encode((string)$value) : $value;
			}

			$merged = array_intersect_key($raw, self::DEFAULTS) + self::DEFAULTS;
			if ($merged['keep_days'] < $merged['lock_days']) {
				warn('keep_days (%d) < lock_days (%d): locked data outlives its snapshots', $merged['keep_days'], $merged['lock_days']);
			}

			$tmp = "{$path}.tmp." . getmypid();
			$fh = fopen($tmp, 'x');
			if (!$fh) {
				return error('failed to create %s', $tmp);
			}
			$yaml = Yaml::dump($raw);
			$ok = chmod($tmp, 0600) && fwrite($fh, $yaml) === strlen($yaml) && fclose($fh) && rename($tmp, $path);
			if (!$ok) {
				@unlink($tmp);

				return error('failed to write %s', $path);
			}

			return true;
		} finally {
			flock($lock, LOCK_UN);
			fclose($lock);
		}
	}

	public static function missing(array $s): array
	{
		return array_keys(array_filter(
			array_intersect_key($s, array_flip(['bucket', 'access_key', 'secret_key'])),
			static fn($v) => $v === null || $v === ''
		));
	}

	private static function raw(): array
	{
		$path = self::path();
		if (!is_file($path)) {
			return [];
		}
		try {
			$raw = Yaml::parseFile($path);
		} catch (ParseException $e) {
			// keep the broken file for inspection; the UI then shows defaults and can be re-saved
			$aside = $path . '.corrupt-' . date('Ymd-His');
			rename($path, $aside);
			error('%s is not valid YAML (%s); moved to %s', $path, $e->getMessage(), $aside);

			return [];
		}

		return is_array($raw) ? $raw : [];
	}

	/**
	 * @return mixed normalized value, or null when invalid
	 */
	private static function validate(string $key, mixed $value, bool $report = true): mixed
	{
		$fail = static function (string $msg, ...$args) use ($report) {
			if ($report) {
				error($msg, ...$args);
			}

			return null;
		};
		if (!is_scalar($value)) {
			return $fail('%s must be a scalar', $key);
		}
		$value = trim((string)$value);
		switch ($key) {
			case 'lock_days':
			case 'keep_days':
				if (!ctype_digit($value) || (int)$value < 2 || (int)$value > self::MAX_DAYS) {
					return $fail('%s must be an integer between 2 and %d', $key, self::MAX_DAYS);
				}

				return (int)$value;
			case 'lock_mode':
				$value = strtoupper($value);
				if (!in_array($value, ['COMPLIANCE', 'GOVERNANCE'], true)) {
					return $fail('lock_mode must be COMPLIANCE or GOVERNANCE');
				}

				return $value;
			case 'endpoint':
				if (!preg_match('!^https://[a-z0-9.-]+(:\d+)?/?$!i', $value)) {
					return $fail('endpoint must be an https:// URL');
				}

				return rtrim($value, '/');
			case 'region':
				if (!preg_match('/^[a-z0-9-]{1,64}$/', $value)) {
					return $fail('invalid region');
				}

				return $value;
			case 'bucket':
				if (!preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $value)) {
					return $fail('invalid bucket name');
				}

				return $value;
			case 'prefix':
				$value = trim($value, '/');
				if (!preg_match('!^[A-Za-z0-9._/-]{1,200}$!', $value)) {
					return $fail('prefix may contain only letters, digits, . _ - /');
				}

				return $value . '/';
			case 'access_key':
				if (!preg_match('/^[A-Za-z0-9]{16,128}$/', $value)) {
					return $fail('invalid access key');
				}

				return $value;
			case 'secret_key':
				if ($value === '' || strlen($value) > 256) {
					return $fail('invalid secret key');
				}

				return $value;
			default:
				return $value;
		}
	}
}
