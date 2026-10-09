<?php

return [
    'up' => function (PDO $pdo) {
        // Fixed-window counters for App\Security\RateLimiter. `bucket` is a
        // SHA-256 of the limiter key (e.g. "login:platform:email:<email>"),
        // so emails/IPs are never stored in clear here.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `rate_limits` (
                `bucket`   CHAR(64)     NOT NULL,
                `hits`     INT UNSIGNED NOT NULL DEFAULT 0,
                `reset_at` DATETIME     NOT NULL,
                PRIMARY KEY (`bucket`),
                KEY `rate_limits_reset_at_idx` (`reset_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function (PDO $pdo) {
        $pdo->exec("DROP TABLE IF EXISTS `rate_limits`");
    },
];
