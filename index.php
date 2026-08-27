<?php

use AdaiasMagdiel\Erlenmeyer\App;

require_once __DIR__ . '/bootstrap.php';

$app = new App();

require __DIR__ . '/routes/index.php';

$app->run();
