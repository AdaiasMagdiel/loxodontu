<?php

return [
    'up' => function (PDO $pdo) {
        $pdo->exec("
            ALTER TABLE `projects`
                ADD COLUMN `public_id` VARCHAR(32) NULL AFTER `id`
        ");

        $stmt = $pdo->query('SELECT id FROM projects');
        $update = $pdo->prepare('UPDATE projects SET public_id = ? WHERE id = ?');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $update->execute([
                'prj_' . bin2hex(random_bytes(12)),
                $row['id'],
            ]);
        }

        $pdo->exec("
            ALTER TABLE `projects`
                MODIFY `public_id` VARCHAR(32) NOT NULL,
                ADD UNIQUE KEY `projects_public_id_unique` (`public_id`)
        ");
    },
    'down' => function (PDO $pdo) {
        $pdo->exec("
            ALTER TABLE `projects`
                DROP INDEX `projects_public_id_unique`,
                DROP COLUMN `public_id`
        ");
    },
];
