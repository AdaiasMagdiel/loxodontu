<?php

return [
    'up' => function (PDO $pdo) {
        $pdo->exec("
            ALTER TABLE `project_functions`
                MODIFY `handler` VARCHAR(255) NULL DEFAULT NULL,
                ADD COLUMN `source_code` LONGTEXT NULL AFTER `handler`,
                ADD COLUMN `runtime` ENUM('php') NOT NULL DEFAULT 'php' AFTER `source_code`,
                ADD COLUMN `timeout_seconds` INT UNSIGNED NOT NULL DEFAULT 10 AFTER `runtime`,
                ADD COLUMN `memory_limit_mb` INT UNSIGNED NOT NULL DEFAULT 32 AFTER `timeout_seconds`
        ");
    },
    'down' => function (PDO $pdo) {
        $pdo->exec("
            ALTER TABLE `project_functions`
                DROP COLUMN `memory_limit_mb`,
                DROP COLUMN `timeout_seconds`,
                DROP COLUMN `runtime`,
                DROP COLUMN `source_code`,
                MODIFY `handler` VARCHAR(255) NOT NULL
        ");
    },
];
