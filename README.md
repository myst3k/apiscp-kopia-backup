# apiscp-kopia-backup

ApisCP add-in: per-site Kopia backups to an object-locked S3 bucket. Installs Kopia from the official RPM repo, registers the `kopiabackup` module, and adds **System → Kopia Backup** to the admin panel.

Status: early, not yet tested on a live server. The settings page and bucket check are written; scheduled backups and restores are not.

## Install

```sh
# on the server as root
git clone https://github.com/myst3k/apiscp-kopia-backup.git /usr/local/apnscp/resources/playbooks/addins/kopia-backup
cd /usr/local/apnscp/resources/playbooks
ansible-playbook addin.yml --extra-vars=addin=kopia-backup
```

Update with `git -C /usr/local/apnscp/resources/playbooks/addins/kopia-backup pull` and the same `ansible-playbook` line.

The menu is cached per session, so log out and back in to see the link. The add-in hooks itself into `roles/custom/installed`, so `upcp -b` re-runs it and updates Kopia.

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
- Files: the site's `shadow` and `info`. ApisCP's own compressed exports under `mysql_backups/` are excluded.
