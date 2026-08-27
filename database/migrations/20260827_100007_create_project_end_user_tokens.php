<?php

return [
    'up' => function (PDO $pdo) {
        $pdo->exec("
            CREATE TABLE `project_end_user_tokens` (
                `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `end_user_id` BIGINT UNSIGNED NOT NULL,
                `token_hash`  VARCHAR(64) NOT NULL,
                `expires_at`  TIMESTAMP NOT NULL,
                `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `project_end_user_tokens_hash_idx` (`token_hash`),
                CONSTRAINT `fk_peut_end_user_id`
                    FOREIGN KEY (`end_user_id`) REFERENCES `project_end_users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function (PDO $pdo) {
        $pdo->exec("DROP TABLE IF EXISTS `project_end_user_tokens`");
    },
];
