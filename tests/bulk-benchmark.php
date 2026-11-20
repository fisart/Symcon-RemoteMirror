<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

(static function (int $count) {
    if ($count < 1 || $count > 10000) throw new RuntimeException('Use 1 to 10000 events.');
    foreach ([false, true] as $history) {
        $m = fresh($history);
        $m->properties['BatchIntervalMs'] = 5000;
        $m->properties['MaxBufferSize'] = $count;
        if (!$history) {
            $rows = [];
            $base = json_decode($m->attributes['SyncListCache'], true)[0];
            for ($i=0; $i<$count; $i++) {
                $row = $base;
                $row['ObjectID'] = 20001 + $i;
                obj($row['ObjectID'], 2, 10001);
                $rows[] = $row;
            }
            $m->attributes['SyncListCache'] = json_encode($rows);
        }
        $m->ApplyChanges();
        connectPeer();
        $start = hrtime(true);
        for ($i=0; $i<$count; $i++) event($m,$i,true,$history?20001:20001+$i);
        $capture = (hrtime(true)-$start)/1e9;
        $pending = json_decode($m->GetDeliveryStatus(),true)['lanes'][0];
        check($pending['normalPending']+$pending['historyPending']===$count,'Capture count.');
        forceDue($m);
        for ($pass=0; $pass<$count && (stats($m)['AcceptedUpdates']??0)<$count; $pass++) flushLane($m);
        check(stats($m)['AcceptedUpdates']===$count,'All events acknowledged.');
        $pending = json_decode($m->GetDeliveryStatus(),true)['lanes'][0];
        check($pending['normalPending']+$pending['historyPending']===0,'Queue fully drained.');
        printf("%s: %d events; capture %.3f s; capture and mocked delivery %.3f s\n",$history?'Full History':'Ordinary',$count,$capture,(hrtime(true)-$start)/1e9);
    }
})((int)($argv[1]??1000));
