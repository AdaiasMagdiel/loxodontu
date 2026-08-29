<?php

return [
    'up' => function (PDO $pdo) {
        $pdo->exec("
            CREATE TABLE `project_storage_buckets` (
                `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `project_id` BIGINT UNSIGNED NOT NULL,
                `name`       VARCHAR(64) NOT NULL,
                `public`     TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `project_storage_buckets_project_name_unique` (`project_id`, `name`),
                CONSTRAINT `fk_storage_buckets_project_id`
                    FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function (PDO $pdo) {
        $pdo->exec("DROP TABLE IF EXISTS `project_storage_buckets`");
    },
];
