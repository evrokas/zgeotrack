(function () {
    'use strict';

    var PALETTE = ['#2563eb', '#dc2626', '#16a34a', '#d97706', '#7c3aed', '#0891b2', '#db2777', '#65a30d'];

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

    function escapeHtml(s) {
        var div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }

    document.addEventListener('DOMContentLoaded', function () {
        var mapEl = document.getElementById('geotrack-map');
        if (!mapEl) return;

        var listEl = document.getElementById('device-list');
        var locationsUrl = mapEl.getAttribute('data-locations-url');

        var map = L.map(mapEl).setView([37.9838, 23.7275], 6); // Athens, until real data arrives
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);

        var markers = {};

        fetch(locationsUrl, { headers: { 'Accept': 'application/json' } })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                var devices = data.devices || [];
                var bounds = [];

                devices.forEach(function (d) {
                    var color = colorForDevice(d.device_guid);
                    var marker = L.circleMarker([d.lat, d.lon], {
                        radius: 8,
                        color: color,
                        fillColor: color,
                        fillOpacity: 0.9
                    }).addTo(map);

                    var popupHtml = '<strong>' + escapeHtml(d.device_name) + '</strong><br>'
                        + timeAgo(d.recorded_at)
                        + (d.speed !== null ? '<br>Speed: ' + d.speed.toFixed(1) + ' m/s' : '')
                        + (d.battery_level !== null ? '<br>Battery: ' + Math.round(d.battery_level * 100) + '%' : '');
                    marker.bindPopup(popupHtml);

                    markers[d.device_guid] = marker;
                    bounds.push([d.lat, d.lon]);

                    if (listEl) {
                        var li = document.createElement('li');
                        li.className = 'device-item';
                        li.setAttribute('data-device-guid', d.device_guid);
                        li.innerHTML = '<span class="device-dot" style="background:' + color + '"></span>'
                            + '<span class="device-name">' + escapeHtml(d.device_name) + '</span>'
                            + '<span class="device-lastseen">' + timeAgo(d.recorded_at) + '</span>';
                        li.addEventListener('click', function () {
                            map.setView([d.lat, d.lon], 15);
                            markers[d.device_guid].openPopup();
                        });
                        listEl.appendChild(li);
                    }
                });

                if (bounds.length === 1) {
                    map.setView(bounds[0], 15);
                } else if (bounds.length > 1) {
                    map.fitBounds(bounds, { padding: [30, 30] });
                }
            })
            .catch(function (err) {
                console.error('GeoTrack: failed to load locations', err);
            });
    });
})();
