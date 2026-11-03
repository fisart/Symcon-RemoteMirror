# Remote Mirror 1.13.0: fewer requests and secret lookups

On branch `fix/secrets-read-retry-storm`, ordinary variable updates now collect for a fixed one-second window per target/lane. The latest pending value for each ordinary variable wins. Updates reported unchanged by Symcon are skipped before payload construction, queue writes or secret access. A manual/initial sync still sends current values.

## Installation and settings

Update the Remote Mirror library on `fix/secrets-read-retry-storm`, then open instance 28315 and apply/save its settings. Do not change the Secrets library branch for this update. The existing receiver and gateway scripts remain compatible; **Install Remote is not required**.

| Setting | Default | Behavior |
| --- | --- | --- |
| Batch window | 1000 ms | Fixed deadline from the first pending update, configurable 0–5000 ms. Zero dispatches immediately. Continuous updates do not extend the deadline. |
| Skip unchanged ordinary values | Enabled | Uses Symcon's VM_UPDATE change flag. Missing metadata is treated as an update. Disable to forward repeated ordinary values, still coalesced within a batch. |
| Immediate (per variable in Step 3) | Disabled | Bypasses the batch window and retains every queued update, including repeated values. Enable for alarms, pulses, commands or heartbeat/event signals that must not be coalesced. Save selections after changing it. |
| Full Hist. (existing per-variable setting) | Preserved | Keeps every queued update, including repeated values, in order. These updates can share a batch. This does not introduce timestamped archive import or exactly-once delivery. |

Ordinary signals may now arrive about one second later plus scheduling/network delay. Brief ordinary transitions can be coalesced: use Immediate or Full Hist. when every transition matters. Immediate still respects lane serialization and failed-connection backoff; it cannot bypass a failed or busy connection. Manual deletion requests bypass the batch window. Queue limits still apply and overflow is logged/counted.

Existing mappings, instance IDs, action flags, profiles and credentials are retained. Existing retry backoff remains 5/10/20/40/60 seconds. Successful chunks of up to 400 queued entries continue promptly. Worker reservations prevent duplicate queued jobs, expire after 30 seconds if a dispatched job never starts, and pending queues resume after Apply Changes/restart. The dispatcher timer stops when idle or when only retry cooldowns remain. Pending work from previous lane-count settings is also considered.

Credentials are freshly requested for each actual batch; the change does not cache credentials between batches or bypass revoked access. Profile replication and receiver behavior are unchanged. This reduces application traffic; it does not prove or guarantee a fix for console disconnections.

## Measuring the result

Use **Performance Monitoring → Reset traffic counters**, run a representative workload, then **Show traffic counters**. Alternatively, in a local script for the known test instance:

```php
<?php
echo RS_GetTrafficStatistics(28315);
```

The instance form buttons discover their own instance ID through `$id`.

- `RPCRequests`: outgoing JSON-RPC attempts made by this module's client, including failed attempts and script discovery/setup calls.
- `RequestBodyBytes`: sum of JSON request body bytes passed to cURL, including failed attempts. Excludes response bodies, HTTP/TLS overhead and transport retransmissions; it is not a wire-byte measurement.
- `SecretLookups`: actual local `SEC_GetSecret` attempts to initialize a connection, including failed reads.
- `FilteredUpdates`: unchanged ordinary updates skipped per destination mapping.
- `CoalescedUpdates`: ordinary pending entries replaced by newer values.
- `DroppedUpdates`: entries rejected because a lane reached its queue limit.
- `SuccessfulBatches`: batches for which the receiver RPC returned without an exception, not an independent verification of every remote value.
- `Since`: counter start/reset time as a Unix timestamp.

Counters are held in module RAM buffers and reset on runtime buffer loss/restart or manual reset. They do not write persistent attributes per request and contain no secret values, endpoint URLs or payloads. Counter increments use a bounded lock and are best-effort under lock contention. Existing performance variables and their separate publication interval still work.

For a useful comparison, measure equal periods with the same workload. Immediate unfiltered behavior uses Batch window 0 and Skip unchanged disabled; then compare with the defaults. Requests and lookups should fall for bursts or repeated readings. Savings depend on update rates, lane distribution, network time and Immediate/Full Hist. selections.

## Validation

Run `php -n tests/retry-regression.php` and `php -n tests/traffic-regression.php`. These exercise the actual module with Symcon/cURL doubles and no network or live secrets. They cover fixed deadlines, unchanged filtering without durable writes, fresh credentials/revocation, repeated full-history and urgent transitions, concurrent dispatch suppression, failed dispatch/backoff, expired worker reservations, restart, lane changes, in-flight updates, chunking, overflow and counter privacy/accuracy.

The deterministic synthetic burst of 100 changed and 100 unchanged updates uses 200 RPC requests/secret lookups with immediate unfiltered delivery and one with the defaults. It demonstrates the batching/filtering mechanism; it is not a measurement of a live installation.
