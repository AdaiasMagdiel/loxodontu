<?php

return [
    'up' => function (PDO $pdo) {
        // Platform administrators may create cron jobs and edge functions that
        // run arbitrary server-side code (command/callback jobs, class-based
        // function handlers). Regular tenants never can. There is no API to
        // grant it on purpose — promote a user directly in the database:
        //   UPDATE users SET is_admin = 1 WHERE email = 'you@example.com';
        $pdo->exec("
            ALTER TABLE `users`
                ADD COLUMN `is_admin` TINYINT(1) NOT NULL DEFAULT 0 AFTER `password`
        ");
    },
    'down' => function (PDO $pdo) {
        $pdo->exec("ALTER TABLE `users` DROP COLUMN `is_admin`");
    },
];
