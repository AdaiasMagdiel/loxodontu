<?php

return [
    'up' => function (PDO $pdo) {
        $pdo->exec("
            CREATE TABLE `projects` (
                `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id`     BIGINT UNSIGNED NOT NULL,
                `name`        VARCHAR(255) NOT NULL,
                `slug`        VARCHAR(255) NOT NULL,
                `description` TEXT NULL DEFAULT NULL,
                `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `projects_user_slug_unique` (`user_id`, `slug`),
                CONSTRAINT `fk_projects_user_id`
                    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function (PDO $pdo) {
        $pdo->exec("DROP TABLE IF EXISTS `projects`");
    },
];
