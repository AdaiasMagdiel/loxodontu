<?php

return [
    'up' => function (PDO $pdo) {
        $pdo->exec("
            CREATE TABLE `project_api_keys` (
                `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `project_id`   BIGINT UNSIGNED NOT NULL,
                `name`         VARCHAR(255) NOT NULL,
                `key_prefix`   VARCHAR(8) NOT NULL,
                `key_hash`     VARCHAR(255) NOT NULL,
                `permissions`  VARCHAR(255) NOT NULL DEFAULT '[]',
                `last_used_at` TIMESTAMP NULL DEFAULT NULL,
                `expires_at`   DATETIME NULL DEFAULT NULL,
                `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                CONSTRAINT `fk_api_keys_project_id`
                    FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function (PDO $pdo) {
        $pdo->exec("DROP TABLE IF EXISTS `project_api_keys`");
    },
];
