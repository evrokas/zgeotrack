<?php

/**
 * GeoTrack's own permission vocabulary for the RBAC (role-based access
 * control) system. The reusable *engine* -- the roles/permissions/
 * role_permissions/user_roles schema, the *ClassEx extension classes, and
 * rbacClass::isPermitted()/rbacClass::require() -- lives in zeusfw core
 * (core/classes/yaml/{roles,permissions,role_permissions,user_roles}.yaml,
 * core/lib/Rbac.php). See that file's own docblock for the full design.
 * This app only owns which permission slugs exist and their labels; see
 * web/rbac_seed.php for which roles exist and what each one grants.
 *
 * rbacClass::require($perm) is a drop-in replacement for
 * SecurityClass::require($perm): null on success, a rendered 401 page on
 * failure.
 */

const ZGT_PERM_DEVICES_VIEW = 'devices-view';
const ZGT_PERM_DEVICES_MANAGE = 'devices-manage';
const ZGT_PERM_LOCATIONS_VIEW = 'locations-view';

// Every permission slug above, plus the framework's own
// ZEUSFW_PERM_MANAGE_USERS (core/lib/Rbac.php -- gates core's generic
// /admin/{entity} users/roles/permissions CRUD UI), in one place -- the
// single list bin/setup.php seeds into the permissions table from. Kept
// in sync by hand with the constants above and with every
// rbacClass::require() call site -- no reflection-based discovery
// anywhere in this framework.
function zgt_all_permission_slugs(): array {
    return [
        ZGT_PERM_DEVICES_VIEW,
        ZGT_PERM_DEVICES_MANAGE,
        ZGT_PERM_LOCATIONS_VIEW,
        ZEUSFW_PERM_MANAGE_USERS,
    ];
}
