(function () {
    'use strict';

    var PALETTE = ['#2563eb', '#dc2626', '#16a34a', '#d97706', '#7c3aed', '#0891b2', '#db2777', '#65a30d'];

    // Every time-window option, grouped for the <select>'s <optgroup>s.
    // Each entry resolves to either a `minutes` value (a plain rolling
    // duration -- reuses /api/locations/track's existing `minutes=N`,
    // computed server-side against the server's own clock) or a
    // `calendar` key ('lastweek'/'lastmonth' -- genuinely calendar-bound,
    // not expressible as "N minutes ago"; see web/api_locations.php's own
    // zgt_calendar_range()). 'live' (no group) means no trail at all --
    // just each device's current position, this page's original behavior.
    //
    // 60m and 1h (and 1440m/1d) are literally the same duration -- kept
    // as a single entry with the more natural label at that scale (1h,
    // not 60m) rather than two buttons for one duration.
    var WINDOW_GROUPS = [
        {
            group: null,
            options: [{ key: 'live', label: 'Live' }]
        },
        {
            group: 'Minutes',
            options: [
                { key: 'min-1', label: '1 minute', minutes: 1 },
                { key: 'min-5', label: '5 minutes', minutes: 5 },
                { key: 'min-20', label: '20 minutes', minutes: 20 },
                { key: 'min-40', label: '40 minutes', minutes: 40 }
            ]
        },
        {
            group: 'Hours',
            options: [
                { key: 'h-1', label: '1 hour', minutes: 60 },
                { key: 'h-1.5', label: '1.5 hours', minutes: 90 },
                { key: 'h-2', label: '2 hours', minutes: 120 },
                { key: 'h-6', label: '6 hours', minutes: 360 },
                { key: 'h-12', label: '12 hours', minutes: 720 }
            ]
        },
        {
            group: 'Days',
            options: [
                { key: 'd-1', label: '1 day', minutes: 1440 },
                { key: 'd-2', label: '2 days', minutes: 2880 },
                { key: 'd-5', label: '5 days', minutes: 7200 }
            ]
        },
        {
            group: 'Calendar',
            options: [
                { key: 'week', label: 'Last 7 days', minutes: 10080 },
                { key: 'lastweek', label: 'Last week (Mon-Sun)', calendar: 'lastweek' },
                { key: 'month', label: 'Last 30 days', minutes: 43200 },
                { key: 'lastmonth', label: 'Last month', calendar: 'lastmonth' }
            ]
        }
    ];

    // Flat key -> option lookup, built once, so refreshTracks() doesn't
    // need to re-walk the grouped structure on every call.
    var WINDOWS_BY_KEY = {};
    WINDOW_GROUPS.forEach(function (g) {
        g.options.forEach(function (opt) { WINDOWS_BY_KEY[opt.key] = opt; });
    });

    // How often the map re-polls while left open, so "Live" actually
    // means something rather than a one-time snapshot at page load.
    var REFRESH_MS = 20000;

    function colorForDevice(guid) {
        var hash = 0;
        for (var i = 0; i < guid.length; i++) {
            hash = (hash * 31 + guid.charCodeAt(i)) & 0xffffffff;
        }
        return PALETTE[Math.abs(hash) % PALETTE.length];
    }

    // web/api_locations.php now emits recorded_at as a proper ISO-8601
    // string with an explicit, DST-correct UTC offset (e.g.
    // '2026-09-19T13:05:14+03:00' for Europe/Athens in summer) -- Date()
    // parses that unambiguously, no guessing/appending 'Z' needed. (An
    // earlier version of this function assumed the string was bare UTC and
    // appended 'Z' itself; the API actually sent Athens local time with no
    // offset at all, which under-reported age by the UTC offset -- e.g. a
    // 98-minute-old point showed as "0s ago", clamped by the Math.max(0,
    // ...) below. Fixed at the source in api_locations.php instead of
    // guessing the offset here.)
    function timeAgo(isoLike) {
        var then = new Date(isoLike).getTime();
        var diffSeconds = Math.max(0, Math.round((Date.now() - then) / 1000));
        if (diffSeconds < 60) return diffSeconds + 's ago';
        if (diffSeconds < 3600) return Math.round(diffSeconds / 60) + 'm ago';
        if (diffSeconds < 86400) return Math.round(diffSeconds / 3600) + 'h ago';
        return Math.round(diffSeconds / 86400) + 'd ago';
    }

    function escapeHtml(s) {
        var div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }

    document.addEventListener('DOMContentLoaded', function () {
        var mapEl = document.getElementById('geotrack-map');
        if (!mapEl) return;

        var listEl = document.getElementById('device-list');
        var windowSelectEl = document.getElementById('map-window-select');
        var locationsUrl = mapEl.getAttribute('data-locations-url');
        var trackUrlTemplate = mapEl.getAttribute('data-track-url-template'); // has __GUID__ placeholder

        var markers = {};       // device_guid -> current-position circleMarker
        var trackLines = {};    // device_guid -> polyline (only while a window other than 'live' is active)
        var latestDevices = []; // last-fetched /api/locations/latest response
        var currentWindow = 'live';
        var hasFitBoundsOnce = false;

        // Built before Leaflet is ever touched, and independent of it --
        // none of this needs a map instance to exist. If the Leaflet CDN
        // is ever slow/unreachable for a real visitor (this app loads it
        // from unpkg.com, a single external host with no local fallback),
        // the window selector and the device sidebar should still work
        // rather than the whole script dying on the very first line that
        // touches the (then-undefined) `L` global.
        function buildWindowSelect() {
            if (!windowSelectEl) return;
            WINDOW_GROUPS.forEach(function (g) {
                var container = windowSelectEl;
                if (g.group) {
                    container = document.createElement('optgroup');
                    container.label = g.group;
                    windowSelectEl.appendChild(container);
                }
                g.options.forEach(function (opt) {
                    var optionEl = document.createElement('option');
                    optionEl.value = opt.key;
                    optionEl.textContent = opt.label;
                    container.appendChild(optionEl);
                });
            });
            windowSelectEl.addEventListener('change', function () {
                currentWindow = windowSelectEl.value;
                refreshTracks();
            });
        }

        var map = null;
        try {
            map = L.map(mapEl).setView([37.9838, 23.7275], 6); // Athens, until real data arrives
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(map);
        } catch (err) {
            // Leaflet itself didn't load (CDN unreachable, blocked, or
            // slow) -- every function below already checks `map` before
            // touching it, so the rest of the page (selector, sidebar
            // list data) still works; only the actual map canvas doesn't.
            console.error('GeoTrack: Leaflet failed to initialize -- the map itself will be unavailable', err);
        }

        function clearTracks() {
            if (!map) return;
            Object.keys(trackLines).forEach(function (guid) {
                map.removeLayer(trackLines[guid]);
            });
            trackLines = {};
        }

        function refreshTracks() {
            if (!map) return;
            clearTracks();

            var windowDef = WINDOWS_BY_KEY[currentWindow];
            if (!windowDef || currentWindow === 'live' || !trackUrlTemplate) return;

            var query = windowDef.calendar
                ? 'calendar=' + encodeURIComponent(windowDef.calendar)
                : 'minutes=' + windowDef.minutes;

            latestDevices.forEach(function (d) {
                var url = trackUrlTemplate.replace('__GUID__', encodeURIComponent(d.device_guid))
                    + '?' + query;

                fetch(url, { headers: { 'Accept': 'application/json' } })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        var points = (data.points || []).map(function (p) { return [p.lat, p.lon]; });
                        if (points.length < 2) return; // nothing to draw a line between

                        var color = colorForDevice(d.device_guid);
                        trackLines[d.device_guid] = L.polyline(points, {
                            color: color,
                            weight: 3,
                            opacity: 0.6
                        }).addTo(map);
                    })
                    .catch(function (err) {
                        console.error('GeoTrack: failed to load track for ' + d.device_guid, err);
                    });
            });
        }

        function renderLatest(devices) {
            latestDevices = devices;

            if (listEl) listEl.innerHTML = '';

            // The sidebar device list (name/dot/last-seen) is independent
            // of the map itself, so it's still built even if `map` is
            // null (Leaflet failed to load) -- only the marker/bounds
            // half below is skipped in that case.
            devices.forEach(function (d) {
                var color = colorForDevice(d.device_guid);
                if (listEl) {
                    var li = document.createElement('li');
                    li.className = 'device-item';
                    li.setAttribute('data-device-guid', d.device_guid);
                    li.innerHTML = '<span class="device-dot" style="background:' + color + '"></span>'
                        + '<span class="device-name">' + escapeHtml(d.device_name) + '</span>'
                        + '<span class="device-lastseen">' + timeAgo(d.recorded_at) + '</span>';
                    li.addEventListener('click', function () {
                        if (!map || !markers[d.device_guid]) return;
                        map.setView([d.lat, d.lon], 15);
                        markers[d.device_guid].openPopup();
                    });
                    listEl.appendChild(li);
                }
            });

            if (!map) return;

            // Current-position markers: remove stale ones (a device that
            // stopped reporting, or was disabled) and add/update the rest,
            // rather than tearing down and rebuilding every marker on
            // every refresh tick -- keeps popups/selection state stable
            // across the periodic Live refresh.
            var seenGuids = {};
            var bounds = [];

            devices.forEach(function (d) {
                seenGuids[d.device_guid] = true;
                var color = colorForDevice(d.device_guid);
                bounds.push([d.lat, d.lon]);

                var popupHtml = '<strong>' + escapeHtml(d.device_name) + '</strong><br>'
                    + timeAgo(d.recorded_at)
                    + (d.speed !== null ? '<br>Speed: ' + d.speed.toFixed(1) + ' m/s' : '')
                    + (d.battery_level !== null ? '<br>Battery: ' + Math.round(d.battery_level * 100) + '%' : '');

                if (markers[d.device_guid]) {
                    markers[d.device_guid].setLatLng([d.lat, d.lon]);
                    markers[d.device_guid].setPopupContent(popupHtml);
                } else {
                    markers[d.device_guid] = L.circleMarker([d.lat, d.lon], {
                        radius: 8,
                        color: color,
                        fillColor: color,
                        fillOpacity: 0.9
                    }).addTo(map).bindPopup(popupHtml);
                }
            });

            Object.keys(markers).forEach(function (guid) {
                if (!seenGuids[guid]) {
                    map.removeLayer(markers[guid]);
                    delete markers[guid];
                }
            });

            // Only auto-fit the view once, on first load -- refitting on
            // every 20s refresh would fight anyone who's manually panned/
            // zoomed to look at a specific device in the meantime.
            if (!hasFitBoundsOnce && bounds.length) {
                hasFitBoundsOnce = true;
                if (bounds.length === 1) {
                    map.setView(bounds[0], 15);
                } else {
                    map.fitBounds(bounds, { padding: [30, 30] });
                }
            }
        }

        function refreshLatest() {
            return fetch(locationsUrl, { headers: { 'Accept': 'application/json' } })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    renderLatest(data.devices || []);
                    refreshTracks();
                })
                .catch(function (err) {
                    console.error('GeoTrack: failed to load locations', err);
                });
        }

        buildWindowSelect();
        refreshLatest();
        setInterval(refreshLatest, REFRESH_MS);
    });
})();
