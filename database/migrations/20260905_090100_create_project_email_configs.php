<?php

return [
    'up' => function (PDO $pdo) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `project_email_configs` (
                `id`                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `project_id`                BIGINT UNSIGNED NOT NULL,
                `provider`                  ENUM('smtp', 'resend') NOT NULL DEFAULT 'smtp',
                `from_address`              VARCHAR(255) NOT NULL,
                `from_name`                 VARCHAR(255) NULL,
                `smtp_host`                 VARCHAR(255) NULL,
                `smtp_port`                 INT UNSIGNED NULL,
                `smtp_username`             VARCHAR(255) NULL,
                `smtp_encryption`           ENUM('none', 'tls', 'ssl') NULL,
                `smtp_password_encrypted`   TEXT NULL,
                `resend_api_key_encrypted`  TEXT NULL,
                `require_email_confirmation` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at`                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_pec_project_id` (`project_id`),
                CONSTRAINT `fk_pec_project_id`
                    FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function (PDO $pdo) {
        $pdo->exec("DROP TABLE IF EXISTS `project_email_configs`");
    },
];
