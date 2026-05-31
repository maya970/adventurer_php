<?php
declare(strict_types=1);

/**
 * 场景内 MZ 行走图与「皮肤 id」的映射；抽奖解锁 id 后可用于角色与领地分身的立绘选择。
 */
function avatar_skins_json_path(): string
{
    return dirname(__DIR__, 2) . '/data/avatar_skins.json';
}

/** @return array<int, array{id:string, man_png:int, label:string}> */
function avatar_skins_catalog(): array
{
    static $c = null;
    if (is_array($c)) {
        return $c;
    }
    $c = [];
    $path = avatar_skins_json_path();
    if (!is_readable($path)) {
        return $c;
    }
    $j = json_decode((string) file_get_contents($path), true);
    if (!is_array($j) || !isset($j['skins']) || !is_array($j['skins'])) {
        return $c;
    }
    foreach ($j['skins'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (string) ($row['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $c[] = [
            'id' => $id,
            'man_png' => max(1, (int) ($row['man_png'] ?? 1001)),
            'label' => (string) ($row['label'] ?? $id),
        ];
    }

    return $c;
}

function avatar_skins_man_png_by_id(string $skinId): ?int
{
    foreach (avatar_skins_catalog() as $s) {
        if ($s['id'] === $skinId) {
            return (int) $s['man_png'];
        }
    }

    return null;
}

function avatar_skins_id_valid(string $skinId): bool
{
    return avatar_skins_man_png_by_id($skinId) !== null;
}

function gacha_player_skins_ensure(PDO $pdo): void
{
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS player_unlocked_skins (
                player_id INT UNSIGNED NOT NULL,
                skin_id VARCHAR(48) NOT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (player_id, skin_id),
                KEY (player_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (Throwable $e) {
    }
}

/** @return list<string> */
function player_unlocked_skin_ids(PDO $pdo, int $playerId): array
{
    gacha_player_skins_ensure($pdo);
    try {
        $st = $pdo->prepare('SELECT skin_id FROM player_unlocked_skins WHERE player_id = ? ORDER BY skin_id');
        $st->execute([$playerId]);
        $out = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $sid = (string) ($r['skin_id'] ?? '');
            if ($sid !== '') {
                $out[] = $sid;
            }
        }

        if (!in_array('m1001', $out, true)) {
            array_unshift($out, 'm1001');
        }
        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    } catch (Throwable $e) {
        return ['m1001'];
    }
}

function player_unlock_skin(PDO $pdo, int $playerId, string $skinId): bool
{
    if (!avatar_skins_id_valid($skinId)) {
        return false;
    }
    gacha_player_skins_ensure($pdo);
    try {
        if (player_has_unlocked_skin($pdo, $playerId, $skinId)) {
            return true;
        }
        $ins = $pdo->prepare('INSERT IGNORE INTO player_unlocked_skins (player_id, skin_id) VALUES (?, ?)');
        $ins->execute([$playerId, $skinId]);

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function player_has_unlocked_skin(PDO $pdo, int $playerId, string $skinId): bool
{
    if ($skinId === 'm1001') {
        return true;
    }
    gacha_player_skins_ensure($pdo);
    try {
        $st = $pdo->prepare('SELECT 1 FROM player_unlocked_skins WHERE player_id = ? AND skin_id = ? LIMIT 1');
        $st->execute([$playerId, $skinId]);
        if ($st->fetch()) {
            return true;
        }
    } catch (Throwable $e) {
        return false;
    }
    if ($skinId === 'm1002') {
        $c = $pdo->prepare("SELECT COUNT(*) FROM player_items WHERE player_id = ? AND item_key = 'man_sheet_1002'");
        $c->execute([$playerId]);

        return (int) $c->fetchColumn() > 0;
    }

    return false;
}
