<?php

return [
    'up' => function (PDO $pdo) {
        $pdo->exec("
            CREATE TABLE `project_end_users` (
                `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `project_id`        BIGINT UNSIGNED NOT NULL,
                `email`             VARCHAR(255) NOT NULL,
                `password`          VARCHAR(255) NOT NULL,
                `role`              VARCHAR(64) NULL DEFAULT NULL,
                `email_verified_at` TIMESTAMP NULL DEFAULT NULL,
                `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `project_end_users_project_email_unique` (`project_id`, `email`),
                CONSTRAINT `fk_project_end_users_project_id`
                    FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function (PDO $pdo) {
        $pdo->exec("DROP TABLE IF EXISTS `project_end_users`");
    },
];
