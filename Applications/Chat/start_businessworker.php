<?php

declare(strict_types=1);

use GatewayWorker\BusinessWorker;
use Workerman\Worker;

if (!defined('GLOBAL_START')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
}

require_once __DIR__ . '/Events.php';

$worker = new BusinessWorker();
$worker->name = 'ChatBusinessWorker';
$worker->count = 1;
$worker->registerAddress = '127.0.0.1:1236';
$worker->eventHandler = \Applications\Chat\Events::class;

if (!defined('GLOBAL_START')) {
    Worker::runAll();
}
