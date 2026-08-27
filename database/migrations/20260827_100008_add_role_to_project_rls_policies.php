<?php

return [
    'up' => function (PDO $pdo) {
        $pdo->exec("
            ALTER TABLE `project_rls_policies`
                ADD COLUMN `role` VARCHAR(64) NULL DEFAULT NULL AFTER `name`
        ");
    },
    'down' => function (PDO $pdo) {
        $pdo->exec("ALTER TABLE `project_rls_policies` DROP COLUMN `role`");
    },
];
