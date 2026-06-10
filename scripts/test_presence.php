<?php

declare(strict_types=1);

final class WebSocketTestClient
{
    /** @var resource */
    private $socket;

    public function __construct(private readonly string $host, private readonly int $port)
    {
        $socket = stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 3);
        if (!is_resource($socket)) {
            throw new RuntimeException("Cannot connect to Gateway: {$errstr}", $errno);
        }

        stream_set_timeout($socket, 3);
        $this->socket = $socket;
        $this->handshake();
    }

    public function sendJson(array $payload): void
    {
        $this->sendFrame(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function readJson(): array
    {
        $payload = $this->readFrame();
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Server returned invalid JSON: ' . $payload);
        }

        return $decoded;
    }

    public function close(): void
    {
        fclose($this->socket);
    }

    private function handshake(): void
    {
        $key = base64_encode(random_bytes(16));
        $request = "GET / HTTP/1.1\r\n"
            . "Host: {$this->host}:{$this->port}\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n";

        fwrite($this->socket, $request);

        $response = '';
        while (!str_contains($response, "\r\n\r\n")) {
            $chunk = fgets($this->socket);
            if ($chunk === false) {
                throw new RuntimeException('Gateway did not complete WebSocket handshake.');
            }
            $response .= $chunk;
        }

        if (!str_starts_with($response, 'HTTP/1.1 101')) {
            throw new RuntimeException('Gateway rejected WebSocket handshake: ' . trim($response));
        }
    }

    private function sendFrame(string $payload): void
    {
        $length = strlen($payload);
        $mask = random_bytes(4);
        $header = chr(0x81);

        if ($length <= 125) {
            $header .= chr(0x80 | $length);
        } elseif ($length <= 65535) {
            $header .= chr(0x80 | 126) . pack('n', $length);
        } else {
            $header .= chr(0x80 | 127) . pack('J', $length);
        }

        $masked = '';
        for ($i = 0; $i < $length; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }

        fwrite($this->socket, $header . $mask . $masked);
    }

    private function readFrame(): string
    {
        $firstTwoBytes = fread($this->socket, 2);
        if ($firstTwoBytes === false || strlen($firstTwoBytes) < 2) {
            throw new RuntimeException('Could not read WebSocket frame header.');
        }

        $secondByte = ord($firstTwoBytes[1]);
        $isMasked = (bool) ($secondByte & 0x80);
        $length = $secondByte & 0x7f;

        if ($length === 126) {
            $extended = fread($this->socket, 2);
            $length = unpack('n', $extended)[1];
        } elseif ($length === 127) {
            $extended = fread($this->socket, 8);
            $parts = unpack('Nhigh/Nlow', $extended);
            $length = ($parts['high'] << 32) + $parts['low'];
        }

        $mask = $isMasked ? fread($this->socket, 4) : '';
        $payload = '';
        while (strlen($payload) < $length) {
            $chunk = fread($this->socket, $length - strlen($payload));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Could not read WebSocket frame payload.');
            }
            $payload .= $chunk;
        }

        if ($isMasked) {
            $decoded = '';
            for ($i = 0; $i < $length; $i++) {
                $decoded .= $payload[$i] ^ $mask[$i % 4];
            }
            return $decoded;
        }

        return $payload;
    }
}

function assertType(array $message, string $expectedType): void
{
    if (($message['type'] ?? null) !== $expectedType) {
        throw new RuntimeException("Expected type {$expectedType}, got " . json_encode($message));
    }
}

$roomId = 'room_presence_' . bin2hex(random_bytes(4));
$userOne = 'user_presence_' . bin2hex(random_bytes(4));
$userTwo = 'user_presence_' . bin2hex(random_bytes(4));

$clientOne = new WebSocketTestClient('127.0.0.1', 7272);
$clientTwo = new WebSocketTestClient('127.0.0.1', 7272);

try {
    assertType($clientOne->readJson(), 'connected');
    assertType($clientTwo->readJson(), 'connected');

    $clientOne->sendJson(['type' => 'login', 'uid' => $userOne, 'name' => 'User One']);
    assertType($clientOne->readJson(), 'login_success');

    $clientTwo->sendJson(['type' => 'login', 'uid' => $userTwo, 'name' => 'User Two']);
    assertType($clientTwo->readJson(), 'login_success');

    $clientOne->sendJson(['type' => 'join_room', 'room_id' => $roomId]);
    assertType($clientOne->readJson(), 'join_room_success');

    $clientTwo->sendJson(['type' => 'join_room', 'room_id' => $roomId]);
    assertType($clientTwo->readJson(), 'join_room_success');

    $clientOne->sendJson(['type' => 'room_online_users', 'room_id' => $roomId]);
    $onlineUsers = $clientOne->readJson();
    assertType($onlineUsers, 'room_online_users');

    $uids = array_column($onlineUsers['users'] ?? [], 'uid');
    sort($uids);
    $expected = [$userOne, $userTwo];
    sort($expected);

    if ($uids !== $expected) {
        throw new RuntimeException('Unexpected room online users: ' . json_encode($onlineUsers));
    }

    echo "Presence test passed for {$roomId}\n";
} finally {
    $clientOne->close();
    $clientTwo->close();
}
