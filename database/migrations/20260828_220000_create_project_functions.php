<?php

return [
    'up' => function (PDO $pdo) {
        $pdo->exec("
            CREATE TABLE `project_functions` (
                `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `project_id`      BIGINT UNSIGNED NOT NULL,
                `slug`            VARCHAR(64) NOT NULL,
                `name`            VARCHAR(120) NOT NULL,
                `description`     TEXT NULL DEFAULT NULL,
                `handler`         VARCHAR(255) NOT NULL,
                `methods`         JSON NULL DEFAULT NULL,
                `require_api_key` TINYINT(1) NOT NULL DEFAULT 1,
                `enabled`         TINYINT(1) NOT NULL DEFAULT 1,
                `last_invoked_at` DATETIME NULL DEFAULT NULL,
                `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `project_functions_project_slug_unique` (`project_id`, `slug`),
                KEY `project_functions_project_idx` (`project_id`),
                CONSTRAINT `fk_project_functions_project_id`
                    FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function (PDO $pdo) {
        $pdo->exec("DROP TABLE IF EXISTS `project_functions`");
    },
];
