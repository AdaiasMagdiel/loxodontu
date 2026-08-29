<?php
/** @var int $projectId */
/** @var string $activeNav */
$tabs = [
    ['overview', '', 'Overview'],
    ['tables', '/tables', 'Tables'],
    ['sql', '/sql', 'SQL Editor'],
    ['keys', '/keys', 'API Keys'],
    ['functions', '/functions', 'Functions'],
    ['cron-jobs', '/cron-jobs', 'Cron Jobs'],
    ['end-users', '/end-users', 'End Users'],
];
?>
<div class="flex gap-2 mb-6">
    <?php foreach ($tabs as [$id, $suffix, $label]): ?>
        <a href="/dashboard/projects/<?= $projectId ?><?= $suffix ?>" class="tab-link <?= $activeNav === $id ? 'active' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>
