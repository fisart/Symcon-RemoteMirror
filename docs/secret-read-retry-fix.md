# RemoteSync failure retry containment

This test branch is based on `feat/manager-integration` (45db19299c8ff3fab27a826d0d763c1dd36ff95b). It leaves that branch and other installations unchanged until they explicitly select the fix. Existing module/instance IDs, credentials, target/root settings and API call signatures are preserved. Version: 1.12.6.

A refused `SEC_GetSecret()` returned no connection record. The original worker requeued its packet in `catch`, then unconditionally scheduled itself again in `finally`, without any delay. This reproduces an unbounded retry and log flood. It is a plausible contributor to reported overload, not evidence of the cause of a host/process crash.

Failed lanes now wait 5, 10, 20, 40, then at most 60 seconds before another attempt. A five-second timer dispatches due retries; incoming events also respect cooldown. Successful sends clear retry state and successful multi-chunk sends keep their immediate continuation. The retry state stays inside the existing `_SyncState` attribute and survives restart. Queue recovery keeps newer in-flight values and separate history entries. An unavailable remote receiver remains queued as a retryable failure.

This does not fix missing/denied credentials, change secret permissions, restore an old vault, or change RPC/TLS transport policy. In particular the pre-existing RPC client TLS settings are unchanged by this stability fix. Unknown remote delivery outcomes retain the existing retry semantics; this change does not add an exactly-once guarantee.

For the affected test installation:

1. Update SymconSecrets on `migration/legacy-sec-replacement` to obtain pure JSON-safe read failures, automatic delayed native binding, bounded logs, and the **Show native connection status** control.
2. Select `fix/secrets-read-retry-storm` for the Remote Mirror library in Module Control and update it. Do not recreate instances, reinstall remote scripts, change target mappings or clear buffers as part of applying this fix.
3. Check the native connection/access status in the existing Secrets instance. Explicit revocations, identity changes and pending settings still require resolution; they are never silently overridden.
4. Verify that failed lanes retry with delay, queues drain after successful credential access/connection recovery, and fresh values reach their expected target. Capture kernel/Symcon/container logs separately if investigating the reported crash.

Automated tests execute the actual module worker with synthetic Symcon and cURL doubles: denied reads/network errors, no immediate respawn, retry cooldown/cap, rejected ScriptEngine dispatch, persisted cooldown, recovery, lane isolation, newest-value/history preservation, unavailable receiver, successful chunking, empty lanes and semaphore cleanup. No live connection or secret is used.
