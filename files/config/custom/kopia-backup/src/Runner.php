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
	private ?string $logFile = null;
	private const KEEP_LOGS = 60;

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
		$this->logFile = "{$this->stateDir}/logs/run-" . date('Ymd-His') . '.log';
		$this->report['log'] = basename($this->logFile);
		// running.json marks a run in progress; last.json keeps the previous result until this one finishes
		file_put_contents("{$this->stateDir}/running.json", json_encode($this->report));
		$this->log("backup of {$this->domain} ({$this->site}) started");
		try {
			$this->ensureRepo();
			$this->applyPolicy();
			$this->applyIgnores();
			$this->report['databases'] = $this->dumpDatabases();
			$this->dumpAcls();
			$this->report['snapshots'] = $this->snapshot();
			$t = microtime(true);
			$this->kopia(['maintenance', 'run']);
			$this->log(sprintf('maintenance run in %.1fs', microtime(true) - $t));
			$this->report['repo'] = $this->repoStats();
			if ($this->report['repo']) {
				$this->log(sprintf('repository: %d snapshots, %s stored', $this->report['repo']['snapshots'] ?? 0, self::human($this->report['repo']['stored'] ?? 0)));
			}
			$this->report['ok'] = true;
			$this->log('backup complete in ' . (time() - $this->report['started']) . 's');
		} catch (\Throwable $e) {
			$this->report['error'] = $e->getMessage();
			$this->log('FAILED: ' . $e->getMessage());
			throw $e;
		} finally {
			$this->pruneLogs();
			$this->report['finished'] = time();
			file_put_contents("{$this->stateDir}/last.json", json_encode($this->report, JSON_PRETTY_PRINT));
			$this->bumpCounters($this->report['ok']);
			@unlink("{$this->stateDir}/running.json");
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

	/**
	 * What Kopia holds for this site: retained snapshots of the files source and bytes stored in the bucket
	 *
	 * @return array{snapshots?: int, oldest?: ?int, stored?: int}
	 */
	private function repoStats(): array
	{
		$stats = [];
		$ret = $this->kopia(['snapshot', 'list', '--all', '--json'], false);
		if ($ret['success'] && is_array($list = json_decode($ret['stdout'], true))) {
			$times = [];
			foreach ($list as $snap) {
				if (($snap['source']['path'] ?? '') === $this->shadowPath() && !empty($snap['startTime'])) {
					$times[] = strtotime($snap['startTime']);
				}
			}
			$times = array_filter($times);
			$stats['snapshots'] = count($times);
			$stats['oldest'] = $times ? min($times) : null;
		}
		$ret = $this->kopia(['blob', 'stats', '--raw'], false);
		if ($ret['success'] && preg_match('/Total:\s*(\d+)/', $ret['stdout'], $m)) {
			$stats['stored'] = (int)$m[1];
		}

		return $stats;
	}

	private function bumpCounters(bool $ok): void
	{
		$file = "{$this->stateDir}/counters.json";
		$c = is_file($file) ? (array)json_decode((string)file_get_contents($file), true) : [];
		$c += ['runs' => 0, 'ok' => 0, 'failed' => 0, 'consecutive_failures' => 0, 'first_run' => $this->report['started']];
		$c['runs']++;
		$c[$ok ? 'ok' : 'failed']++;
		$c['consecutive_failures'] = $ok ? 0 : $c['consecutive_failures'] + 1;
		file_put_contents($file, json_encode($c));
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
		$current = is_file("{$dir}/running.json") ? (array)json_decode((string)file_get_contents("{$dir}/running.json"), true) : [];
		$counters = is_file("{$dir}/counters.json") ? (array)json_decode((string)file_get_contents("{$dir}/counters.json"), true) : [];

		return $last + [
			'running'     => $running,
			'run_started' => $current['started'] ?? null,
			// a running.json nobody holds the lock for means the process died mid-run
			'interrupted' => !$running && $current !== [],
			'ok'          => null,
			'finished'    => null,
			'verify'      => $verify,
			'counters'    => $counters,
		];
	}

	private function ensureRepo(bool $create = true): void
	{
		if (is_file("{$this->stateDir}/kopia.config") && $this->kopia(['repository', 'status'], false)['success']) {
			$this->log('repository: connected');

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
			$this->log('repository: connected to existing repository at ' . $this->settings['prefix'] . $this->domain . '/');

			return;
		}
		$this->log('repository: none found, creating');

		$this->kopia(array_merge(['repository', 'create'], $s3, [
			'--retention-mode', (string)$this->settings['lock_mode'],
			'--retention-period', $this->settings['lock_days'] . 'd',
			// smaller chunks so scattered row updates in SQL dumps re-upload less
			'--object-splitter', 'DYNAMIC-1M-BUZHASH',
			'--description', gethostname() . ' ' . $this->domain,
		]));
		info('%s: repository created', $this->domain);
		$this->log(sprintf('repository: created, %s lock %dd', $this->settings['lock_mode'], $this->settings['lock_days']));
	}

	/**
	 * Retention and maintenance settings live in the repo; re-applied every run so changes on the admin
	 * page reach existing repositories. Maintenance params are a manifest (inside locked blobs) and the
	 * schedule blob kopia.maintenance is unlocked by design: losing it only makes maintenance run sooner.
	 */
	private function applyPolicy(): void
	{
		$this->kopia([
			'policy', 'set', '--global',
			'--keep-latest', (string)$this->settings['keep_latest'], '--keep-hourly', '0', '--keep-daily', (string)$this->settings['keep_days'],
			'--keep-weekly', '0', '--keep-monthly', '0', '--keep-annual', '0',
			'--compression', 'zstd',
			'--ignore-file-errors', 'true', '--ignore-dir-errors', 'true',
		]);
		// locks are renewed by full maintenance, which must run at least a day inside the lock period
		$this->kopia(['maintenance', 'set', '--extend-object-locks', 'true', '--full-interval', '24h', '--quick-interval', '4h']);
	}

	/**
	 * Maintenance state as Kopia reports it: lock extension on, and a full cycle within the last two days
	 *
	 * @return array{ok: bool, detail: string, extend_object_locks: ?bool, last_full: ?int}
	 */
	public function maintenanceCheck(): array
	{
		$ret = $this->kopia(['maintenance', 'info', '--json'], false);
		$info = $ret['success'] ? json_decode($ret['stdout'], true) : null;
		if (!is_array($info)) {
			return ['ok' => false, 'detail' => 'maintenance info unavailable: ' . trim((string)$ret['stderr']), 'extend_object_locks' => null, 'last_full' => null];
		}
		$extend = (bool)($info['extendObjectLocks'] ?? false);
		$lastFull = null;
		foreach ((array)($info['schedule']['runs'] ?? []) as $task => $runs) {
			if (!str_starts_with((string)$task, 'full-') && $task !== 'extend-blob-retention-time') {
				continue;
			}
			foreach ((array)$runs as $run) {
				if (!empty($run['success']) && !empty($run['end']) && ($t = strtotime($run['end']))) {
					$lastFull = max($lastFull ?? 0, $t);
				}
			}
		}
		$stale = $lastFull === null || $lastFull < time() - 2 * 86400;
		$problems = [];
		if (!$extend) {
			$problems[] = 'extend-object-locks is OFF';
		}
		if ($stale) {
			$problems[] = 'no successful full maintenance in the last 2 days' . ($lastFull ? ' (last ' . date('Y-m-d H:i', $lastFull) . ')' : '');
		}

		return [
			'ok'                  => !$problems,
			'detail'              => $problems ? implode('; ', $problems) : 'extend-object-locks on, full maintenance ran ' . date('Y-m-d H:i', $lastFull),
			'extend_object_locks' => $extend,
			'last_full'           => $lastFull,
		];
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
		$this->log(sprintf('policy: keep %d daily + %d latest, extend-object-locks on; %d ignore patterns', $this->settings['keep_days'], $this->settings['keep_latest'], count($this->report['ignores'])));
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

		if (!$dbs) {
			$this->log('databases: none');
		}
		$done = [];
		foreach ($dbs as $db) {
			$out = "{$dir}/{$db}.sql";
			$t = microtime(true);
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
			$this->log(sprintf('database %s dumped: %s in %.1fs', $db, self::human($done[$db]), microtime(true) - $t));
		}
		foreach (glob("{$dir}/*.sql") ?: [] as $stale) {
			if (!isset($done[basename($stale, '.sql')])) {
				unlink($stale);
				$this->log('database dump removed (database no longer exists): ' . basename($stale));
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
			$this->log('acl dump FAILED: ' . trim($ret['stderr']));
		} else {
			$this->log('acl dump: ' . self::human((int)@filesize("{$this->stateDir}/acl.txt")));
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
		$t = microtime(true);
		$ret = $this->kopia(array_merge(['snapshot', 'create', '--json', '--parallel', '2'], $paths), true, true);
		$elapsed = microtime(true) - $t;

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
		foreach ($out as $snap) {
			$this->log(sprintf('snapshot %s: %s, %s files, %d errors (%s)', $snap['id'] ? substr($snap['id'], 0, 12) : '?', self::human((int)$snap['size']), number_format((int)$snap['files']), $snap['failed'], $snap['source']));
		}
		$this->log(sprintf('snapshot phase took %.1fs', $elapsed));

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
		if (!$ret['success']) {
			$this->log("kopia {$args[0]} " . ($args[1] ?? '') . ' exited ' . $ret['return'] . ': ' . trim((string)$ret['stderr']));
			if ($throw) {
				throw new \RuntimeException("kopia {$args[0]} " . ($args[1] ?? '') . ': ' . trim($ret['stderr']));
			}
		}

		return $ret;
	}

	private function log(string $line): void
	{
		if ($this->logFile) {
			file_put_contents($this->logFile, '[' . date('Y-m-d H:i:s') . "] {$line}\n", FILE_APPEND);
		}
	}

	private function pruneLogs(): void
	{
		$logs = glob("{$this->stateDir}/logs/run-*.log") ?: [];
		rsort($logs);
		foreach (array_slice($logs, self::KEEP_LOGS) as $old) {
			@unlink($old);
		}
	}

	public static function human(int $bytes): string
	{
		$units = ['B', 'KB', 'MB', 'GB', 'TB'];
		$i = 0;
		$n = (float)$bytes;
		while ($n >= 1024 && $i < 4) {
			$n /= 1024;
			$i++;
		}

		return ($i ? number_format($n, 2) : (string)$bytes) . ' ' . $units[$i];
	}

	/**
	 * Plugin-written logs for a site, newest first
	 *
	 * @return array<int, array{name: string, size: int, mtime: int}>
	 */
	public static function listLogs(string $site): array
	{
		$out = [];
		foreach (glob(self::STATE_DIR . "/{$site}/logs/{run,verify}-*.log", GLOB_BRACE) ?: [] as $f) {
			$out[] = ['name' => basename($f), 'size' => (int)filesize($f), 'mtime' => (int)filemtime($f)];
		}
		usort($out, static fn($a, $b) => strcmp($b['name'], $a['name']));

		return $out;
	}

	public static function readLog(string $site, string $name, int $maxBytes = 262144): ?string
	{
		if (!preg_match('/^(run|verify)-\d{8}-\d{6}\.log$/', $name)) {
			return null;
		}
		$f = self::STATE_DIR . "/{$site}/logs/{$name}";
		if (!is_file($f)) {
			return null;
		}
		$size = filesize($f);

		return $size > $maxBytes ? "[... first " . self::human($size - $maxBytes) . " omitted ...]\n" . file_get_contents($f, false, null, $size - $maxBytes) : (string)file_get_contents($f);
	}

	public static function writeVerifyLog(string $site, array $report): ?string
	{
		$dir = self::STATE_DIR . "/{$site}/logs";
		if (!is_dir($dir)) {
			return null;
		}
		$name = 'verify-' . date('Ymd-His', $report['started']) . '.log';
		$lines = ['[' . date('Y-m-d H:i:s', $report['started']) . "] verification of " . ($report['domain'] ?? $site) . ' started'];
		$lines[] = 'snapshot integrity: ' . (!empty($report['integrity']['ok']) ? 'ok' : 'FAILED');
		if (!empty($report['integrity']['output'])) {
			$lines[] = $report['integrity']['output'];
		}
		if (isset($report['maintenance'])) {
			$lines[] = 'maintenance: ' . (!empty($report['maintenance']['ok']) ? 'ok' : 'FAILED') . ' (' . $report['maintenance']['detail'] . ')';
		}
		if (!empty($report['locks'])) {
			$l = $report['locks'];
			$lines[] = sprintf('object locks: %d of %d sampled locked (%d objects), mode %s, earliest expiry %s', $l['locked'], $l['sampled'], $l['objects'], $l['mode'] ?? '?', $l['earliest_retain_until'] ?? '?');
			foreach (['unlocked' => 'no lock', 'wrong_mode' => 'wrong mode', 'short' => 'too short'] as $k => $label) {
				foreach ($l[$k] as $item) {
					$lines[] = "  {$label}: {$item}";
				}
			}
		} else {
			$lines[] = 'object locks: could not be read';
		}
		$lines[] = '[' . date('Y-m-d H:i:s', $report['finished']) . '] verification ' . (!empty($report['ok']) ? 'passed' : 'FAILED');
		file_put_contents("{$dir}/{$name}", implode("\n", $lines) . "\n");

		return $name;
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
