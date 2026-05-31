<?php
declare(strict_types=1);

function dungeon_save_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS dungeon_save_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            player_id INT UNSIGNED NOT NULL,
            level_before INT UNSIGNED NOT NULL DEFAULT 1,
            level_after INT UNSIGNED NOT NULL DEFAULT 1,
            items_gained INT UNSIGNED NOT NULL DEFAULT 0,
            xp_granted INT UNSIGNED NOT NULL DEFAULT 0,
            gold_granted INT UNSIGNED NOT NULL DEFAULT 0,
            peak_floor INT UNSIGNED NOT NULL DEFAULT 1,
            kill_count INT UNSIGNED NOT NULL DEFAULT 0,
            chest_count INT UNSIGNED NOT NULL DEFAULT 0,
            save_detail TEXT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_player_created (player_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec('ALTER TABLE players ADD COLUMN dungeon_unlock_floor INT UNSIGNED NOT NULL DEFAULT 10');
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec(
            'UPDATE players SET dungeon_unlock_floor = LEAST(100000, GREATEST(10, COALESCE((SELECT max_unlocked_floor FROM world_dungeon WHERE id = 1), 10)))
             WHERE dungeon_unlock_floor <= 10'
        );
    } catch (Throwable $e) {
    }
}

function player_dungeon_unlock_floor(PDO $pdo, array $player): int
{
    dungeon_save_ensure_schema($pdo);
    $n = (int) ($player['dungeon_unlock_floor'] ?? 0);
    if ($n >= 10) {
        return min(100000, $n);
    }
    try {
        $st = $pdo->prepare('SELECT dungeon_unlock_floor FROM players WHERE id = ? LIMIT 1');
        $st->execute([(int) ($player['id'] ?? 0)]);
        $v = $st->fetchColumn();
        if ($v !== false && (int) $v >= 10) {
            return min(100000, (int) $v);
        }
    } catch (Throwable $e) {
    }

    return 10;
}

/** @return array{max_unlocked_floor:int,bosses:array} */
function fetch_dungeon_world_for_player(PDO $pdo, array $player): array
{
    return [
        'max_unlocked_floor' => player_dungeon_unlock_floor($pdo, $player),
        'bosses' => [],
    ];
}

/**
 * @return array{ok:bool,error?:string,save?:array}&array
 */
function dungeon_save_commit(PDO $pdo, array $playerRow, array $body): array
{
    dungeon_save_ensure_schema($pdo);
    $pid = (int) ($playerRow['id'] ?? 0);
    if ($pid < 1) {
        return ['ok' => false, 'error' => '角色无效'];
    }
    $events = $body['events'] ?? [];
    if (!is_array($events)) {
        $events = [];
    }
    if (count($events) > 400) {
        return ['ok' => false, 'error' => '单次存档事件过多，请回城后再进'];
    }
    $peakFloor = max(1, min(100000, (int) ($body['peak_floor'] ?? 1)));
    $bossMilestones = $body['boss_milestones_defeated'] ?? [];
    if (!is_array($bossMilestones)) {
        $bossMilestones = [];
    }
    $validBoss = [];
    foreach ($bossMilestones as $m) {
        $mi = (int) $m;
        if ($mi >= 10 && $mi % 10 === 0 && $mi <= 100000) {
            $validBoss[$mi] = true;
        }
    }
    foreach (array_keys($validBoss) as $milestone) {
        $hasBossKill = false;
        foreach ($events as $ev) {
            if (!is_array($ev)) {
                continue;
            }
            if ((string) ($ev['type'] ?? '') !== 'kill') {
                continue;
            }
            $f = (int) ($ev['floor'] ?? 0);
            $k = substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($ev['monster_key'] ?? '')), 0, 64);
            if ($f === $milestone && $k === 'world_boss') {
                $hasBossKill = true;
                break;
            }
        }
        if (!$hasBossKill) {
            unset($validBoss[$milestone]);
        }
    }

    $invBefore = fetch_inventory($pdo, $pid);
    $levelBefore = xp_to_level_from_xp_string(player_xp_total_decimal_string($playerRow));

    $totalXp = 0;
    $totalGold = 0;
    $killCount = 0;
    $chestCount = 0;
    $skillBooksDropped = [];
    $monsterKeys = load_monster_keys();

    try {
        $pdo->beginTransaction();
        $stPl = $pdo->prepare('SELECT * FROM players WHERE id = ? FOR UPDATE');
        $stPl->execute([$pid]);
        $pl = $stPl->fetch(PDO::FETCH_ASSOC);
        if (!$pl) {
            throw new RuntimeException('角色不存在');
        }

        foreach ($events as $ev) {
            if (!is_array($ev)) {
                continue;
            }
            $type = (string) ($ev['type'] ?? '');
            if ($type !== 'kill' && $type !== 'chest') {
                continue;
            }
            $floor = max(1, min(100000, (int) ($ev['floor'] ?? 1)));
            if ($type === 'kill') {
                $mKey = substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($ev['monster_key'] ?? '')), 0, 64);
                if ($mKey === '' || !in_array($mKey, $monsterKeys, true)) {
                    continue;
                }
                $killCount++;
            } else {
                $chestCount++;
            }
            [$xpGain, $goldGain, $chance] = mint_rewards_for_floor($floor, $type);
            if (function_exists('game_world_xp_gain_blocked') && game_world_xp_gain_blocked($pdo)) {
                $xpGain = 0;
            }
            if ($xpGain > 0) {
                player_xp_apply_gain($pdo, $pid, $xpGain);
                $totalXp += $xpGain;
            }
            if ($goldGain > 0) {
                $pdo->prepare('UPDATE players SET gold = gold + ? WHERE id = ?')->execute([$goldGain, $pid]);
                $totalGold += $goldGain;
            }
            $roll = random_int(1, 100);
            if ($roll <= $chance) {
                $tpl = pick_loot_item_for_floor($floor);
                if ($tpl) {
                    insert_generated_item($pdo, $pid, $tpl);
                }
            }
            if ($type === 'kill' || $type === 'chest') {
                $dropPm = dungeon_skill_book_drop_per_mille($floor, $type);
                if (random_int(1, 1000) <= $dropPm) {
                    $bookTpl = skill_pick_book_template_for_drop($pdo, $floor);
                    if ($bookTpl) {
                        insert_generated_item($pdo, $pid, $bookTpl);
                        $skillBooksDropped[] = (string) ($bookTpl['label'] ?? '技能书');
                    }
                }
            }
        }

        $stPl->execute([$pid]);
        $pl = $stPl->fetch(PDO::FETCH_ASSOC) ?: $pl;

        $unlock = player_dungeon_unlock_floor($pdo, $pl);
        foreach (array_keys($validBoss) as $milestone) {
            if ($milestone > $unlock - 1) {
                $unlock = max($unlock, min(100000, $milestone + 10));
            }
        }
        $pdo->prepare('UPDATE players SET dungeon_unlock_floor = ? WHERE id = ?')->execute([$unlock, $pid]);
        $pl['dungeon_unlock_floor'] = $unlock;

        try {
            $pdo->prepare('UPDATE players SET dungeon_peak_floor = GREATEST(COALESCE(dungeon_peak_floor, 1), ?) WHERE id = ?')
                ->execute([$peakFloor, $pid]);
            $pl['dungeon_peak_floor'] = max((int) ($pl['dungeon_peak_floor'] ?? 1), $peakFloor);
        } catch (Throwable $e) {
        }

        $stPl->execute([$pid]);
        $pl = $stPl->fetch(PDO::FETCH_ASSOC) ?: $pl;
        $levelAfter = xp_to_level_from_xp_string(player_xp_total_decimal_string($pl));
        $invAfter = fetch_inventory($pdo, $pid);
        $itemsGained = max(0, count($invAfter) - count($invBefore));

        $detail = json_encode([
            'kill_count' => $killCount,
            'chest_count' => $chestCount,
            'boss_milestones' => array_keys($validBoss),
            'peak_floor' => $peakFloor,
            'skill_books' => $skillBooksDropped,
        ], JSON_UNESCAPED_UNICODE);

        $ins = $pdo->prepare(
            'INSERT INTO dungeon_save_log (player_id, level_before, level_after, items_gained, xp_granted, gold_granted, peak_floor, kill_count, chest_count, save_detail)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        $ins->execute([
            $pid,
            $levelBefore,
            $levelAfter,
            $itemsGained,
            $totalXp,
            $totalGold,
            $peakFloor,
            $killCount,
            $chestCount,
            $detail,
        ]);

        $pdo->prepare('INSERT INTO event_log (player_id, kind, detail) VALUES (?,?,?)')->execute([
            $pid,
            'dungeon_save',
            json_encode([
                'level_before' => $levelBefore,
                'level_after' => $levelAfter,
                'items_gained' => $itemsGained,
                'xp' => $totalXp,
                'gold' => $totalGold,
                'peak_floor' => $peakFloor,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'error' => $e->getMessage()];
    }

    $stFresh = $pdo->prepare('SELECT * FROM players WHERE id = ?');
    $stFresh->execute([$pid]);
    $fresh = $stFresh->fetch(PDO::FETCH_ASSOC);
    if (!$fresh) {
        return ['ok' => false, 'error' => '存档后读取角色失败'];
    }

    $out = player_payload($pdo, $fresh);
    $out['save'] = [
        'level_before' => $levelBefore,
        'level_after' => $levelAfter,
        'level_gain' => max(0, $levelAfter - $levelBefore),
        'items_gained' => $itemsGained,
        'xp_granted' => $totalXp,
        'gold_granted' => $totalGold,
        'peak_floor' => $peakFloor,
        'skill_books' => $skillBooksDropped,
    ];

    return $out;
}
