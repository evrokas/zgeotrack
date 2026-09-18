<?php

// Extends the maker-generated devicesClass (web/classes/yaml/devices.yaml)
// with lookup/creation helpers. Self-loaded by the generated class file
// via that yaml's `extention:` directive -- see zeusfw core's
// ernsauth_sso_attempts.yaml / CLAUDE.md entry for the convention this
// follows.

class devicesClassEx extends devicesClass {

    // 64 hex chars -- matches devices.yaml's `varchar(64) UNIQUE`
    // device_key column exactly. This is the shared secret Overland's
    // "Receiver URL" carries as ?key=..., not a value a human ever types,
    // so there's no reason to keep it short/memorable.
    static function generateDeviceKey(): string {
        return bin2hex(random_bytes(32));
    }

    static function getByGuid(string $guid): ?devicesClass {
        $rows = devicesClass::sgetAllFilter('devices', ['guid' => $guid]);
        return $rows[0] ?? null;
    }

    // Excludes soft-deleted rows -- callers that need to include them
    // (there are none today) should query devicesClass directly.
    static function getAllActive(): array {
        $db = dbConnection::getConnection();
        $stmt = $db->query("SELECT * FROM devices WHERE deleted IS NULL ORDER BY name ASC");
        $out = [];
        while ($row = $stmt->fetch()) {
            $d = new devicesClass('devices');
            $d->loadFields($row);
            $out[] = $d;
        }
        return $out;
    }
}
