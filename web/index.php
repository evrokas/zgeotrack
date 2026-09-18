<?php

include_once(__DIR__ . "/../config/db.php");       // load database parameters

require_once(__FWDIR__ . '/bootstrap.php');
require_once(__DIR__ . '/rbac.php');
require_once(__DIR__ . '/api_overland.php');
require_once(__DIR__ . '/api_locations.php');

ini_set('session.gc_maxlifetime', 3600);

$kernel = new Kernel($_SERVER, "../config");
SecurityClass::init($kernel->getConfig('roles'));

$router = new RouterClass($kernel->getConfig('routes'));

Renderer::init($kernel->getConfig('templates'), false, $kernel->safeGetConfig('template_cache_path'), $kernel->getConfig('enable_comments'));

$Request = new RequestClass($_SERVER);

zeusfw_session_start();

// login.zetem always renders csrf_field(), and every webform this app
// defines (devices.yaml) does too via generateHTMLForm() -- so both
// enforcement switches are safe to turn on unconditionally. See
// csrfClass::$enforceLogin/$enforceWebforms's own docblocks (zeusfw
// core/lib/Csrf.php).
csrfClass::enableLoginProtection();
csrfClass::enableWebformProtection();

// Safe unconditionally -- every account starts at wrongpasscount=0, so
// this can't retroactively lock anyone out (see LoginSecurityClass's own
// docblock, zeusfw core/lib/UserLogin.php). Deliberately NOT also calling
// enableAccountStatusEnforcement() -- this app has no existing accounts
// whose active/expired columns predate that check, but bin/setup.php's
// seeded accounts are created with the correct values anyway, so there's
// no reason to skip it either... except that any account created by hand
// later must remember to set both columns correctly, same caveat
// zpms's own CLAUDE.md documents. Left off to match that framework-wide
// default rather than assume this app's operator will remember.
LoginSecurityClass::enableLockout();

// Send an anonymous visitor straight to /login instead of a bare 401 for
// any `access:`-gated route -- see SecurityClass::$loginRedirectUrl's own
// docblock (zeusfw core/lib/Security.php). homepage() below applies the
// same treatment to '/' itself, which has no `access:` of its own.
SecurityClass::enableLoginRedirect('/login');

$kernel->isUserLoggedin();
$kernel->setCurrentLanguage('en');

ob_start();

registerModules();

$match = $router->matchRoute($Request);
$_SESSION['route_match'] = $match;
$_SESSION['request'] = $Request->getQueryRoute();

$content_response = $router->routerCallFunction($match);

$kernel->renderPage();

ob_end_flush();


/* ----- website handlers ----- */

function homepage($params) {
    global $kernel;

    // '/' has no `access:` of its own (see settings.info.yaml) since it
    // renders different content per login state -- same reasoning
    // zpms's own homepage() gives for this same explicit check.
    if (!SecurityClass::userLoggedIn()) {
        header('location: ' . rel_url('/login'));
        exit();
    }

    return Renderer::render('homepage.zetem', [
        'device_count' => count(devicesClassEx::getAllActive()),
    ]);
}

function zgt_profile($params) {
    global $kernel;

    $uname = $kernel->getUserName();
    $account = usersClassEx::getUserAccount($uname);

    return Renderer::render('profile.zetem', [
        'display_name' => $account ? $account->getname() : $uname,
        'can_manage_devices' => rbacClass::isPermitted(ZGT_PERM_DEVICES_MANAGE),
        'can_manage_users' => rbacClass::isPermitted(ZEUSFW_PERM_MANAGE_USERS),
    ]);
}

function zgt_devices_list($params) {
    if (($errmsg = rbacClass::require(ZGT_PERM_DEVICES_MANAGE))) return $errmsg;

    // The exact, bare Receiver URL to paste into Overland -- no query
    // string, since device identity/auth now travel as Overland's own
    // separate Access Token field (Authorization: Bearer header) instead
    // (see web/api_overland.php's own docblock for why). rel_url() only
    // ever returns a path -- Overland's settings screen needs the full
    // absolute URL, so scheme/host are added by hand from the request.
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $overlandBaseUrl = $scheme . '://' . $host . rel_url('/api/overland');

    return Renderer::render('devices_list.zetem', [
        'devices_table' => formsClass::renderFormResults('devices'),
        // device_key is a hidden form input (devices.yaml) pre-filled
        // here with a freshly generated key -- see that yaml's own
        // comment for why (storeFormResults() only auto-fills guid/
        // cdate/cuser on insert, nothing else).
        'devices_form' => formsClass::renderForm('devices', null, [
            'device_key' => devicesClassEx::generateDeviceKey(),
        ]),
        'overland_base_url' => $overlandBaseUrl,
    ]);
}

// GET /devices/{guid}/edit -- same convention as zpms's clinics_row_edit().
function zgt_devices_row_edit($params) {
    if (($errmsg = rbacClass::require(ZGT_PERM_DEVICES_MANAGE))) return $errmsg;

    $device = devicesClassEx::getByGuid($params['guid'] ?? '');
    if (!$device) return error_404();

    return formsClass::renderForm('devices', null, [
        'name' => $device->getname(),
        'notes' => $device->getnotes(),
        'active' => $device->getactive(),
        'row_guid' => $device->getguid(),
        // Carried back unchanged -- editing a device must never rotate
        // its Overland key out from under an already-configured phone.
        'device_key' => $device->getdevice_key(),
    ]);
}

function devices_delete_url($guid, $fields) {
    return rel_url('/webform/delete/devices/' . $guid);
}

function devices_edit_url($guid, $fields) {
    return rel_url('/devices/' . $guid . '/edit');
}

function zgt_locations_table($params) {
    if (($errmsg = rbacClass::require(ZGT_PERM_LOCATIONS_VIEW))) return $errmsg;

    $devices = devicesClassEx::getAllActive();
    $selectedGuid = (string)($_GET['device'] ?? '');
    $before = isset($_GET['before']) ? (string)$_GET['before'] : null;

    $perPage = 50;
    $devicesByGuid = [];
    foreach ($devices as $d) {
        $devicesByGuid[$d->getguid()] = $d;
    }

    if ($selectedGuid !== '' && isset($devicesByGuid[$selectedGuid])) {
        $locs = locationsClassEx::getRecentForDevice($selectedGuid, $perPage + 1, $before);
    } else {
        $selectedGuid = '';
        $locs = [];
        foreach ($devices as $d) {
            $locs = array_merge($locs, locationsClassEx::getRecentForDevice($d->getguid(), $perPage + 1, $before));
        }
        usort($locs, fn($a, $b) => strcmp($b->getrecorded_at(), $a->getrecorded_at()));
        $locs = array_slice($locs, 0, $perPage + 1);
    }

    $hasMore = count($locs) > $perPage;
    $locs = array_slice($locs, 0, $perPage);

    $rows = [];
    foreach ($locs as $l) {
        $device = $devicesByGuid[$l->getdevice_guid()] ?? null;
        $rows[] = [
            'device_name' => $device ? $device->getname() : $l->getdevice_guid(),
            'recorded_at' => $l->getrecorded_at(),
            'lat' => $l->getlat(),
            'lon' => $l->getlon(),
            'speed' => $l->getspeed(),
            'altitude' => $l->getaltitude(),
            'battery_level' => $l->getbattery_level(),
            'battery_pct' => $l->getbattery_level() !== null ? round($l->getbattery_level() * 100) : null,
        ];
    }

    return Renderer::render('locations_table.zetem', [
        'devices' => $devices,
        'selected_device_guid' => $selectedGuid,
        'rows' => $rows,
        'has_more' => $hasMore,
        'next_before' => $hasMore ? end($rows)['recorded_at'] : null,
    ]);
}
