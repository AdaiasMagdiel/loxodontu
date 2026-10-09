<?php

return [
    'up' => function (PDO $pdo) {
        // Where auth emails (magic link, password reset, verification, email
        // change) may send the end user. A client-supplied redirect_url must
        // match one of these, otherwise anyone could request a reset for a
        // victim with their own site as the target and collect the token.
        $pdo->exec("
            ALTER TABLE `projects`
                ADD COLUMN `auth_site_url` VARCHAR(2048) NULL,
                ADD COLUMN `auth_redirect_urls` JSON NULL
        ");
    },
    'down' => function (PDO $pdo) {
        $pdo->exec("ALTER TABLE `projects` DROP COLUMN `auth_site_url`, DROP COLUMN `auth_redirect_urls`");
    },
];
