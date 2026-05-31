<?php

declare(strict_types=1);

/** 打怪不会掉落的剧情/系统杂物 id（与 items.json 的 id 一致） */
function exclusive_no_drop_item_ids(): array
{
    return ['bonfire_blade', 'sanctuary_scepter', 'man_sheet_1002', 'sunbeam_crystal'];
}

/** 不可出售给商店、不可一键卖出、不可上架拍卖 */
function player_item_cannot_sell_or_auction(array $row): bool
{
    $k = (string) ($row['item_key'] ?? '');

    return $k === 'bonfire_blade' || $k === 'man_sheet_1002';
}

function item_template_is_no_drop(array $t): bool
{
    if (!empty($t['no_drop'])) {
        return true;
    }
    $id = (string) ($t['id'] ?? '');

    return in_array($id, exclusive_no_drop_item_ids(), true);
}

/** 当日深层阵亡是否仍允许进入地下城（已取消次数限制，始终可进）。 */
function player_can_enter_deep_dungeon(PDO $pdo, array $player): bool
{
    return true;
}

/** 与全服解锁上限成比例：统计线 = ⌊全服最高可至层 ÷ 2⌋（如 30→15、20→10）；死亡所在层 > 该值才计入当日次数 */
function stamina_death_threshold_floor(int $worldMaxUnlocked): int
{
    $w = max(1, $worldMaxUnlocked);

    return (int) floor($w / 2);
}

function next_local_midnight_unix(): int
{
    $t = strtotime('tomorrow midnight');

    return $t !== false ? $t : time() + 86400;
}

/**
 * 若跨日则重置当日体力计数（在玩家行上就地更新 $player 数组）。
 */
function stamina_ensure_today(PDO $pdo, array &$player): void
{
    try {
        $today = date('Y-m-d');
        $pid = (int) ($player['id'] ?? 0);
        if ($pid < 1) {
            return;
        }
        $cur = isset($player['stamina_today_date']) ? (string) $player['stamina_today_date'] : '';
        if ($cur === $today) {
            return;
        }
        $pdo->prepare(
            'UPDATE players SET stamina_today_date = ?, stamina_deaths_above = 0, stamina_bonus_lives = 0 WHERE id = ?'
        )->execute([$today, $pid]);
        $player['stamina_today_date'] = $today;
        $player['stamina_deaths_above'] = 0;
        $player['stamina_bonus_lives'] = 0;
    } catch (Throwable $e) {
    }
}

/** 背包或已穿杂物槽均算持有；仓库内不算（需取出后才可在地城使用） */
function player_has_misc_item_key(array $inventory, string $key): bool
{
    foreach ($inventory as $it) {
        if ((int) ($it['in_warehouse'] ?? 0) === 1) {
            continue;
        }
        if (($it['slot'] ?? '') !== 'misc') {
            continue;
        }
        if ((string) ($it['item_key'] ?? '') === $key) {
            return true;
        }
    }

    return false;
}

function stamina_snapshot_for_player(PDO $pdo, array $player, array $inventory): array
{
    $today = date('Y-m-d');
    $campF = (int) ($player['campfire_floor'] ?? 0);
    $campD = isset($player['campfire_date']) ? (string) $player['campfire_date'] : '';
    $campOk = $campD === $today && $campF > 0;

    return [
        'can_enter_dungeon' => true,
        'campfire_floor_today' => $campOk ? $campF : 0,
        'has_bonfire_blade' => player_has_misc_item_key($inventory, 'bonfire_blade'),
        'has_sanctuary_scepter' => player_has_misc_item_key($inventory, 'sanctuary_scepter'),
    ];
}

