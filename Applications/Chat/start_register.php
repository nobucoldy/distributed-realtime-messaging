<?php

declare(strict_types=1);

use GatewayWorker\Register;
use Workerman\Worker;

if (!defined('GLOBAL_START')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
}

$register = new Register('text://0.0.0.0:1236');
$register->name = 'ChatRegister';

if (!defined('GLOBAL_START')) {
    Worker::runAll();
}
