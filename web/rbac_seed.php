<?php

/**
 * Seed data for GeoTrack's RBAC roles -- see web/rbac.php's own docblock
 * for the full design. Split out from bin/setup.php (a thin CLI wrapper
 * around the function below) purely so it's testable/reusable on its own,
 * same reasoning as zpms's own web/rbac_seed.php.
 */

// name => ['label' => ..., 'is_superuser' => bool, 'permissions' => [...]]
// 'administrator' needs no permissions list at all -- is_superuser bypasses
// the permission check entirely (rbacClass::isPermitted(), zeusfw core).
function zgt_role_seed_definitions(): array {
    return [
        'viewer' => [
            'label' => 'Viewer',
            'is_superuser' => false,
            'permissions' => [
                ZGT_PERM_DEVICES_VIEW,
                ZGT_PERM_LOCATIONS_VIEW,
            ],
        ],
        'operator' => [
            'label' => 'Operator',
            'is_superuser' => false,
            'permissions' => [
                ZGT_PERM_DEVICES_VIEW,
                ZGT_PERM_DEVICES_MANAGE,
                ZGT_PERM_LOCATIONS_VIEW,
            ],
        ],
        'administrator' => [
            'label' => 'Administrator',
            'is_superuser' => true,
            'permissions' => [],
        ],
    ];
}

function zgt_permission_label_seed(): array {
    return [
        ZGT_PERM_DEVICES_VIEW => 'View the device list',
        ZGT_PERM_DEVICES_MANAGE => 'Create/edit/delete devices and their API keys',
        ZGT_PERM_LOCATIONS_VIEW => 'View device locations (map and table)',
        ZEUSFW_PERM_MANAGE_USERS => 'Manage user accounts and roles/permissions',
    ];
}

// Idempotent: skips any permission/role/grant that already exists by
// name. $log is called with one line of progress text per step (pass a
// no-op closure to run silently). $dryRun=true logs what WOULD happen and
// writes nothing.
//
// Returns ['permissionIdsByName' => [...], 'roleIdsByName' => [...]].
function zgt_seed_permissions_and_roles(bool $dryRun, callable $log): array {
    $permissionLabels = zgt_permission_label_seed();
    $roleSeed = zgt_role_seed_definitions();

    $log("-- Permissions --");
    $permissionIdsByName = [];
    foreach (zgt_all_permission_slugs() as $name) {
        $existing = permissionsClassEx::sgetByName($name);
        if ($existing) {
            $log("  exists: $name");
            $permissionIdsByName[$name] = (int)$existing->getid();
            continue;
        }
        $log(($dryRun ? "  would create: " : "  creating: ") . $name);
        if (!$dryRun) {
            $p = new permissionsClass([
                'guid' => guid(),
                'cuser' => 'setup-script',
                'cdate' => getDBtime(),
                'name' => $name,
                'label' => $permissionLabels[$name] ?? $name,
            ]);
            $p->insert();
            $permissionIdsByName[$name] = (int)$p->getid();
        }
    }

    $log("-- Roles --");
    $roleIdsByName = [];
    foreach ($roleSeed as $name => $def) {
        $existing = rolesClassEx::sgetByName($name);
        if ($existing) {
            $log("  exists: $name");
            $roleIdsByName[$name] = (int)$existing->getid();
            continue;
        }
        $log(($dryRun ? "  would create: " : "  creating: ") . $name . ($def['is_superuser'] ? ' (is_superuser)' : ''));
        if (!$dryRun) {
            $r = new rolesClass([
                'guid' => guid(),
                'cuser' => 'setup-script',
                'cdate' => getDBtime(),
                'name' => $name,
                'label' => $def['label'],
                'is_superuser' => $def['is_superuser'] ? 1 : 0,
            ]);
            $r->insert();
            $roleIdsByName[$name] = (int)$r->getid();
        }
    }

    $log("-- Role permissions --");
    $db = dbConnection::getConnection();
    foreach ($roleSeed as $roleName => $def) {
        $roleId = $roleIdsByName[$roleName] ?? null;
        foreach ($def['permissions'] as $permName) {
            $permId = $permissionIdsByName[$permName] ?? null;
            if (!$roleId || !$permId) {
                // Only possible in dry-run mode -- nothing was actually
                // inserted above, so there's no id to check an existing
                // grant against yet.
                $log("  would grant: $roleName -> $permName");
                continue;
            }
            $existing = $db->prepare("SELECT id FROM role_permissions WHERE role_id=:r AND permission_id=:p");
            $existing->bindValue(':r', $roleId, PDO::PARAM_INT);
            $existing->bindValue(':p', $permId, PDO::PARAM_INT);
            $existing->execute();
            if ($existing->fetch()) {
                $log("  exists: $roleName -> $permName");
                continue;
            }
            if ($dryRun) {
                $log("  would grant: $roleName -> $permName");
                continue;
            }
            $log("  granting: $roleName -> $permName");
            $rp = new role_permissionsClass([
                'guid' => guid(),
                'cuser' => 'setup-script',
                'cdate' => getDBtime(),
                'role_id' => $roleId,
                'permission_id' => $permId,
            ]);
            $rp->insert();
        }
    }

    return ['permissionIdsByName' => $permissionIdsByName, 'roleIdsByName' => $roleIdsByName];
}
