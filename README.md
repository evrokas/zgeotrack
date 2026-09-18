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
  and a searchable/paginated history table (`/locations`).
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

1. Log in as an `operator`/`administrator` account and go to **Devices**.
2. Add a device -- a random 64-character key is generated automatically.
3. In the table below, note that device's **guid** and **device_key**
   columns.
4. On the phone, open Overland's settings and set the **Receiver URL**
   to:

   ```
   https://<your-host>/api/overland?device_id=<guid>&key=<device_key>
   ```

   (the exact URL, with the real guid/key already substituted, is also
   printed at the top of the Devices page).

Overland batches its points locally and retries until this endpoint
responds `{"result":"ok"}` -- a wrong key gets a `401` and Overland will
keep retrying with the same (still-wrong) URL until you fix it on the
phone.

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
