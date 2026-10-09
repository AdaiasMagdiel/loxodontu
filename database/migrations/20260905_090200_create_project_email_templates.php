<?php

return [
    'up' => function (PDO $pdo) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `project_email_templates` (
                `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `project_id`    BIGINT UNSIGNED NOT NULL,
                `template_key`  ENUM('magic_link', 'password_reset', 'email_verification', 'email_change') NOT NULL,
                `subject`       VARCHAR(255) NOT NULL,
                `body`          TEXT NOT NULL,
                `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_pet_project_template` (`project_id`, `template_key`),
                CONSTRAINT `fk_pet_project_id`
                    FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function (PDO $pdo) {
        $pdo->exec("DROP TABLE IF EXISTS `project_email_templates`");
    },
];
