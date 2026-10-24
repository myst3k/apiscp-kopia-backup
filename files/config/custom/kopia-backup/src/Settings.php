<?php declare(strict_types=1);

namespace KopiaBackup;

use Opcenter\Crypto\Keyring;
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

	public static function path(): string
	{
		return config_path('custom/kopia-backup/settings.yaml');
	}

	public static function load(): array
	{
		$s = array_intersect_key(self::raw(), self::DEFAULTS) + self::DEFAULTS;
		$s['prefix'] ??= strtok(gethostname(), '.') . '/';
		foreach (self::SECRETS as $k) {
			if (is_string($s[$k]) && Keyring::is($s[$k])) {
				$s[$k] = Keyring::decode($s[$k]);
			}
		}

		return $s;
	}

	/**
	 * Validate and store several settings at once; an empty value reverts that key to its default
	 */
	public static function save(array $values): bool
	{
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

		$path = self::path();
		if (false === file_put_contents("{$path}.tmp", Yaml::dump($raw)) || !chmod("{$path}.tmp", 0600) || !rename("{$path}.tmp", $path)) {
			return error('failed to write %s', $path);
		}

		return true;
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
		return is_file(self::path()) ? (array)Yaml::parseFile(self::path()) : [];
	}

	private static function validate(string $key, mixed $value): mixed
	{
		$value = is_string($value) ? trim($value) : $value;
		switch ($key) {
			case 'lock_days':
			case 'keep_days':
				if (!ctype_digit((string)$value) || (int)$value < 2) {
					return error('%s must be an integer >= 2', $key) ?: null;
				}

				return (int)$value;
			case 'lock_mode':
				$value = strtoupper((string)$value);
				if (!in_array($value, ['COMPLIANCE', 'GOVERNANCE'], true)) {
					return error('lock_mode must be COMPLIANCE or GOVERNANCE') ?: null;
				}

				return $value;
			case 'endpoint':
				if (!preg_match('!^https://[a-z0-9.-]+(:\d+)?/?$!i', (string)$value)) {
					return error('endpoint must be an https:// URL') ?: null;
				}

				return rtrim((string)$value, '/');
			case 'bucket':
				if (!preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', (string)$value)) {
					return error('invalid bucket name') ?: null;
				}

				return $value;
			case 'prefix':
				$value = trim((string)$value, '/');
				if (!preg_match('!^[A-Za-z0-9._/-]+$!', $value)) {
					return error('prefix may contain only letters, digits, . _ - /') ?: null;
				}

				return $value . '/';
			default:
				return (string)$value;
		}
	}
}
