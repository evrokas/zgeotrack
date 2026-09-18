#!/usr/bin/env php
<?php
/*
 * Seeds GeoTrack's RBAC roles/permissions (web/rbac_seed.php -- schema
 * lives in zeusfw core, see core/lib/Rbac.php) and, optionally, creates
 * the first administrator account. There is no user-management UI until
 * an administrator account exists to reach /admin/users through, so this
 * script is the one bootstrap step every fresh install needs before its
 * first real login.
 *
 * Idempotent and safe to re-run: seeding skips any permission/role that
 * already exists by name, and account creation refuses (rather than
 * duplicates) if --admin-user already exists.
 *
 * Usage:
 *   php bin/setup.php --dry-run
 *   php bin/setup.php --yes
 *   php bin/setup.php --yes --admin-user=admin --admin-email=you@example.com --admin-password='...'
 *
 * Run --dry-run first -- it prints exactly what would be created and
 * writes nothing. Targets config/db.php (the real database) -- there is
 * no separate test-database override, unlike zpms's ZPMS_DB_CONFIG
 * convention, since this app has no test suite yet to need one.
 *
 * Schema prerequisite: the permissions/roles/role_permissions/user_roles/
 * users/devices/locations tables must already exist -- run the same
 * spill:class:all / update:bootstrap / spill:sql:all steps your normal
 * deploy process uses (cwd=<this app>/web/classes, using the vendored
 * <zeusfw>/core/maker/maker.php), then load the generated .sql files by
 * hand -- this framework has no migration runner.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(1);
}

$options = getopt('', ['dry-run', 'yes', 'admin-user:', 'admin-email:', 'admin-password:']);
$dryRun = isset($options['dry-run']);
$confirmed = isset($options['yes']);

if (!$dryRun && !$confirmed) {
    fwrite(STDERR, <<<USAGE
    Usage:
      php bin/setup.php --dry-run     # show what WOULD be created, write nothing
      php bin/setup.php --yes         # seed roles/permissions
      php bin/setup.php --yes --admin-user=admin --admin-email=you@example.com --admin-password='...'

    Run --dry-run first -- see this file's own header comment.

    USAGE);
    exit(1);
}

define('__APPDIR__', dirname(__DIR__));

$configPath = __APPDIR__ . '/config/db.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Database config not found: $configPath\n");
    fwrite(STDERR, "(copy config/db.php.in to config/db.php first)\n");
    exit(1);
}
require_once $configPath;

if (!defined('__FWDIR__')) {
    define('__FWDIR__', __APPDIR__ . '/web/core');
}
if (!is_dir(__FWDIR__)) {
    fwrite(STDERR, __FWDIR__ . " not found -- is web/core vendored (symlinked) in this checkout? See README.md.\n");
    exit(1);
}

// web/user_classes.php (loaded by the framework bootstrap below) reads
// classes/bootstrap_classes.php via a path relative to the current
// working directory, not to itself -- same reasoning zpms's own CLI
// scripts chdir() for.
chdir(__APPDIR__ . '/web');

// Every generated entity class file starts with a few stray lines of
// whitespace before its opening <?php tag (a known maker.php spill_class()
// quirk) -- harmless in the real app (every HTTP response is wrapped in
// its own output buffering) but would otherwise splatter noise across
// this script's own output.
ob_start();
require_once __FWDIR__ . '/bootstrap.php';
require_once __APPDIR__ . '/web/rbac.php';
require_once __APPDIR__ . '/web/rbac_seed.php';
ob_end_clean();

// Normally done inside Kernel's constructor -- this script never
// instantiates a Kernel (no routing/rendering needed here), so it has to
// do this step itself.
dbConnection::init(DB_HOST, DB_USER, DB_PASS, DB_NAME);

$log = function (string $msg): void {
    echo $msg . "\n";
};

$log($dryRun ? '=== DRY RUN -- no changes will be written ===' : '=== Setting up ===');
echo "\n";

$seeded = zgt_seed_permissions_and_roles($dryRun, $log);
echo "\n";

$adminUser = $options['admin-user'] ?? null;
$adminEmail = $options['admin-email'] ?? null;
$adminPassword = $options['admin-password'] ?? null;

if ($adminUser || $adminEmail || $adminPassword) {
    if (!$adminUser || !$adminEmail || !$adminPassword) {
        fwrite(STDERR, "--admin-user, --admin-email and --admin-password must all be given together.\n");
        exit(1);
    }

    $log("-- Administrator account --");
    $existing = usersClassEx::getUserAccount($adminUser);
    if ($existing) {
        $log("  exists: $adminUser (skipping -- account creation never overwrites an existing user)");
    } else {
        $log(($dryRun ? "  would create: " : "  creating: ") . $adminUser);
        if (!$dryRun) {
            $u = new usersClass([
                'name' => $adminUser,
                'email' => $adminEmail,
                'uname' => $adminUser,
                'upass' => password_hash($adminPassword, PASSWORD_DEFAULT),
                'cdate' => getDBtime(),
                'active' => 1,
                'expired' => 0,
                'wrongpasscount' => 0,
                // Legacy column (zeusfw core users.yaml: `type: '@guid'` ->
                // CHAR(36) NOT NULL) -- never read once an account has a
                // real user_roles row (see core/lib/Rbac.php), which
                // assignRole() below gives this one immediately, but the
                // generated usersClass::insert() still binds every field
                // unconditionally, so a NOT NULL column needs *something*
                // or the INSERT itself fails outright. Confirmed the hard
                // way: omitting this threw "Column 'roles' cannot be
                // null" against a real MariaDB server before this line
                // was added.
                'roles' => guid(),
            ]);
            $u->insert();

            $roleId = $seeded['roleIdsByName']['administrator'] ?? null;
            if ($roleId) {
                user_rolesClassEx::assignRole((int)$u->getid(), $roleId, 'setup-script');
                $log("  granted: administrator role");
            } else {
                $log("  WARNING: 'administrator' role id not found -- account created but has no role. Assign it by hand via user_rolesClassEx::assignRole().");
            }
        }
    }
    echo "\n";
} else {
    $log("(no --admin-user/--admin-email/--admin-password given -- skipping account creation. Run again with all three to create the first login.)");
    echo "\n";
}

echo ($dryRun ? '=== Dry run complete ===' : '=== Setup complete ===') . "\n";
