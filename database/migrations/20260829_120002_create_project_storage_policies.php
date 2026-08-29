<?php

return [
    'up' => function (PDO $pdo) {
        $pdo->exec("
            CREATE TABLE `project_storage_policies` (
                `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `bucket_id`  BIGINT UNSIGNED NOT NULL,
                `name`       VARCHAR(255) NOT NULL,
                `role`       VARCHAR(64) NULL DEFAULT NULL,
                `operation`  ENUM('SELECT','INSERT','UPDATE','DELETE','ALL') NOT NULL DEFAULT 'ALL',
                `expression` TEXT NOT NULL,
                `enabled`    TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                CONSTRAINT `fk_storage_policies_bucket_id`
                    FOREIGN KEY (`bucket_id`) REFERENCES `project_storage_buckets` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function (PDO $pdo) {
        $pdo->exec("DROP TABLE IF EXISTS `project_storage_policies`");
    },
];
