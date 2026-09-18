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

`web/core` is gitignored and never committed -- clone the framework
checkout somewhere and symlink it in:

```sh
git clone https://github.com/evrokas/zeusfw.git ../zeusfw   # or wherever
ln -s /path/to/zeusfw/core web/core
```

### 2. Database

```sh
cp config/db.php.in config/db.php
# edit config/db.php with real DB_HOST/DB_USER/DB_PASS/DB_NAME
```

Generate the entity classes/SQL from every `*.yaml` schema (this app's
own `web/classes/yaml/{devices,locations}.yaml`, plus every framework-core
table -- users, roles, permissions, role_permissions, user_roles,
feed_hashes, etc.) and load them into a fresh database:

```sh
cd web/core/classes
php ../maker/maker.php spill:class:all
php ../maker/maker.php update:bootstrap
php ../maker/maker.php spill:sql:all
# load every generated sql/*.sql this produced into your database, e.g.:
for f in sql/*.sql; do mysql -u <user> -p <db> < "$f"; done
cd -

cd web/classes
php ../core/maker/maker.php spill:class:all
php ../core/maker/maker.php spill:sql:all
for f in sql/*.sql; do mysql -u <user> -p <db> < "$f"; done
cd -
```

There is no migration runner in this framework -- schema changes are
always applied by hand this way, on every deploy that touches a `.yaml`.

### 3. Site config

```sh
cp config/site.info.yaml.in config/site.info.yaml
```

### 4. Seed roles/permissions and create the first login

```sh
php bin/setup.php --dry-run     # see what it would do first
php bin/setup.php --yes --admin-user=admin --admin-email=you@example.com --admin-password='a real password'
```

This seeds the `viewer`/`operator`/`administrator` roles (see
`web/rbac_seed.php`) and, with the three `--admin-*` flags, creates one
`administrator` account so there's a way to log in and reach `/admin/users`
at all.

### 5. Point your web server at `web/`

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

- **Unchecking "Active" on an existing device while editing it does not
  save.** An unchecked HTML checkbox simply isn't present in the POST
  body at all, and ZeusFW core's generic `formsClass::storeFormResults()`
  update path only touches a field that's actually present in the
  submission -- so an edit that unchecks Active silently leaves the
  device active in the database. This is an existing behavior of the
  shared webform engine (not something specific to this app's
  `devices.yaml`), identified by reading `storeFormResults()`'s update
  path directly, not by driving the actual form through a browser (this
  sandbox has no database to test against -- see the last item below).
  Confirm it against a real deployment before relying on the Active
  checkbox to disable a device; direct DB access is the reliable fallback
  either way.
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
- **What was actually verified, and what wasn't** -- this sandbox has no
  MySQL/MariaDB server and no path to a real iOS device, so verification
  was done in layers instead of one real end-to-end run:
  - `php -l` clean on every `.php` file; the generated `devices`/
    `locations` SQL (`maker.php spill:sql:all` against a real, temporarily
    vendored zeusfw checkout) matches the schema described above,
    including the `UNIQUE` constraint on `device_key`.
  - `devicesClassEx`/`locationsClassEx` (key generation, device lookup,
    Overland point parsing -- including the `-1` sentinel and a
    malformed-point rejection) were exercised against a real in-memory
    SQLite database (via reflection into `dbConnection`'s PDO handle,
    since it's hardcoded to a MySQL DSN) -- 18/18 checks passed.
  - `web/api_overland.php`'s full HTTP-shaped handler (wrong key,
    unknown device, right key) was run as a real subprocess against a
    file-backed SQLite database -- unauthorized cases correctly return
    `401`.
  - **Not verified**: the JSON-body-parsing seam specifically (`php://input`
    doesn't carry a request body under the CLI SAPI at all, so the
    subprocess run above only exercised it with an empty body), the
    generic webform CRUD flow (`/devices` add/edit/delete) end-to-end
    through real HTTP, and anything against a real MySQL/MariaDB server
    or a real Overland-running iPhone. Confirm all of these against a
    real deployment before relying on this for anything time-sensitive.
