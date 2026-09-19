(function () {
    'use strict';

    var PALETTE = ['#2563eb', '#dc2626', '#16a34a', '#d97706', '#7c3aed', '#0891b2', '#db2777', '#65a30d'];

    // Minutes shown as buttons alongside "Live" -- the exact set asked
    // for (1, 5, 20, 40, 60, 90). 0 means "Live": just each device's
    // current position, no trail, same as this page's original behavior.
    var WINDOWS = [0, 1, 5, 20, 40, 60, 90];

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

    function timeAgo(isoLike) {
        var then = new Date(isoLike.replace(' ', 'T') + 'Z').getTime();
        var diffSeconds = Math.max(0, Math.round((Date.now() - then) / 1000));
        if (diffSeconds < 60) return diffSeconds + 's ago';
        if (diffSeconds < 3600) return Math.round(diffSeconds / 60) + 'm ago';
        if (diffSeconds < 86400) return Math.round(diffSeconds / 3600) + 'h ago';
        return Math.round(diffSeconds / 86400) + 'd ago';
    }

    function windowLabel(minutes) {
        return minutes === 0 ? 'Live' : minutes + 'm';
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
        var windowButtonsEl = document.getElementById('map-window-buttons');
        var locationsUrl = mapEl.getAttribute('data-locations-url');
        var trackUrlTemplate = mapEl.getAttribute('data-track-url-template'); // has __GUID__ placeholder

        var markers = {};       // device_guid -> current-position circleMarker
        var trackLines = {};    // device_guid -> polyline (only while a window > 0 is active)
        var latestDevices = []; // last-fetched /api/locations/latest response
        var currentWindow = 0;  // 0 = Live (no trail)
        var hasFitBoundsOnce = false;

        // Built before Leaflet is ever touched, and independent of it --
        // none of this needs a map instance to exist. If the Leaflet CDN
        // is ever slow/unreachable for a real visitor (this app loads it
        // from unpkg.com, a single external host with no local fallback),
        // the window buttons and the device sidebar should still work
        // rather than the whole script dying on the very first line that
        // touches the (then-undefined) `L` global.
        function buildWindowButtons() {
            if (!windowButtonsEl) return;
            WINDOWS.forEach(function (minutes) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'map-window-btn' + (minutes === currentWindow ? ' active' : '');
                btn.textContent = windowLabel(minutes);
                btn.setAttribute('data-minutes', String(minutes));
                btn.addEventListener('click', function () {
                    if (minutes === currentWindow) return;
                    currentWindow = minutes;
                    windowButtonsEl.querySelectorAll('.map-window-btn').forEach(function (b) {
                        b.classList.toggle('active', b === btn);
                    });
                    refreshTracks();
                });
                windowButtonsEl.appendChild(btn);
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
            // touching it, so the rest of the page (buttons, sidebar
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
            if (currentWindow === 0 || !trackUrlTemplate) return;

            latestDevices.forEach(function (d) {
                var url = trackUrlTemplate.replace('__GUID__', encodeURIComponent(d.device_guid))
                    + '?minutes=' + currentWindow;

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

        buildWindowButtons();
        refreshLatest();
        setInterval(refreshLatest, REFRESH_MS);
    });
})();
