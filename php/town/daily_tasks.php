<?php
declare(strict_types=1);

function daily_tasks_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS player_daily_task_claims (
            player_id INT UNSIGNED NOT NULL,
            task_key VARCHAR(48) NOT NULL,
            claim_date DATE NOT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (player_id, task_key, claim_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS player_daily_task_progress (
            player_id INT UNSIGNED NOT NULL,
            progress_date DATE NOT NULL,
            dungeon_visited TINYINT UNSIGNED NOT NULL DEFAULT 0,
            dungeon_xp_gained BIGINT UNSIGNED NOT NULL DEFAULT 0,
            branch_success TINYINT UNSIGNED NOT NULL DEFAULT 0,
            pk_won TINYINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (player_id, progress_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
    }
}

/** @return array<string, array{key:string,label:string,desc:string,crystal:int}> */
function daily_tasks_catalog(): array
{
    return [
        'sign_in' => [
            'key' => 'sign_in',
            'label' => '每日签到',
            'desc' => '打开本页领取今日签到奖励。',
            'crystal' => 1,
        ],
        'dungeon_xp_third' => [
            'key' => 'dungeon_xp_third',
            'label' => '地下城修行',
            'desc' => '今日进入地下城，并在存档结算中获得至少「当前等级升级所需经验 ÷ 3」的经验。',
            'crystal' => 1,
        ],
        'level_top10' => [
            'key' => 'level_top10',
            'label' => '等级榜前十',
            'desc' => '今日等级排行榜位列前十（按总经验排序）。',
            'crystal' => 1,
        ],
        'gear_top_weapon' => [
            'key' => 'gear_top_weapon',
            'label' => '武器榜前十',
            'desc' => '今日主手武器排行榜位列前十（按已装备武器评分）。',
            'crystal' => 1,
        ],
        'gear_top_armor' => [
            'key' => 'gear_top_armor',
            'label' => '防具榜前十',
            'desc' => '今日护甲排行榜位列前十。',
            'crystal' => 1,
        ],
        'gear_top_boots' => [
            'key' => 'gear_top_boots',
            'label' => '鞋榜前十',
            'desc' => '今日鞋靴排行榜位列前十。',
            'crystal' => 1,
        ],
        'gear_top_ring' => [
            'key' => 'gear_top_ring',
            'label' => '戒指榜前十',
            'desc' => '今日戒指排行榜位列前十。',
            'crystal' => 1,
        ],
        'branch_success' => [
            'key' => 'branch_success',
            'label' => '分支地牢凯旋',
            'desc' => '今日在分支地牢安全层成功结束冒险并取回行囊。',
            'crystal' => 1,
        ],
        'pk_win' => [
            'key' => 'pk_win',
            'label' => 'PK 胜利',
            'desc' => '今日在匹配 PK 中取得至少一场胜利。',
            'crystal' => 1,
        ],
    ];
}

function daily_tasks_today(): string
{
    return date('Y-m-d');
}

function daily_tasks_progress_row(PDO $pdo, int $playerId): array
{
    daily_tasks_ensure_schema($pdo);
    $today = daily_tasks_today();
    try {
        $st = $pdo->prepare('SELECT * FROM player_daily_task_progress WHERE player_id=? AND progress_date=? LIMIT 1');
        $st->execute([$playerId, $today]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
        $pdo->prepare(
            'INSERT INTO player_daily_task_progress (player_id, progress_date) VALUES (?,?)'
        )->execute([$playerId, $today]);

        return [
            'player_id' => $playerId,
            'progress_date' => $today,
            'dungeon_visited' => 0,
            'dungeon_xp_gained' => 0,
            'branch_success' => 0,
            'pk_won' => 0,
        ];
    } catch (Throwable $e) {
        return [
            'dungeon_visited' => 0,
            'dungeon_xp_gained' => 0,
            'branch_success' => 0,
            'pk_won' => 0,
        ];
    }
}

function daily_tasks_mark_dungeon_visit(PDO $pdo, int $playerId): void
{
    daily_tasks_ensure_schema($pdo);
    daily_tasks_progress_row($pdo, $playerId);
    try {
        $pdo->prepare(
            'UPDATE player_daily_task_progress SET dungeon_visited=1 WHERE player_id=? AND progress_date=?'
        )->execute([$playerId, daily_tasks_today()]);
    } catch (Throwable $e) {
    }
}

function daily_tasks_add_dungeon_xp(PDO $pdo, int $playerId, int $xp): void
{
    if ($xp < 1) {
        return;
    }
    daily_tasks_ensure_schema($pdo);
    daily_tasks_progress_row($pdo, $playerId);
    try {
        $pdo->prepare(
            'UPDATE player_daily_task_progress SET dungeon_visited=1, dungeon_xp_gained=dungeon_xp_gained+? WHERE player_id=? AND progress_date=?'
        )->execute([$xp, $playerId, daily_tasks_today()]);
    } catch (Throwable $e) {
    }
}

function daily_tasks_mark_branch_success(PDO $pdo, int $playerId): void
{
    daily_tasks_ensure_schema($pdo);
    daily_tasks_progress_row($pdo, $playerId);
    try {
        $pdo->prepare(
            'UPDATE player_daily_task_progress SET branch_success=1 WHERE player_id=? AND progress_date=?'
        )->execute([$playerId, daily_tasks_today()]);
    } catch (Throwable $e) {
    }
}

function daily_tasks_mark_pk_win(PDO $pdo, int $playerId): void
{
    daily_tasks_ensure_schema($pdo);
    daily_tasks_progress_row($pdo, $playerId);
    try {
        $pdo->prepare(
            'UPDATE player_daily_task_progress SET pk_won=1 WHERE player_id=? AND progress_date=?'
        )->execute([$playerId, daily_tasks_today()]);
    } catch (Throwable $e) {
    }
}

function daily_tasks_claimed_today(PDO $pdo, int $playerId): array
{
    daily_tasks_ensure_schema($pdo);
    $claimed = [];
    try {
        $st = $pdo->prepare('SELECT task_key FROM player_daily_task_claims WHERE player_id=? AND claim_date=?');
        $st->execute([$playerId, daily_tasks_today()]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $k) {
            $claimed[(string) $k] = true;
        }
    } catch (Throwable $e) {
    }

    return $claimed;
}

function daily_tasks_xp_need_one_third(PDO $pdo, array $playerRow): int
{
    $need = xp_remaining_to_next_level(player_xp_total_decimal_string($playerRow));
    if ($need === null || $need === '') {
        return 0;
    }
    if (function_exists('bcdiv')) {
        return max(1, (int) bcdiv((string) $need, '3', 0));
    }

    return max(1, (int) floor(((float) $need) / 3));
}

function daily_tasks_player_level_rank(PDO $pdo, int $playerId): int
{
    try {
        $xpOrd = player_xp_leaderboard_order_sql($pdo);
        $st = $pdo->query(
            'SELECT p.id FROM players p INNER JOIN accounts a ON a.id = p.user_id ORDER BY ' . $xpOrd . ' LIMIT 50'
        );
        $rank = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $rank++;
            if ((int) $id === $playerId) {
                return $rank;
            }
        }
    } catch (Throwable $e) {
    }

    return 0;
}

function daily_tasks_player_gear_rank(PDO $pdo, int $playerId, string $slot): int
{
    $stUser = $pdo->prepare('SELECT a.username FROM players p INNER JOIN accounts a ON a.id=p.user_id WHERE p.id=? LIMIT 1');
    $stUser->execute([$playerId]);
    $uname = trim((string) ($stUser->fetchColumn() ?: ''));
    if ($uname === '') {
        return 0;
    }
    $board = leaderboard_top_gear_by_slot($pdo, $slot, 10);
    foreach ($board as $i => $row) {
        if (trim((string) ($row['username'] ?? '')) === $uname) {
            return $i + 1;
        }
    }

    return 0;
}

function daily_tasks_check_eligible(PDO $pdo, int $playerId, array $playerRow, string $taskKey, array $progress): array
{
    $cat = daily_tasks_catalog();
    if (!isset($cat[$taskKey])) {
        return ['ok' => false, 'error' => '未知任务'];
    }
    switch ($taskKey) {
        case 'sign_in':
            return ['ok' => true];
        case 'dungeon_xp_third':
            $need = daily_tasks_xp_need_one_third($pdo, $playerRow);
            $got = (int) ($progress['dungeon_xp_gained'] ?? 0);
            $visited = (int) ($progress['dungeon_visited'] ?? 0) === 1;
            if (!$visited) {
                return ['ok' => false, 'error' => '今日尚未进入地下城'];
            }
            if ($got < $need) {
                return ['ok' => false, 'error' => '今日地下城经验不足，还需 ' . max(0, $need - $got) . '（目标 ' . $need . '）'];
            }

            return ['ok' => true];
        case 'level_top10':
            $r = daily_tasks_player_level_rank($pdo, $playerId);
            if ($r < 1 || $r > 10) {
                return ['ok' => false, 'error' => '当前不在等级榜前十（你的名次：' . ($r > 0 ? $r : '未上榜') . '）'];
            }

            return ['ok' => true];
        case 'gear_top_weapon':
        case 'gear_top_armor':
        case 'gear_top_boots':
        case 'gear_top_ring':
            $slot = substr($taskKey, strlen('gear_top_'));
            $labels = ['weapon' => '武器', 'armor' => '防具', 'boots' => '鞋', 'ring' => '戒指'];
            $r = daily_tasks_player_gear_rank($pdo, $playerId, $slot);
            if ($r < 1 || $r > 10) {
                return ['ok' => false, 'error' => '当前不在' . ($labels[$slot] ?? $slot) . '榜前十'];
            }

            return ['ok' => true];
        case 'branch_success':
            if ((int) ($progress['branch_success'] ?? 0) !== 1) {
                return ['ok' => false, 'error' => '今日尚未在安全层成功结束分支冒险'];
            }

            return ['ok' => true];
        case 'pk_win':
            if ((int) ($progress['pk_won'] ?? 0) !== 1) {
                return ['ok' => false, 'error' => '今日尚无 PK 胜利记录'];
            }

            return ['ok' => true];
        default:
            return ['ok' => false, 'error' => '未知任务'];
    }
}

function daily_tasks_grant_crystals(PDO $pdo, int $playerId, int $count): void
{
    grant_player_items_by_key($pdo, $playerId, 'sunbeam_crystal', $count);
}

/** @return array{ok:bool,tasks?:array,progress?:array,crystal_count?:int,error?:string} */
function daily_tasks_status_payload(PDO $pdo, int $playerId, array $playerRow): array
{
    daily_tasks_ensure_schema($pdo);
    $progress = daily_tasks_progress_row($pdo, $playerId);
    $claimed = daily_tasks_claimed_today($pdo, $playerId);
    $xpNeed = daily_tasks_xp_need_one_third($pdo, $playerRow);
    $tasks = [];
    foreach (daily_tasks_catalog() as $def) {
        $key = $def['key'];
        $isClaimed = !empty($claimed[$key]);
        $elig = ['ok' => false, 'error' => ''];
        if (!$isClaimed) {
            $elig = daily_tasks_check_eligible($pdo, $playerId, $playerRow, $key, $progress);
        }
        $tasks[] = [
            'key' => $key,
            'label' => $def['label'],
            'desc' => $def['desc'],
            'crystal' => (int) $def['crystal'],
            'claimed' => $isClaimed,
            'can_claim' => !$isClaimed && !empty($elig['ok']),
            'status_hint' => $isClaimed
                ? '今日已领取'
                : (!empty($elig['ok']) ? '可领取' : (string) ($elig['error'] ?? '未达成')),
        ];
    }

    return [
        'ok' => true,
        'tasks' => $tasks,
        'progress' => [
            'dungeon_visited' => (int) ($progress['dungeon_visited'] ?? 0),
            'dungeon_xp_gained' => (int) ($progress['dungeon_xp_gained'] ?? 0),
            'dungeon_xp_target' => $xpNeed,
            'branch_success' => (int) ($progress['branch_success'] ?? 0),
            'pk_won' => (int) ($progress['pk_won'] ?? 0),
            'level_rank' => daily_tasks_player_level_rank($pdo, $playerId),
        ],
        'crystal_count' => player_sunbeam_crystal_count($pdo, $playerId),
    ];
}

/** @return array{ok:bool,message?:string,error?:string}&array */
function daily_tasks_claim(PDO $pdo, int $playerId, array $playerRow, string $taskKey): array
{
    $taskKey = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($taskKey)));
    $claimed = daily_tasks_claimed_today($pdo, $playerId);
    if (!empty($claimed[$taskKey])) {
        return ['ok' => false, 'error' => '今日已领取过该任务'];
    }
    $progress = daily_tasks_progress_row($pdo, $playerId);
    $elig = daily_tasks_check_eligible($pdo, $playerId, $playerRow, $taskKey, $progress);
    if (empty($elig['ok'])) {
        return ['ok' => false, 'error' => (string) ($elig['error'] ?? '条件未达成')];
    }
    $cat = daily_tasks_catalog();
    $reward = (int) ($cat[$taskKey]['crystal'] ?? 1);
    try {
        $pdo->beginTransaction();
        daily_tasks_grant_crystals($pdo, $playerId, $reward);
        $pdo->prepare(
            'INSERT INTO player_daily_task_claims (player_id, task_key, claim_date) VALUES (?,?,?)'
        )->execute([$playerId, $taskKey, daily_tasks_today()]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'error' => $e->getMessage()];
    }
    $def = $cat[$taskKey];

    return [
        'ok' => true,
        'message' => '已领取「' . ($def['label'] ?? $taskKey) . '」：曦光晶屑 ×' . $reward,
    ];
}
