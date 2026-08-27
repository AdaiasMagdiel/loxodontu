<?php

use App\Database;

require_once __DIR__ . '/bootstrap.php';

return Database::getConn('default');
