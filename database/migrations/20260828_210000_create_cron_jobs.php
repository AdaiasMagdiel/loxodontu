<?php

return [
    'up' => function (PDO $pdo) {
        $pdo->exec("
            CREATE TABLE `cron_jobs` (
                `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `project_id`          BIGINT UNSIGNED NOT NULL,
                `queue`               VARCHAR(50) NOT NULL DEFAULT 'default',
                `name`                VARCHAR(120) NOT NULL,
                `type`                ENUM('http','command','callback','function') NOT NULL,
                `target`              TEXT NOT NULL,
                `method`              VARCHAR(10) NULL DEFAULT NULL,
                `headers`             JSON NULL DEFAULT NULL,
                `payload`             LONGTEXT NULL DEFAULT NULL,
                `interval_seconds`    INT UNSIGNED NULL DEFAULT NULL,
                `run_at`              DATETIME NULL DEFAULT NULL,
                `next_run_at`         DATETIME NOT NULL,
                `last_run_at`         DATETIME NULL DEFAULT NULL,
                `last_finished_at`    DATETIME NULL DEFAULT NULL,
                `last_status`         ENUM('never','success','failed') NOT NULL DEFAULT 'never',
                `last_error`          TEXT NULL DEFAULT NULL,
                `failure_count`       INT UNSIGNED NOT NULL DEFAULT 0,
                `max_retries`         INT NULL DEFAULT 3,
                `retry_backoff`       ENUM('fixed','exponential') NOT NULL DEFAULT 'exponential',
                `retry_delay_seconds` INT UNSIGNED NOT NULL DEFAULT 300,
                `max_retry_delay_seconds` INT UNSIGNED NOT NULL DEFAULT 86400,
                `timeout_seconds`     INT UNSIGNED NOT NULL DEFAULT 30,
                `allow_overlap`       TINYINT(1) NOT NULL DEFAULT 0,
                `enabled`             TINYINT(1) NOT NULL DEFAULT 1,
                `locked_at`           DATETIME NULL DEFAULT NULL,
                `locked_by`           VARCHAR(64) NULL DEFAULT NULL,
                `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `cron_jobs_due_idx` (`enabled`, `queue`, `next_run_at`, `locked_at`),
                KEY `cron_jobs_project_idx` (`project_id`),
                CONSTRAINT `fk_cron_jobs_project_id`
                    FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE `cron_job_runs` (
                `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `cron_job_id`      BIGINT UNSIGNED NOT NULL,
                `started_at`       DATETIME NOT NULL,
                `finished_at`      DATETIME NULL DEFAULT NULL,
                `status`           ENUM('running','success','failed') NOT NULL DEFAULT 'running',
                `attempt`          INT UNSIGNED NOT NULL DEFAULT 1,
                `duration_ms`      INT UNSIGNED NULL DEFAULT NULL,
                `output`           LONGTEXT NULL DEFAULT NULL,
                `error`            TEXT NULL DEFAULT NULL,
                `worker_id`        VARCHAR(64) NOT NULL,
                PRIMARY KEY (`id`),
                KEY `cron_job_runs_job_idx` (`cron_job_id`, `started_at`),
                CONSTRAINT `fk_cron_job_runs_cron_job_id`
                    FOREIGN KEY (`cron_job_id`) REFERENCES `cron_jobs` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function (PDO $pdo) {
        $pdo->exec("DROP TABLE IF EXISTS `cron_job_runs`");
        $pdo->exec("DROP TABLE IF EXISTS `cron_jobs`");
    },
];
