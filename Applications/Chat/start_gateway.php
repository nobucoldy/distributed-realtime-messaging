<?php

declare(strict_types=1);

use GatewayWorker\Gateway;
use Workerman\Worker;

if (!defined('GLOBAL_START')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
}

$gateway = new Gateway('websocket://0.0.0.0:7272');
$gateway->name = 'ChatGateway';
$gateway->count = 1;
$gateway->lanIp = '127.0.0.1';
$gateway->startPort = 2900;
$gateway->registerAddress = '127.0.0.1:1236';

if (!defined('GLOBAL_START')) {
    Worker::runAll();
}
