# Distributed Realtime Messaging with GatewayWorker

Demo chat realtime phân tán bằng PHP, GatewayWorker và Redis.

Dự án minh họa 2 tính năng phân tán:

- Online Presence phân tán bằng Redis.
- Distributed Message Queue bằng Redis Queue, có retry và dead letter queue.

## Kiến Trúc

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

Vai trò:

- Register: giúp Gateway và BusinessWorker tìm thấy nhau.
- Gateway: giữ kết nối WebSocket với client.
- BusinessWorker: xử lý logic trong `Applications/Chat/Events.php`.
- Redis: lưu presence và queue message.
- Queue Consumer: đọc job và đẩy message bằng `Gateway::sendToGroup` hoặc
  `Gateway::sendToUid`.

## Yêu Cầu

- PHP CLI.
- Composer dependencies trong `vendor/`.
- Redis server tại `127.0.0.1:6379`.

Kiểm tra Redis:

```bash
redis-cli ping
```

Nếu Redis chưa chạy:

```bash
sudo service redis-server start
```

## Chạy Server

Start GatewayWorker:

```bash
php start.php start -d
```

Kiểm tra trạng thái:

```bash
php start.php status
```

Stop GatewayWorker:

```bash
php start.php stop
```

Sau khi sửa code, restart:

```bash
php start.php restart -d
```

## Chạy Queue Consumer

Consumer chạy liên tục:

```bash
php Applications/Chat/queue_consumer.php
```

Consumer xử lý một job rồi thoát:

```bash
php Applications/Chat/queue_consumer.php --once
```

## Client HTML

Mở file sau bằng browser:

```text
public/client.html
```

Client kết nối mặc định tới:

```text
ws://127.0.0.1:7272
```

Thứ tự demo:

1. Start Redis.
2. Start GatewayWorker.
3. Start queue consumer.
4. Mở 2 tab `public/client.html`.
5. Tab 1 login `user_1`, tab 2 login `user_2`.
6. Cả 2 tab join `room_1`.
7. Bấm online users để xem danh sách trong room.
8. Gửi group message và private message.

## Test Tự Động

Test Online Presence:

```bash
php scripts/test_presence.php
```

Test Redis Queue, retry và dead letter queue:

```bash
php scripts/test_queue.php
```

Kết quả mong đợi:

```text
Presence test passed for room_presence_xxxxxxxx
Queue test passed for room_queue_xxxxxxxx
```

## Message JSON Hỗ Trợ

Login:

```json
{"type":"login","uid":"user_1","name":"Nguyen Van A"}
```

Join room:

```json
{"type":"join_room","room_id":"room_1"}
```

Lấy danh sách online trong room:

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

Kiểm tra key:

```bash
redis-cli --scan --pattern "presence:*"
redis-cli llen queue:messages
redis-cli llen queue:messages:dead
```

## Tài Liệu

- `docs/feature-work.md`: đặc tả feature và thiết kế.
- `docs/development-log.md`: nhật ký từng bước phát triển.
- `docs/demo-guide.md`: hướng dẫn demo và checklist thuyết trình.
