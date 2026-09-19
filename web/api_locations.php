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
    } elseif (isset($_GET['calendar'])) {
        // The map page's time-window dropdown has two entries that aren't
        // expressible as "N minutes/hours before now" at all -- "Last
        // week"/"Last month" mean the previous *calendar* week/month,
        // wherever "now" happens to fall inside the current one. Every
        // other window on that dropdown (1h/2h/.../30 days) is a plain
        // rolling duration and stays on the `minutes=` path below,
        // unchanged -- this branch only exists for the two that
        // genuinely need calendar-boundary math, computed here against
        // the server's own `now()` for the same timezone-consistency
        // reason `minutes=` already documented above.
        [$from, $to] = zgt_calendar_range((string)$_GET['calendar']);
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

// [$from, $to] ('Y-m-d H:i:s' strings, inclusive) for zgt_api_locations_track()'s
// `calendar=` param. Weeks are ISO (Monday start) -- there's no other
// week-start convention anywhere in this app to match, and Monday-start
// is the standard default absent one. "Last week"/"last month" both mean
// the previous full calendar period relative to the server's own `now()`
// (already active-timezone-correct by construction, since every
// `strtotime()`/`date()` call here runs through the same tz `minutes=`
// already relies on) -- never the visitor's own, which this endpoint has
// no reliable way to know.
function zgt_calendar_range(string $which): array {
    $now = time();

    if ($which === 'lastweek') {
        $dayOfWeek = (int)date('N', $now); // 1 (Mon) .. 7 (Sun)
        $thisMonday = strtotime('-' . ($dayOfWeek - 1) . ' days', strtotime(date('Y-m-d', $now)));
        $lastMonday = strtotime('-7 days', $thisMonday);
        $lastSunday = strtotime('-1 day', $thisMonday);
        return [date('Y-m-d 00:00:00', $lastMonday), date('Y-m-d 23:59:59', $lastSunday)];
    }

    if ($which === 'lastmonth') {
        $firstOfThisMonth = strtotime(date('Y-m-01', $now));
        $firstOfLastMonth = strtotime('-1 month', $firstOfThisMonth);
        $lastOfLastMonth = strtotime('-1 day', $firstOfThisMonth);
        return [date('Y-m-d 00:00:00', $firstOfLastMonth), date('Y-m-d 23:59:59', $lastOfLastMonth)];
    }

    // Unknown value -- fail toward "no data" (an empty, valid range) same
    // as this endpoint's other inputs never fall through to a fatal
    // error for a malformed request; the map's own dropdown never sends
    // anything but 'lastweek'/'lastmonth' into this branch to begin with.
    return [date('Y-m-d H:i:s', $now), date('Y-m-d H:i:s', $now)];
}
