<?php

/**
 * Session-authenticated JSON consumed by the map page's own vanilla JS
 * (web/js/map.js) -- distinct from web/api_overland.php, which
 * authenticates by device key instead of a login session. Both routes
 * carry `access: authenticated` in config/settings.info.yaml, so
 * Router.php already refuses an anonymous request before either handler
 * runs; the rbacClass::require() calls below are the finer-grained
 * locations-view check on top of that.
 */

function zgt_api_locations_latest($params) {
    header('Content-Type: application/json');

    if (($errmsg = rbacClass::require(ZGT_PERM_LOCATIONS_VIEW))) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        exit();
    }

    $devicesByGuid = [];
    foreach (devicesClassEx::getAllActive() as $d) {
        $devicesByGuid[$d->getguid()] = $d;
    }

    $out = [];
    foreach (locationsClassEx::getLatestPerDevice() as $loc) {
        $device = $devicesByGuid[$loc->getdevice_guid()] ?? null;
        if (!$device) {
            // Latest fix belongs to a device that's since been disabled/
            // deleted -- skip rather than show a location with no name.
            continue;
        }
        $out[] = [
            'device_guid' => $loc->getdevice_guid(),
            'device_name' => $device->getname(),
            'lat' => (float)$loc->getlat(),
            'lon' => (float)$loc->getlon(),
            'recorded_at' => $loc->getrecorded_at(),
            'speed' => $loc->getspeed() !== null ? (float)$loc->getspeed() : null,
            'battery_level' => $loc->getbattery_level() !== null ? (float)$loc->getbattery_level() : null,
        ];
    }

    echo json_encode(['devices' => $out]);
    exit();
}

function zgt_api_locations_track($params) {
    header('Content-Type: application/json');

    if (($errmsg = rbacClass::require(ZGT_PERM_LOCATIONS_VIEW))) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        exit();
    }

    $deviceGuid = (string)($params['device_guid'] ?? '');
    $device = $deviceGuid !== '' ? devicesClassEx::getByGuid($deviceGuid) : null;
    if (!$device) {
        http_response_code(404);
        echo json_encode(['error' => 'not_found']);
        exit();
    }

    // Defaults to the last 24 hours -- the map's own "show track" feature
    // is meant for "where has this device been recently", not a full
    // history dump (that's what /locations, the table page, is for).
    //
    // `minutes=N` is the map page's own preferred way to ask for this
    // (js/map.js's time-window buttons -- 1/5/20/40/60/90 minutes) rather
    // than computing an explicit `from` client-side: `date()` here runs in
    // whatever timezone Kernel::__construct() set from config/
    // settings.info.yaml's `tz:` (Europe/Athens), which has no reason to
    // match a visitor's own browser/device timezone -- computing `from` in
    // the browser and sending it as a wall-clock string risked silently
    // asking for the wrong window (or, worse, a window that looks
    // plausible but is off by the visitor's UTC offset). Doing the
    // subtraction here, against the server's own `now()`, sidesteps that
    // entirely. `from`/`to` stay supported as explicit overrides for any
    // other caller that already knows the exact range it wants.
    $to = isset($_GET['to']) ? (string)$_GET['to'] : date('Y-m-d H:i:s');
    if (isset($_GET['from'])) {
        $from = (string)$_GET['from'];
    } elseif (isset($_GET['minutes']) && is_numeric($_GET['minutes'])) {
        $minutes = max(1, (int)$_GET['minutes']);
        $from = date('Y-m-d H:i:s', strtotime($to) - $minutes * 60);
    } else {
        $from = date('Y-m-d H:i:s', strtotime($to) - 86400);
    }

    $points = [];
    foreach (locationsClassEx::getForDeviceRange($deviceGuid, $from, $to) as $loc) {
        $points[] = [
            'lat' => (float)$loc->getlat(),
            'lon' => (float)$loc->getlon(),
            'recorded_at' => $loc->getrecorded_at(),
        ];
    }

    echo json_encode(['device_name' => $device->getname(), 'points' => $points]);
    exit();
}
