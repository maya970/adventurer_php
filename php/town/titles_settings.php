<?php

declare(strict_types=1);

/**
 * 全局键值配置（game_settings）。表缺失时回退默认值。
 */
function game_setting_int(PDO $pdo, string $key, int $default): int
{
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $st = $pdo->prepare('SELECT v_int FROM game_settings WHERE k = ? LIMIT 1');
        $st->execute([$key]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $cache[$key] = $row ? max(0, (int) ($row['v_int'] ?? 0)) : $default;
    } catch (Throwable $e) {
        $cache[$key] = $default;
    }

    return $cache[$key];
}

/**
 * 全局：game_settings.k = xp_gain_disabled，v_int=1 时禁止一切经验增加（后端强制；前端应配合不展示经验奖励）。
 */
function game_world_xp_gain_blocked(PDO $pdo): bool
{
    return game_setting_int($pdo, 'xp_gain_disabled', 0) === 1;
}

/** 超过统计阈值层的当日阵亡次数上限（不含生命教会加成），由 game_settings 可调。 */
function dungeon_daily_deep_death_limit(PDO $pdo): int
{
    return max(1, game_setting_int($pdo, 'dungeon_daily_deep_death_limit', 50));
}

/**
 * 称号目录 + 玩家持有（类似物品，独立表）。表缺失时返回空。
 *
 * @return array{owned: list<array>, equipped: ?array}
 */
function titles_player_payload_slice(PDO $pdo, int $playerId): array
{
    $empty = ['owned' => [], 'equipped' => null];
    try {
        $st = $pdo->prepare(
            'SELECT pt.id AS player_title_id, pt.equipped, t.id AS title_id, t.title_key, t.label, t.rarity, t.item_desc '
            . 'FROM player_titles pt INNER JOIN titles t ON t.id = pt.title_id WHERE pt.player_id = ? '
            . 'ORDER BY pt.equipped DESC, t.sort_order ASC, t.id ASC'
        );
        $st->execute([$playerId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $owned = [];
        $equipped = null;
        foreach ($rows as $r) {
            $slice = [
                'player_title_id' => (int) $r['player_title_id'],
                'title_id' => (int) $r['title_id'],
                'title_key' => (string) $r['title_key'],
                'label' => (string) $r['label'],
                'rarity' => (string) ($r['rarity'] ?? 'common'),
                'item_desc' => (string) ($r['item_desc'] ?? ''),
                'equipped' => (int) ($r['equipped'] ?? 0) === 1,
            ];
            $owned[] = $slice;
            if ($slice['equipped']) {
                $equipped = $slice;
            }
        }

        return ['owned' => $owned, 'equipped' => $equipped];
    } catch (Throwable $e) {
        return $empty;
    }
}

function title_equip_player_row(PDO $pdo, int $playerId, int $playerTitleId): array
{
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id FROM player_titles WHERE id = ? AND player_id = ? LIMIT 1 FOR UPDATE');
        $st->execute([$playerTitleId, $playerId]);
        if (!$st->fetch()) {
            $pdo->rollBack();

            return ['ok' => false, 'error' => '称号不存在或不属于你'];
        }
        $pdo->prepare('UPDATE player_titles SET equipped = 0 WHERE player_id = ?')->execute([$playerId]);
        $pdo->prepare('UPDATE player_titles SET equipped = 1 WHERE id = ? AND player_id = ?')->execute([$playerTitleId, $playerId]);
        $pdo->commit();

        return ['ok' => true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'error' => '称号装备失败（请确认已执行数据库升级）'];
    }
}

function title_unequip_all(PDO $pdo, int $playerId): array
{
    try {
        $pdo->prepare('UPDATE player_titles SET equipped = 0 WHERE player_id = ?')->execute([$playerId]);

        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => '卸下失败'];
    }
}
