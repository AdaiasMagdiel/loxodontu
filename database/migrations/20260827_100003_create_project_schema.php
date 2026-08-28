<?php

return [
    'up' => function (PDO $pdo) {
        $pdo->exec("
            CREATE TABLE `project_tables` (
                `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `project_id` BIGINT UNSIGNED NOT NULL,
                `name`       VARCHAR(64) NOT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `project_tables_project_name_unique` (`project_id`, `name`),
                CONSTRAINT `fk_project_tables_project_id`
                    FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE `project_columns` (
                `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `table_id`      BIGINT UNSIGNED NOT NULL,
                `name`          VARCHAR(64) NOT NULL,
                `type`          ENUM('text','longtext','integer','bigint','decimal','float','boolean','date','time','timestamp','json','uuid') NOT NULL DEFAULT 'text',
                `nullable`      TINYINT(1) NOT NULL DEFAULT 1,
                `default_value` TEXT NULL DEFAULT NULL,
                `reference_table_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                `reference_column`   VARCHAR(64) NULL DEFAULT NULL,
                `position`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `project_columns_table_name_unique` (`table_id`, `name`),
                CONSTRAINT `fk_project_columns_table_id`
                    FOREIGN KEY (`table_id`) REFERENCES `project_tables` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_project_columns_reference_table_id`
                    FOREIGN KEY (`reference_table_id`) REFERENCES `project_tables` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function (PDO $pdo) {
        $pdo->exec("DROP TABLE IF EXISTS `project_columns`");
        $pdo->exec("DROP TABLE IF EXISTS `project_tables`");
    },
];
