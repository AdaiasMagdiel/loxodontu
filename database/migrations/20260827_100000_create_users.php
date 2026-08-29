<?php

return [
    'up' => function (PDO $pdo) {
        $pdo->exec("
            CREATE TABLE `users` (
                `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name`              VARCHAR(255) NOT NULL,
                `email`             VARCHAR(255) NOT NULL,
                `password`          VARCHAR(255) NOT NULL,
                `email_verified_at` TIMESTAMP NULL DEFAULT NULL,
                `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `users_email_unique` (`email`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function (PDO $pdo) {
        $pdo->exec("DROP TABLE IF EXISTS `users`");
    },
];
