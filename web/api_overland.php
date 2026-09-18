<?php

/**
 * Ingestion endpoint for the Overland iOS app
 * (https://overland.p3k.app / https://github.com/aaronpk/Overland-iOS).
 * Overland's own settings screen only offers a single "Receiver URL" --
 * no custom headers, no separate auth fields -- so the device identity
 * and shared secret both travel as query-string parameters baked
 * directly into that one URL:
 *
 *   https://<your-host>/api/overland?device_id=<devices.guid>&key=<device_key>
 *
 * (See web/devicesClassEx.php::generateDeviceKey() for how device_key is
 * created, and the Devices admin page for where to copy this exact URL
 * from per-device.)
 *
 * Deliberately NOT SecurityClass/session-gated -- the caller is the
 * Overland app itself, not a logged-in browser -- same reasoning as
 * zpms's docarc_patients_search_api(). The overland_ingest route has no
 * `access:` in config/settings.info.yaml, so this function is the sole
 * guard.
 */
function zgt_overland_ingest($params) {
    header('Content-Type: application/json');

    $deviceGuid = (string)($_GET['device_id'] ?? '');
    $presentedKey = (string)($_GET['key'] ?? '');

    if ($deviceGuid === '' || $presentedKey === '') {
        http_response_code(401);
        echo json_encode(['error' => 'unauthorized']);
        exit();
    }

    $device = devicesClassEx::getByGuid($deviceGuid);
    if (!$device || !hash_equals((string)$device->getdevice_key(), $presentedKey)) {
        error_log('[geotrack] overland: unauthorized ingest attempt for device_id=' . $deviceGuid
            . ' from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        http_response_code(401);
        echo json_encode(['error' => 'unauthorized']);
        exit();
    }

    if (!$device->getactive() || $device->getdeleted() !== null) {
        http_response_code(403);
        echo json_encode(['error' => 'device_disabled']);
        exit();
    }

    $body = json_decode((string)file_get_contents('php://input'), true);
    $locations = is_array($body) ? ($body['locations'] ?? []) : [];
    if (!is_array($locations)) {
        $locations = [];
    }

    $stored = 0;
    foreach ($locations as $feature) {
        if (!is_array($feature)) {
            continue;
        }
        if (locationsClassEx::insertFromOverlandPoint($deviceGuid, $feature)) {
            $stored++;
        }
    }

    error_log('[geotrack] overland: ' . $stored . '/' . count($locations)
        . ' point(s) stored for device_id=' . $deviceGuid);

    // {"result":"ok"} is the exact shape Overland checks for before it
    // clears these points from its own local retry queue -- anything
    // else (including a different JSON body, or an HTTP error status)
    // makes it keep retrying the same batch indefinitely.
    echo json_encode(['result' => 'ok']);
    exit();
}
