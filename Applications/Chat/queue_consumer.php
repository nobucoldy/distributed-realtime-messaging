<?php

declare(strict_types=1);

use Applications\Chat\RedisClient;
use GatewayWorker\Lib\Gateway;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/RedisClient.php';

Gateway::$registerAddress = getenv('GATEWAY_REGISTER_ADDRESS') ?: '127.0.0.1:1236';

$runOnce = in_array('--once', $argv, true);
$sleepMicroseconds = 200000;

do {
    $jobJson = RedisClient::instance()->rPop('queue:messages');
    if ($jobJson === null) {
        if ($runOnce) {
            exit(0);
        }

        usleep($sleepMicroseconds);
        continue;
    }

    processMessageJob($jobJson);
} while (!$runOnce);

function processMessageJob(string $jobJson): void
{
    $job = json_decode($jobJson, true);
    if (!is_array($job)) {
        moveToDeadLetter([
            'id' => uniqid('invalid_job_', true),
            'type' => 'invalid_json',
            'raw' => $jobJson,
            'retry' => 3,
            'last_error' => 'Job is not valid JSON.',
            'failed_at' => time(),
        ]);
        return;
    }

    try {
        dispatchMessageJob($job);
        echo 'Processed job ' . ($job['id'] ?? 'unknown') . PHP_EOL;
    } catch (Throwable $exception) {
        retryOrDeadLetter($job, $exception);
    }
}

function dispatchMessageJob(array $job): void
{
    $type = $job['type'] ?? '';
    $payload = [
        'type' => $type,
        'from_uid' => requiredJobString($job, 'from_uid'),
        'from_name' => requiredJobString($job, 'from_name'),
        'content' => requiredJobString($job, 'content'),
        'created_at' => $job['created_at'] ?? time(),
    ];

    if ($type === 'group_message') {
        $roomId = requiredJobString($job, 'room_id');
        $payload['room_id'] = $roomId;
        Gateway::sendToGroup($roomId, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return;
    }

    if ($type === 'private_message') {
        $toUid = requiredJobString($job, 'to_uid');
        $payload['to_uid'] = $toUid;
        Gateway::sendToUid($toUid, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return;
    }

    throw new RuntimeException('Unsupported job type: ' . (is_scalar($type) ? (string) $type : 'invalid'));
}

function requiredJobString(array $job, string $field): string
{
    $value = $job[$field] ?? null;
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException("Missing job field: {$field}");
    }

    return $value;
}

function retryOrDeadLetter(array $job, Throwable $exception): void
{
    $job['retry'] = (int) ($job['retry'] ?? 0) + 1;
    $job['last_error'] = $exception->getMessage();
    $job['failed_at'] = time();

    if ($job['retry'] >= 3) {
        moveToDeadLetter($job);
        echo 'Moved job ' . ($job['id'] ?? 'unknown') . ' to dead letter queue' . PHP_EOL;
        return;
    }

    RedisClient::instance()->lPush('queue:messages', json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo 'Retried job ' . ($job['id'] ?? 'unknown') . ' attempt ' . $job['retry'] . PHP_EOL;
}

function moveToDeadLetter(array $job): void
{
    RedisClient::instance()->lPush('queue:messages:dead', json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
