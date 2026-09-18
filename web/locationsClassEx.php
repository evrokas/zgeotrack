<?php

// Extends the maker-generated locationsClass (web/classes/yaml/locations.yaml)
// with the Overland ingestion parser and every query the map/table pages
// need. Self-loaded via that yaml's `extention:` directive.

class locationsClassEx extends locationsClass {

    // Parses one GeoJSON Feature from Overland's POST body
    // ({"locations":[<feature>, ...]}) and inserts it as a row for
    // $deviceGuid. Returns false (and logs why) for a structurally
    // invalid feature instead of throwing -- one bad point in a batch
    // must never sink the rest of that batch (web/api_overland.php loops
    // over every feature and keeps going regardless of this return
    // value).
    static function insertFromOverlandPoint(string $deviceGuid, array $feature): bool {
        $geometry = $feature['geometry'] ?? null;
        $coords = $geometry['coordinates'] ?? null;
        if (!is_array($coords) || count($coords) < 2 || !is_numeric($coords[0]) || !is_numeric($coords[1])) {
            error_log('[geotrack] overland point skipped: missing/malformed geometry.coordinates');
            return false;
        }

        $lon = (float)$coords[0];
        $lat = (float)$coords[1];
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            error_log('[geotrack] overland point skipped: coordinates out of range');
            return false;
        }

        $props = (array)($feature['properties'] ?? []);

        // Overland sends an ISO-8601 timestamp; fall back to "now" (server
        // receipt time) if it's missing or unparseable rather than
        // dropping the point entirely -- a slightly-wrong recorded_at is
        // far more useful than a silently lost GPS fix.
        $ts = isset($props['timestamp']) ? strtotime((string)$props['timestamp']) : false;
        $recordedAt = $ts !== false ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');

        // Overland's own convention: -1 means "not available" for both
        // speed and battery_level -- stored as NULL, not literal -1, so
        // every consumer (map popups, the table page, any future
        // average-speed query) doesn't have to special-case the sentinel.
        $speed = isset($props['speed']) && is_numeric($props['speed']) && $props['speed'] >= 0
            ? (float)$props['speed'] : null;
        $altitude = isset($props['altitude']) && is_numeric($props['altitude']) ? (float)$props['altitude'] : null;
        $accuracy = isset($props['horizontal_accuracy']) && is_numeric($props['horizontal_accuracy'])
            ? (float)$props['horizontal_accuracy'] : null;
        $batteryLevel = isset($props['battery_level']) && is_numeric($props['battery_level']) && $props['battery_level'] >= 0
            ? (float)$props['battery_level'] : null;
        $batteryState = isset($props['battery_state']) ? (string)$props['battery_state'] : null;
        $motion = isset($props['motion']) && is_array($props['motion'])
            ? implode(',', array_map('strval', $props['motion'])) : null;

        $loc = new locationsClass([
            'guid' => guid(),
            'cdate' => getDBtime(),
            'device_guid' => $deviceGuid,
            'recorded_at' => $recordedAt,
            'lat' => $lat,
            'lon' => $lon,
            'altitude' => $altitude,
            'speed' => $speed,
            'accuracy' => $accuracy,
            'battery_level' => $batteryLevel,
            'battery_state' => $batteryState,
            'motion' => $motion,
            'raw' => json_encode($props),
        ]);
        return (bool)$loc->insert();
    }

    private static function hydrate(array $rows): array {
        $out = [];
        foreach ($rows as $row) {
            $l = new locationsClass('locations');
            $l->loadFields($row);
            $out[] = $l;
        }
        return $out;
    }

    // One row per device -- its single most recent fix -- for the
    // dashboard/map "where is everything right now" view. A plain
    // per-device MAX(recorded_at) correlated subquery rather than a
    // window function: MariaDB versions still in real use in this
    // ecosystem (see zeusfw's own MariaDB-only test setup) predate
    // reliable ROW_NUMBER() support, and this table is never large
    // enough (one row per device, not per ping) for the subquery's cost
    // to matter.
    static function getLatestPerDevice(): array {
        $db = dbConnection::getConnection();
        $sql = "SELECT l.* FROM locations l
                INNER JOIN (
                    SELECT device_guid, MAX(recorded_at) AS max_recorded_at
                    FROM locations GROUP BY device_guid
                ) latest
                ON l.device_guid = latest.device_guid AND l.recorded_at = latest.max_recorded_at
                ORDER BY l.recorded_at DESC";
        return self::hydrate($db->query($sql)->fetchAll());
    }

    // Most recent $limit points for one device, newest first -- used by
    // the table page. $beforeGuid (optional) paginates by excluding
    // everything at-or-after a previously-seen row's own timestamp,
    // rather than a numeric OFFSET, since new rows keep arriving between
    // page loads (an OFFSET-based page 2 would silently skip/repeat rows
    // as ingestion continues; anchoring on the last-seen recorded_at
    // does not).
    static function getRecentForDevice(string $deviceGuid, int $limit = 100, ?string $beforeRecordedAt = null): array {
        $db = dbConnection::getConnection();
        $sql = "SELECT * FROM locations WHERE device_guid = :guid";
        if ($beforeRecordedAt !== null) {
            $sql .= " AND recorded_at < :before";
        }
        $sql .= " ORDER BY recorded_at DESC LIMIT :limit";
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':guid', $deviceGuid, PDO::PARAM_STR);
        if ($beforeRecordedAt !== null) {
            $stmt->bindValue(':before', $beforeRecordedAt, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return self::hydrate($stmt->fetchAll());
    }

    // Every point for one device within [from, to] (inclusive,
    // 'Y-m-d H:i:s' strings), oldest first -- the shape the map page's
    // polyline/playback needs. $limit is a hard safety cap, not a page
    // size -- the map draws the whole range in one request.
    static function getForDeviceRange(string $deviceGuid, string $from, string $to, int $limit = 5000): array {
        $db = dbConnection::getConnection();
        $stmt = $db->prepare(
            "SELECT * FROM locations WHERE device_guid = :guid
             AND recorded_at BETWEEN :from AND :to
             ORDER BY recorded_at ASC LIMIT :limit"
        );
        $stmt->bindValue(':guid', $deviceGuid, PDO::PARAM_STR);
        $stmt->bindValue(':from', $from, PDO::PARAM_STR);
        $stmt->bindValue(':to', $to, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return self::hydrate($stmt->fetchAll());
    }
}
