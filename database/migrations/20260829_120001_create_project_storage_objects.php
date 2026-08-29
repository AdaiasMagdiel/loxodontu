<?php

return [
    'up' => function (PDO $pdo) {
        $pdo->exec("
            CREATE TABLE `project_storage_objects` (
                `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `bucket_id`  BIGINT UNSIGNED NOT NULL,
                `path`       VARCHAR(1024) NOT NULL,
                `owner_id`   BIGINT UNSIGNED NULL DEFAULT NULL,
                `size`       BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `mime_type`  VARCHAR(255) NULL DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `project_storage_objects_bucket_path_unique` (`bucket_id`, `path`(255)),
                CONSTRAINT `fk_storage_objects_bucket_id`
                    FOREIGN KEY (`bucket_id`) REFERENCES `project_storage_buckets` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_storage_objects_owner_id`
                    FOREIGN KEY (`owner_id`) REFERENCES `project_end_users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function (PDO $pdo) {
        $pdo->exec("DROP TABLE IF EXISTS `project_storage_objects`");
    },
];
