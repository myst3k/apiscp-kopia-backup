# apiscp-kopia-backup

ApisCP add-in: per-site Kopia backups to an object-locked S3 bucket. Installs Kopia from the official RPM repo, registers the `kopiabackup` module, and adds **System → Kopia Backup** to the admin panel.

Status: early. Settings, bucket verification, per-site backups and the nightly timer are written; restores are not yet. Not yet proven on a live server beyond the bucket check.

## Install

```sh
# on the server as root
git clone https://github.com/myst3k/apiscp-kopia-backup.git /usr/local/apnscp/resources/playbooks/addins/kopia-backup
cd /usr/local/apnscp/resources/playbooks
ansible-playbook addin.yml --extra-vars=addin=kopia-backup
```

Update with `git -C /usr/local/apnscp/resources/playbooks/addins/kopia-backup pull` and the same `ansible-playbook` line.

The menu is cached per session, so log out and back in to see the link. The add-in hooks itself into `roles/custom/installed`, so `upcp -b` re-runs it. Whether that upgrades Kopia or holds the installed version is set on the admin page (**Kopia** tab: latest or an exact version).

## Bucket

- Create it with object lock enabled. Leave default retention off.
- Add the lifecycle rule shown on the admin page, using an admin key.
- The admin page's **Bucket setup** tab generates this policy for the saved bucket. In Wasabi, create a policy from it, create a user with programmatic access only, attach the policy, and generate an access key for it. `ListBucketVersions` and `GetObjectVersion` are read-only and are what `kopia --point-in-time` recovery needs.

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": [
        "s3:ListBucket",
        "s3:ListBucketVersions",
        "s3:ListBucketMultipartUploads",
        "s3:GetBucketLocation",
        "s3:GetBucketVersioning",
        "s3:GetBucketObjectLockConfiguration",
        "s3:GetLifecycleConfiguration"
      ],
      "Resource": "arn:aws:s3:::BUCKET"
    },
    {
      "Effect": "Allow",
      "Action": [
        "s3:GetObject",
        "s3:GetObjectVersion",
        "s3:PutObject",
        "s3:DeleteObject",
        "s3:AbortMultipartUpload",
        "s3:ListMultipartUploadParts",
        "s3:GetObjectRetention",
        "s3:PutObjectRetention"
      ],
      "Resource": "arn:aws:s3:::BUCKET/*"
    }
  ]
}
```

Enter the settings on the admin page, then **Save & test bucket**. CLI equivalent: `cpcmd kopiabackup:test-bucket`.

## How sites are backed up

- One Kopia repo per site at `<prefix><site>/`, created with splitter `DYNAMIC-1M-BUZHASH` so small scattered row changes re-upload less.
- Databases: each of the site's MySQL databases is dumped nightly as uncompressed SQL with `mysqldump --single-transaction --quick --routines --triggers --events --skip-dump-date` into `/var/lib/kopia-backup/<site>/`. Kopia stores only changed chunks, so each night uploads the difference and every snapshot restores as a complete dump.
- Files: the site's `shadow` and `info`, minus the ignore rules. The global list (**Ignore rules** section) defaults to temp files, logs, page caches, Wordfence logs, backup-plugin archives and ApisCP's own compressed `mysql_backups/`; each site can add its own under **Rules** in the Sites table. Rules are re-applied to the repo policy on every run.

## Running backups

- **Sites** section of the admin page: last run per site, **Back up now** per site, and **Back up all sites**, each queued as a panel job.
- **Kopia** section: how many sites run at once (default 2) and the nightly timer toggle (02:30). Each site runs in its own `cpcmd` process under `nice`/`ionice`.
- **Verify** per site or **Verify all sites**: `kopia snapshot verify` (every referenced object must exist; a configurable percentage of file contents is downloaded and re-hashed) plus a sample of object-lock retention read back from the bucket. Also runs weekly after the nightly backup on the configured day.
- **Reset** per site forgets the local state (connection, cache, reports) without touching the bucket. Use it after deleting a repository in Wasabi; the next run creates a fresh one. Locked objects can only be deleted by the root account with bypass-governance in GOVERNANCE mode, and not at all in COMPLIANCE mode until they expire; in that case change the Prefix instead.
- CLI: `cpcmd kopiabackup:run <site>`, `run-all`, `verify <site>`, `verify-all`, `verify-locks <site>`, `reset <site>`, `status`.

Every repository password derives from one master secret created on first run and kept in the ApisCP keyring. The **Recovery** section shows the full kit (master, derivation, bucket details, and the `kopia` commands to read a repo from any machine); store it in a password manager. Without the master nothing in the bucket can be read. On a rebuilt server: `cpcmd kopiabackup:import-master <master>`.

Per-site state lives in `/var/lib/kopia-backup/<siteN>/` (Kopia config, SQL dumps, ACL dump, `last.json`, logs); cache in `/var/cache/kopia-backup/<siteN>/`. Both are root-only.
