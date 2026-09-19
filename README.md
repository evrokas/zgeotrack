# GeoTrack

A [ZeusFW](https://github.com/evrokas/zeusfw)-based app that ingests GPS
location pings from the [Overland](https://overland.p3k.app/) iOS app and
shows them per-device on a live map (Leaflet + OpenStreetMap) and in a
paginated table. User accounts and role-based access control reuse
ZeusFW core's own RBAC engine, the same "define once in core, every app
just consumes it" pattern zpms/erweb already use.

## How it works

- The Overland app on each phone POSTs a batch of GPS points to this
  app's `/api/overland` endpoint on whatever schedule Overland's own
  settings specify.
- Each point is stored in a `locations` table, tagged with the device
  that sent it.
- Logged-in users see a live map (`/`) of every device's most recent fix,
  refreshed automatically every 20s, plus a time-window selector (Live,
  1m, 5m, 20m, 40m, 60m, 90m) that overlays each device's own recent
  track as a colored line for that window -- and a searchable/paginated
  history table (`/locations`).
- An `operator`/`administrator` account manages devices at `/devices`
  (create/rename/disable, and see each device's Overland setup URL) and
  users/roles at `/admin/{users,roles,permissions,role_permissions,user_roles}`
  (framework-provided, `administrator`-only).

## Setup

### 1. Vendor the ZeusFW framework

`fw/` and `web/core` are both gitignored and never committed -- clone the
framework checkout *into the repo itself* (not a sibling directory --
`fw/bin/init.sh`/`update.sh` below both assume `sql/`, `config/`, `web/`
all live under the same root they're invoked from) and symlink it in:

```sh
git clone https://github.com/evrokas/zeusfw.git fw
ln -s ../fw/core web/core
```

### 2. First-time setup: `fw/bin/init.sh`

Run it from the repo root -- it walks through creating `config/db.php`
and `sql/admin.sql` (both from their `.in` templates, both gitignored:
`sql/admin.sql` is the same real credentials as `config/db.php`, just in
a shape `sql/msql.sh`/`msqldump.sh` below can parse without a PHP
interpreter) and, if you say yes, creates the database user/schema
itself via `sudo mysql -u root -p < sql/admin.sql`:

```sh
./fw/bin/init.sh
```

Answer yes when it asks to update `admin.sql`/`db.php` and to create the
database. Skip the last prompt ("create links to Zeus Framework folder")
-- step 1 above already did that by hand; answering yes there would
re-symlink `web/core` to wherever you type, which isn't necessary here.

`sql/msql.sh`/`sql/msqldump.sh` (thin `mysql`/`mysqldump` wrappers that
read `sql/admin.sql`'s own `CREATE USER`/`CREATE DATABASE` lines for
credentials, so you're never typing a password on the command line) are
committed here, copied verbatim from zpms's own `sql/` -- fully generic,
no zpms-specific values baked in, so they work unmodified for any app on
this framework. Two real constraints inherited from that parsing
approach, confirmed by actually running it, not just reading it:

- **Both scripts call the `gawk` binary by name**, not plain `awk` --
  `mawk` (Debian/Ubuntu's default `awk` provider) doesn't have it under
  that name, so a fresh box needs `apt install gawk` (or equivalent)
  before `init.sh`/`update.sh`'s `sql/msql.sh` calls will work at all,
  confirmed by reproducing "command not found" with only `mawk`
  installed and no `gawk`.
- **Your database password must not contain a single-quote character.**
  `init.sh`'s substitution is a plain `sed`, and the result is a
  single-quoted SQL string with no escaping -- a `'` in the password
  breaks both the generated SQL and `msql.sh`'s own parsing of it back
  out, confirmed by reproducing the exact corrupted output. Any other
  character is fine; this was verified working end-to-end (template ->
  real `admin.sql` -> `msql.sh` correctly recovering the same user/pass/db)
  with a quote-free password.

### 3. Every deploy (including the very first): `fw/bin/update.sh`

```sh
./fw/bin/update.sh
```

Also run from the repo root. This is the framework's own generic
migration runner -- there is no separate one, and no manual
`maker.php spill:class:all`/`spill:sql:all` dance to remember. It:

1. Regenerates every framework-core entity class + SQL from
   `web/core/classes/yaml/*.yaml` (users, roles, permissions,
   role_permissions, user_roles, feed_hashes, ...), offers to create any
   table missing from the database, then shows a `diff:sql:all` and asks
   before applying anything.
2. Does the same for this app's own `web/classes/yaml/{devices,
   locations}.yaml`.
3. Asks about feeder content -- answer no; this app has none.
4. **Scans `web/classes/yaml/*.yaml` for a `form:` key and offers to
   `form:load` it into the `webforms` table.** `devices.yaml` has one --
   say yes. This step is not optional bookkeeping: `formsClass::
   renderForm()`/`renderFormResults()` (what `/devices` actually calls)
   reads the form definition back out of that `webforms` table, not out
   of the yaml file directly, so skipping this step leaves `/devices`
   unable to render its add-device form at all on a fresh install.

Re-run this same script on every future deploy that changes a `.yaml`
schema or a webform's `form:`/`table_view:`/`form_view:` block -- there
is no migration runner beyond this, on any app on this framework.

### 4. Site config

```sh
cp config/site.info.yaml.in config/site.info.yaml
```

### 5. Seed roles/permissions and create the first login

```sh
php bin/setup.php --dry-run     # see what it would do first
php bin/setup.php --yes --admin-user=admin --admin-email=you@example.com --admin-password='a real password'
```

This seeds the `viewer`/`operator`/`administrator` roles (see
`web/rbac_seed.php`) and, with the three `--admin-*` flags, creates one
`administrator` account so there's a way to log in and reach `/admin/users`
at all.

### 6. Point your web server at `web/`

Any server that can run PHP 8.1+ with `mod_rewrite` (or an equivalent
front-controller rewrite to `index.php`) works -- see `web/.htaccess`.

## Adding a device

Overland's own settings screen has 3 separate fields -- verified directly
against [aaronpk/Overland-iOS](https://github.com/aaronpk/Overland-iOS)'s
own docs, not assumed -- and this app uses all 3 rather than cramming
everything into the URL:

1. Log in as an `operator`/`administrator` account and go to **Devices**.
2. Add a device -- a random 64-character key is generated automatically.
3. On the phone, open Overland's settings and set:
   - **Receiver URL**: `https://<your-host>/api/overland` (the same for
     every device -- also shown, with its own copy button, at the top of
     the Devices page).
   - **Access Token**: that device's own **device_key** from the table
     below (click the small copy button next to it) -- Overland sends
     this back as a real `Authorization: Bearer` header, which is what
     actually authenticates the device.
   - **Device ID** (optional): that device's own **guid**, purely so
     it's easy to recognize which device a point came from later. Not
     needed for authentication -- Access Token alone already identifies
     the device (`device_key` is `UNIQUE`).

Overland batches its points locally and retries until this endpoint
responds `{"result":"ok"}` -- a missing/wrong Access Token gets a `401`
and Overland will keep retrying with the same (still-wrong) request until
you fix it on the phone.

**Not the URL-embedded `?device_id=&key=` scheme an earlier version of
this README described** -- that leaked the shared secret into every
server/proxy access log line for that request, which a header never
does. If you're upgrading from that version, re-copy each device's
Access Token into Overland's own field; the old query-string form no
longer authenticates at all.

## Roles and permissions

Three roles are seeded by `bin/setup.php` (`web/rbac_seed.php`):

| Role            | Can do                                                   |
|------------------|-----------------------------------------------------------|
| `viewer`         | View the map and location history                        |
| `operator`       | + create/edit/disable devices                            |
| `administrator`  | Everything, plus `/admin/users` (accounts, roles, grants) |

The RBAC *engine* (schema, `rbacClass::isPermitted()`/`require()`, the
`/admin/{entity}` CRUD UI) is entirely framework-provided -- see
[zeusfw's own `CLAUDE.md`](https://github.com/evrokas/zeusfw/blob/main/CLAUDE.md)
for the full design. This app only owns its own permission vocabulary
(`web/rbac.php`) and the three roles above.

## Known limitations

- **No index on `locations(device_guid, recorded_at)`.** ZeusFW's schema
  YAML has no `index:` option beyond the single-column `UNIQUE`-in-type-
  string trick `devices.device_key` uses -- see `web/classes/yaml/locations.yaml`'s
  own comment. Fine at the scale a handful of phones produce; add the
  index by hand against the live database if query latency becomes
  noticeable at higher device counts or longer retention.
- **No single-user device ownership.** Every `viewer`/`operator`/
  `administrator` sees every device -- there's no per-user device
  scoping. Add an `owner_guid` column and filter on it if this is ever
  used by more than one household/person who shouldn't see each other's
  devices.

## Verified end-to-end

Everything below was driven over real HTTP (`php -S` with a router script
replicating `web/.htaccess`'s actual rewrite semantics) against a real
MariaDB server, not assumed or unit-tested in isolation -- see zeusfw's
own `CLAUDE.md` for the three real bugs this run found and fixed along
the way (`core/lib/FormElement.php`'s checkbox rendering, `core/router/
Request.php`'s query-string route matching, `bin/init.sh`'s database
creation step):

- `fw/bin/init.sh` and `fw/bin/update.sh` end to end -- real database/user
  creation, every core + app table generated and loaded, the `devices`
  webform's `form:load` step, `diff:sql:all` apply.
- `bin/setup.php` seeding roles/permissions and creating the first
  administrator account (this surfaced and fixed a real bug of its own --
  see that file's own comment on the `roles` column).
- Login (`/login` -> `/profile`), the map dashboard (`/`), the devices
  page (`/devices`) including adding a device through the real webform
  POST and editing it back with the Active checkbox and current name
  correctly prefilled, `/locations`, and `/admin/users` (framework-
  provided) -- all 200, all rendering real data.
- The actual Overland ingestion contract: a wrong `key` returns `401`; a
  real GeoJSON batch posted with query-string `device_id`/`key` params
  (the exact shape Overland itself sends, and the exact case that
  exposed the `Request.php` routing bug above) returns
  `{"result":"ok"}` and lands correctly in `locations`, immediately
  visible on both the map's `/api/locations/latest` and the `/locations`
  table.

Not verified: a real Overland-running iPhone (only the HTTP contract it
uses was reproduced by hand) and the map page's own client-side Leaflet
JS (no browser in this environment -- the data endpoint it fetches from
was confirmed correct, but the map rendering itself wasn't screenshotted).

### Follow-up: Overland's real Access Token field, not the URL

The ingestion contract described above (query-string `device_id`/`key`)
was wrong -- built without checking Overland's own docs first. Verified
against [aaronpk/Overland-iOS](https://github.com/aaronpk/Overland-iOS)'s
actual README directly and rebuilt around what it really has: a
`Receiver URL` (no query string), a separate `Access Token` field sent as
a real `Authorization: Bearer` header, and an optional, purely
informational `Device ID`. `device_key` is `UNIQUE`, so the token alone
identifies the device -- there's no longer a second identifier to trust
or cross-check.

Re-verified end to end against the same real MariaDB-backed instance: a
correct Bearer token stores a real point under the server's own resolved
device guid (never trusting anything the client's JSON body claims about
which device it is); a wrong token, a missing `Authorization` header
entirely, and the *old* query-string form (`?device_id=&key=`) all
correctly return `401` now -- confirming the old scheme is fully retired,
not just superseded in the UI. `Authorization` header extraction checks
`getallheaders()`, `$_SERVER['HTTP_AUTHORIZATION']`, and
`$_SERVER['REDIRECT_HTTP_AUTHORIZATION']` -- the last one specifically
because Apache is known to move the header there after an internal
rewrite (exactly what `web/.htaccess` does for every request), a real
gotcha this sandbox's `php -S` dev server can't actually reproduce, so
that specific fallback path is defensive/documented, not itself directly
exercised.

Also re-verified live in a real browser: the Devices page's new Receiver
URL box and its copy button, and a small inline copy button this update
adds next to each row's guid/device_key value -- clipboard contents
confirmed correct for both, no accidental navigation. **Files**:
`web/api_overland.php`, `web/devicesClassEx.php`, `web/index.php`,
`web/classes/yaml/devices.yaml`, `web/templates/content/devices_list.zetem`,
`web/js/devices.js`.

### Follow-up: "remember me" fixed (framework-level), historical map trails, mobile layout

Three reports handled together, since two turned out related once actually investigated:

**"Remember me doesn't work"** -- a real bug in zeusfw core itself, not this app (see zeusfw's own
`CLAUDE.md` for the full writeup). The literal IP filter people expected to be the cause was already
removed in an earlier fix; a second, equally strict filter on **user-agent** sat one step further down
the same cookie-validation path and had been missed -- any UA drift between issuing the remember-me
cookie and using it later (an app update, a browser/OS update, a different WebView) silently failed the
whole auto-login, with zero error message. A second bug in the same function meant even a successful
restore could carry the wrong RBAC roles. Reproduced the failure first (a valid cookie + a different
User-Agent + no session correctly bounced to `/login`) against a disposable checkout of the pre-fix
code, then confirmed the fix resolves it (a real `/devices` load, 200, real content) against this same
MariaDB-backed instance. Nothing in this app itself needed to change -- pull the fixed `fw/` checkout.

**Historical map trails** -- `/api/locations/track/{device_guid}` already existed (built for exactly
this, per its own docblock) but was never wired into the map page. Added `minutes=N` as an alternative
to explicit `from`/`to` (computed server-side against the server's own `now()`, deliberately -- see
`web/api_locations.php`'s own comment on why computing this in the visitor's browser and sending a
timezone-naive string risked silently asking for the wrong window), a row of time-window buttons (Live,
1m, 5m, 20m, 40m, 60m, 90m) above the map, and a colored polyline per device for whichever window is
selected. The map now also polls `/api/locations/latest` every 20s on its own, so "Live" actually means
something rather than a snapshot frozen at page load.

Verified two ways, since this sandbox's outbound-HTTPS policy blocks every CDN (`unpkg.com`, `jsdelivr`,
`cdnjs`), including the one this page's own Leaflet `<script>` tag already used -- a real Leaflet map
was never something this environment could render, not this feature's own limitation: (1) the server's
own `minutes=` computation, directly, against real points posted through the actual `/api/overland`
ingestion endpoint (not hand-inserted SQL -- an early attempt at that produced a false failure, traced
to MySQL's `NOW()` being UTC while this app's own PHP-side timestamp handling runs in `Europe/Athens,`
config's `tz:` -- self-consistent for real ingested data, since both sides go through the same `date()`
call, but not for a raw `NOW()`-seeded row): `minutes=1/5/20/40/60/90` against 6 points spaced
2/8/15/35/55/85 minutes apart returned exactly 0/1/3/4/5/6 points, matching what each window should
contain. (2) `web/js/map.js` itself, run directly in Node against a minimal stub standing in for the
`L` (Leaflet) global -- confirmed the real file builds all 7 buttons, and that clicking "60m" fetches
exactly `/api/locations/track/<guid>?minutes=60` and would hand `L.polyline()` the correct point pairs.
Also hardened while doing this: the whole page's script used to die on its very first line if Leaflet's
CDN was ever slow/unreachable (`L is not defined`, an uncaught exception) -- the window buttons and the
device sidebar list no longer depend on Leaflet having loaded at all, confirmed live under the exact
CDN-blocked condition this sandbox already forces.

**Mobile layout was confined to a sliver, not full width** -- this stylesheet had zero `@media` queries
at all before now. Confirmed live at a real 390px phone viewport: `.map-canvas` rendered **74px** wide
(`.map-sidebar`'s fixed 260px left almost nothing), effectively unusable. Added a `max-width: 768px`
breakpoint stacking the sidebar above the map instead of beside it, both at full available width. A
first attempt at the breakpoint's own CSS had a second bug, also only caught by measuring the real
rendered page rather than reading the CSS: `.map-canvas`'s base `flex: 1` set a `flex-basis` that
silently overrode the media query's own explicit `height: 60vh` (rendered 2px tall) -- fixed by turning
off flex-sizing (`flex: none`) on the stacked elements so the explicit heights actually apply. Re-measured
after the fix: full-width `.map-canvas` at a real, usable height, zero horizontal page overflow.

**Known, disclosed, not fixed**: the framework's own hamburger-menu nav (`core/templates/nav/
main_navigation.zetem`) is a pure checkbox-driven CSS toggle that core deliberately ships unstyled (see
zeusfw's own "bare unstyled fallback, app can override" convention) -- this app never added the CSS to
make it actually toggle, so the raw checkboxes/icons show up uncollapsed on narrow screens (visible in
the mobile screenshots taken while verifying the fix above). Pre-existing, not touched by this pass,
and not what was reported -- flagged here rather than fixed silently or left unmentioned. **Files**:
`zeusfw/core/ClassExFW.php`, `zeusfw/core/kernel/Kernel.php` (remember-me, separate repo),
`web/api_locations.php`, `web/js/map.js`, `web/templates/content/homepage.zetem`, `web/css/styles.css`.
