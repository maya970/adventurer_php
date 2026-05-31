<?php
declare(strict_types=1);

function battle_deck_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS player_battle_deck (
            player_id INT UNSIGNED NOT NULL,
            slot_index TINYINT UNSIGNED NOT NULL,
            card_type ENUM('skill','potion') NOT NULL,
            card_ref VARCHAR(64) NOT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (player_id, slot_index),
            KEY idx_player (player_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec('ALTER TABLE player_items ADD COLUMN bonus_int TINYINT NOT NULL DEFAULT 0');
    } catch (Throwable $e) {
    }
}

/** @return array<int,array{slot_index:int,card_type:string,card_ref:string,label:string}> */
function battle_deck_rows(PDO $pdo, int $playerId): array
{
    battle_deck_ensure_schema($pdo);
    try {
        $st = $pdo->prepare('SELECT slot_index, card_type, card_ref FROM player_battle_deck WHERE player_id = ? ORDER BY slot_index ASC');
        $st->execute([$playerId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $si = (int) ($r['slot_index'] ?? 0);
        $type = (string) ($r['card_type'] ?? '');
        $ref = (string) ($r['card_ref'] ?? '');
        $label = battle_deck_card_label($pdo, $playerId, $type, $ref);
        $out[] = [
            'slot_index' => $si,
            'card_type' => $type,
            'card_ref' => $ref,
            'label' => $label,
        ];
    }

    return $out;
}

function battle_deck_card_label(PDO $pdo, int $playerId, string $type, string $ref): string
{
    if ($type === 'skill') {
        $st = $pdo->prepare('SELECT label FROM skills_catalog WHERE skill_key = ? LIMIT 1');
        $st->execute([$ref]);
        $lb = $st->fetchColumn();

        return $lb !== false ? (string) $lb : $ref;
    }
    if ($type === 'potion') {
        $st = $pdo->prepare('SELECT label FROM player_items WHERE player_id = ? AND id = ? LIMIT 1');
        $st->execute([$playerId, (int) $ref]);
        $lb = $st->fetchColumn();

        return $lb !== false ? (string) $lb : '药水';
    }

    return $ref;
}

function battle_deck_payload_slice(PDO $pdo, int $playerId): array
{
    $rows = battle_deck_rows($pdo, $playerId);
    $skills = 0;
    $potions = 0;
    foreach ($rows as $r) {
        if (($r['card_type'] ?? '') === 'skill') {
            $skills++;
        } elseif (($r['card_type'] ?? '') === 'potion') {
            $potions++;
        }
    }

    return [
        'cards' => $rows,
        'skill_count' => $skills,
        'potion_count' => $potions,
        'complete' => $skills === 12 && $potions === 6,
    ];
}

/** @return array{ok:bool,error?:string}&array */
function battle_deck_set(PDO $pdo, int $playerId, array $body): array
{
    battle_deck_ensure_schema($pdo);
    $cards = $body['cards'] ?? [];
    if (!is_array($cards)) {
        return ['ok' => false, 'error' => '牌组格式无效'];
    }
    if (count($cards) !== 18) {
        return ['ok' => false, 'error' => '牌组须恰好 18 张（12 技能 + 6 药水）'];
    }
    $skillKeys = [];
    $potionIds = [];
    $skRows = player_skills_rows($pdo, $playerId);
    $learned = [];
    foreach ($skRows as $sk) {
        $learned[(string) ($sk['skill_key'] ?? '')] = true;
    }
    $inv = fetch_inventory($pdo, $playerId);
    $potionsOwned = [];
    foreach ($inv as $it) {
        if ((int) ($it['in_warehouse'] ?? 0) === 1) {
            continue;
        }
        if ((int) ($it['equipped'] ?? 0) === 1) {
            continue;
        }
        $k = strtolower((string) ($it['item_key'] ?? ''));
        if (battle_deck_item_is_potion($it)) {
            $potionsOwned[(int) $it['id']] = true;
        }
    }
    foreach ($cards as $i => $c) {
        if (!is_array($c)) {
            return ['ok' => false, 'error' => '第 ' . ($i + 1) . ' 张无效'];
        }
        $type = (string) ($c['card_type'] ?? '');
        $ref = (string) ($c['card_ref'] ?? '');
        if ($type === 'skill') {
            if (!isset($learned[$ref])) {
                return ['ok' => false, 'error' => '未学会技能：' . $ref];
            }
            $skillKeys[] = $ref;
        } elseif ($type === 'potion') {
            $iid = (int) $ref;
            if ($iid < 1 || !isset($potionsOwned[$iid])) {
                return ['ok' => false, 'error' => '药水不在背包：#' . $ref];
            }
            $potionIds[] = $iid;
        } else {
            return ['ok' => false, 'error' => '未知卡牌类型'];
        }
    }
    if (count($skillKeys) !== 12) {
        return ['ok' => false, 'error' => '须包含 12 张技能牌'];
    }
    if (count($potionIds) !== 6) {
        return ['ok' => false, 'error' => '须包含 6 张药水牌'];
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM player_battle_deck WHERE player_id = ?')->execute([$playerId]);
        $ins = $pdo->prepare('INSERT INTO player_battle_deck (player_id, slot_index, card_type, card_ref) VALUES (?,?,?,?)');
        foreach ($cards as $idx => $c) {
            $ins->execute([$playerId, (int) $idx, (string) $c['card_type'], (string) $c['card_ref']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'error' => $e->getMessage()];
    }

    return ['ok' => true, 'deck' => battle_deck_payload_slice($pdo, $playerId)];
}

function battle_deck_item_is_potion(array $it): bool
{
    $k = strtolower((string) ($it['item_key'] ?? ''));
    $slot = (string) ($it['slot'] ?? '');
    if ($slot === 'consumable') {
        return true;
    }

    return str_contains($k, 'potion') || str_contains($k, 'life_') || str_contains($k, 'elixir') || str_contains($k, 'healing');
}

/** 战斗中消耗药水（永久删除物品） */
function battle_deck_consume_potion(PDO $pdo, int $playerId, int $itemId): array
{
    if ($itemId < 1) {
        return ['ok' => false, 'error' => '无效物品'];
    }
    $st = $pdo->prepare('SELECT * FROM player_items WHERE id = ? AND player_id = ? LIMIT 1');
    $st->execute([$itemId, $playerId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || !battle_deck_item_is_potion($row)) {
        return ['ok' => false, 'error' => '药水不存在'];
    }
    $pdo->prepare('DELETE FROM player_items WHERE id = ? AND player_id = ?')->execute([$itemId, $playerId]);

    return ['ok' => true, 'item_id' => $itemId];
}
