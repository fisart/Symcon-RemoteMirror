# Upgrade from RemoteSync 1.13.0

The sender keeps library ID `{58A6A420-5614-4161-9A31-931089943321}`, module ID `{C6D3D6A3-0442-4217-9150-70588661C02D}` and the `RS` prefix. The new RemoteSync Receiver uses prefix `RSR`. The minimum runtime is IP-Symcon 7.0 with PHP 8.2, cURL and a writable kernel data directory.

## Install the upgrade

1. Back up the existing Symcon installations, settings and scripts. Record one dashboard mirror ID and a representative reverse control on each destination.
2. In Symcon Module Control, use the gear next to the existing `fisart/Symcon-RemoteMirror` library to select `feat/remotesync-1.14.0`. Upgrade all participating systems during the same maintenance window. For a destination that previously hosted only generated scripts, add the same repository and select the same branch once. Both modules are in the same library. Keep the existing sender instance. Apply it if the kernel has yet to apply it. [Symcon Module Control](https://www.symcon.de/de/service/dokumentation/modulreferenz/kern-instanzen/module-control/)
3. The sender reads its existing targets, secret references, roots, variable selections and delivery flags. It creates one companion per sender and target under the saved remote script root. Setup runs automatically at kernel readiness and retries unavailable destinations. **Verify companion** repeats setup on demand. The registered receiver stores the allowed root mappings, source identity and reverse Secrets reference. A selected reverse action also causes a read-only HTTPS call back to the source during setup.
4. Verify that your recorded mirror ID receives a fresh value, the dashboard still points to it and an action reaches the actual device. Confirm the mirrored value changes when the source reports it. Use **Show delivery status** to check for retained history and the Symcon log for rejected items.

Targets need authenticated JSON-RPC over HTTPS, with a certificate trusted by the calling Symcon host and valid for the saved hostname. A bare host or host with a port is interpreted as HTTPS. A saved HTTP-only endpoint requires a working HTTPS endpoint in its existing Secrets entry. The module uses the operating installation's trust store. Credentials remain in Secrets. Library upgrades and certificate deployment are operator actions.

Starting in 1.14.4, if cURL reports a missing issuer before sending an HTTP request, RemoteSync can retry once with its bundled Let's Encrypt issuers. It checks their SHA-256 checksum and validates each issuer against the CA file already used by PHP cURL. The retry keeps certificate and hostname verification enabled and uses a temporary copy of that CA file. Existing connections with valid chains use the original request path. [Public issuer sources](https://letsencrypt.org/certificates/)

Starting in 1.14.5, a missing source root, source variable, or destination root pauses only the affected mapping or variable. The Symcon log reports the missing object ID. Healthy mappings continue, including others on the same destination. The sender checks destination roots during setup and periodically rechecks paused roots. Queued **Full History** remains bound to its original mapping and resumes when that mapping and its objects are available again. Ordinary updates remain best effort, and **Auto-Create** can still recreate a missing mirror variable under an existing root.

Existing mirrors are matched using their saved source reference within the mapped destination root. The numeric object ID, existing ident and location are preserved. Starting in 1.14.6, when multiple mirrors carry that reference, the receiver uses the unique mirror whose path below the destination root exactly matches the path below the source root, including the variable name. When none or more than one matches, only that variable is rejected and queued **Full History** stays pending. Conflicting idents still produce an error for that variable. Duplicate cleanup remains an operator action. Run **Verify companion** after editing the destination tree to refresh the receiver's mirror index.

For controls, Symcon requires a script ID as a variable custom action. A verified stock 1.13.0 gateway is reused as a short adapter. A customized or shared gateway is preserved and the companion creates its own adapter. All reverse-action logic lives in the companion. A failed or missing action handler reports failure. The gateway calls `RequestAction` and waits for ordinary replication to confirm the displayed value.

A requested deletion applies to an owned mirror without children. A mirror containing other objects is held for review. Empty destination containers remain in place.

Temporary gaps or repeats during mixed-version deployment are possible. On first apply the sender copies remaining routable 1.13.0 buffered values into the durable queue. Values already removed by an old worker cannot be recovered. Startup and manual synchronization can also record a current-value snapshot. After cutover, verify Full History by disconnecting a test destination briefly, then comparing its acknowledged records with the accepted updates after the queue drains.

## Delivery after cutover

| Selection | Behavior |
| --- | --- |
| Ordinary | Latest pending value per mapping and variable. Delivery is best effort. A later update or manual sync refreshes current state. |
| Immediate | Starts dispatch without the configured batching delay. The ordinary buffering and failure policy applies. |
| Full History | Persists each accepted source update before sending. The selection includes equal values. Records remain queued until acknowledged individually. |
| Both | Immediate dispatch with Full History retention. |

A transport failure backs off per lane, starting at five seconds and increasing to sixty seconds. An item rejection delays its own variable stream for thirty seconds. Other variables on the lane can continue. The sender releases storage for acknowledged records as it drains. Local disk or permission errors can prevent new events from being accepted. Consult the sender log and recover the storage promptly.

Source history, stable identity and pending routes are under `remotesync/<sender instance ID>/` in the kernel directory. Receiver history is under `remotesync-received/<receiver instance ID>/history/`; each completed JSON record is named by its event ID. Source timestamps and repeated values are preserved. A pending receipt allows interrupted application to resume. History files are independent of Archive Control and remain available until the operator archives them. Back up these directories together with Symcon. Configuration exports cover settings and selection rows.

File and receipt commits use durable file writes and same-directory renames. On Windows PHP cannot flush a directory through this implementation. Device power-loss durability needs verification on the actual storage hardware.

## Administration and monitoring

In 1.14.1, if the sender cannot confirm protocol 2, open its **Debug log** and look for the `RSR_Configure` entry. It includes the destination receiver ID and reply type. On the destination, open the **Debug log** variable under that receiver ID and compare the entries at the same time. The receiver records whether it saved the binding or which setup stage failed. A receiver records setup diagnostics automatically. Enable **Debug Mode** on the sender to include successful setup calls as well as failures.

If the sender reports `returned string ("2"). Expected int (2).`, update the sender to 1.14.2. This release accepts the integer `2` and exact string `"2"` as valid setup confirmations. A 1.14.1 receiver already installed on the destination can serve a 1.14.2 sender. Verify that the existing mirror ID receives a fresh value after the sender restarts delivery.

For delivery diagnosis in 1.14.3, update the destination library and open **Debug log** below the existing receiver. The same entries appear in Symcon's system log for that receiver instance. **Waiting for a batch** identifies the point immediately after reload. **RSR_Receive started** identifies an incoming batch, and the completion entry lists counts and example source and mirror IDs. Rejections show the failing step and IDs. Enable **Trace every received batch** temporarily when comparing repeated updates. These diagnostics record metadata without copying variable values or packet contents.

The existing `RS_GetSyncState` entry point provides its legacy buffer, event, start, deadline and retry fields. Requesting this detailed snapshot reads the pending records. Use `RS_GetDeliveryStatus` for lightweight per-lane counts and bytes, and `RS_GetTrafficStatistics` for capture, filtering and transport counters. The existing per-target performance variables retain their idents.

The native form exposes configuration export/import, delivery status and the bounded Debug variable. To use the optional web page, enable web administration and open the path returned by `RS_GetWebURL` on the installation's HTTPS origin. It uses the selected Secrets instance's existing passkey session. The page supports variable selections, pause/resume, sync, validation, export/import and debugging. Drain pending delivery before pausing for a configuration import. Import is for intentional configuration transfer. In-place upgrades reuse their saved settings directly.

## Rollback

For a planned rollback, drain accepted history, pause capture, and restore the 1.13.0 library and the matching pre-upgrade remote action scripts or restore the installation backups together. The old sender cannot drain the 1.14.0 on-disk queue. Preserve the journal directories when recovering pending history. Restoring a pre-upgrade snapshot can reverse values written since that snapshot. Switching the sender branch alone cannot restore a replaced legacy gateway.

## Offline validation

The PHP suites in `tests/` run with `php -n` and mock Symcon and network calls. The sender and companion execute their actual module methods; the journal writes real temporary files. The snapshot rehearsal takes a settings file and an instance ID and checks preservation without sending packets. The browser test exercises the administration page at two viewport sizes with intercepted APIs. A two-host Symcon rehearsal is needed to verify a particular site's certificate chain, action handlers, passkey session and kernel behavior.
