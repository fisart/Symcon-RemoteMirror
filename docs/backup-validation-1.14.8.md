# Configuration backup validation, 1.14.8

Reports for RemoteSync instances 13566, 41754 and 28315 retained the native export but warned that the module validator returned no valid result. The validator returned `scope: RemoteSync.Configuration.v1`; the central v1 wire protocol requires the literal `scope: format`. The native format remains in the export envelope's `format` field and payload's `schema` field.

The fix changes that single validator field. Source binding, payload hashes, parser rejection, configuration data and import safety remain unchanged. Existing generations retain their original warning; create a fresh configuration backup after updating RemoteSync on each affected installation.

The release is based on `feat/remotesync-1.14.0` at `6cab237fd7f124812096df25352b2101074bf960`, which merged 1.14.7 and has the same tree as `f31418907bed86431f630c96eec7b8b23604bf3b`. GitHub ancestry confirms it includes the manager integration, retry containment, 1.14.0 work and fixes through 1.14.7. The installed branch was not available for independent inspection. The older repository default `main` has six README-only commits outside this history; those historical 1.8/1.9 explanations were not merged into the 1.14 documentation. Do not use `main` as the consolidated 1.14 release.

## Independent interoperability test

`tests/backup-protocol.php` runs the real RemoteSync exporter and validator through the central `MCBBackupProtocol::capture` and `MCBEngine::snapshot`. It reproduces the warning before the scope correction and passes afterward. It also verifies unchanged native payloads, read-only behavior, credential exclusion, source-property/selection mismatch rejection, invalid configuration rejection, module identity and payload-hash rejection, and canonical numeric metadata vectors.

The default reference files are unchanged copies from `fisart/Symcon-Backup-Module` revision `208c377efdabf816aa4cf05f2f9b1dc060149b12`:

| File | Git blob |
| --- | --- |
| Engine.php | 2b9c1d513b13476632d3ac4e5ba02865c6dd360f |
| BackupProtocol.php | 27987db251320039d98c44ba01010b0e87d56f0a |
| SourceContract.php | 307ab55ff684b0bfe6e837244d451cdf5ee18205 |
| ConfigurationScope.php | 36af30626239b9a3767492195b1e04ac53966fe0 |

To test another checked-out central version, set `REMOTESYNC_BACKUP_ENGINE` to its absolute `ConfigurationBackup/Engine.php` path. CI runs the pinned interoperability test alongside the delivery, traffic, migration and journal suites. The five local PHP suites passed 147 assertions on PHP 8.4.28. No production restore or live module installation was performed.

The separate Hermitage test secret-read failures are not caused by this scope field. They still require a successful authorized Secrets application read before GitHub delivery can be confirmed.
