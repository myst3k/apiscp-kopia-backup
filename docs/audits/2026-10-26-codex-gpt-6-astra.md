Static review covered all 14 project files, with framework-dependent checks against the local ApisCP reference source and Kopia’s upstream source. No files changed. Live exploitation, restores, and the deployed Wasabi policy remain **unverified**.

## 1. Security

**S1 — critical — Root restores follow site-controlled symlinks.**  
[Runner.php:518](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Runner.php:518), [Runner.php:667](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Runner.php:667), [Runner.php:692](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Runner.php:692)

Path validation rejects `..` but does not constrain filesystem resolution. A site owner can replace a live destination file or ancestor directory with a symlink before an administrator restores it. Kopia’s default writer follows these links and truncates the destination as root; this is confirmed in [Kopia’s restore implementation](https://github.com/kopia/kopia/blob/v0.21.1/snapshot/restore/local_fs_output.go). Copy mode is also vulnerable through `~/restore`; pointing it into a document root can expose restored content over HTTP. Subsequent `chown()` follows links too.

**Fix:** Restore into private root-owned staging outside the site. Transfer through a confined, symlink-safe implementation using directory descriptors and no-follow resolution. Prevent concurrent tenant writes during privileged installation; a preliminary `realpath()` check alone leaves a race.

**S2 — high — CSRF protection accepts ambient cookies.**  
[kopiabackup.php:81](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/apps/kopiabackup/kopiabackup.php:81)

A POST without a token—or with an incorrect token—passes whenever the browser supplies the matching CSRF cookie. An attacker-controlled HTTPS sibling origin can submit settings changes, disable the timer, reset state, or trigger destructive restores using the administrator’s cookies. The reference platform sets `SameSite=Strict`, which restricts cross-site requests but does not stop same-site, cross-origin requests.

**Fix:** Require the posted token to match. Handle token rotation with a bounded server-side previous-token allowance, and validate the request origin.

**S3 — high — Master-password initialization races across workers.**  
[Kopiabackup_Module.php:745](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Kopiabackup_Module.php:745), [Kopiabackup_Module.php:733](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Kopiabackup_Module.php:733)

The master is read, generated, and stored without a global lock. On first `run_all`, concurrent workers can each observe no master, create different values, and initialize repositories with different derived passwords. Only the last stored master appears in the recovery kit. `import_master()` has the same check-then-write race. The reference keyring implementation provides no atomic create-if-absent operation.

**Fix:** Serialize initialization and import with one stable global lock, re-read under that lock, and initialize the master before launching workers.

**S4 — high — Lock verification can report expired protection as healthy.**  
[BucketCheck.php:110](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/BucketCheck.php:110), [Runner.php:260](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Runner.php:260)

Retention is compared against object creation time, not current time. An old object whose original lock expired still passes. Maintenance health accepts any recent successful `full-*` task, even when the subsequent lock-extension task failed. Sampling also selects objects before removing exempt logs, potentially checking very few data objects.

**Trigger:** Fail lock renewal while allowing earlier maintenance tasks and new uploads to succeed.

**Fix:** Check remaining protection against a defined minimum, require the latest lock-extension task to succeed, and select non-exempt samples including old live packs and both repository configuration blobs.

**S5 — medium — Probe permissions do not establish repository-wide key safety.**  
[BucketCheck.php:286](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/BucketCheck.php:286), [BucketCheck.php:387](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/BucketCheck.php:387), [index.blade.php:353](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/apps/kopiabackup/views/index.blade.php:353)

All destructive permission probes use `.kopia-backup-probe/`. A policy allowing version deletion or governance bypass specifically under a site repository can pass these probes. Bucket-policy and lifecycle mutation permissions are not checked either, yet the UI claims the key cannot destroy backups. Such permissions can destroy governance-protected history or accelerate deletion after locks expire.

**Fix:** Require and audit explicit denies on destructive version operations and bucket-policy/lifecycle changes across the protected resources. Describe probe results as resource-specific tests, not a complete IAM audit.

## 2. Correctness and PHP

**C1 — high — Snapshot grouping reads the wrong tag key.**  
[Runner.php:380](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Runner.php:380), [Runner.php:447](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Runner.php:447)

Kopia stores `--tags run:VALUE` as `tags["tag:run"]`; the plugin reads `tags["run"]`. Consequently, every current backup uses the minute-based legacy fallback. A file snapshot taking several minutes separates files from its database and ACL snapshots; separate runs within one minute can merge. This is confirmed in [Kopia’s tag implementation](https://github.com/kopia/kopia/blob/v0.21.1/cli/command_snapshot_create.go).

**Fix:** Read `tag:run`, retain the fallback only for genuinely untagged snapshots, and validate source completeness before presenting a restore run.

**C2 — high — Incomplete backups are reported as successful.**  
[Runner.php:240](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Runner.php:240), [Runner.php:373](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Runner.php:373), [Runner.php:399](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Runner.php:399)

File and directory errors are explicitly ignored. Reporting reads only `numFailed`, overlooking `numIgnoredErrors`, and sets `ok=true` regardless. Required sources that fail `file_exists()` disappear silently, so a missing shadow tree can yield a successful database-only backup. ACL-dump failure likewise permits success.

**Fix:** Require the expected sources, inspect ignored errors and incomplete-snapshot status, and distinguish degraded backups from complete ones. Treat missing required ACL metadata as failure. Kopia exposes separate [fatal and ignored error counts](https://github.com/kopia/kopia/blob/master/fs/entry.go).

**C3 — high — Storage changes leave existing sites connected to the old destination.**  
[Runner.php:189](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Runner.php:189)

Any working `kopia.config` is accepted without comparing its endpoint, bucket, prefix, or credentials with saved settings. Changing the prefix or bucket can pass the new bucket test while existing sites continue backing up to the old repository. Newly initialized sites then use a different destination. Saved credentials also take precedence over environment credentials in [Kopia’s S3 provider](https://github.com/kopia/kopia/blob/master/repo/blob/s3/s3_storage.go).

**Fix:** Persist a connection-settings fingerprint and perform an explicit reconnect/migration when it changes. Verify the connected repository identity before backup.

**C4 — high — Restore and reset bypass the backup lock.**  
[Runner.php:55](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Runner.php:55), [Runner.php:500](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Runner.php:500), [Runner.php:584](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Runner.php:584), [Kopiabackup_Module.php:190](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Kopiabackup_Module.php:190)

Only backups acquire the lock. Restores can run during backups, and foreground restores can collide over shared staging filenames. Reset merely observes the lock, releases it, then deletes the directory containing it. A backup starting between those steps can continue on an unlinked lock while another worker acquires a newly created lock.

**Fix:** Put stable per-site locks outside resettable directories. Acquire them around backup, restore, and reset; use unique per-operation staging directories and compatible locking for verification.

**C5 — high — `--delete-extra` deletes intentionally excluded live data.**  
[Runner.php:373](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Runner.php:373), [Runner.php:518](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Runner.php:518), [Runner.php:528](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Runner.php:528)

The snapshot contains the filtered shadow tree, while restoration targets `fst`. Whole-site deletion therefore treats omitted runtime files and ignored directories as extras. Custom exclusions containing valuable data are equally exposed. Kopia’s [delete-extra option](https://github.com/kopia/kopia/blob/master/cli/command_restore.go) removes destination entries absent from the snapshot without knowing why they were omitted.

**Fix:** Prohibit whole-`fst` deletion. Generate an explicit deletion plan restricted to backed-up paths, preserving exclusions and platform-managed content.

**C6 — high — Live database restore can leave a mixed, partially destroyed database.**  
[Runner.php:623](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Runner.php:623), [Runner.php:631](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Runner.php:631)

The dump is streamed directly into the live database without stopping application writers or preserving a rollback copy. The platform export command includes table drops. An import error halfway through leaves earlier tables replaced and later tables untouched; concurrent requests can write during that process. Tables created after the backup remain because they are absent from the dump.

**Fix:** Restore and validate in a separate database, quiesce application writes, then perform a controlled cutover with rollback retained. If in-place replacement remains supported, require a fresh recovery copy and explicitly reconcile obsolete tables.

**C7 — medium — Partial file restores alter ACLs across the entire web tree.**  
[Runner.php:537](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Runner.php:537), [Runner.php:553](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Runner.php:553)

Restoring one folder replays the full absolute-path ACL dump, potentially reverting newer Fortification permissions elsewhere. ACL extraction or replay failure is logged but still returns successful restoration. Because dumping uses `--skip-base`, replay also does not explicitly remove newer extended ACLs from files that originally had none.

**Fix:** Apply ACLs only to restored entries, clear obsolete ACLs there, and propagate failures. Validate and remap absolute paths before replay.

**C8 — medium — Saving the page silently disables weekly verification.**  
[index.blade.php:437](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/apps/kopiabackup/views/index.blade.php:437)

PHP converts numeric string array keys `'0'` through `'6'` into integers. Comparing `(string)$s['verify_day'] === $v` therefore never selects a weekday. The browser defaults to the first option, `Never`; saving any tab submits that value.

**Fix:** Compare `(string)$s['verify_day'] === (string)$v`.

**C9 — medium — Historical database selection uses the newest backup’s databases.**  
[Runner.php:465](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Runner.php:465), [index.blade.php:174](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/apps/kopiabackup/views/index.blade.php:174)

Changing the selected snapshot does not refresh database names. A database deleted after an older backup cannot be selected for recovery; a newly created database remains selectable for a backup that never contained it.

**Fix:** Load database names for the selected run and update the selector whenever that run changes.

**C10 — medium — Valid ApisCP database names cannot be restored.**  
[Runner.php:586](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Runner.php:586)

ApisCP accepts database names containing `-` and `.`, but restoration permits only letters, digits, and underscores. Such databases are backed up and listed, then rejected during recovery.

**Fix:** Use the platform’s database-name validator while retaining exact snapshot-membership checks and safe process arguments.

**C11 — medium — Missing configuration causes a PHP return-type error.**  
[Kopiabackup_Module.php:241](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Kopiabackup_Module.php:241)

`run_all(): array` returns the boolean result of `error()` when settings are missing. PHP 8 throws `TypeError` instead of returning the intended diagnostic result.

**Fix:** Declare `array|bool`, or consistently throw a handled exception.

## 3. Operational

**O1 — high — Lock renewal stops when backups fail or sites become inactive.**  
[Runner.php:70](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Runner.php:70), [Runner.php:74](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Runner.php:74), [Kopiabackup_Module.php:250](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Kopiabackup_Module.php:250)

There is no independent maintenance schedule. Repeated database-dump failures prevent reaching snapshot-driven maintenance; suspended sites disappear from enumeration altogether. Their old backups remain, but locks eventually expire. The explicit `maintenance run` performs quick maintenance; full renewal relies on automatic maintenance during successful snapshot operations. `keep_days=180` does not itself preserve 180 days of immutable protection.

**Fix:** Schedule full maintenance independently for every retained repository, including inactive sites. Alert on renewal failure or insufficient remaining protection. See [Kopia’s maintenance command](https://github.com/kopia/kopia/blob/master/cli/command_maintenance_run.go) and [lock-renewal requirements](https://kopia.io/docs/advanced/ransomware-protection/).

**O2 — medium — The configured parallelism is not a server-wide limit.**  
[Kopiabackup_Module.php:313](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Kopiabackup_Module.php:313), [Kopiabackup_Module.php:384](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Kopiabackup_Module.php:384), [kopia-backup.service:8](/Users/chris/projects/apiscp/kopia-backup/files/systemd/kopia-backup.service:8)

The nightly service and manual `run-all` use different unit names, so both pools can run simultaneously. Individual backup units bypass the pool limit entirely. At 1,000 sites this creates extra database and storage load, duplicate work, and lock-conflict failures.

**Fix:** Route all work through one scheduler with a global concurrency limit and a singleton batch coordinator.

**O3 — high — Hung jobs can stall the nightly service indefinitely.**  
[Kopiabackup_Module.php:391](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Kopiabackup_Module.php:391), [Runner.php:417](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Runner.php:417), [kopia-backup.service:7](/Users/chris/projects/apiscp/kopia-backup/files/systemd/kopia-backup.service:7)

Neither pool children nor backup subprocesses have deadlines. A hung dump or process consumes a worker permanently; enough hangs stop queue progress. The batch remains active, so subsequent timer firings do not start another run, as specified by [systemd timer behavior](https://www.freedesktop.org/software/systemd/man/latest/systemd.timer.html).

**Fix:** Add per-operation deadlines, termination escalation, and durable timeout reporting. Ensure cancellation reaches the backend operation, not merely its `cpcmd` client.

**O4 — medium — A 25-object verification scans and sorts the entire repository.**  
[BucketCheck.php:60](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/BucketCheck.php:60)

Every object is loaded into a PHP array and sorted before sampling. Large repositories can exhaust memory; across 1,000 sites, weekly verification performs a full object inventory for each site merely to select a small sample.

**Fix:** Maintain a bounded sample while paging, fetch configuration blobs directly, and avoid retaining the complete listing. Record candidate objects from backups where practical.

**O5 — medium — Local storage has no aggregate budget or complete cleanup.**  
[Runner.php:329](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Runner.php:329), [Runner.php:602](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Runner.php:602), [Runner.php:718](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Runner.php:718)

Full uncompressed database dumps remain for every site. New dumps temporarily coexist with old ones. Restore dumps survive failures occurring before import cleanup, and verification/restore logs are never pruned. Only `run-*.log` receives the 60-file limit. At 1,000 sites, retained dumps alone can dominate local disk usage; Kopia’s separate log limits are per repository and lack an aggregate byte budget.

**Fix:** Add capacity checks and aggregate limits, clean restore staging in `finally`, prune every plugin log type, and budget Kopia logs/cache explicitly. Kopia’s own defaults are documented in [Logging](https://kopia.io/docs/advanced/logging/).

**O6 — medium — Domain renames break repository continuity.**  
[Runner.php:26](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Runner.php:26), [Runner.php:34](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Runner.php:34), [Runner.php:204](/Users/chris/projects/apiscp/kopia-backup/files/config/custom/kopia-backup/src/Runner.php:204)

Both repository prefix and password derivation use the current primary domain. Renaming a site changes its password and destination while retaining the old local configuration. Historical backups then fall outside the plugin’s normal connection and restore flow.

**Fix:** Persist an immutable repository identifier, derivation input, and destination when first initialized. Make domain changes update display metadata rather than repository identity.

## 4. Ansible/install

**A1 — medium — Blade linting does not validate the template.**  
[tasks/main.yml:59](/Users/chris/projects/apiscp/kopia-backup/tasks/main.yml:59)

Running `php -l` on the raw Blade file treats its directives and expressions as text outside PHP tags. Broken `@php` expressions or Blade-generated PHP can pass deployment checks and break the admin page afterward.

**Fix:** Compile Blade with the installed platform engine, lint the compiled PHP, and perform a render smoke check before activation.

**A2 — medium — The predeployment lint assumes controller and target share paths.**  
[tasks/main.yml:60](/Users/chris/projects/apiscp/kopia-backup/tasks/main.yml:60)

`with_fileglob` resolves files on the Ansible controller, but `command` executes those absolute paths on the managed host. Installation from a separate controller fails or validates unrelated files if the same paths exist there. The documented local installation avoids this condition. [Ansible documents fileglob as controller-local](https://docs.ansible.com/projects/ansible/latest/collections/ansible/builtin/fileglob_lookup.html).

**Fix:** Stage candidate files on the target and validate those exact files before deployment.

**A3 — medium — Existing PHP closing tags defeat hook installation.**  
[tasks/main.yml:111](/Users/chris/projects/apiscp/kopia-backup/tasks/main.yml:111), [tasks/main.yml:125](/Users/chris/projects/apiscp/kopia-backup/tasks/main.yml:125)

Managed blocks are appended without checking whether the existing customization file ends in `?>`. In that case registration/menu code lands outside PHP and can be emitted as response text. The subsequent syntax check still passes.

**Fix:** Insert within a verified PHP context or use a small required helper file. Validate the complete candidate hook before replacing the live file.

**A4 — medium — Failed upgrades can leave a partially deployed installation.**  
[tasks/main.yml:27](/Users/chris/projects/apiscp/kopia-backup/tasks/main.yml:27), [tasks/main.yml:67](/Users/chris/projects/apiscp/kopia-backup/tasks/main.yml:67), [tasks/main.yml:133](/Users/chris/projects/apiscp/kopia-backup/tasks/main.yml:133)

Kopia is upgraded before plugin validation; plugin files and hooks are then changed in place before the final lint. A later failure leaves those changes installed and can prevent the restart handler from running. Concurrent backup processes can also invoke different Kopia versions between successive subprocesses.

**Fix:** Validate a staged release first, serialize activation against backup/restore work, and atomically activate the code with rollback. Coordinate binary upgrades with the same maintenance window.