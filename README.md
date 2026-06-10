# Distributed Realtime Messaging with GatewayWorker

Demo chat realtime phan tan bang PHP, GatewayWorker va Redis.

Du an minh hoa 2 tinh nang phan tan:

- Online Presence phan tan bang Redis.
- Distributed Message Queue bang Redis Queue, co retry va dead letter queue.

## Kien Truc

```text
WebSocket Client
      |
      v
GatewayWorker Gateway
      |
      v
GatewayWorker BusinessWorker
      |
      +--> Redis Online Presence
      |
      +--> Redis Message Queue
                 |
                 v
          Queue Consumer
```

Vai tro:

- Register: giup Gateway va BusinessWorker tim thay nhau.
- Gateway: giu ket noi WebSocket voi client.
- BusinessWorker: xu ly logic trong `Applications/Chat/Events.php`.
- Redis: luu presence va queue message.
- Queue Consumer: doc job va day message bang `Gateway::sendToGroup` hoac
  `Gateway::sendToUid`.

## Yeu Cau

- PHP CLI.
- Composer dependencies trong `vendor/`.
- Redis server tai `127.0.0.1:6379`.

Kiem tra Redis:

```bash
redis-cli ping
```

Neu Redis chua chay:

```bash
sudo service redis-server start
```

## Chay Server

Start GatewayWorker:

```bash
php start.php start -d
```

Kiem tra trang thai:

```bash
php start.php status
```

Stop GatewayWorker:

```bash
php start.php stop
```

Sau khi sua code, restart:

```bash
php start.php restart -d
```

## Chay Queue Consumer

Consumer chay lien tuc:

```bash
php Applications/Chat/queue_consumer.php
```

Consumer xu ly mot job roi thoat:

```bash
php Applications/Chat/queue_consumer.php --once
```

## Client HTML

Mo file sau bang browser:

```text
public/client.html
```

Client ket noi mac dinh toi:

```text
ws://127.0.0.1:7272
```

Thu tu demo:

1. Start Redis.
2. Start GatewayWorker.
3. Start queue consumer.
4. Mo 2 tab `public/client.html`.
5. Tab 1 login `user_1`, tab 2 login `user_2`.
6. Ca 2 tab join `room_1`.
7. Bam online users de xem danh sach trong room.
8. Gui group message va private message.

## Test Tu Dong

Test Online Presence:

```bash
php scripts/test_presence.php
```

Test Redis Queue, retry va dead letter queue:

```bash
php scripts/test_queue.php
```

Ket qua mong doi:

```text
Presence test passed for room_presence_xxxxxxxx
Queue test passed for room_queue_xxxxxxxx
```

## Message JSON Ho Tro

Login:

```json
{"type":"login","uid":"user_1","name":"Nguyen Van A"}
```

Join room:

```json
{"type":"join_room","room_id":"room_1"}
```

Lay danh sach online trong room:

```json
{"type":"room_online_users","room_id":"room_1"}
```

Group message:

```json
{"type":"group_message","room_id":"room_1","content":"Hello everyone"}
```

Private message:

```json
{"type":"private_message","to_uid":"user_2","content":"Hello"}
```

## Redis Keys

Online Presence:

```text
presence:users
presence:client:{client_id}
presence:user:{uid}:clients
presence:user:{uid}:rooms
presence:client:{client_id}:rooms
presence:room:{room_id}:users
```

Message Queue:

```text
queue:messages
queue:messages:dead
```

Kiem tra key:

```bash
redis-cli --scan --pattern "presence:*"
redis-cli llen queue:messages
redis-cli llen queue:messages:dead
```


