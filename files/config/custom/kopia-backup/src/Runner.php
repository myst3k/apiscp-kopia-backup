<?php declare(strict_types=1);

namespace KopiaBackup;

use Opcenter\SiteConfiguration;

/**
 * Backs up one site into its own Kopia repository: MySQL dumps, ACLs, then a snapshot of shadow + info.
 * Runs as root from the backend; nothing here is reachable from the site's own context.
 */
class Runner
{
	public const STATE_DIR = '/var/lib/kopia-backup';
	public const CACHE_DIR = '/var/cache/kopia-backup';

	private string $domain;
	private string $stateDir;
	private string $cacheDir;
	private array $env;
	private array $report = [];

	public function __construct(private array $settings, private string $site, string $master)
	{
		$this->domain = (string)(new SiteConfiguration($site))->getServiceValue('siteinfo', 'domain');
		if ($this->domain === '') {
			throw new \RuntimeException("{$site}: no domain in siteinfo");
		}
		$this->stateDir = self::STATE_DIR . "/{$site}";
		$this->cacheDir = self::CACHE_DIR . "/{$site}";
		$this->env = [
			// one master secret in the keyring; each repo gets a password derived from it
			'KOPIA_PASSWORD'        => hash_hmac('sha256', $this->domain, $master),
			'KOPIA_CONFIG_PATH'     => "{$this->stateDir}/kopia.config",
			'KOPIA_LOG_DIR'         => "{$this->stateDir}/logs",
			'AWS_ACCESS_KEY_ID'     => (string)$settings['access_key'],
			'AWS_SECRET_ACCESS_KEY' => (string)$settings['secret_key'],
			'HOME'                  => '/root',
		];
	}

	public function domain(): string
	{
		return $this->domain;
	}

	public function run(): array
	{
		foreach ([$this->stateDir, "{$this->stateDir}/db", "{$this->stateDir}/logs", $this->cacheDir] as $dir) {
			if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
				throw new \RuntimeException("cannot create {$dir}");
			}
		}
		$lock = fopen("{$this->stateDir}/lock", 'c');
		if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
			throw new \RuntimeException("backup already running for {$this->domain}");
		}

		$this->report = ['site' => $this->site, 'domain' => $this->domain, 'started' => time(), 'ok' => false];
		file_put_contents("{$this->stateDir}/last.json", json_encode($this->report, JSON_PRETTY_PRINT));
		try {
			$this->ensureRepo();
			$this->applyIgnores();
			$this->report['databases'] = $this->dumpDatabases();
			$this->dumpAcls();
			$this->report['snapshots'] = $this->snapshot();
			$this->kopia(['maintenance', 'run']);
			$this->report['ok'] = true;
		} catch (\Throwable $e) {
			$this->report['error'] = $e->getMessage();
			throw $e;
		} finally {
			$this->report['finished'] = time();
			file_put_contents("{$this->stateDir}/last.json", json_encode($this->report, JSON_PRETTY_PRINT));
			flock($lock, LOCK_UN);
			fclose($lock);
		}

		return $this->report;
	}

	/**
	 * Integrity check: every object referenced by every snapshot must exist, and a random
	 * percentage of file contents is downloaded and re-hashed.
	 *
	 * @return array{ok: bool, output: string}
	 */
	public function verify(int $percent): array
	{
		$this->ensureRepo(false);
		$ret = $this->kopia(['snapshot', 'verify', '--verify-files-percent', (string)max(0, min(100, $percent))], false, true);
		$lines = preg_split('/\R/', trim($ret['stderr'] . "\n" . $ret['stdout']));

		return ['ok' => $ret['success'], 'output' => implode("\n", array_slice(array_filter($lines), -15))];
	}

	public static function saveVerify(string $site, array $report): void
	{
		$dir = self::STATE_DIR . "/{$site}";
		if (is_dir($dir)) {
			file_put_contents("{$dir}/verify.json", json_encode($report, JSON_PRETTY_PRINT));
		}
	}

	/**
	 * Last backup and verification reports plus whether a run holds the lock right now
	 */
	public static function status(string $site): array
	{
		$dir = self::STATE_DIR . "/{$site}";
		$last = is_file("{$dir}/last.json") ? (array)json_decode((string)file_get_contents("{$dir}/last.json"), true) : [];
		$verify = is_file("{$dir}/verify.json") ? (array)json_decode((string)file_get_contents("{$dir}/verify.json"), true) : [];
		$running = false;
		if (is_file("{$dir}/lock") && ($fh = fopen("{$dir}/lock", 'r'))) {
			$running = !flock($fh, LOCK_EX | LOCK_NB);
			fclose($fh);
		}

		return $last + ['running' => $running, 'ok' => null, 'finished' => null, 'verify' => $verify];
	}

	private function ensureRepo(bool $create = true): void
	{
		if (is_file("{$this->stateDir}/kopia.config") && $this->kopia(['repository', 'status'], false)['success']) {
			return;
		}
		if (!$create) {
			throw new \RuntimeException("{$this->domain}: no repository connected; run a backup first");
		}
		$s3 = [
			's3',
			'--bucket', (string)$this->settings['bucket'],
			'--endpoint', $this->endpointHost(),
			'--region', (string)$this->settings['region'],
			'--prefix', $this->settings['prefix'] . $this->domain . '/',
			'--cache-directory', $this->cacheDir,
			'--no-check-for-updates',
		];
		// a repo may already exist in the bucket (reinstall, rebuilt server): reuse it rather than fail
		if ($this->kopia(array_merge(['repository', 'connect'], $s3), false)['success']) {
			info('%s: connected to existing repository', $this->domain);

			return;
		}

		$this->kopia(array_merge(['repository', 'create'], $s3, [
			'--retention-mode', (string)$this->settings['lock_mode'],
			'--retention-period', $this->settings['lock_days'] . 'd',
			// smaller chunks so scattered row updates in SQL dumps re-upload less
			'--object-splitter', 'DYNAMIC-1M-BUZHASH',
			'--description', gethostname() . ' ' . $this->domain,
		]));
		$this->kopia([
			'policy', 'set', '--global',
			'--keep-latest', '1', '--keep-hourly', '0', '--keep-daily', (string)$this->settings['keep_days'],
			'--keep-weekly', '0', '--keep-monthly', '0', '--keep-annual', '0',
			'--compression', 'zstd',
			'--ignore-file-errors', 'true', '--ignore-dir-errors', 'true',
		]);
		// locks are renewed by full maintenance, which must run at least a day inside the lock period
		$this->kopia(['maintenance', 'set', '--extend-object-locks', 'true', '--full-interval', '24h', '--quick-interval', '4h']);
		info('%s: repository created', $this->domain);
	}

	/**
	 * Ignore rules live in the repo as a policy on the shadow path, so re-apply them every run:
	 * global list plus this site's additions, replacing whatever was there.
	 */
	private function applyIgnores(): void
	{
		$patterns = array_filter(array_merge(
			preg_split('/\R/', (string)$this->settings['ignore']),
			preg_split('/\R/', (string)($this->settings['site_ignores'][$this->domain] ?? ''))
		));
		$this->kopia(['policy', 'set', $this->shadowPath(), '--clear-ignore']);
		if (!$patterns) {
			return;
		}
		$args = ['policy', 'set', $this->shadowPath()];
		foreach (array_unique($patterns) as $pattern) {
			array_push($args, '--add-ignore', $pattern);
		}
		$this->kopia($args);
		$this->report['ignores'] = array_values(array_unique($patterns));
	}

	/**
	 * @return array<string,int> database => dump size
	 */
	private function dumpDatabases(): array
	{
		$dir = "{$this->stateDir}/db";
		$afi = \apnscpFunctionInterceptor::factory(\Auth::context(null, $this->site));
		$dbs = $afi->mysql_enabled() ? (array)$afi->mysql_list_databases() : [];

		// --defaults-extra-file must be mysqldump's first option; same assembly as bin/scripts/backup_dbs.php
		$cmd = explode(' ', \Mysql_Module::EXPORT_CMD, 2);
		array_splice($cmd, 1, 0, '--defaults-extra-file=%(cnf)s');
		$cmd = implode(' ', $cmd) . ' --skip-dump-date -u root %(db)s > %(tmp)s';

		$done = [];
		foreach ($dbs as $db) {
			$out = "{$dir}/{$db}.sql";
			$ret = (new \Util_Process_Safe())->run("/bin/sh -c 'set -o pipefail; {$cmd}'", [
				'cnf' => '/root/.my.cnf', 'db' => $db, 'tmp' => "{$out}.tmp",
			]);
			if (!$ret['success']) {
				@unlink("{$out}.tmp");
				throw new \RuntimeException("mysqldump {$db}: " . trim($ret['stderr']));
			}
			chmod("{$out}.tmp", 0600);
			rename("{$out}.tmp", $out);
			$done[$db] = filesize($out) ?: 0;
		}
		foreach (glob("{$dir}/*.sql") ?: [] as $stale) {
			if (!isset($done[basename($stale, '.sql')])) {
				unlink($stale);
			}
		}

		return $done;
	}

	/**
	 * Kopia keeps owner and mode but not POSIX ACLs; Fortification sets ACLs, so save them for setfacl --restore
	 */
	private function dumpAcls(): void
	{
		$www = $this->shadowPath() . '/var/www';
		if (!is_dir($www)) {
			return;
		}
		$ret = (new \Util_Process_Safe())->run("/bin/sh -c 'getfacl -R -p --skip-base %s > %s'", $www, "{$this->stateDir}/acl.txt");
		if (!$ret['success']) {
			warn('%s: getfacl failed: %s', $this->domain, trim($ret['stderr']));
		}
	}

	private function snapshot(): array
	{
		$paths = array_values(array_filter([
			$this->shadowPath(),
			"/home/virtual/{$this->site}/info",
			"{$this->stateDir}/db",
			"{$this->stateDir}/acl.txt",
		], 'file_exists'));
		$ret = $this->kopia(array_merge(['snapshot', 'create', '--json', '--parallel', '2'], $paths), true, true);

		$decoded = json_decode(trim($ret['stdout']), true);
		$items = is_array($decoded) && isset($decoded['id']) ? [$decoded] : (array)$decoded;
		if (!$items) {
			foreach (preg_split('/\R/', trim($ret['stdout'])) as $line) {
				if (is_array($j = json_decode($line, true))) {
					$items[] = $j;
				}
			}
		}
		$out = [];
		foreach ($items as $j) {
			$out[] = [
				'id'     => $j['id'] ?? null,
				'source' => $j['source']['path'] ?? null,
				'size'   => $j['rootEntry']['summ']['size'] ?? null,
				'files'  => $j['rootEntry']['summ']['files'] ?? null,
				'failed' => $j['rootEntry']['summ']['numFailed'] ?? 0,
			];
		}

		return $out;
	}

	private function kopia(array $args, bool $throw = true, bool $nice = false): array
	{
		$cmd = array_merge(
			$nice ? ['nice', '-n', '19', 'ionice', '-c', '3'] : [],
			['kopia', '--no-progress', '--config-file', "{$this->stateDir}/kopia.config", '--log-dir', "{$this->stateDir}/logs"],
			$args
		);
		$ret = (new \Util_Process_Safe())->setEnvironment($this->env)->run($cmd);
		if (!$ret['success'] && $throw) {
			throw new \RuntimeException("kopia {$args[0]} " . ($args[1] ?? '') . ': ' . trim($ret['stderr']));
		}

		return $ret;
	}

	private function shadowPath(): string
	{
		return "/home/virtual/{$this->site}/shadow";
	}

	private function endpointHost(): string
	{
		$u = parse_url((string)$this->settings['endpoint']);

		return ($u['host'] ?? '') . (isset($u['port']) ? ':' . $u['port'] : '');
	}
}
