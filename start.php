<?php

declare(strict_types=1);

use Workerman\Worker;

require_once __DIR__ . '/vendor/autoload.php';

define('GLOBAL_START', true);

require_once __DIR__ . '/Applications/Chat/start_register.php';
require_once __DIR__ . '/Applications/Chat/start_gateway.php';
require_once __DIR__ . '/Applications/Chat/start_businessworker.php';

Worker::runAll();
