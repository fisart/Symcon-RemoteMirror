<?php
declare(strict_types=1);
require_once __DIR__ . '/../libs/Journal.php';

$makeEvent = static fn(int $producer, int $n) => [
    'MappingID' => hash('sha256','fixture'), 'LocalID' => 10101,
    'EventID' => substr(hash('sha256', $producer . '/' . $n), 0, 32), 'Value' => [$producer,$n]
];
if (($argv[1] ?? '') === 'writer') {
    $journal = new RemoteSyncJournalV2($argv[2]);
    for ($i = 0; $i < 12; $i++) $journal->append($makeEvent((int)$argv[3], $i));
    exit;
}
$checks = 0;
$expect = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    $checks++;
};
$dir = sys_get_temp_dir() . '/rs-journal-' . bin2hex(random_bytes(12));
$journal = new RemoteSyncJournalV2($dir);
$processes = [];
foreach ([1,2] as $producer) {
    $process = proc_open([PHP_BINARY,'-n',__FILE__,'writer',$dir,(string)$producer], [], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start test writer.');
    $processes[] = $process;
}
foreach ($processes as $process) $expect(proc_close($process) === 0,'Writer failed.');
$rows = $journal->peek(24); $events = array_column($rows,'payload');
$expect(count(array_unique(array_column($events,'EventID'))) === 24,'Concurrent writers lost or duplicated events.');
$expect(array_column($events,'Sequence') === range(1,24),'Concurrent sequence allocation is consistent.');
$ids = array_column($events,'EventID');
$journal->settle(array_slice($rows,0,12), array_slice($ids,0,12));
$expect($journal->summary()['count'] === 12,'Partial acknowledgements advance the cursor.');
$files = glob($dir . '/streams/*/[0-9]*.json');
$expect(count($files) === 12,'Acknowledged files reclaimed with backlog still pending.');
$stateFile = glob($dir . '/streams/*/state.json')[0];
$saved = file_get_contents($stateFile);
$event = $makeEvent(3,0);$journal->append($event);
RemoteSyncJournalV2::durableWrite($stateFile,$saved);
$reopened = new RemoteSyncJournalV2($dir);
$expect($reopened->summary()['count'] === 13,'Restart recovers event written before cursor commit.');
$rows = $reopened->peek(13);$ids = array_column(array_column($rows,'payload'),'EventID');
$reopened->settle($rows,$ids);
$expect($reopened->summary()['count'] === 0 && !$reopened->summary()['bytes'],'Drained queue frees its records.');
$reopened->append($makeEvent(4,0));$rows = $reopened->peek(1);
$expect($rows[0]['payload']['Sequence'] === 26,'Reused stream continues its sequence after drain.');
echo "PASS on-disk journal: $checks assertions\n";
