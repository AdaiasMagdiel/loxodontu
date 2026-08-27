<?php

return [
    'up' => function (PDO $pdo) {
        $pdo->exec("
            CREATE TABLE `project_rls_policies` (
                `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `table_id`   BIGINT UNSIGNED NOT NULL,
                `name`       VARCHAR(255) NOT NULL,
                `operation`  ENUM('SELECT','INSERT','UPDATE','DELETE','ALL') NOT NULL DEFAULT 'ALL',
                `expression` TEXT NOT NULL,
                `enabled`    TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                CONSTRAINT `fk_rls_policies_table_id`
                    FOREIGN KEY (`table_id`) REFERENCES `project_tables` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function (PDO $pdo) {
        $pdo->exec("DROP TABLE IF EXISTS `project_rls_policies`");
    },
];
