<?php

return [
    'up' => function (PDO $pdo) {
        $pdo->exec("
            ALTER TABLE `project_columns`
                MODIFY COLUMN `type` ENUM('text','longtext','integer','bigint','decimal','float','boolean','date','time','timestamp','json','uuid') NOT NULL DEFAULT 'text'
        ");

        $stmt = $pdo->query("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'project_columns'
              AND COLUMN_NAME = 'reference_table_id'
        ");
        if ((int) $stmt->fetchColumn() > 0) {
            return;
        }

        $pdo->exec("
            ALTER TABLE `project_columns`
                ADD COLUMN `reference_table_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `default_value`,
                ADD COLUMN `reference_column` VARCHAR(64) NULL DEFAULT NULL AFTER `reference_table_id`,
                ADD CONSTRAINT `fk_project_columns_reference_table_id`
                    FOREIGN KEY (`reference_table_id`) REFERENCES `project_tables` (`id`) ON DELETE SET NULL
        ");
    },
    'down' => function (PDO $pdo) {
        $pdo->exec("
            ALTER TABLE `project_columns`
                DROP FOREIGN KEY `fk_project_columns_reference_table_id`,
                DROP COLUMN `reference_column`,
                DROP COLUMN `reference_table_id`,
                MODIFY COLUMN `type` ENUM('text','integer','decimal','boolean','timestamp','json') NOT NULL DEFAULT 'text'
        ");
    },
];
