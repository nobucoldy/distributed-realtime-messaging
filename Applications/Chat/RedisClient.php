<?php

declare(strict_types=1);

namespace Applications\Chat;

use RuntimeException;

class RedisClient
{
    private const DEFAULT_HOST = '127.0.0.1';
    private const DEFAULT_PORT = 6379;
    private const DEFAULT_TIMEOUT = 2.0;

    private static ?self $instance = null;

    /** @var resource|null */
    private $socket = null;

    private function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly float $timeout
    ) {
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self(
                getenv('REDIS_HOST') ?: self::DEFAULT_HOST,
                (int) (getenv('REDIS_PORT') ?: self::DEFAULT_PORT),
                (float) (getenv('REDIS_TIMEOUT') ?: self::DEFAULT_TIMEOUT)
            );
        }

        return self::$instance;
    }

    public function set(string $key, string $value): void
    {
        $this->command('SET', $key, $value);
    }

    public function get(string $key): ?string
    {
        $value = $this->command('GET', $key);
        return is_string($value) ? $value : null;
    }

    public function del(string ...$keys): void
    {
        if ($keys === []) {
            return;
        }

        $this->command('DEL', ...$keys);
    }

    public function sAdd(string $key, string $member): void
    {
        $this->command('SADD', $key, $member);
    }

    public function sRem(string $key, string $member): void
    {
        $this->command('SREM', $key, $member);
    }

    public function sMembers(string $key): array
    {
        $members = $this->command('SMEMBERS', $key);
        return is_array($members) ? $members : [];
    }

    public function sCard(string $key): int
    {
        $count = $this->command('SCARD', $key);
        return is_int($count) ? $count : 0;
    }

    public function hSet(string $key, string $field, string $value): void
    {
        $this->command('HSET', $key, $field, $value);
    }

    public function hGet(string $key, string $field): ?string
    {
        $value = $this->command('HGET', $key, $field);
        return is_string($value) ? $value : null;
    }

    private function command(string ...$parts): mixed
    {
        $this->connect();

        fwrite($this->socket, $this->encodeCommand($parts));
        return $this->readResponse();
    }

    private function connect(): void
    {
        if (is_resource($this->socket)) {
            return;
        }

        $socket = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
        if (!is_resource($socket)) {
            throw new RuntimeException("Cannot connect to Redis at {$this->host}:{$this->port}: {$errstr}", $errno);
        }

        stream_set_timeout($socket, (int) $this->timeout);
        $this->socket = $socket;
    }

    private function encodeCommand(array $parts): string
    {
        $command = '*' . count($parts) . "\r\n";
        foreach ($parts as $part) {
            $command .= '$' . strlen($part) . "\r\n" . $part . "\r\n";
        }

        return $command;
    }

    private function readResponse(): mixed
    {
        $line = fgets($this->socket);
        if ($line === false) {
            $this->socket = null;
            throw new RuntimeException('Redis connection closed unexpectedly.');
        }

        $prefix = $line[0];
        $payload = rtrim(substr($line, 1), "\r\n");

        return match ($prefix) {
            '+' => $payload,
            ':' => (int) $payload,
            '$' => $this->readBulkString((int) $payload),
            '*' => $this->readArray((int) $payload),
            '-' => throw new RuntimeException('Redis error: ' . $payload),
            default => throw new RuntimeException('Invalid Redis response: ' . trim($line)),
        };
    }

    private function readBulkString(int $length): ?string
    {
        if ($length === -1) {
            return null;
        }

        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($this->socket, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Could not read Redis bulk string.');
            }
            $data .= $chunk;
        }

        fread($this->socket, 2);
        return $data;
    }

    private function readArray(int $length): array
    {
        if ($length === -1) {
            return [];
        }

        $items = [];
        for ($i = 0; $i < $length; $i++) {
            $items[] = $this->readResponse();
        }

        return $items;
    }
}
