<?php

declare(strict_types=1);

namespace Applications\Chat;

use GatewayWorker\Lib\Gateway;
use Throwable;

class Events
{
    public static function onConnect(string $clientId): void
    {
        Gateway::sendToClient($clientId, self::json([
            'type' => 'connected',
            'client_id' => $clientId,
            'message' => 'Connection established',
        ]));
    }

    public static function onMessage(string $clientId, string $message): void
    {
        $payload = json_decode($message, true);
        if (!is_array($payload)) {
            self::sendError($clientId, 'invalid_json', 'Message must be valid JSON.');
            return;
        }

        $type = $payload['type'] ?? '';
        if (!is_string($type) || $type === '') {
            self::sendError($clientId, 'missing_type', 'Field "type" is required.');
            return;
        }

        switch ($type) {
            case 'login':
                self::handleLogin($clientId, $payload);
                return;

            case 'join_room':
                self::handleJoinRoom($clientId, $payload);
                return;

            case 'group_message':
                self::handleGroupMessage($clientId, $payload);
                return;

            case 'private_message':
                self::handlePrivateMessage($clientId, $payload);
                return;

            case 'room_online_users':
                self::handleRoomOnlineUsers($clientId, $payload);
                return;

            default:
                self::sendError($clientId, 'unsupported_type', 'Unsupported message type.');
        }
    }

    public static function onClose(string $clientId): void
    {
        try {
            self::removeClientPresence($clientId);
        } catch (Throwable) {
            // The connection is already closed, so cleanup errors are only logged by Workerman.
        }
    }

    private static function handleLogin(string $clientId, array $payload): void
    {
        $uid = self::requiredString($payload, 'uid');
        $name = self::requiredString($payload, 'name');
        if ($uid === null || $name === null) {
            self::sendError($clientId, 'invalid_login', 'Fields "uid" and "name" are required.');
            return;
        }

        Gateway::bindUid($clientId, $uid);
        Gateway::setSession($clientId, [
            'uid' => $uid,
            'name' => $name,
        ]);

        try {
            self::saveLoginPresence($clientId, $uid, $name);
        } catch (Throwable $exception) {
            self::sendError($clientId, 'redis_unavailable', $exception->getMessage());
            return;
        }

        Gateway::sendToClient($clientId, self::json([
            'type' => 'login_success',
            'uid' => $uid,
            'name' => $name,
            'client_id' => $clientId,
        ]));
    }

    private static function handleJoinRoom(string $clientId, array $payload): void
    {
        $roomId = self::requiredString($payload, 'room_id');
        if ($roomId === null) {
            self::sendError($clientId, 'invalid_join_room', 'Field "room_id" is required.');
            return;
        }

        $session = self::requireLogin($clientId);
        if ($session === null) {
            return;
        }

        try {
            self::saveRoomPresence($clientId, $roomId, $session);
            Gateway::joinGroup($clientId, $roomId);
        } catch (Throwable $exception) {
            self::sendError($clientId, 'redis_unavailable', $exception->getMessage());
            return;
        }

        Gateway::sendToClient($clientId, self::json([
            'type' => 'join_room_success',
            'room_id' => $roomId,
            'client_id' => $clientId,
        ]));
    }

    private static function handleGroupMessage(string $clientId, array $payload): void
    {
        $roomId = self::requiredString($payload, 'room_id');
        $content = self::requiredString($payload, 'content');
        if ($roomId === null || $content === null) {
            self::sendError($clientId, 'invalid_group_message', 'Fields "room_id" and "content" are required.');
            return;
        }

        $session = self::requireLogin($clientId);
        if ($session === null) {
            return;
        }

        try {
            $jobId = self::enqueueMessageJob([
                'type' => 'group_message',
                'room_id' => $roomId,
                'from_uid' => $session['uid'],
                'from_name' => $session['name'],
                'content' => $content,
            ]);
        } catch (Throwable $exception) {
            self::sendError($clientId, 'queue_unavailable', $exception->getMessage());
            return;
        }

        Gateway::sendToClient($clientId, self::json([
            'type' => 'message_queued',
            'job_id' => $jobId,
            'message_type' => 'group_message',
        ]));
    }

    private static function handlePrivateMessage(string $clientId, array $payload): void
    {
        $toUid = self::requiredString($payload, 'to_uid');
        $content = self::requiredString($payload, 'content');
        if ($toUid === null || $content === null) {
            self::sendError($clientId, 'invalid_private_message', 'Fields "to_uid" and "content" are required.');
            return;
        }

        $session = self::requireLogin($clientId);
        if ($session === null) {
            return;
        }

        try {
            $jobId = self::enqueueMessageJob([
                'type' => 'private_message',
                'to_uid' => $toUid,
                'from_uid' => $session['uid'],
                'from_name' => $session['name'],
                'content' => $content,
            ]);
        } catch (Throwable $exception) {
            self::sendError($clientId, 'queue_unavailable', $exception->getMessage());
            return;
        }

        Gateway::sendToClient($clientId, self::json([
            'type' => 'message_queued',
            'job_id' => $jobId,
            'message_type' => 'private_message',
            'to_uid' => $toUid,
        ]));
    }

    private static function handleRoomOnlineUsers(string $clientId, array $payload): void
    {
        $roomId = self::requiredString($payload, 'room_id');
        if ($roomId === null) {
            self::sendError($clientId, 'invalid_room_online_users', 'Field "room_id" is required.');
            return;
        }

        if (self::requireLogin($clientId) === null) {
            return;
        }

        try {
            $users = self::getRoomOnlineUsers($roomId);
        } catch (Throwable $exception) {
            self::sendError($clientId, 'redis_unavailable', $exception->getMessage());
            return;
        }

        Gateway::sendToClient($clientId, self::json([
            'type' => 'room_online_users',
            'room_id' => $roomId,
            'users' => $users,
        ]));
    }

    private static function requireLogin(string $clientId): ?array
    {
        $session = Gateway::getSession($clientId);
        if (!is_array($session) || empty($session['uid']) || empty($session['name'])) {
            self::sendError($clientId, 'login_required', 'Please login before using this action.');
            return null;
        }

        return $session;
    }

    private static function saveLoginPresence(string $clientId, string $uid, string $name): void
    {
        $redis = RedisClient::instance();
        $now = time();

        $redis->set("presence:client:{$clientId}", $uid);
        $redis->sAdd("presence:user:{$uid}:clients", $clientId);
        $redis->hSet('presence:users', $uid, self::json([
            'uid' => $uid,
            'name' => $name,
            'online' => true,
            'last_seen' => $now,
        ]));
    }

    private static function enqueueMessageJob(array $data): string
    {
        $jobId = uniqid('job_', true);
        $job = $data + [
            'id' => $jobId,
            'retry' => 0,
            'created_at' => time(),
        ];

        RedisClient::instance()->lPush('queue:messages', self::json($job));
        return $jobId;
    }

    private static function saveRoomPresence(string $clientId, string $roomId, array $session): void
    {
        $uid = $session['uid'];
        $redis = RedisClient::instance();

        $redis->sAdd("presence:user:{$uid}:rooms", $roomId);
        $redis->sAdd("presence:client:{$clientId}:rooms", $roomId);
        $redis->sAdd("presence:room:{$roomId}:users", $uid);
    }

    private static function getRoomOnlineUsers(string $roomId): array
    {
        $redis = RedisClient::instance();
        $uids = $redis->sMembers("presence:room:{$roomId}:users");
        $users = [];

        foreach ($uids as $uid) {
            if (!is_string($uid)) {
                continue;
            }

            $rawUser = $redis->hGet('presence:users', $uid);
            if ($rawUser === null) {
                continue;
            }

            $user = json_decode($rawUser, true);
            if (!is_array($user) || ($user['online'] ?? false) !== true) {
                continue;
            }

            $users[] = [
                'uid' => $user['uid'] ?? $uid,
                'name' => $user['name'] ?? '',
                'online' => true,
            ];
        }

        return $users;
    }

    private static function removeClientPresence(string $clientId): void
    {
        $redis = RedisClient::instance();
        $uid = $redis->get("presence:client:{$clientId}");
        if ($uid === null) {
            return;
        }

        $clientRoomsKey = "presence:client:{$clientId}:rooms";
        $rooms = $redis->sMembers($clientRoomsKey);
        $redis->sRem("presence:user:{$uid}:clients", $clientId);

        if ($redis->sCard("presence:user:{$uid}:clients") === 0) {
            foreach ($rooms as $roomId) {
                if (is_string($roomId)) {
                    $redis->sRem("presence:room:{$roomId}:users", $uid);
                }
            }

            $rawUser = $redis->hGet('presence:users', $uid);
            $user = is_string($rawUser) ? json_decode($rawUser, true) : [];
            $redis->hSet('presence:users', $uid, self::json([
                'uid' => $uid,
                'name' => is_array($user) ? ($user['name'] ?? '') : '',
                'online' => false,
                'last_seen' => time(),
            ]));
        }

        $redis->del("presence:client:{$clientId}", $clientRoomsKey);
    }

    private static function requiredString(array $payload, string $field): ?string
    {
        $value = $payload[$field] ?? null;
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private static function sendError(string $clientId, string $code, string $message): void
    {
        Gateway::sendToClient($clientId, self::json([
            'type' => 'error',
            'code' => $code,
            'message' => $message,
        ]));
    }

    private static function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
