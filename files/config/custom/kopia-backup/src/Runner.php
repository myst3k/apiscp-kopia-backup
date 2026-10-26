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

	/**
	 * Where this site's repository lives, as recorded when it was connected or created. kopia.config
	 * keeps working after the bucket or prefix changes on the admin page, so the recorded location is
	 * compared with the settings on every run and a mismatch forces a reconnect to the new one.
	 */
	private function repoLocation(): array
	{
		return [
			'endpoint' => $this->endpointHost(),
			'region'   => (string)$this->settings['region'],
			'bucket'   => (string)$this->settings['bucket'],
			'prefix'   => $this->settings['prefix'] . $this->domain . '/',
		];
	}

	private function ensureRepo(bool $create = true): void
	{
		$repoFile = "{$this->stateDir}/repo.json";
		$want = $this->repoLocation();
		$have = is_file($repoFile) ? (array)json_decode((string)file_get_contents($repoFile), true) : [];
		$moved = $have && array_intersect_key($have, $want) != $want;
		if (is_file("{$this->stateDir}/kopia.config") && !$moved && $this->kopia(['repository', 'status'], false)['success']) {
			$this->log('repository: connected');
			if (!$have) {
				// repositories from before locations were recorded
				file_put_contents($repoFile, json_encode($want + ['connected' => time()], JSON_PRETTY_PRINT));
			}

			return;
		}
		if (!$create) {
			throw new \RuntimeException($moved
				? "{$this->domain}: storage settings changed since the repository was connected; run a backup first to reconnect"
				: "{$this->domain}: no repository connected; run a backup first");
		}
		if ($moved) {
			$this->log(sprintf('repository: storage settings changed (was %s/%s), reconnecting', $have['bucket'] ?? '?', $have['prefix'] ?? '?'));
			@unlink("{$this->stateDir}/kopia.config");
		}
		$s3 = [
			's3',
			'--bucket', $want['bucket'],
			'--endpoint', $want['endpoint'],
			'--region', $want['region'],
			'--prefix', $want['prefix'],
			'--cache-directory', $this->cacheDir,
			'--no-check-for-updates',
		];
		// a repo may already exist in the bucket (reinstall, rebuilt server): reuse it rather than fail
		if ($this->kopia(array_merge(['repository', 'connect'], $s3), false)['success']) {
			info('%s: connected to existing repository', $this->domain);
			$this->log('repository: connected to existing repository at ' . $want['prefix']);
			file_put_contents($repoFile, json_encode($want + ['connected' => time()], JSON_PRETTY_PRINT));

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
		file_put_contents($repoFile, json_encode($want + [
			'lock_mode' => (string)$this->settings['lock_mode'],
			'lock_days' => (int)$this->settings['lock_days'],
			'created'   => time(),
		], JSON_PRETTY_PRINT));
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
		$ret = $this->kopia(array_merge(['snapshot', 'create', '--json', '--parallel', '2', '--tags', 'run:' . $this->report['started']], $paths), true, true);
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
		$failed = $skipped = 0;
		foreach ($items as $j) {
			$summ = $j['rootEntry']['summ'] ?? [];
			$out[] = [
				'id'      => $j['id'] ?? null,
				'source'  => $j['source']['path'] ?? null,
				'size'    => $summ['size'] ?? null,
				'files'   => $summ['files'] ?? null,
				'failed'  => (int)($summ['numFailed'] ?? 0),
				// the policy ignores unreadable entries so one bad file cannot sink the run; they are counted, not hidden
				'skipped' => (int)($summ['numIgnoredErrors'] ?? 0),
			];
			$failed += (int)($summ['numFailed'] ?? 0);
			$skipped += (int)($summ['numIgnoredErrors'] ?? 0);
			foreach (array_slice((array)($summ['errors'] ?? []), 0, 10) as $e) {
				$this->log(sprintf('  skipped %s: %s', $e['path'] ?? '?', $e['error'] ?? '?'));
			}
		}
		foreach ($out as $snap) {
			$this->log(sprintf('snapshot %s: %s, %s files, %d skipped, %d failed (%s)', $snap['id'] ? substr($snap['id'], 0, 12) : '?', self::human((int)$snap['size']), number_format((int)$snap['files']), $snap['skipped'], $snap['failed'], $snap['source']));
		}
		$this->log(sprintf('snapshot phase took %.1fs', $elapsed));
		if (!$out) {
			throw new \RuntimeException('snapshot produced no result: ' . trim((string)$ret['stderr']));
		}
		if (!in_array($this->shadowPath(), array_column($out, 'source'), true)) {
			throw new \RuntimeException('snapshot of the site tree is missing');
		}
		if ($failed > 0) {
			throw new \RuntimeException("snapshot has {$failed} errors; see the run log");
		}
		$this->report['skipped'] = $skipped;
		if ($skipped > 0) {
			warn('%s: %d entries could not be read and are missing from this backup', $this->domain, $skipped);
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
		if (!$ret['success']) {
			$this->log("kopia {$args[0]} " . ($args[1] ?? '') . ' exited ' . $ret['return'] . ': ' . trim((string)$ret['stderr']));
			if ($throw) {
				throw new \RuntimeException("kopia {$args[0]} " . ($args[1] ?? '') . ': ' . trim($ret['stderr']));
			}
		}

		return $ret;
	}

	/**
	 * Snapshots grouped per run, newest first. Each run produced one snapshot per source
	 * (site tree, info, database dumps, ACLs); the run tag ties them together.
	 *
	 * @return array<int, array{run: string, time: int, size: int, files: int, sources: array<string, string>, databases: string[]}>
	 */
	public function listSnapshots(int $limit = 30, bool $withDatabases = true): array
	{
		$this->ensureRepo(false);
		$ret = $this->kopia(['snapshot', 'list', '--all', '--json']);
		$list = json_decode($ret['stdout'], true);
		if (!is_array($list)) {
			return [];
		}
		$runs = [];
		foreach ($list as $snap) {
			$path = $snap['source']['path'] ?? '';
			$time = strtotime($snap['startTime'] ?? '') ?: 0;
			// kopia stores --tags run:X under "tag:run"; snapshots from before tagging are grouped by the minute they started
			$run = $snap['tags']['tag:run'] ?? $snap['tags']['run'] ?? (string)($time - $time % 60);
			$runs[$run] ??= ['run' => $run, 'time' => $time, 'size' => 0, 'files' => 0, 'sources' => [], 'databases' => []];
			$runs[$run]['time'] = max($runs[$run]['time'], $time);
			if ($path === $this->shadowPath()) {
				$runs[$run]['sources']['files'] = $snap['id'];
				$runs[$run]['size'] += (int)($snap['rootEntry']['summ']['size'] ?? 0);
				$runs[$run]['files'] = (int)($snap['rootEntry']['summ']['files'] ?? 0);
			} elseif ($path === "{$this->stateDir}/db") {
				$runs[$run]['sources']['db'] = $snap['id'];
				$runs[$run]['size'] += (int)($snap['rootEntry']['summ']['size'] ?? 0);
			} elseif ($path === "/home/virtual/{$this->site}/info") {
				$runs[$run]['sources']['info'] = $snap['id'];
			} elseif ($path === "{$this->stateDir}/acl.txt") {
				$runs[$run]['sources']['acl'] = $snap['id'];
			}
		}
		usort($runs, static fn($a, $b) => $b['time'] <=> $a['time']);
		$runs = array_slice($runs, 0, $limit);
		// each lookup is a separate kopia invocation; only the newest run needs its database names for the UI
		if ($withDatabases && $runs && isset($runs[0]['sources']['db'])) {
			$runs[0]['databases'] = $this->databasesIn($runs[0]['sources']['db']);
		}

		return $runs;
	}

	/**
	 * @return string[] database names dumped in a db-source snapshot
	 */
	private function databasesIn(string $snapshotId): array
	{
		$ret = $this->kopia(['ls', $snapshotId], false);
		$dbs = [];
		foreach (preg_split('/\R/', trim((string)$ret['stdout'])) as $name) {
			if (str_ends_with($name, '.sql')) {
				$dbs[] = substr($name, 0, -4);
			}
		}

		return $dbs;
	}

	/**
	 * Restore files from a run.
	 *
	 * Kopia writes into a root-owned staging directory directly under the site root, which only root can
	 * reach, and the move into place runs chrooted into the site. A symlink the site has planted on the
	 * destination (~/restore, wp-content/uploads, var/www itself) then resolves inside the site, never on
	 * the host, so a compromised site cannot turn a root restore into a write anywhere else.
	 *
	 * @param string $run         run tag from listSnapshots
	 * @param string $subpath     path inside the site, e.g. var/www/html/wp-content/uploads; empty for everything
	 * @param string $where       copy: into ~/restore/<date>/<subpath>; live: back onto the site at the same paths
	 * @param bool   $deleteExtra live only, never for the entire site: also remove files under the path that are not in the snapshot
	 * @return string path the files were written to, as the site sees it
	 */
	public function restoreFiles(string $run, string $subpath, string $where = 'copy', bool $deleteExtra = false): string
	{
		$subpath = trim($subpath, '/');
		if ($subpath !== '' && (str_contains($subpath, '..') || !preg_match('~^[A-Za-z0-9._@/-]+$~', $subpath))) {
			throw new \RuntimeException("invalid path `{$subpath}'");
		}
		if (!in_array($where, ['copy', 'live'], true)) {
			throw new \RuntimeException('where must be copy or live');
		}
		if ($deleteExtra && $where !== 'live') {
			throw new \RuntimeException('removing files not in the snapshot only applies to an in-place restore');
		}
		if ($deleteExtra && $subpath === '') {
			// the snapshot holds the site's own layer; the live tree also carries the read-only system layers,
			// which --delete would treat as extras
			throw new \RuntimeException('removing files not in the snapshot is not allowed for the entire site; pick var/www, home or a folder');
		}
		$snap = $this->findRun($run);
		if (empty($snap['sources']['files'])) {
			throw new \RuntimeException("run {$run} has no file snapshot");
		}
		$this->openLog('restore');
		$this->log(sprintf('restore files from run %s (%s): %s, %s%s', $run, date('Y-m-d H:i', $snap['time']),
			$subpath ?: '(entire site)', $where === 'live' ? 'in place' : 'copy to ~/restore', $deleteExtra ? ', removing files not in snapshot' : ''));

		$t = microtime(true);
		$dest = $where === 'live'
			? '/' . $subpath
			: '/home/' . $this->adminUser() . '/restore/' . date('Ymd-His') . ($subpath !== '' ? "/{$subpath}" : '');

		$this->withStaging(function (string $staging) use ($snap, $subpath, $where, $deleteExtra, $dest) {
			$source = $snap['sources']['files'] . ($subpath !== '' ? "/{$subpath}" : '');
			$ret = $this->kopia(['restore', '--parallel', '16', $source, $this->fst() . "{$staging}/data"], false);
			if (!$ret['success']) {
				$this->log('FAILED: ' . trim($ret['stderr']));
				throw new \RuntimeException('kopia restore: ' . trim($ret['stderr']));
			}
			if ($where === 'copy') {
				[$uid, $gid] = $this->adminIds();
				// parents are made as the site admin so they belong to the admin; the restored tree keeps its owners
				$this->inSite(['/usr/bin/mkdir', '-p', dirname($dest)], $uid, $gid);
				$this->inSite(['/usr/bin/mv', '-T', "{$staging}/data", $dest]);
				$this->inSite(['/usr/bin/chown', '-h', "{$uid}:{$gid}", $dest]);

				return;
			}
			$this->inSite(['/usr/bin/mkdir', '-p', $dest]);
			$this->inSite(array_merge(
				['/usr/bin/rsync', '-aH', '--numeric-ids'],
				$deleteExtra ? ['--delete'] : [],
				["{$staging}/data/", "{$dest}/"]
			));
			$this->reapplyAcls($snap, $subpath, $staging);
		});
		$this->log(sprintf('%s %s in %.1fs', $where === 'live' ? 'restored in place at' : 'restored to', $dest, microtime(true) - $t));

		return $dest;
	}

	/**
	 * Kopia restores owners and modes but not POSIX ACLs; Fortification depends on them, so put back the
	 * ACL dump taken with that run, limited to the restored path so nothing outside it changes. The dump
	 * holds shadow paths; they are rewritten to in-site paths and applied chrooted like the files.
	 */
	private function reapplyAcls(array $snap, string $subpath, string $staging): void
	{
		if (empty($snap['sources']['acl'])) {
			$this->log('no ACL dump in this run; ACLs not re-applied');

			return;
		}
		$raw = $this->fst() . "{$staging}/acl-raw.txt";
		$ret = $this->kopia(['restore', $snap['sources']['acl'], $raw], false);
		if (!$ret['success'] || !is_file($raw)) {
			$this->log('ACL dump restore FAILED: ' . trim($ret['stderr']));

			return;
		}
		$prefix = $this->shadowPath();
		$want = '/' . $subpath;
		$kept = [];
		foreach (preg_split('/\n{2,}/', trim((string)file_get_contents($raw))) as $block) {
			if (!preg_match('/^# file: (.+)$/m', $block, $m)) {
				continue;
			}
			$path = str_starts_with($m[1], $prefix) ? substr($m[1], strlen($prefix)) : null;
			if ($path === null || !($subpath === '' || $path === $want || str_starts_with($path, "{$want}/"))) {
				continue;
			}
			$kept[] = substr_replace($block, '# file: ' . $path, (int)strpos($block, $m[0]), strlen($m[0]));
		}
		if (!$kept) {
			$this->log('ACL dump has no entries under ' . $want);

			return;
		}
		file_put_contents($this->fst() . "{$staging}/acl.txt", implode("\n\n", $kept) . "\n");
		$ret = $this->inSite(['/usr/bin/setfacl', '--restore=' . "{$staging}/acl.txt"], null, null, false);
		// entries for files that no longer exist are reported, not fatal
		$this->log($ret['success'] ? count($kept) . ' ACL entries re-applied' : count($kept) . ' ACL entries re-applied with warnings: ' . trim(substr((string)$ret['stderr'], 0, 500)));
	}

	/**
	 * Restore one database dump from a run, either into a fresh database or over the live one.
	 * Replacing saves the live database to ~/restore/<date>/ first, then drops every table and view so
	 * the result is the snapshot alone, not a mix with tables created since.
	 *
	 * @param string $mode new|replace
	 * @return string database the data went into
	 */
	public function restoreDatabase(string $run, string $db, string $mode): string
	{
		if (!preg_match('/^[A-Za-z0-9_-]+$/', $db)) {
			throw new \RuntimeException("invalid database name `{$db}'");
		}
		if (!in_array($mode, ['new', 'replace'], true)) {
			throw new \RuntimeException("mode must be new or replace");
		}
		$snap = $this->findRun($run);
		if (empty($snap['sources']['db'])) {
			throw new \RuntimeException("run {$run} has no database snapshot");
		}
		if (!in_array($db, $this->databasesIn($snap['sources']['db']), true)) {
			throw new \RuntimeException("database {$db} is not in run {$run}");
		}
		$this->openLog('restore');
		$this->log("restore database {$db} from run {$run} (" . date('Y-m-d H:i', $snap['time']) . "), mode {$mode}");

		$staging = "{$this->stateDir}/restore";
		if (!is_dir($staging) && !mkdir($staging, 0700, true)) {
			throw new \RuntimeException("cannot create {$staging}");
		}
		$file = "{$staging}/{$db}.sql";
		@unlink($file);
		$ret = $this->kopia(['restore', $snap['sources']['db'] . "/{$db}.sql", $file], false);
		if (!$ret['success'] || !is_file($file)) {
			$this->log('FAILED: ' . trim($ret['stderr']));
			throw new \RuntimeException('kopia restore: ' . trim($ret['stderr']));
		}
		$this->log('dump restored: ' . self::human((int)filesize($file)));

		$afi = \apnscpFunctionInterceptor::factory(\Auth::context(null, $this->site));
		try {
			if ($mode === 'new') {
				$target = $db . '_r' . date('md_Hi');
				if (!$afi->mysql_create_database($target)) {
					throw new \RuntimeException("could not create database {$target}");
				}
				$this->log("created database {$target}");
			} else {
				$target = $db;
				if (!$afi->mysql_database_exists($target)) {
					throw new \RuntimeException("live database {$target} no longer exists; restore into a new one instead");
				}
				$saved = $this->saveLiveDatabase($target);
				$this->log("live database saved to {$saved}");
				$this->dropAllTables($target);
				$this->log("replacing live database {$target}");
			}

			$t = microtime(true);
			$ret = (new \Util_Process_Safe())->run("/bin/sh -c 'mysql --defaults-extra-file=%(cnf)s %(db)s < %(file)s'", [
				'cnf' => '/root/.my.cnf', 'db' => $target, 'file' => $file,
			]);
			if (!$ret['success']) {
				$this->log('import FAILED: ' . trim($ret['stderr']));
				throw new \RuntimeException("mysql import into {$target}: " . trim($ret['stderr']));
			}
			$this->log(sprintf('imported into %s in %.1fs', $target, microtime(true) - $t));
		} finally {
			@unlink($file);
		}

		return $target;
	}

	/**
	 * Dump the live database into ~/restore/<date>/<db>.sql before it is replaced
	 *
	 * @return string path as the site sees it
	 */
	private function saveLiveDatabase(string $db): string
	{
		$dest = '/home/' . $this->adminUser() . '/restore/' . date('Ymd-His');
		$this->withStaging(function (string $staging) use ($db, $dest) {
			$cmd = explode(' ', \Mysql_Module::EXPORT_CMD, 2);
			array_splice($cmd, 1, 0, '--defaults-extra-file=%(cnf)s');
			$cmd = implode(' ', $cmd) . ' --skip-dump-date -u root %(db)s > %(out)s';
			$ret = (new \Util_Process_Safe())->run("/bin/sh -c 'set -o pipefail; {$cmd}'", [
				'cnf' => '/root/.my.cnf', 'db' => $db, 'out' => $this->fst() . "{$staging}/{$db}.sql",
			]);
			if (!$ret['success']) {
				throw new \RuntimeException("could not save the live database before replacing it: " . trim($ret['stderr']));
			}
			[$uid, $gid] = $this->adminIds();
			$this->inSite(['/usr/bin/mkdir', '-p', $dest], $uid, $gid);
			$this->inSite(['/usr/bin/mv', '-T', "{$staging}/{$db}.sql", "{$dest}/{$db}.sql"]);
			$this->inSite(['/usr/bin/chown', '-h', "{$uid}:{$gid}", "{$dest}/{$db}.sql"]);
			$this->inSite(['/usr/bin/chmod', '600', "{$dest}/{$db}.sql"]);
		});

		return "{$dest}/{$db}.sql";
	}

	private function dropAllTables(string $db): void
	{
		$ret = (new \Util_Process_Safe())->run('mysql --defaults-extra-file=%s -N -B -e %s', '/root/.my.cnf',
			"SELECT table_name, table_type FROM information_schema.tables WHERE table_schema = '{$db}'");
		if (!$ret['success']) {
			throw new \RuntimeException('could not list tables: ' . trim($ret['stderr']));
		}
		$tables = $views = [];
		foreach (preg_split('/\R/', trim((string)$ret['stdout'])) as $line) {
			if ($line === '') {
				continue;
			}
			[$name, $type] = explode("\t", $line, 2) + [1 => ''];
			$quoted = '`' . str_replace('`', '``', $name) . '`';
			if ($type === 'VIEW') {
				$views[] = $quoted;
			} else {
				$tables[] = $quoted;
			}
		}
		if (!$tables && !$views) {
			return;
		}
		$sql = 'SET FOREIGN_KEY_CHECKS=0;'
			. ($views ? ' DROP VIEW IF EXISTS ' . implode(', ', $views) . ';' : '')
			. ($tables ? ' DROP TABLE IF EXISTS ' . implode(', ', $tables) . ';' : '');
		$ret = (new \Util_Process_Safe())->run('mysql --defaults-extra-file=%s %s -e %s', '/root/.my.cnf', $db, $sql);
		if (!$ret['success']) {
			throw new \RuntimeException('could not empty the live database: ' . trim($ret['stderr']));
		}
		$this->log(sprintf('dropped %d tables and %d views from %s', count($tables), count($views), $db));
	}

	private function findRun(string $run): array
	{
		foreach ($this->listSnapshots(1000, false) as $r) {
			if ($r['run'] === $run) {
				return $r;
			}
		}
		throw new \RuntimeException("no snapshot run {$run}");
	}

	private function adminUser(): string
	{
		$admin = (string)(new SiteConfiguration($this->site))->getServiceValue('siteinfo', 'admin_user');
		if ($admin === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $admin)) {
			throw new \RuntimeException("{$this->site}: no admin_user");
		}

		return $admin;
	}

	/**
	 * @return array{int, int} uid and gid of the site admin; site users live in the site's own passwd,
	 *                         so the home directory's owner is authoritative
	 */
	private function adminIds(): array
	{
		$home = $this->fst() . '/home/' . $this->adminUser();
		$uid = (int)fileowner($home);
		if ($uid === 0) {
			throw new \RuntimeException("cannot determine owner of {$home}");
		}

		return [$uid, (int)filegroup($home)];
	}

	/**
	 * The site's root as mounted on the host
	 */
	private function fst(): string
	{
		return FILESYSTEM_VIRTBASE . "/{$this->site}/fst";
	}

	/**
	 * A root-owned scratch directory directly under the site root, handed to the callback as an in-site
	 * path and removed afterwards. The site root itself is root's, so the site cannot replace it.
	 */
	private function withStaging(callable $fn): void
	{
		$staging = '/.kopia-restore.' . bin2hex(random_bytes(4));
		if (!mkdir($this->fst() . $staging, 0700)) {
			throw new \RuntimeException("cannot create staging directory in {$this->site}");
		}
		try {
			$fn($staging);
		} finally {
			\Util_Process_Safe::exec(['rm', '-rf', '--one-file-system', $this->fst() . $staging]);
		}
	}

	/**
	 * Run a command chrooted into the site, as root or as the given user. Binaries and paths are in-site.
	 */
	private function inSite(array $cmd, ?int $uid = null, ?int $gid = null, bool $throw = true): array
	{
		$args = ['/usr/sbin/chroot'];
		if ($uid !== null) {
			$args[] = "--userspec={$uid}:{$gid}";
		}
		$args[] = $this->fst();
		$ret = (new \Util_Process_Safe())->run(array_merge($args, $cmd));
		if (!$ret['success'] && $throw) {
			$this->log("{$cmd[0]} failed: " . trim((string)$ret['stderr']));
			throw new \RuntimeException(basename($cmd[0]) . ' in site: ' . trim((string)$ret['stderr']));
		}

		return $ret;
	}

	private function openLog(string $kind): void
	{
		if (!is_dir("{$this->stateDir}/logs")) {
			mkdir("{$this->stateDir}/logs", 0700, true);
		}
		$this->logFile = "{$this->stateDir}/logs/{$kind}-" . date('Ymd-His') . '.log';
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
		foreach (glob(self::STATE_DIR . "/{$site}/logs/{run,verify,restore}-*.log", GLOB_BRACE) ?: [] as $f) {
			$out[] = ['name' => basename($f), 'size' => (int)filesize($f), 'mtime' => (int)filemtime($f)];
		}
		usort($out, static fn($a, $b) => strcmp($b['name'], $a['name']));

		return $out;
	}

	public static function readLog(string $site, string $name, int $maxBytes = 262144): ?string
	{
		if (!preg_match('/^(run|verify|restore)-\d{8}-\d{6}\.log$/', $name)) {
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
