<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

// Uses saved module properties, selections and numeric hierarchy only. No production RPC or Secrets calls.
if ($argc !== 3) throw new RuntimeException('Usage: php -n rehearse-snapshot.php settings-file instance-id');
(static function (string $path, int $instance) {
    $snapshot = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $objects = $snapshot['objects'];
    $data = $objects['ID' . $instance]['data'];
    if (strtoupper($data['moduleID']) !== '{C6D3D6A3-0442-4217-9150-70588661C02D}') throw new RuntimeException('Not a RemoteSync instance.');
    $GLOBALS['kernel'] = $GLOBALS['suiteRoot'] . '/snapshot';
    $module = new RemoteSync();
    $module->InstanceID = $instance;
    $module->Create();
    $module->properties = $data['configuration'] + $module->properties;
    $module->attributes['SyncListCache'] = $data['attributes']['SyncListCache'];
    $before = $module->ExportConfig();
    foreach ($objects as $key => $o) {
        $id = (int)substr($key, 2);
        if ($id) obj($id, (int)$o['type'], (int)$o['parentID']);
    }
    $module->ApplyChanges();
    check($module->ExportConfig() === $before, 'Saved properties and selection bytes preserved.');
    check($module->InstanceID === $instance, 'Instance ID preserved.');
    $result = json_decode($module->ValidateConfigurationBackup($before), true);
    check($result['valid'], 'Native schema accepts saved settings.');
    check(!$GLOBALS['requests'] && empty($GLOBALS['reads']), 'Offline rehearsal did not access a service.');
    printf("Instance %d: %d targets, %d roots, %d selection rows preserved; %d checks passed.\n",
        $instance, count(json_decode($data['configuration']['Targets'], true)), count(json_decode($data['configuration']['Roots'], true)),
        count(json_decode($data['attributes']['SyncListCache'], true)), $GLOBALS['testCount']);
})($argv[1], (int)$argv[2]);
