<?php

/**
 * Ingestion endpoint for the Overland iOS app
 * (https://overland.p3k.app / https://github.com/aaronpk/Overland-iOS).
 *
 * Overland's settings screen has 3 relevant fields, verified directly
 * against that app's own README (an earlier version of this file got
 * this wrong -- assumed there was no separate auth field at all, and
 * baked device_id+key into the Receiver URL's own query string instead;
 * that leaks the shared secret into server/proxy access logs the moment
 * anyone looks at them, which a header never does):
 *
 *   - Receiver URL: just this endpoint, no query string --
 *     https://<your-host>/api/overland
 *   - Access Token: a device's device_key (devicesClassEx::
 *     generateDeviceKey()) -- Overland sends this back as a real
 *     `Authorization: Bearer <token>` header on every request.
 *   - Device ID (optional): purely informational -- Overland embeds
 *     whatever's typed here into every location's own `properties.
 *     device_id`, but this app never reads or trusts that value for
 *     anything. device_key is already `varchar(64) UNIQUE`
 *     (devices.yaml), so the Bearer token alone identifies the device;
 *     there is no second identifier to cross-check, and no reason to
 *     trust anything the client itself claims about which device it is.
 *
 * Deliberately NOT SecurityClass/session-gated -- the caller is the
 * Overland app itself, not a logged-in browser -- same reasoning as
 * zpms's docarc_patients_search_api(). The overland_ingest route has no
 * `access:` in config/settings.info.yaml, so this function is the sole
 * guard.
 */
function zgt_overland_ingest($params) {
    header('Content-Type: application/json');

    $token = zgt_extract_bearer_token();

    if ($token === '') {
        http_response_code(401);
        echo json_encode(['error' => 'unauthorized']);
        exit();
    }

    $device = devicesClassEx::getByDeviceKey($token);
    if (!$device) {
        error_log('[geotrack] overland: unauthorized ingest attempt (unknown token) from '
            . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        http_response_code(401);
        echo json_encode(['error' => 'unauthorized']);
        exit();
    }

    if (!$device->getactive() || $device->getdeleted() !== null) {
        http_response_code(403);
        echo json_encode(['error' => 'device_disabled']);
        exit();
    }

    $deviceGuid = $device->getguid();

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

// Reads the bare token out of `Authorization: Bearer <token>`, checking
// every source PHP might actually surface it under -- a real,
// well-documented gotcha, not defensive-for-its-own-sake: Apache
// commonly does NOT populate $_SERVER['HTTP_AUTHORIZATION'] under
// mod_php/php-fpm unless explicitly configured (`CGIPassAuth On`, or an
// `.htaccess` rule), and after an internal rewrite -- exactly what this
// app's own web/.htaccess does for every request -- Apache frequently
// moves it to REDIRECT_HTTP_AUTHORIZATION instead. getallheaders() (only
// available under the Apache/FPM SAPIs, not php -S's own dev server,
// which is why $_SERVER is still checked too) is tried first since it's
// the most direct, least surprising source when it exists.
function zgt_extract_bearer_token(): string {
    $header = null;

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $header = $value;
                break;
            }
        }
    }

    if ($header === null) {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? null;
    }

    if ($header === null || stripos($header, 'Bearer ') !== 0) {
        return '';
    }

    return trim(substr($header, 7));
}
