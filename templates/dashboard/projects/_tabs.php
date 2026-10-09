<?php
/** @var int $projectId */
/** @var string $activeNav */
$tabs = [
    ['overview', '', 'Overview'],
    ['tables', '/tables', 'Tables'],
    ['sql', '/sql', 'SQL Editor'],
    ['keys', '/keys', 'API Keys'],
    ['storage', '/storage', 'Storage'],
    ['functions', '/functions', 'Functions'],
    ['cron-jobs', '/cron-jobs', 'Cron Jobs'],
    ['end-users', '/end-users', 'End Users'],
];
?>
<div class="scroll-x flex gap-2 mb-6 -mx-4 px-4 sm:mx-0 sm:px-0">
    <?php foreach ($tabs as [$id, $suffix, $label]): ?>
        <a href="/dashboard/projects/<?= $projectId ?><?= $suffix ?>" class="tab-link <?= $activeNav === $id ? 'active' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>
