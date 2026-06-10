<?php

declare(strict_types=1);

namespace Applications\Chat;

use GatewayWorker\Lib\Gateway;

class Events
{
    private static array $clientRooms = [];
    private static array $roomUsers = [];

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
        self::removeClientFromRooms($clientId);
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

        Gateway::joinGroup($clientId, $roomId);
        self::addClientToRoom($clientId, $roomId, $session);

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

        $response = [
            'type' => 'group_message',
            'room_id' => $roomId,
            'from_uid' => $session['uid'],
            'from_name' => $session['name'],
            'content' => $content,
            'created_at' => time(),
        ];

        Gateway::sendToGroup($roomId, self::json($response));
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

        $response = [
            'type' => 'private_message',
            'to_uid' => $toUid,
            'from_uid' => $session['uid'],
            'from_name' => $session['name'],
            'content' => $content,
            'created_at' => time(),
        ];

        Gateway::sendToUid($toUid, self::json($response));
        Gateway::sendToClient($clientId, self::json([
            'type' => 'private_message_sent',
            'to_uid' => $toUid,
            'content' => $content,
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

        Gateway::sendToClient($clientId, self::json([
            'type' => 'room_online_users',
            'room_id' => $roomId,
            'users' => array_values(self::$roomUsers[$roomId] ?? []),
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

    private static function addClientToRoom(string $clientId, string $roomId, array $session): void
    {
        $uid = $session['uid'];
        self::$clientRooms[$clientId][$roomId] = true;
        self::$roomUsers[$roomId][$uid] = [
            'uid' => $uid,
            'name' => $session['name'],
            'online' => true,
        ];
    }

    private static function removeClientFromRooms(string $clientId): void
    {
        $session = Gateway::getSession($clientId);
        if (!is_array($session) || empty($session['uid'])) {
            unset(self::$clientRooms[$clientId]);
            return;
        }

        $uid = $session['uid'];
        foreach (array_keys(self::$clientRooms[$clientId] ?? []) as $roomId) {
            unset(self::$roomUsers[$roomId][$uid]);
            if (self::$roomUsers[$roomId] === []) {
                unset(self::$roomUsers[$roomId]);
            }
        }

        unset(self::$clientRooms[$clientId]);
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
