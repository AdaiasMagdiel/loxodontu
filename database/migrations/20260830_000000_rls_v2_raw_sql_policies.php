<?php

return [
    'up' => function (PDO $pdo) {
        // `expression` is reinterpreted from a JSON column=>value map to a raw
        // SQL boolean expression (see App\Rls\PolicyEngine) — `role` is dropped
        // since `$auth.role = 'x'` now lives directly in the expression, which
        // is strictly more powerful (multiple roles, OR, arbitrary conditions).
        $pdo->exec("ALTER TABLE `project_rls_policies` DROP COLUMN `role`");
        $pdo->exec("ALTER TABLE `project_storage_policies` DROP COLUMN `role`");
    },
    'down' => function (PDO $pdo) {
        $pdo->exec("ALTER TABLE `project_rls_policies` ADD COLUMN `role` VARCHAR(64) NULL DEFAULT NULL AFTER `name`");
        $pdo->exec("ALTER TABLE `project_storage_policies` ADD COLUMN `role` VARCHAR(64) NULL DEFAULT NULL AFTER `name`");
    },
];
