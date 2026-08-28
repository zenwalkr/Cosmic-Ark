<?php
declare(strict_types=1);

/*
 * Cosmic Ark room service
 * -----------------------
 * A small, dependency-free PHP session + locked JSON service. The elected
 * host advances one shared simulation; every crew member submits only their
 * current controls. This keeps all players on the same two visible scenes.
 */

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();
header_remove('X-Powered-By');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

const STORE_FILE = __DIR__ . '/.cosmicark_rooms.store.php';
const STORE_HEADER = "<?php http_response_code(404); exit; ?>\n";
const MAX_PLAYERS = 4;
const PLAYER_TIMEOUT = 45;
const ROOM_TIMEOUT = 1800;
const CODE_LENGTH = 6;
const MAX_WORLD_BYTES = 180000;

function jsonOut(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function posted(string $key, mixed $fallback = ''): mixed {
    return $_POST[$key] ?? $fallback;
}

function cleanText(string $value, int $length, string $fallback): string {
    $clean = preg_replace('/[^\p{L}\p{N} _.,!?#\-]/u', '', trim($value)) ?? '';
    $clean = function_exists('mb_substr') ? mb_substr($clean, 0, $length) : substr($clean, 0, $length);
    return $clean !== '' ? $clean : $fallback;
}

function boolValue(mixed $value): bool {
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function playerId(): string {
    if (empty($_SESSION['cosmic_ark_player_id'])) {
        $_SESSION['cosmic_ark_player_id'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['cosmic_ark_player_id'];
}

function withStore(callable $callback, bool $write = false): mixed {
    $handle = @fopen(STORE_FILE, 'c+');
    if (!$handle) jsonOut(['ok' => false, 'error' => 'The Cosmic Ark room store is not writable.'], 500);
    if (!flock($handle, $write ? LOCK_EX : LOCK_SH)) {
        fclose($handle);
        jsonOut(['ok' => false, 'error' => 'The room uplink is busy.'], 503);
    }
    try {
        rewind($handle);
        $raw = stream_get_contents($handle) ?: '';
        if (str_starts_with($raw, STORE_HEADER)) $raw = substr($raw, strlen(STORE_HEADER));
        $store = json_decode($raw, true);
        if (!is_array($store)) $store = ['rooms' => []];
        if (!isset($store['rooms']) || !is_array($store['rooms'])) $store['rooms'] = [];
        $result = $callback($store);
        if ($write) {
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, STORE_HEADER . json_encode($store, JSON_UNESCAPED_SLASHES));
            fflush($handle);
        }
        return $result;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function roomCode(array $rooms): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $code = '';
        for ($i = 0; $i < CODE_LENGTH; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    } while (isset($rooms[$code]));
    return $code;
}

function defaultInput(): array {
    return ['up' => false, 'down' => false, 'left' => false, 'right' => false, 'action' => false, 'analog' => false, 'axisX' => 0.0, 'axisY' => 0.0, 'seq' => 0];
}

function crewRole(int $index): array {
    $roles = [
        ['shower' => 'N/S GUNNER', 'rescue' => 'SHUTTLE HELM'],
        ['shower' => 'E/W GUNNER', 'rescue' => 'TRACTOR BEAM'],
        ['shower' => 'RELIEF GUNNER', 'rescue' => 'PORT DECOY'],
        ['shower' => 'SHIELD TECH', 'rescue' => 'STARBOARD DECOY'],
    ];
    return $roles[$index % count($roles)];
}

function playerIndex(array $room, string $id): int {
    foreach (($room['state']['players'] ?? []) as $index => $player) {
        if (($player['id'] ?? '') === $id) return $index;
    }
    return -1;
}

function pruneRoom(array &$room): void {
    $cutoff = time() - PLAYER_TIMEOUT;
    $players = array_values(array_filter(
        $room['state']['players'] ?? [],
        fn(array $player): bool => (int)($player['lastSeen'] ?? 0) >= $cutoff
    ));
    if ($players && !array_filter($players, fn(array $player): bool => !empty($player['host']))) {
        $players[0]['host'] = true;
    }
    foreach ($players as $index => &$player) $player['role'] = crewRole($index);
    unset($player);
    $room['state']['players'] = $players;
}

function cleanupRooms(): void {
    $cutoff = time() - ROOM_TIMEOUT;
    withStore(function (array &$store) use ($cutoff): void {
        foreach ($store['rooms'] as $code => $room) {
            if ((int)($room['updatedAt'] ?? 0) < $cutoff) unset($store['rooms'][$code]);
        }
    }, true);
}

function requirePlayer(array $room, string $id): int {
    $index = playerIndex($room, $id);
    if ($index < 0) throw new RuntimeException('Your crew link has expired.', 403);
    return $index;
}

function mutateRoom(string $code, callable $callback): array {
    try {
        return withStore(function (array &$store) use ($code, $callback): array {
            if (!isset($store['rooms'][$code])) throw new RuntimeException('Room not found.', 404);
            $room = $store['rooms'][$code];
            pruneRoom($room);
            $callback($room);
            $room['updatedAt'] = time();
            $store['rooms'][$code] = $room;
            return $room;
        }, true);
    } catch (Throwable $error) {
        if ($error instanceof RuntimeException) {
            jsonOut(['ok' => false, 'error' => $error->getMessage()], max(400, $error->getCode()));
        }
        jsonOut(['ok' => false, 'error' => 'Room update failed.'], 500);
    }
}

function roomPayload(array $room, string $selfId): array {
    $players = [];
    foreach (($room['state']['players'] ?? []) as $index => $player) {
        $players[] = [
            'id' => (string)$player['id'],
            'nickname' => (string)$player['nickname'],
            'host' => !empty($player['host']),
            'role' => $player['role'] ?? crewRole($index),
            'input' => $player['input'] ?? defaultInput(),
        ];
    }
    return [
        'code' => (string)$room['code'],
        'name' => (string)$room['name'],
        'status' => (string)$room['status'],
        'private' => !empty($room['private']),
        'maxPlayers' => (int)$room['maxPlayers'],
        'difficulty' => (string)($room['state']['difficulty'] ?? 'regular'),
        'seed' => (int)($room['state']['seed'] ?? 1),
        'startAt' => (int)($room['state']['startAt'] ?? 0),
        'world' => $room['state']['world'] ?? null,
        'players' => $players,
        'chat' => array_slice($room['state']['chat'] ?? [], -40),
        'selfId' => $selfId,
    ];
}

function loadRoom(string $code): ?array {
    return withStore(fn(array $store): ?array => $store['rooms'][$code] ?? null);
}

function sanitizeWorld(mixed $world): array {
    if (!is_array($world)) throw new RuntimeException('Invalid mission state.', 400);
    $phase = (string)($world['phase'] ?? '');
    if (!in_array($phase, ['shower', 'rescue', 'transition', 'gameover'], true)) {
        throw new RuntimeException('Invalid mission phase.', 400);
    }
    $encoded = json_encode($world, JSON_UNESCAPED_SLASHES);
    if ($encoded === false || strlen($encoded) > MAX_WORLD_BYTES) {
        throw new RuntimeException('Mission state is too large.', 413);
    }
    return $world;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin') === 'cross-site') {
    jsonOut(['ok' => false, 'error' => 'Cross-site room commands are blocked.'], 403);
}

$action = strtolower((string)($_POST['action'] ?? $_GET['action'] ?? 'list'));
$selfId = playerId();
cleanupRooms();

if ($action === 'list') {
    $rooms = withStore(function (array $store): array {
        $result = [];
        $all = array_values($store['rooms']);
        usort($all, fn(array $a, array $b): int => (int)$b['updatedAt'] <=> (int)$a['updatedAt']);
        foreach (array_slice($all, 0, 24) as $room) {
            if (!empty($room['private'])) continue;
            $active = array_filter($room['state']['players'] ?? [], fn(array $p): bool => (int)($p['lastSeen'] ?? 0) >= time() - PLAYER_TIMEOUT);
            if (!$active) continue;
            $result[] = [
                'code' => $room['code'], 'name' => $room['name'], 'status' => $room['status'],
                'players' => count($active), 'maxPlayers' => $room['maxPlayers'],
                'difficulty' => $room['state']['difficulty'] ?? 'regular',
            ];
        }
        return $result;
    });
    jsonOut(['ok' => true, 'rooms' => $rooms]);
}

if ($action === 'create') {
    $name = cleanText((string)posted('name'), 28, 'Alpha Ro Rescue');
    $nickname = cleanText((string)posted('nickname'), 18, 'Atlantean');
    $maxPlayers = max(2, min(MAX_PLAYERS, (int)posted('maxPlayers', 2)));
    $difficulty = strtolower((string)posted('difficulty', 'regular')) === 'advanced' ? 'advanced' : 'regular';
    $private = boolValue(posted('private', false));
    $now = time();
    $room = withStore(function (array &$store) use ($name, $nickname, $maxPlayers, $difficulty, $private, $now, $selfId): array {
        $code = roomCode($store['rooms']);
        $room = [
            'code' => $code, 'name' => $name, 'status' => 'lobby', 'private' => $private,
            'maxPlayers' => $maxPlayers, 'createdAt' => $now, 'updatedAt' => $now,
            'state' => [
                'seed' => random_int(1, 2147483000), 'difficulty' => $difficulty,
                'startAt' => 0, 'world' => null, 'chat' => [],
                'players' => [[
                    'id' => $selfId, 'nickname' => $nickname, 'host' => true,
                    'role' => crewRole(0), 'input' => defaultInput(), 'lastSeen' => $now,
                ]],
            ],
        ];
        $store['rooms'][$code] = $room;
        return $room;
    }, true);
    jsonOut(['ok' => true, 'room' => roomPayload($room, $selfId)]);
}

if ($action === 'join') {
    $code = strtoupper(trim((string)posted('code')));
    $nickname = cleanText((string)posted('nickname'), 18, 'Atlantean');
    $room = mutateRoom($code, function (array &$room) use ($selfId, $nickname): void {
        $existing = playerIndex($room, $selfId);
        if ($existing >= 0) {
            $room['state']['players'][$existing]['nickname'] = $nickname;
            $room['state']['players'][$existing]['lastSeen'] = time();
            return;
        }
        if ($room['status'] !== 'lobby') throw new RuntimeException('That mission is already underway.', 409);
        if (count($room['state']['players']) >= (int)$room['maxPlayers']) throw new RuntimeException('That Ark crew is full.', 409);
        $index = count($room['state']['players']);
        $room['state']['players'][] = [
            'id' => $selfId, 'nickname' => $nickname, 'host' => false,
            'role' => crewRole($index), 'input' => defaultInput(), 'lastSeen' => time(),
        ];
    });
    jsonOut(['ok' => true, 'room' => roomPayload($room, $selfId)]);
}

$code = strtoupper(trim((string)posted('code')));
if ($code === '' || !preg_match('/^[A-Z2-9]{6}$/', $code)) jsonOut(['ok' => false, 'error' => 'A valid room code is required.'], 400);

if ($action === 'poll') {
    $room = mutateRoom($code, function (array &$room) use ($selfId): void {
        $index = requirePlayer($room, $selfId);
        $room['state']['players'][$index]['lastSeen'] = time();
    });
    jsonOut(['ok' => true, 'room' => roomPayload($room, $selfId)]);
}

if ($action === 'chat') {
    $message = cleanText((string)posted('message'), 140, '');
    if ($message === '') jsonOut(['ok' => false, 'error' => 'Type a transmission first.'], 400);
    $room = mutateRoom($code, function (array &$room) use ($selfId, $message): void {
        $index = requirePlayer($room, $selfId);
        $player = &$room['state']['players'][$index];
        $nowMs = (int)round(microtime(true) * 1000);
        if ($nowMs - (int)($player['lastChatAt'] ?? 0) < 450) throw new RuntimeException('Transmission rate too high.', 429);
        $player['lastChatAt'] = $nowMs;
        $player['lastSeen'] = time();
        $room['state']['chat'][] = [
            'id' => bin2hex(random_bytes(5)), 'playerId' => $selfId,
            'nickname' => $player['nickname'], 'message' => $message, 'at' => $nowMs,
        ];
        $room['state']['chat'] = array_slice($room['state']['chat'], -40);
    });
    jsonOut(['ok' => true, 'room' => roomPayload($room, $selfId)]);
}

if ($action === 'start') {
    $room = mutateRoom($code, function (array &$room) use ($selfId): void {
        $index = requirePlayer($room, $selfId);
        if (empty($room['state']['players'][$index]['host'])) throw new RuntimeException('Only the captain can launch.', 403);
        if (count($room['state']['players']) < 2) throw new RuntimeException('Online missions need at least two crew members.', 409);
        $room['status'] = 'playing';
        $room['state']['world'] = null;
        $room['state']['seed'] = random_int(1, 2147483000);
        $room['state']['startAt'] = (int)round(microtime(true) * 1000) + 3000;
        foreach ($room['state']['players'] as &$player) $player['input'] = defaultInput();
        unset($player);
    });
    jsonOut(['ok' => true, 'room' => roomPayload($room, $selfId)]);
}

if ($action === 'input') {
    $room = mutateRoom($code, function (array &$room) use ($selfId): void {
        $index = requirePlayer($room, $selfId);
        $previous = $room['state']['players'][$index]['input'] ?? defaultInput();
        $room['state']['players'][$index]['input'] = [
            'up' => boolValue(posted('up')), 'down' => boolValue(posted('down')),
            'left' => boolValue(posted('left')), 'right' => boolValue(posted('right')),
            'action' => boolValue(posted('action')),
            'analog' => boolValue(posted('analog')),
            'axisX' => max(-1.0, min(1.0, (float)posted('axisX'))),
            'axisY' => max(-1.0, min(1.0, (float)posted('axisY'))),
            'seq' => (int)($previous['seq'] ?? 0) + 1,
        ];
        $room['state']['players'][$index]['lastSeen'] = time();
    });
    jsonOut(['ok' => true, 'room' => roomPayload($room, $selfId)]);
}

if ($action === 'state') {
    $rawWorld = (string)posted('world');
    if (strlen($rawWorld) > MAX_WORLD_BYTES) jsonOut(['ok' => false, 'error' => 'Mission state is too large.'], 413);
    $decoded = json_decode($rawWorld, true);
    $world = sanitizeWorld($decoded);
    $room = mutateRoom($code, function (array &$room) use ($selfId, $world): void {
        $index = requirePlayer($room, $selfId);
        if (empty($room['state']['players'][$index]['host'])) throw new RuntimeException('Only the captain may synchronize the Ark.', 403);
        $room['state']['world'] = $world;
        $room['state']['players'][$index]['lastSeen'] = time();
        if (($world['phase'] ?? '') === 'gameover') $room['status'] = 'finished';
    });
    jsonOut(['ok' => true, 'room' => roomPayload($room, $selfId)]);
}

if ($action === 'leave') {
    withStore(function (array &$store) use ($code, $selfId): void {
        if (!isset($store['rooms'][$code])) return;
        $room = $store['rooms'][$code];
        $room['state']['players'] = array_values(array_filter(
            $room['state']['players'] ?? [], fn(array $p): bool => ($p['id'] ?? '') !== $selfId
        ));
        if (!$room['state']['players']) {
            unset($store['rooms'][$code]);
            return;
        }
        foreach ($room['state']['players'] as $index => &$player) {
            $player['host'] = $index === 0;
            $player['role'] = crewRole($index);
        }
        unset($player);
        $room['updatedAt'] = time();
        $store['rooms'][$code] = $room;
    }, true);
    jsonOut(['ok' => true]);
}

jsonOut(['ok' => false, 'error' => 'Unknown uplink action.'], 400);
