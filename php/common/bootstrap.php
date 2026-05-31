<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$RpgRoot = dirname(__DIR__, 2);
$RpgPhp = dirname(__DIR__);
$configPath = $RpgRoot . '/config.php';
if (!is_readable($configPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '缺少 config.php，请复制 config.example.php 并填写数据库']);
    exit;
}

/** @var array $cfg */
$cfg = require $configPath;

require_once $RpgPhp . '/town/titles_settings.php';
require_once $RpgPhp . '/dungeon/dungeon_features.php';
require_once $RpgPhp . '/dungeon/dungeon_save.php';
require_once $RpgPhp . '/town/adventurer_titles.php';
require_once $RpgPhp . '/town/guild.php';
require_once $RpgPhp . '/town/battle_deck.php';
require_once $RpgPhp . '/town/match_pk.php';
require_once $RpgPhp . '/town/general_shop.php';
require_once $RpgPhp . '/common/expansion_systems.php';
require_once $RpgPhp . '/common/skill_system.php';
require_once $RpgPhp . '/gacha/avatar_skins.php';
require_once $RpgPhp . '/gacha/sunbeam_gacha.php';
require_once $RpgPhp . '/town/daily_tasks.php';

function app_build_id(): string
{
    global $cfg;
    if (is_array($cfg) && !empty($cfg['app_build'])) {
        return (string) $cfg['app_build'];
    }
    return 'rpg-default-build';
}

/**
 * 排行用校验和：伤害骰 n×m × max(1, 强化等级)；与掉落/强化逻辑共用 NdM 解析。
 */
function item_rank_checksum(array $row): int
{
    $dice = (string) ($row['damage_dice'] ?? '1d4');
    [$n, $m] = damage_dice_nm($dice);
    $prod = max(1, $n * $m);
    $pl = max(0, (int) ($row['plus_level'] ?? 0));
    return $prod * max(1, $pl);
}

/** 武器强化至 +5、+10…时在介绍末尾追加纪要（可多次叠加） */
function append_weapon_enhance_lore(string $desc, string $who, int $plusLevel): string
{
    $who = trim($who) !== '' ? trim($who) : '无名冒险者';
    $y = (int) date('Y');
    $mo = (int) date('n');
    $d = (int) date('j');
    $line = "\n\n【强化纪要】" . $y . '年' . $mo . '月' . $d . '日，' . $who . ' 将此武器强化至 +' . $plusLevel . '。';
    return $desc . $line;
}

function append_legendary_lore_once(string $desc): string
{
    $title = '【传奇铭文】';
    if (strpos($desc, $title) !== false) {
        return $desc;
    }
    $line = "\n\n【传奇铭文】历经千锤百炼之后，它已超越原有品阶，如今必为传奇之器。";
    return trim($desc) === '' ? ltrim($line) : ($desc . $line);
}

function backfill_rank_checksum_column(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $rows = $pdo->query('SELECT id, damage_dice, plus_level FROM player_items WHERE rank_checksum = 0')->fetchAll();
    } catch (Throwable $e) {
        return;
    }
    if ($rows === []) {
        return;
    }
    $u = $pdo->prepare('UPDATE player_items SET rank_checksum = ? WHERE id = ?');
    foreach ($rows as $r) {
        $u->execute([item_rank_checksum($r), (int) $r['id']]);
    }
}

/** 排行榜：每名玩家该槽位已装备中 rank_checksum 最高的一件（仅 MySQL 8+ 窗口函数） */
function leaderboard_top_gear_by_slot(PDO $pdo, string $slot, int $limit): array
{
    $lim = max(1, min(50, $limit));
    $st = $pdo->prepare(
        'SELECT t.username, t.display_name, t.rank_checksum AS score, t.label AS item_label, t.plus_level, t.damage_dice
         FROM (
           SELECT acc.username, p.display_name, pi.rank_checksum, pi.label, pi.plus_level, pi.damage_dice,
             ROW_NUMBER() OVER (PARTITION BY p.id ORDER BY pi.rank_checksum DESC, pi.id DESC) AS rn
           FROM players p
           INNER JOIN accounts acc ON acc.id = p.user_id
           INNER JOIN player_items pi ON pi.player_id = p.id AND pi.equipped = 1 AND pi.slot = ?
         ) t
         WHERE t.rn = 1
         ORDER BY t.rank_checksum DESC, t.username ASC
         LIMIT ' . $lim
    );
    $st->execute([$slot]);
    return $st->fetchAll();
}

/** 世界首领首杀：层数从高到低，同层按击倒时间；最多 $limit 条 */
function leaderboard_world_boss_first_kills(PDO $pdo, int $limit): array
{
    $lim = max(1, min(20, $limit));
    try {
        $st = $pdo->query(
            'SELECT milestone, first_killer_username, first_killed_at
             FROM world_boss
             WHERE first_killer_player_id IS NOT NULL
             ORDER BY milestone DESC, first_killed_at DESC
             LIMIT ' . $lim
        );

        return $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        return [];
    }
}

function leaderboard_player_row_effective(array $pr, array $snap): array
{
    return [
        'username' => $pr['username'],
        'display_name' => $pr['display_name'],
        'xp' => json_xp_payload_field(player_xp_total_decimal_string($pr)),
        'gold' => (int) $pr['gold'],
        'level' => $snap['level'],
        'str_effective' => $snap['str_effective'],
        'dex_effective' => $snap['dex_effective'],
        'con_effective' => $snap['con_effective'],
        'ac' => $snap['ac'],
        'str_mod' => $snap['str_mod'],
        'dex_mod' => $snap['dex_mod'],
        'weapon_dice' => $snap['weapon_dice'],
    ];
}

function db(): PDO
{
    static $pdo = null;
    global $cfg;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $d = $cfg['db'];
    $dsn = 'mysql:host=' . $d['host'] . ';dbname=' . $d['name'] . ';charset=' . $d['charset'];
    $pdo = new PDO($dsn, $d['user'], $d['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

function json_in(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function get_player_by_user_id(PDO $pdo, int $userId): ?array
{
    $st = $pdo->prepare('SELECT * FROM players WHERE user_id = ? LIMIT 1');
    $st->execute([$userId]);
    $row = $st->fetch();
    return $row ?: null;
}

/** dungeon_black_room 非空时禁止游戏（仅管理员在库中写入） */
function player_is_black_room(?array $playerRow): bool
{
    if (!$playerRow) {
        return false;
    }
    return trim((string) ($playerRow['dungeon_black_room'] ?? '')) !== '';
}

/** 已装备武器/护甲/戒指/鞋的 rank_checksum 之和，作击杀时「装备分」 */
function equipped_gear_score_sum(array $inventory): int
{
    $sum = 0;
    foreach ($inventory as $it) {
        if ((int) $it['equipped'] !== 1) {
            continue;
        }
        $slot = (string) ($it['slot'] ?? '');
        if (!in_array($slot, ['weapon', 'armor', 'ring', 'boots'], true)) {
            continue;
        }
        $sum += max(0, (int) ($it['rank_checksum'] ?? 0));
    }
    return $sum;
}

function sanitize_kill_monster_stats(array $body, int $floor): array
{
    $mh = (int) ($body['max_hp'] ?? $body['monster_max_hp'] ?? 0);
    $ac = (int) ($body['ac'] ?? $body['monster_ac'] ?? 0);
    $th = (int) ($body['to_hit'] ?? $body['monster_to_hit'] ?? 0);
    $rawDmg = (string) ($body['damage'] ?? $body['monster_damage'] ?? '1d6');
    $dmg = substr(preg_replace('/[^0-9dD+\-]/', '', $rawDmg), 0, 24);
    if ($dmg === '') {
        $dmg = '1d6';
    }
    return [
        'floor' => max(1, min(100000, $floor)),
        'max_hp' => max(1, min(2000000000, $mh)),
        'ac' => max(1, min(40, $ac)),
        'to_hit' => max(0, min(30, $th)),
        'damage' => $dmg,
    ];
}

function build_kill_metrics_json(PDO $pdo, array $player, array $inv, array $monsterBody, int $floor): string
{
    $snap = compute_combat_snapshot($pdo, $player, $inv);
    $gear = equipped_gear_score_sum($inv);
    $ms = sanitize_kill_monster_stats($monsterBody, $floor);
    $payload = [
        'player' => [
            'gear_score' => $gear,
            'ac' => $snap['ac'],
            'level' => $snap['level'],
            'weapon_hit_dmg_max' => $snap['weapon_hit_dmg_max'],
            'str_mod' => $snap['str_mod'],
            'weapon_dice' => $snap['weapon_dice'],
            'hp_max' => $snap['hp_max'],
        ],
        'monster' => $ms,
    ];
    return json_encode($payload, JSON_UNESCAPED_UNICODE);
}

/**
 * BIGINT 经验：库中十进制字符串（与 upgrade_surface_global_xp.sql 一致，等级无上限）。
 * 升级段：第 n 级→n+1 需 100+(n-1)*50 点（n≤300）；n≥301 时每段固定为「升到 301 级所需累计经验」同量级（见 xp_post301_segment_lump_str）。
 * 累计到 L 级（L>301）：T(301) + (L-301)×S，S=T(301)。301 级前与旧版完全一致。
 */
/** 纯线性旧曲线下的累计阈值（1→L），用于 L≤301 及作为 301 以上曲线的拼接基点 */
function xp_threshold_linear_legacy_cumulative_str(int $level): string
{
    if ($level <= 1) {
        return '0';
    }
    $L = $level - 1;
    if (function_exists('bcadd')) {
        $Ls = (string) $L;

        return bcadd(bcmul($Ls, '100', 0), bcmul(bcmul(bcsub($Ls, '1', 0), $Ls, 0), '25', 0), 0);
    }

    return (string) ($L * 100 + 25 * ($L - 1) * $L);
}

/** 301 级及之后每升一级所需单段经验（与「0→301 累计阈值」同值，约等于旧 0～300 全程之和） */
function xp_post301_segment_lump_str(): string
{
    return xp_threshold_linear_legacy_cumulative_str(301);
}

/** 当前等级 n 升到 n+1 所需本段经验（n≥301 时为固定大块 S；n≤300 为线性公式） */
function xp_post300_segment_cost_str(int $fromLevel): string
{
    $fromLevel = max(1, $fromLevel);
    if ($fromLevel < 301) {
        $n = $fromLevel;

        return (string) (100 + ($n - 1) * 50);
    }

    return xp_post301_segment_lump_str();
}

function player_xp_decimal_string(mixed $xp): string
{
    if (is_int($xp)) {
        return max(0, $xp) === 0 ? '0' : (string) max(0, $xp);
    }
    $s = preg_replace('/\D/', '', (string) $xp);
    if ($s === '') {
        return '0';
    }
    $s = ltrim($s, '0');

    return $s === '' ? '0' : $s;
}

/**
 * 经验三列进位基数：单列合法区间为 [0, RADIX-1]；满 RADIX 则高列 +1。
 * 取 10^16，低于 MySQL BIGINT UNSIGNED 上限，且与 JS Number 安全整数可分页展示。
 */
function player_xp_radix(): string
{
    return '10000000000000000';
}

function player_xp_row_has_carry_columns(array $row): bool
{
    return array_key_exists('xp_t2', $row) && array_key_exists('xp_t3', $row);
}

function player_xp_carry_schema_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $ready = false;
    try {
        $st = $pdo->query("SHOW COLUMNS FROM players WHERE Field IN ('xp_t2','xp_t3')");
        if ($st instanceof PDOStatement) {
            $ready = count($st->fetchAll(PDO::FETCH_ASSOC)) >= 2;
        }
    } catch (Throwable $e) {
    }

    return $ready;
}

/** 将三槽规范到每槽 < RADIX；第三槽仍溢出时封顶为全满槽（总经验上限约 RADIX^3-1） */
function player_xp_carry_normalize_slots(string $t0, string $t1, string $t2): array
{
    if (!function_exists('bcadd') || !function_exists('bcsub') || !function_exists('bccomp')) {
        return [$t0, $t1, $t2];
    }
    $R = player_xp_radix();
    $maxD = bcsub($R, '1', 0);
    while (bccomp($t0, $maxD, 0) > 0) {
        $t0 = bcsub($t0, $R, 0);
        $t1 = bcadd($t1, '1', 0);
        while (bccomp($t1, $maxD, 0) > 0) {
            $t1 = bcsub($t1, $R, 0);
            $t2 = bcadd($t2, '1', 0);
            if (bccomp($t2, $maxD, 0) > 0) {
                return [$maxD, $maxD, $maxD];
            }
        }
    }
    while (bccomp($t1, $maxD, 0) > 0) {
        $t1 = bcsub($t1, $R, 0);
        $t2 = bcadd($t2, '1', 0);
        if (bccomp($t2, $maxD, 0) > 0) {
            return [$maxD, $maxD, $maxD];
        }
    }
    if (bccomp($t2, $maxD, 0) > 0) {
        return [$maxD, $maxD, $maxD];
    }

    return [$t0, $t1, $t2];
}

/** 玩家行累计总经验（用于等级、距下一级、排行榜展示） */
function player_xp_total_decimal_string(array $row): string
{
    if (!player_xp_row_has_carry_columns($row)) {
        return player_xp_decimal_string($row['xp'] ?? 0);
    }
    if (!function_exists('bcadd') || !function_exists('bcmul')) {
        return player_xp_decimal_string($row['xp'] ?? 0);
    }
    $R = player_xp_radix();
    $t0 = player_xp_decimal_string($row['xp'] ?? 0);
    $t1 = player_xp_decimal_string($row['xp_t2'] ?? 0);
    $t2 = player_xp_decimal_string($row['xp_t3'] ?? 0);
    [$t0, $t1, $t2] = player_xp_carry_normalize_slots($t0, $t1, $t2);
    $r2 = bcmul($R, $R, 0);

    return bcadd(bcadd($t0, bcmul($t1, $R, 0), 0), bcmul($t2, $r2, 0), 0);
}

/** 若库中三列非规范（例如迁移前单列 xp 极大），写回拆分结果 */
function player_xp_persist_canonical_slots(PDO $pdo, array &$player): void
{
    if (!player_xp_row_has_carry_columns($player) || !player_xp_carry_schema_ready($pdo)) {
        return;
    }
    if (!function_exists('bccomp')) {
        return;
    }
    $pid = (int) ($player['id'] ?? 0);
    if ($pid < 1) {
        return;
    }
    $R = player_xp_radix();
    $t0 = player_xp_decimal_string($player['xp'] ?? 0);
    $t1 = player_xp_decimal_string($player['xp_t2'] ?? 0);
    $t2 = player_xp_decimal_string($player['xp_t3'] ?? 0);
    [$n0, $n1, $n2] = player_xp_carry_normalize_slots($t0, $t1, $t2);
    if (bccomp($n0, $t0, 0) === 0 && bccomp($n1, $t1, 0) === 0 && bccomp($n2, $t2, 0) === 0) {
        return;
    }
    try {
        $pdo->prepare('UPDATE players SET xp = ?, xp_t2 = ?, xp_t3 = ? WHERE id = ?')->execute([$n0, $n1, $n2, $pid]);
        $player['xp'] = $n0;
        $player['xp_t2'] = $n1;
        $player['xp_t3'] = $n2;
    } catch (Throwable $e) {
    }
}

/**
 * 在已有事务内为玩家增加经验（三列进位）；无迁移列时退化为 xp += gain。
 */
function player_xp_apply_gain(PDO $pdo, int $playerId, int $gain): void
{
    if ($playerId < 1 || $gain <= 0) {
        return;
    }
    if (function_exists('game_world_xp_gain_blocked') && game_world_xp_gain_blocked($pdo)) {
        return;
    }
    if (!player_xp_carry_schema_ready($pdo)) {
        try {
            $pdo->prepare('UPDATE players SET xp = xp + ? WHERE id = ?')->execute([$gain, $playerId]);
        } catch (Throwable $e) {
        }

        return;
    }
    if (!function_exists('bcadd')) {
        try {
            $pdo->prepare('UPDATE players SET xp = xp + ? WHERE id = ?')->execute([$gain, $playerId]);
        } catch (Throwable $e) {
        }

        return;
    }
    $st = $pdo->prepare('SELECT xp, xp_t2, xp_t3 FROM players WHERE id = ? FOR UPDATE');
    $st->execute([$playerId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }
    $t0 = player_xp_decimal_string($row['xp'] ?? 0);
    $t1 = player_xp_decimal_string($row['xp_t2'] ?? 0);
    $t2 = player_xp_decimal_string($row['xp_t3'] ?? 0);
    $t0 = bcadd($t0, (string) $gain, 0);
    [$n0, $n1, $n2] = player_xp_carry_normalize_slots($t0, $t1, $t2);
    $pdo->prepare('UPDATE players SET xp = ?, xp_t2 = ?, xp_t3 = ? WHERE id = ?')->execute([$n0, $n1, $n2, $playerId]);
}

/** 排行榜 ORDER BY 片段（三列字典序等价于总经验序，当每列已规范时） */
function player_xp_leaderboard_order_sql(PDO $pdo): string
{
    return player_xp_carry_schema_ready($pdo)
        ? 'p.xp_t3 DESC, p.xp_t2 DESC, p.xp DESC'
        : 'p.xp DESC';
}

/** API：三列经验条展示（每列当前值与共用上限 RADIX-1） */
function player_xp_meter_payload(array $player): ?array
{
    if (!player_xp_row_has_carry_columns($player)) {
        return null;
    }
    $R = player_xp_radix();
    $maxD = function_exists('bcsub') ? bcsub($R, '1', 0) : (string) ((int) $R - 1);
    $t0 = player_xp_decimal_string($player['xp'] ?? 0);
    $t1 = player_xp_decimal_string($player['xp_t2'] ?? 0);
    $t2 = player_xp_decimal_string($player['xp_t3'] ?? 0);
    if (function_exists('bcadd')) {
        [$t0, $t1, $t2] = player_xp_carry_normalize_slots($t0, $t1, $t2);
    }

    return [
        'radix' => $R,
        'max_per_slot' => $maxD,
        'slots' => [$t0, $t1, $t2],
    ];
}

/** 达到等级 $level（1-based）所需的最低累计经验（字符串，便于 BIGINT） */
function xp_threshold_for_level_cumulative_str(int $level): string
{
    $level = max(1, $level);
    if ($level <= 1) {
        return '0';
    }
    if ($level <= 301) {
        return xp_threshold_linear_legacy_cumulative_str($level);
    }
    if (!function_exists('bcadd') || !function_exists('bcmul')) {
        return xp_threshold_linear_legacy_cumulative_str(301);
    }
    $base301 = xp_threshold_linear_legacy_cumulative_str(301);
    $S = $base301;
    $k = $level - 301;

    return bcadd($base301, bcmul($S, (string) $k, 0), 0);
}

function xp_threshold_for_level_cumulative_int(int $level): int
{
    if ($level <= 1) {
        return 0;
    }
    if ($level <= 301) {
        $L = $level - 1;

        return $L * 100 + 25 * ($L - 1) * $L;
    }
    $s = xp_threshold_for_level_cumulative_str($level);
    if (function_exists('bccomp') && bccomp($s, (string) PHP_INT_MAX, 0) > 0) {
        return PHP_INT_MAX;
    }

    return (int) $s;
}

/** 升到等级 301+k 的累计阈值：base301 + k×S（k≥0；k=0 即 301 级） */
function xp_threshold_post301_cumulative_k_str(string $base301, string $S, int $k): string
{
    $k = max(0, $k);
    if (!function_exists('bcadd') || !function_exists('bcmul')) {
        return $base301;
    }

    return bcadd($base301, bcmul($S, (string) $k, 0), 0);
}

function xp_to_level_from_xp_string(string $xpDec): int
{
    if ($xpDec === '0' || $xpDec === '') {
        return 1;
    }
    $base301 = xp_threshold_linear_legacy_cumulative_str(301);
    if (function_exists('bccomp') && function_exists('bcadd') && function_exists('bcmul') && function_exists('bcsub')) {
        if (bccomp($xpDec, $base301, 0) < 0) {
            $lo = 1;
            $hi = 2;
            while (bccomp(xp_threshold_linear_legacy_cumulative_str($hi), $xpDec, 0) <= 0) {
                $hi *= 2;
                if ($hi > 301) {
                    $hi = 301;
                    break;
                }
            }
            $hi = min($hi, 301);
            while ($lo < $hi) {
                $mid = intdiv($lo + $hi + 1, 2);
                if (bccomp(xp_threshold_linear_legacy_cumulative_str($mid), $xpDec, 0) <= 0) {
                    $lo = $mid;
                } else {
                    $hi = $mid - 1;
                }
            }

            return $lo;
        }
        $S = $base301;
        if (bccomp($S, '0', 0) <= 0) {
            return 301;
        }
        $loK = 0;
        $hiK = 1;
        while (bccomp(xp_threshold_post301_cumulative_k_str($base301, $S, $hiK), $xpDec, 0) <= 0) {
            $hiK *= 2;
            if ($hiK > 2000000) {
                break;
            }
        }
        while ($loK < $hiK) {
            $midK = intdiv($loK + $hiK + 1, 2);
            if (bccomp(xp_threshold_post301_cumulative_k_str($base301, $S, $midK), $xpDec, 0) <= 0) {
                $loK = $midK;
            } else {
                $hiK = $midK - 1;
            }
        }
        $k = $loK;

        return 301 + min($k, 2000000000);
    }
    $xi = (int) $xpDec;
    if ($xi < 0) {
        return 1;
    }
    $base301i = xp_threshold_for_level_cumulative_int(301);
    if ($xi < $base301i) {
        $lo = 1;
        $hi = 2;
        while (xp_threshold_for_level_cumulative_int($hi) <= $xi) {
            $hi *= 2;
            if ($hi > 301) {
                $hi = 301;
                break;
            }
        }
        $hi = min($hi, 301);
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi + 1, 2);
            if (xp_threshold_for_level_cumulative_int($mid) <= $xi) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }

        return $lo;
    }
    $S = max(1, $base301i);
    $k = intdiv($xi - $base301i, $S);

    return 301 + min($k, 2000000000);
}

/** 达到等级 $level 的累计经验阈值（兼容旧名；无 99 封顶） */
function xp_threshold_for_level(int $level): int
{
    $level = max(1, $level);

    return (int) xp_threshold_for_level_cumulative_str($level);
}

/**
 * 距离下一级还差多少经验（BIGINT 下永不为 null；JSON 可能为数字或字符串）
 *
 * @return int|string|null
 */
function xp_remaining_to_next_level(mixed $xp)
{
    $xpDec = player_xp_decimal_string($xp);
    if (function_exists('bccomp') && bccomp($xpDec, (string) PHP_INT_MAX, 0) > 0) {
        $lvl = xp_to_level_from_xp_string($xpDec);
        $next = xp_threshold_for_level_cumulative_str($lvl + 1);
        $rem = bcsub($next, $xpDec, 0);

        return bccomp($rem, '0', 0) < 0 ? '0' : $rem;
    }
    $lvl = xp_to_level_from_xp_string($xpDec);
    $next = xp_threshold_for_level_cumulative_int($lvl + 1);
    $xi = (int) $xpDec;

    return max(0, $next - $xi);
}

/** API JSON：经验字段，超大时发字符串以免 JS 精度丢失 */
function json_xp_payload_field(mixed $rawXp)
{
    $s = player_xp_decimal_string($rawXp);
    if (function_exists('bccomp') && bccomp($s, '9007199254740991', 0) > 0) {
        return $s;
    }

    return (int) $s;
}

/** xp_to_next_level：与 json_xp_payload_field 一致，大数用字符串 */
function json_xp_to_next_field(mixed $rawXp)
{
    $rem = xp_remaining_to_next_level($rawXp);
    if (is_string($rem) && function_exists('bccomp') && bccomp($rem, '9007199254740991', 0) > 0) {
        return $rem;
    }

    return is_string($rem) ? (int) $rem : $rem;
}

function xp_stage_display_payload(mixed $rawXp): array
{
    $xpDec = player_xp_decimal_string($rawXp);
    $lvl = xp_to_level_from_xp_string($xpDec);
    if ($lvl <= 300) {
        return [
            'level_display' => (string) $lvl,
            'stage_no' => 0,
            'stage_level' => 0,
            'stage_progress_pct' => 0,
        ];
    }
    $nextTh = xp_threshold_for_level_cumulative_str($lvl + 1);
    $curTh = xp_threshold_for_level_cumulative_str($lvl);
    if (function_exists('bccomp')) {
        $seg = bcsub($nextTh, $curTh, 0);
        if (bccomp($seg, '1', 0) < 0) {
            $seg = '1';
        }
        $done = bcsub($xpDec, $curTh, 0);
        if (bccomp($done, '0', 0) < 0) {
            $done = '0';
        }
        $pct100 = (float) bcdiv(bcmul($done, '10000', 0), $seg, 4);
        $pct100 = max(0.0, min(10000.0, $pct100));
        $stageLevel = max(1, min(300, (int) floor(($pct100 / 10000) * 300)));
        $stageNo = max(1, $lvl - 300);
        return [
            'level_display' => $stageNo . '阶' . $stageLevel . '级',
            'stage_no' => $stageNo,
            'stage_level' => $stageLevel,
            'stage_progress_pct' => (int) floor($pct100 / 100),
        ];
    }
    $nextI = (int) $nextTh;
    $curI = (int) $curTh;
    $xpI = (int) $xpDec;
    $segI = max(1, $nextI - $curI);
    $doneI = max(0, $xpI - $curI);
    $ratio = max(0.0, min(1.0, $doneI / $segI));
    $stageLevel = max(1, min(300, (int) floor($ratio * 300)));
    $stageNo = max(1, $lvl - 300);
    return [
        'level_display' => $stageNo . '阶' . $stageLevel . '级',
        'stage_no' => $stageNo,
        'stage_level' => $stageLevel,
        'stage_progress_pct' => (int) floor($ratio * 100),
    ];
}

function ability_mod(int $score): int
{
    return (int) floor(($score - 10) / 2);
}

function load_item_templates(): array
{
    $path = dirname(__DIR__, 2) . '/data/items.json';
    if (!is_readable($path)) {
        return [];
    }
    $j = json_decode((string) file_get_contents($path), true);
    return is_array($j) ? $j : [];
}

/** @return array{dungeon_drop:array<string,mixed>,skills:list<array<string,mixed>>} */
function load_skills_json(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $path = dirname(__DIR__, 2) . '/data/skills.json';
    if (!is_readable($path)) {
        $cache = ['dungeon_drop' => [], 'skills' => []];

        return $cache;
    }
    $j = json_decode((string) file_get_contents($path), true);
    if (!is_array($j)) {
        $cache = ['dungeon_drop' => [], 'skills' => []];

        return $cache;
    }
    $cache = [
        'dungeon_drop' => is_array($j['dungeon_drop'] ?? null) ? $j['dungeon_drop'] : [],
        'skills' => is_array($j['skills'] ?? null) ? $j['skills'] : [],
    ];

    return $cache;
}

/** @return list<array<string,mixed>> */
function skills_json_rows(): array
{
    return load_skills_json()['skills'];
}

function skills_json_drop_config(): array
{
    return load_skills_json()['dungeon_drop'];
}

/** 地下城击杀/宝箱：技能书掉落概率（千分比） */
function dungeon_skill_book_drop_per_mille(int $floor, string $eventType): int
{
    $cfg = skills_json_drop_config();
    $floor = max(1, $floor);
    $type = strtolower(trim($eventType));
    if ($type === 'chest') {
        $base = (int) ($cfg['chest_base_per_mille'] ?? 65);
        $bonus = (int) ($cfg['chest_floor_bonus_per_mille'] ?? 4);
    } else {
        $base = (int) ($cfg['kill_base_per_mille'] ?? 42);
        $bonus = (int) ($cfg['kill_floor_bonus_per_mille'] ?? 3);
    }
    $max = (int) ($cfg['max_per_mille'] ?? 180);

    return min($max, $base + max(0, $floor - 1) * $bonus);
}

function branch_skill_book_drop_per_mille(int $depth): int
{
    $cfg = skills_json_drop_config();
    $depth = max(0, $depth);
    $base = (int) ($cfg['branch_combat_base_per_mille'] ?? 85);
    $bonus = (int) ($cfg['branch_depth_bonus_per_mille'] ?? 6);
    $max = (int) ($cfg['max_per_mille'] ?? 180);

    return min($max, $base + $depth * $bonus);
}

function skill_pick_book_template_for_drop(PDO $pdo, int $floor = 1): ?array
{
    $rows = skills_json_rows();
    if ($rows === []) {
        return null;
    }
    $pool = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $minF = max(1, (int) ($row['min_floor'] ?? 1));
        if ($floor < $minF) {
            continue;
        }
        $w = max(1, (int) ($row['drop_weight'] ?? 1));
        $book = (string) ($row['book_item_key'] ?? '');
        if ($book === '') {
            continue;
        }
        $pool[] = ['book' => $book, 'weight' => $w];
    }
    if ($pool === []) {
        return null;
    }
    $total = array_sum(array_column($pool, 'weight'));
    $roll = random_int(1, max(1, $total));
    $acc = 0;
    $picked = $pool[0]['book'];
    foreach ($pool as $p) {
        $acc += (int) $p['weight'];
        if ($roll <= $acc) {
            $picked = (string) $p['book'];
            break;
        }
    }
    foreach (load_item_templates() as $tpl) {
        if ((string) ($tpl['id'] ?? '') === $picked) {
            return $tpl;
        }
    }

    return null;
}

function load_monster_keys(): array
{
    $path = dirname(__DIR__, 2) . '/data/monsters.json';
    if (!is_readable($path)) {
        return [];
    }
    $j = json_decode((string) file_get_contents($path), true);
    if (!is_array($j)) {
        return [];
    }
    return array_keys($j);
}

/** 解析伤害骰 NdM 的 n、m（用于掉落权重与强化难度），失败则 1d1 */
function damage_dice_nm(string $dice): array
{
    $dice = trim($dice);
    if (preg_match('/^(\d+)d(\d+)/i', $dice, $m)) {
        return [max(1, (int) $m[1]), max(1, (int) $m[2])];
    }
    return [1, 1];
}

/** 升级难度倍率：√(n·m) 向上取整，1d1→1，2d8→4 */
function damage_dice_difficulty_mult(string $dice): int
{
    [$n, $m] = damage_dice_nm($dice);
    $p = $n * $m;
    return max(1, (int) ceil(sqrt($p)));
}

/**
 * 掉落：高骰装备权重更低；层数仍略抬高级装出现率。
 */
/**
 * 是否用该武器行计算命中伤害（仅主手；副手仅展示不参与骰子）。
 */
function weapon_row_is_main_hand(array $it): bool
{
    if (($it['slot'] ?? '') !== 'weapon' || (int) ($it['equipped'] ?? 0) !== 1) {
        return false;
    }
    $h = $it['weapon_hand'] ?? null;
    return $h === null || $h === 'main';
}

/** 来自物品模板 equip_hand：main | off | both（缺省 both） */
function weapon_allow_from_template(array $tpl): string
{
    $h = strtolower(trim((string) ($tpl['equip_hand'] ?? 'both')));
    return in_array($h, ['main', 'off', 'both'], true) ? $h : 'both';
}

function weapon_row_allow(array $row): string
{
    $a = strtolower(trim((string) ($row['weapon_allow'] ?? 'both')));
    return in_array($a, ['main', 'off', 'both'], true) ? $a : 'both';
}

function pick_loot_item_for_floor(int $floor): ?array
{
    $templates = load_item_templates();
    $eligible = [];
    foreach ($templates as $t) {
        if (!item_template_is_no_drop($t)) {
            $eligible[] = $t;
        }
    }
    if ($eligible === []) {
        return null;
    }
    $f = max(1, $floor);
    $tier = min(6, (int) floor(($f - 1) / 12));
    $weights = [];
    foreach ($eligible as $t) {
        $dice = (string) ($t['damage_dice'] ?? '1d4');
        [$nn, $mm] = damage_dice_nm($dice);
        $dicePower = max(1, $nn * $mm);
        // 骰子期望伤害越高，权重越低 → 越强装备越难掉（层数 tier 略抬稀有池）
        $rarityPenalty = (int) round(18 * log(1 + $dicePower, 2));
        $w = max(1, (int) ($t['weight'] ?? 10));
        $w = max(1, (int) floor($w * 120 / (10 + $rarityPenalty)));
        $r = $t['rarity'] ?? 'common';
        if ($r === 'legendary') {
            $w += (int) floor($tier * 3);
        } elseif ($r === 'epic') {
            $w += (int) floor($tier * 2);
        } elseif ($r === 'rare') {
            $w += (int) floor($tier * 1.5);
        } elseif ($r === 'uncommon') {
            $w += $tier;
        }
        $weights[] = $w;
    }
    $total = array_sum($weights);
    if ($total < 1) {
        return $eligible[0];
    }
    $r = random_int(1, $total);
    $acc = 0;
    foreach ($eligible as $i => $t) {
        $acc += $weights[$i];
        if ($r <= $acc) {
            return $t;
        }
    }

    return $eligible[array_key_last($eligible)];
}

function rarity_sell_mult(string $rarity): int
{
    return match ($rarity) {
        'uncommon' => 2,
        'rare' => 4,
        'epic' => 8,
        'legendary' => 14,
        default => 1,
    };
}

/** 商店收购价（固定，与出售结算一致） */
function shop_sell_price(array $row): int
{
    $base = 15;
    $mult = rarity_sell_mult((string) ($row['rarity'] ?? 'common'));
    return $base * $mult + 4;
}

/** 拍卖成交时平台手续费基点（500 = 5%，从成交价扣除后付给卖家） */
function auction_sale_fee_bps(): int
{
    return 500;
}

/** @return array{0:int,1:int} [fee, seller_receives] */
function auction_sale_fee_and_net(int $priceGold): array
{
    $bps = auction_sale_fee_bps();
    $fee = (int) floor($priceGold * $bps / 10000);
    $net = max(0, $priceGold - $fee);

    return [$fee, $net];
}

/** 将拍卖快照中的一行物品恢复到玩家背包（下架 / 批量下架共用） */
function auction_restore_player_item_from_snapshot(PDO $pdo, int $playerId, array $snap): void
{
    $recv = function_exists('can_player_receive_item') ? can_player_receive_item($pdo, $playerId, 0) : ['ok' => true, 'in_warehouse' => 0];
    if (!$recv['ok']) {
        throw new RuntimeException((string) ($recv['error'] ?? '背包与仓库已满'));
    }
    $inWh = (int) ($recv['in_warehouse'] ?? 0) === 1 ? 1 : 0;
    $wa = strtolower(trim((string) ($snap['weapon_allow'] ?? 'both')));
    if (!in_array($wa, ['main', 'off', 'both'], true)) {
        $wa = 'both';
    }
    $rcSnap = item_rank_checksum([
        'damage_dice' => (string) ($snap['damage_dice'] ?? '1d4'),
        'plus_level' => (int) ($snap['plus_level'] ?? 0),
    ]);
    $ins = $pdo->prepare(
        'INSERT INTO player_items (player_id, item_key, image_num, label, slot, rarity, bonus_str, bonus_dex, bonus_con, bonus_ac, bonus_trap, damage_dice, item_desc, equipped, weapon_hand, weapon_allow, plus_level, rank_checksum, in_warehouse)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,0,NULL,?,?,?,?)'
    );
    $ins->execute([
        $playerId,
        $snap['item_key'],
        (int) ($snap['image_num'] ?? 0),
        $snap['label'],
        $snap['slot'],
        $snap['rarity'],
        (int) $snap['bonus_str'],
        (int) $snap['bonus_dex'],
        (int) $snap['bonus_con'],
        (int) $snap['bonus_ac'],
        (int) ($snap['bonus_trap'] ?? 0),
        $snap['damage_dice'],
        (string) ($snap['item_desc'] ?? ''),
        $wa,
        (int) ($snap['plus_level'] ?? 0),
        $rcSnap,
        $inWh,
    ]);
}

function fetch_jump_equiv_catalog_rows(PDO $pdo): array
{
    try {
        $st = $pdo->query('SELECT item_key, equiv_units, label FROM jump_equiv_catalog ORDER BY item_key ASC');
        if (!$st) {
            return [];
        }

        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/** 跳跃法阵等价物目录（item_key → equiv_units） */
function jump_equiv_units_map(PDO $pdo): array
{
    $m = [];
    foreach (fetch_jump_equiv_catalog_rows($pdo) as $r) {
        $k = (string) ($r['item_key'] ?? '');
        if ($k !== '') {
            $m[$k] = max(0, (int) ($r['equiv_units'] ?? 0));
        }
    }

    return $m;
}

/**
 * 跳跃法阵等价物 item_key，用于背包「除等价表外全部出售」时保留。
 *
 * @return array<string, true>
 */
function protected_equiv_item_keys_for_bulk_sell(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $out = [];
    foreach (array_keys(jump_equiv_units_map($pdo)) as $k) {
        if ($k !== '') {
            $out[$k] = true;
        }
    }
    $cache = $out;

    return $cache;
}

function item_plus_level_mult(int $plus): float
{
    return $plus < 1 ? 1.0 : pow(1.5, $plus);
}

function scaled_stat_for_plus(int $v, int $plus): int
{
    if ($plus < 1) {
        return $v;
    }
    return (int) round($v * item_plus_level_mult($plus));
}

function damage_dice_with_plus_levels(string $dice, int $plus): string
{
    if ($plus < 1) {
        return $dice;
    }
    $add = $plus * 2;
    $dice = trim($dice);
    if (preg_match('/^(.+?)([+-])(\d+)$/', $dice, $m)) {
        $v = ($m[2] === '+' ? 1 : -1) * (int) $m[3];
        $v += $add;
        if ($v >= 0) {
            return $m[1] . '+' . $v;
        }
        return $m[1] . $v;
    }
    return $dice . '+' . $add;
}

/** NdM(+/-X) 的骰面最小/最大（与地城 rollDice 一致：结果至少为 1） */
function damage_dice_min_max(string $diceExpr): array
{
    $diceExpr = trim($diceExpr);
    if (!preg_match('/^(\d+)d(\d+)([+-]\d+)?$/i', $diceExpr, $m)) {
        return [1, 1];
    }
    $n = max(1, min(20, (int) $m[1]));
    $d = max(2, min(100, (int) $m[2]));
    $mod = isset($m[3]) ? (int) $m[3] : 0;
    $lo = max(1, $n + $mod);
    $hi = max(1, $n * $d + $mod);
    return [$lo, $hi];
}

/** 地城陷阱基础伤害：4～11（与客户端一致） */
function trap_raw_damage_bounds(): array
{
    return [4, 11];
}

/**
 * 鞋：排行分 S = n×m×max(1,强化) 与 bonus_trap（随强化缩放）共同决定减震区间，封顶避免完全免疫。
 * @return array{0:int,1:int} 减震点数 [min, max]
 */
function trap_mitigation_bounds_from_boots(int $score, int $scaledBonusTrap): array
{
    $extra = max(0, $scaledBonusTrap);
    if ($score < 1 && $extra < 1) {
        return [0, 0];
    }
    $lo = (int) floor($score * 0.10) + $extra;
    $hi = (int) floor($score * 0.22) + $extra;
    $lo = max(0, min(11, $lo));
    $hi = max($lo, min(11, $hi));
    return [$lo, $hi];
}

function insert_generated_item(PDO $pdo, int $playerId, array $tpl): int
{
    $recv = function_exists('can_player_receive_item') ? can_player_receive_item($pdo, $playerId, 0) : ['ok' => true, 'in_warehouse' => 0];
    if (!$recv['ok']) {
        throw new RuntimeException((string) ($recv['error'] ?? '背包与仓库已满'));
    }
    $inWh = (int) ($recv['in_warehouse'] ?? 0) === 1 ? 1 : 0;
    $desc = (string) ($tpl['desc'] ?? '');
    $imgNum = (int) ($tpl['image_num'] ?? 0);
    $allow = ($tpl['slot'] ?? '') === 'weapon' ? weapon_allow_from_template($tpl) : 'both';
    $diceIns = (string) ($tpl['damage_dice'] ?? '1d4');
    $rcIns = item_rank_checksum(['damage_dice' => $diceIns, 'plus_level' => 0]);
    $bt = (int) ($tpl['bonus_trap'] ?? 0);
    $st = $pdo->prepare(
        'INSERT INTO player_items (player_id, item_key, image_num, label, slot, rarity, bonus_str, bonus_dex, bonus_con, bonus_ac, bonus_trap, damage_dice, item_desc, equipped, weapon_hand, weapon_allow, plus_level, rank_checksum, in_warehouse)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,0,NULL,?,?,?,?)'
    );
    $st->execute([
        $playerId,
        $tpl['id'],
        $imgNum,
        $tpl['label'],
        $tpl['slot'],
        $tpl['rarity'] ?? 'common',
        (int) ($tpl['bonus_str'] ?? 0),
        (int) ($tpl['bonus_dex'] ?? 0),
        (int) ($tpl['bonus_con'] ?? 0),
        (int) ($tpl['bonus_ac'] ?? 0),
        $bt,
        $diceIns,
        $desc,
        $allow,
        0,
        $rcIns,
        $inWh,
    ]);
    return (int) $pdo->lastInsertId();
}

/** 按 item_key 发放若干件（找不到模板则静默跳过） */
function grant_player_items_by_key(PDO $pdo, int $playerId, string $itemKey, int $count = 1): void
{
    if ($count < 1) {
        return;
    }
    foreach (load_item_templates() as $tpl) {
        if ((string) ($tpl['id'] ?? '') === $itemKey) {
            for ($i = 0; $i < $count; $i++) {
                insert_generated_item($pdo, $playerId, $tpl);
            }

            return;
        }
    }
}

function fetch_inventory(PDO $pdo, int $playerId): array
{
    $st = $pdo->prepare(
        'SELECT id, item_key, image_num, label, slot, rarity, bonus_str, bonus_dex, bonus_con, bonus_ac, bonus_trap, damage_dice, item_desc, equipped, weapon_hand, weapon_allow, plus_level, rank_checksum, in_warehouse
         FROM player_items WHERE player_id = ? ORDER BY equipped DESC, in_warehouse ASC, weapon_hand ASC, id ASC'
    );
    $st->execute([$playerId]);
    return $st->fetchAll();
}

function enrich_inventory_rows(array $inv): array
{
    foreach ($inv as &$row) {
        $row['shop_sell_gold'] = shop_sell_price($row);
        $row['in_warehouse'] = (int) ($row['in_warehouse'] ?? 0);
    }
    unset($row);
    return $inv;
}

/** NdM 中骰子面数 M（用于护甲 AC 衰减曲线；与 D20 标尺对齐）；解析失败默认 4 */
function damage_dice_face_max(string $diceExpr): int
{
    $diceExpr = trim($diceExpr);
    if (!preg_match('/^(\d+)d(\d+)/i', $diceExpr, $m)) {
        return 4;
    }

    return max(1, min(100, (int) $m[2]));
}

/**
 * 护甲骰 d 面 → 护甲「bonus_ac」计入 AC 的生效比例：d1=100%，d10=50%，d20=0%（分段线性）。
 * 与地城/地表客户端展示一致；仅作用于护甲槽的 AC 加值，戒指/鞋的 bonus_ac 仍全额。
 */
function armor_ac_effectiveness_for_die_face(int $dFace): float
{
    $d = max(1, min(20, $dFace));
    if ($d <= 1) {
        return 1.0;
    }
    if ($d >= 20) {
        return 0.0;
    }
    if ($d <= 10) {
        return 1.0 - (($d - 1) / 9.0) * 0.5;
    }

    return 0.5 - (($d - 10) / 10.0) * 0.5;
}

/** @return array{0:?array,1:?array} [主手武器行, 副手武器行] */
function inventory_equipped_weapon_main_off(array $inventory): array
{
    $main = null;
    $off = null;
    foreach ($inventory as $it) {
        if ((int) ($it['equipped'] ?? 0) !== 1) {
            continue;
        }
        if (($it['slot'] ?? '') !== 'weapon') {
            continue;
        }
        $h = $it['weapon_hand'] ?? null;
        if ($h === 'off') {
            $off = $it;
        } elseif ($h === null || $h === '' || $h === 'main') {
            $main = $it;
        }
    }

    return [$main, $off];
}

/**
 * 主手骰命中伤害倍率（与武器种类无关）：仅一手在武器槽装备 ×1.5；主手与副手武器槽均装备 ×0.5；
 * dual_wield_master 或 dual_sword_master=1 时取消双持惩罚（后者为兼容旧字段名）。
 *
 * @return array{0:float,1:string} [倍率, 提示文案（空则无需 Toast）]
 */
function player_weapon_damage_multiplier_and_hint(array $inventory, bool $dualWieldMaster): array
{
    [$main, $off] = inventory_equipped_weapon_main_off($inventory);
    $hasMain = $main !== null;
    $hasOff = $off !== null;
    if (!$hasMain && !$hasOff) {
        return [1.0, ''];
    }
    if ($hasMain xor $hasOff) {
        return [1.5, '单手持武：仅一手装备武器，命中伤害×1.5。'];
    }
    if ($dualWieldMaster) {
        return [1.0, '双持大师：主副手均有武器时不再受×0.5 惩罚。'];
    }

    return [0.5, '双持：主手与副手均装备武器，命中伤害×0.5（未来专精技能可取消）。'];
}

function compute_combat_snapshot(PDO $pdo, array $player, array $inventory): array
{
    expansion_ensure_schema($pdo);
    $baseStr = (int) $player['str'];
    $baseDex = (int) $player['dex'];
    $baseCon = (int) $player['con'];
    $baseInt = (int) ($player['int_stat'] ?? 10);
    $intGear = 0;
    $strGear = 0;
    $dexGear = 0;
    $conGear = 0;
    $acFlat = 0;
    $weaponDice = '1d4';
    $weaponRow = null;
    $armorRow = null;
    $bootsRow = null;
    foreach ($inventory as $it) {
        if ((int) $it['equipped'] !== 1) {
            continue;
        }
        $pl = (int) ($it['plus_level'] ?? 0);
        $slot = (string) ($it['slot'] ?? '');
        if (!in_array($slot, ['weapon', 'armor', 'ring', 'boots'], true)) {
            continue;
        }
        $strGear += scaled_stat_for_plus((int) $it['bonus_str'], $pl);
        $dexGear += scaled_stat_for_plus((int) $it['bonus_dex'], $pl);
        $conGear += scaled_stat_for_plus((int) $it['bonus_con'], $pl);
        $intGear += scaled_stat_for_plus((int) ($it['bonus_int'] ?? 0), $pl);
        if ($slot !== 'armor') {
            $acFlat += scaled_stat_for_plus((int) $it['bonus_ac'], $pl);
        }
        if ($slot === 'weapon' && weapon_row_is_main_hand($it)) {
            $weaponRow = $it;
        }
        if ($slot === 'armor' && $armorRow === null) {
            $armorRow = $it;
        }
        if ($slot === 'boots' && $bootsRow === null) {
            $bootsRow = $it;
        }
    }
    if ($weaponRow !== null) {
        $wpl = (int) ($weaponRow['plus_level'] ?? 0);
        $weaponDice = damage_dice_with_plus_levels((string) $weaponRow['damage_dice'], $wpl);
    }
    $level = xp_to_level_from_xp_string(player_xp_total_decimal_string($player));
    $prof = profession_snapshot_for_player($pdo, (int) ($player['id'] ?? 0), $level);
    $baseStr = max(1, (int) round($baseStr * (float) ($prof['str_mult'] ?? 1.0)));
    $intEffective = max(1, (int) round(($baseInt + $intGear) * (float) ($prof['int_mult'] ?? 1.0)));
    $effStr = $baseStr + $strGear;
    $effDex = $baseDex + $dexGear;
    $effCon = $baseCon + $conGear;
    $conMod = ability_mod($effCon);
    /** 301 级后不再随等级涨生命（避免极端等级 HP 失控；与等级公式解耦） */
    $hpLevel = min(301, $level);
    $hpMax = max(1, (int) (8 + $hpLevel * (4 + max(0, $conMod))));
    $strMod = ability_mod($effStr);

    $armorDiceEff = '1d4';
    $armorRollMin = 1;
    $armorRollMax = 1;
    $armorAcFromItem = 0;
    $armorDieD = 0;
    $armorAcEff = 1.0;
    $armorAcApplied = 0;
    $armorCombatHint = '';
    if ($armorRow !== null) {
        $apl = (int) ($armorRow['plus_level'] ?? 0);
        $armorDiceEff = damage_dice_with_plus_levels((string) $armorRow['damage_dice'], $apl);
        [$armorRollMin, $armorRollMax] = damage_dice_min_max($armorDiceEff);
        $armorAcFromItem = scaled_stat_for_plus((int) $armorRow['bonus_ac'], $apl);
        $armorDieD = damage_dice_face_max($armorDiceEff);
        $armorAcEff = armor_ac_effectiveness_for_die_face($armorDieD);
        $armorAcApplied = (int) round($armorAcFromItem * $armorAcEff);
        $acFlat += $armorAcApplied;
        if ($armorAcFromItem > 0 && $armorAcEff < 0.999) {
            $armorCombatHint = '护甲骰最大面 d' . $armorDieD . '：护甲 AC 加值生效约 ' . (int) round($armorAcEff * 100)
                . '%（d1→100%，d10→50%，d20→0%）。';
        }
    }
    $ac = 10 + ability_mod($effDex) + $acFlat;

    $dualWieldMaster = ((int) ($player['dual_wield_master'] ?? $player['dual_sword_master'] ?? 0)) === 1;
    [$weaponDamageMult, $weaponCombatHint] = player_weapon_damage_multiplier_and_hint($inventory, $dualWieldMaster);
    [$wRollMin, $wRollMax] = damage_dice_min_max($weaponDice);
    $weaponHitMin = max(1, (int) round(($wRollMin + $strMod) * $weaponDamageMult));
    $weaponHitMax = max(1, (int) round(($wRollMax + $strMod) * $weaponDamageMult));

    [$trapRawMin, $trapRawMax] = trap_raw_damage_bounds();
    $bootScore = 0;
    $trapMitigMin = 0;
    $trapMitigMax = 0;
    if ($bootsRow !== null) {
        $bpl = (int) ($bootsRow['plus_level'] ?? 0);
        $bootScore = item_rank_checksum(array_merge($bootsRow, ['plus_level' => $bpl]));
        $trapExtra = scaled_stat_for_plus((int) ($bootsRow['bonus_trap'] ?? 0), $bpl);
        [$trapMitigMin, $trapMitigMax] = trap_mitigation_bounds_from_boots($bootScore, $trapExtra);
    }
    $trapFinalMin = max(1, $trapRawMin - $trapMitigMax);
    $trapFinalMax = max(1, $trapRawMax - $trapMitigMin);

    return [
        'level' => $level,
        'ac' => max(1, $ac),
        'str' => $baseStr,
        'dex' => $baseDex,
        'con' => $baseCon,
        'str_effective' => $effStr,
        'dex_effective' => $effDex,
        'con_effective' => $effCon,
        'str_mod' => $strMod,
        'dex_mod' => ability_mod($effDex),
        'str_mod_base' => ability_mod($baseStr),
        'dex_mod_base' => ability_mod($baseDex),
        'con_mod_base' => ability_mod($baseCon),
        'weapon_dice' => $weaponDice,
        'weapon_roll_min' => $wRollMin,
        'weapon_roll_max' => $wRollMax,
        'weapon_hit_dmg_min' => $weaponHitMin,
        'weapon_hit_dmg_max' => $weaponHitMax,
        'armor_dice' => $armorDiceEff,
        'armor_roll_min' => $armorRollMin,
        'armor_roll_max' => $armorRollMax,
        'armor_ac_bonus' => $armorAcFromItem,
        'armor_die_d' => $armorDieD,
        'armor_ac_effectiveness' => $armorAcEff,
        'armor_ac_applied' => $armorAcApplied,
        'armor_combat_hint' => $armorCombatHint,
        'weapon_damage_mult' => $weaponDamageMult,
        'weapon_combat_hint' => $weaponCombatHint,
        'dual_wield_master' => $dualWieldMaster ? 1 : 0,
        'dual_sword_master' => $dualWieldMaster ? 1 : 0,
        'trap_raw_min' => $trapRawMin,
        'trap_raw_max' => $trapRawMax,
        'trap_mitig_min' => $trapMitigMin,
        'trap_mitig_max' => $trapMitigMax,
        'trap_final_dmg_min' => $trapFinalMin,
        'trap_final_dmg_max' => $trapFinalMax,
        'boots_rank_score' => $bootScore,
        'hp_max' => $hpMax,
        'profession_key' => (string) ($prof['profession_key'] ?? ''),
        'profession_label' => (string) ($prof['label'] ?? '无'),
        'profession_str_mult' => (float) ($prof['str_mult'] ?? 1.0),
        'profession_int_mult' => (float) ($prof['int_mult'] ?? 1.0),
        'int_effective' => $intEffective,
    ];
}

function build_kill_player_snapshot(PDO $pdo, array $player, array $inventory): array
{
    $snap = compute_combat_snapshot($pdo, $player, $inventory);
    return [
        'xp' => json_xp_payload_field(player_xp_total_decimal_string($player)),
        'gold' => (int) $player['gold'],
        'gear_score' => equipped_gear_score_sum($inventory),
        'level' => $snap['level'],
        'str_base' => $snap['str'],
        'dex_base' => $snap['dex'],
        'con_base' => $snap['con'],
        'str_effective' => $snap['str_effective'],
        'dex_effective' => $snap['dex_effective'],
        'con_effective' => $snap['con_effective'],
        'str_mod_base' => $snap['str_mod_base'],
        'dex_mod_base' => $snap['dex_mod_base'],
        'str_mod' => $snap['str_mod'],
        'dex_mod' => $snap['dex_mod'],
        'int_stat' => (int) $player['int_stat'],
        'wis' => (int) $player['wis'],
        'cha' => (int) $player['cha'],
        'ac' => $snap['ac'],
        'hp_max' => $snap['hp_max'],
        'weapon_dice' => $snap['weapon_dice'],
        'inventory_count' => count($inventory),
    ];
}

/** 登录后 player 接口附带：邮箱绑定与验证状态（不含完整邮箱）。 */
function account_email_status_slice(PDO $pdo, int $accountUserId): array
{
    if ($accountUserId < 1) {
        return ['has_email' => false, 'email_verified' => true, 'email_masked' => ''];
    }
    try {
        $st = $pdo->prepare('SELECT email, email_verified FROM accounts WHERE id = ? LIMIT 1');
        $st->execute([$accountUserId]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if (!$a) {
            return ['has_email' => false, 'email_verified' => true, 'email_masked' => ''];
        }
        $em = trim((string) ($a['email'] ?? ''));
        $ev = (int) ($a['email_verified'] ?? 0) === 1;

        return [
            'has_email' => $em !== '',
            'email_verified' => $ev,
            'email_masked' => $em === '' ? '' : mask_email_for_display($em),
        ];
    } catch (Throwable $e) {
        return ['has_email' => false, 'email_verified' => true, 'email_masked' => ''];
    }
}

function mask_email_for_display(string $email): string
{
    $email = trim($email);
    if ($email === '') {
        return '';
    }
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) {
        return '***';
    }
    [$u, $d] = $parts;
    $len = strlen($u);
    if ($len <= 1) {
        $show = '*';
    } elseif ($len <= 3) {
        $show = substr($u, 0, 1) . '***';
    } else {
        $show = substr($u, 0, 2) . '***' . substr($u, -1);
    }

    return $show . '@' . $d;
}

/**
 * 地表/场景内 MZ 行走图：仅当杂货店购入的 man_sheet_1002 在背包中「装备」时为 1002，否则 1001。
 * 不在此写入 players.avatar_sheet；禁止客户端随意改形象。
 */
function player_scene_avatar_sheet_effective(PDO $pdo, array $player, array $inventory): int
{
    $sid = (string) ($player['active_skin_id'] ?? 'm1001');
    if ($sid === '') {
        $sid = 'm1001';
    }
    if (player_has_unlocked_skin($pdo, (int) ($player['id'] ?? 0), $sid)) {
        $png = avatar_skins_man_png_by_id($sid);
        if ($png !== null) {
            return $png;
        }
    }
    foreach ($inventory as $it) {
        if ((int) ($it['equipped'] ?? 0) !== 1) {
            continue;
        }
        if ((string) ($it['item_key'] ?? '') !== 'man_sheet_1002') {
            continue;
        }
        if ((string) ($it['slot'] ?? '') !== 'misc') {
            continue;
        }

        return 1002;
    }

    return 1001;
}

function player_payload(PDO $pdo, array $player): array
{
    expansion_ensure_schema($pdo);
    $id = (int) $player['id'];
    player_xp_persist_canonical_slots($pdo, $player);
    $inv = enrich_inventory_rows(fetch_inventory($pdo, $id));
    $snap = compute_combat_snapshot($pdo, $player, $inv);
    $xpStage = xp_stage_display_payload(player_xp_total_decimal_string($player));
    $pdo->prepare('UPDATE players SET level_cached = ? WHERE id = ?')->execute([$snap['level'], $id]);
    stamina_ensure_today($pdo, $player);
    $staminaOut = stamina_snapshot_for_player($pdo, $player, $inv);
    $worldMax = world_dungeon_max_floor($pdo);
    $spawnF = max(1, (int) ($player['dungeon_spawn_floor'] ?? 1));
    $peakDb = max(1, (int) ($player['dungeon_peak_floor'] ?? 1));
    $peakOut = max($peakDb, $spawnF);
    if ($peakOut > $peakDb) {
        try {
            $pdo->prepare('UPDATE players SET dungeon_peak_floor = ? WHERE id = ?')->execute([$peakOut, $id]);
            $player['dungeon_peak_floor'] = $peakOut;
        } catch (Throwable $e) {
            /* 未执行 sql/upgrade_social_guild_peak.sql 时忽略 */
        }
    }
    $advMeta = adventurer_hud_meta($peakOut, $worldMax);
    $guildSnap = guild_fetch_player_snapshot($pdo, $id);
    $titlesSlice = titles_player_payload_slice($pdo, $id);
    $skillsSlice = player_skills_enriched($pdo, $id, (int) $snap['level'], [
        'int_effective' => (int) ($snap['int_effective'] ?? (int) $player['int_stat']),
        'wis' => (int) $player['wis'],
        'str_effective' => (int) ($snap['str_effective'] ?? (int) $player['str_stat']),
        'int_stat' => (int) $player['int_stat'],
    ]);
    $knapsackRows = player_knapsack_sync($pdo, $id);
    $autoActions = player_auto_actions_get($pdo, $id);
    $limitsCfg = function_exists('game_limits_for_player')
        ? game_limits_for_player($pdo, $id)
        : ['bag_capacity' => 100, 'warehouse_capacity' => 100, 'auction_post_limit' => 10];
    $limitsCnt = function_exists('player_storage_counts') ? player_storage_counts($pdo, $id) : ['bag' => 0, 'warehouse' => 0];
    $surfaceHud = ['enabled' => 0];
    $accUserId = (int) ($player['user_id'] ?? 0);
    player_unlock_skin($pdo, $id, 'm1001');
    if ((string) ($player['active_skin_id'] ?? '') === '') {
        try {
            $pdo->prepare("UPDATE players SET active_skin_id='m1001' WHERE id=? AND (active_skin_id IS NULL OR active_skin_id='')")->execute([$id]);
            $player['active_skin_id'] = 'm1001';
        } catch (Throwable $e) {
        }
    }
    $unlockedSkins = [];
    foreach (player_unlocked_skin_ids($pdo, $id) as $sid) {
        $label = $sid;
        foreach (avatar_skins_catalog() as $sc) {
            if ($sc['id'] === $sid) {
                $label = (string) $sc['label'];
                break;
            }
        }
        $unlockedSkins[] = [
            'id' => $sid,
            'label' => $label,
            'man_png' => avatar_skins_man_png_by_id($sid) ?? 1001,
        ];
    }
    $deckSlice = battle_deck_payload_slice($pdo, $id);

    return [
        'ok' => true,
        'account' => account_email_status_slice($pdo, $accUserId),
        'player' => [
            'id' => $id,
            'surface' => $surfaceHud,
            'display_name' => $player['display_name'],
            'xp' => json_xp_payload_field(player_xp_total_decimal_string($player)),
            'xp_to_next_level' => json_xp_to_next_field(player_xp_total_decimal_string($player)),
            'xp_meter' => player_xp_meter_payload($player),
            'gold' => (int) $player['gold'],
            'sunbeam_crystal_count' => player_sunbeam_crystal_count($pdo, $id),
            'level' => $snap['level'],
            'level_display' => (string) ($xpStage['level_display'] ?? (string) $snap['level']),
            'level_stage_no' => (int) ($xpStage['stage_no'] ?? 0),
            'level_stage_level' => (int) ($xpStage['stage_level'] ?? 0),
            'level_stage_progress_pct' => (int) ($xpStage['stage_progress_pct'] ?? 0),
            'str' => $snap['str'],
            'dex' => $snap['dex'],
            'con' => $snap['con'],
            'str_effective' => $snap['str_effective'],
            'dex_effective' => $snap['dex_effective'],
            'con_effective' => $snap['con_effective'],
            'str_mod' => $snap['str_mod'],
            'dex_mod' => $snap['dex_mod'],
            'str_mod_base' => $snap['str_mod_base'],
            'dex_mod_base' => $snap['dex_mod_base'],
            'con_mod_base' => $snap['con_mod_base'],
            'int_stat' => (int) $player['int_stat'],
            'int_effective' => (int) ($snap['int_effective'] ?? (int) $player['int_stat']),
            'wis' => (int) $player['wis'],
            'cha' => (int) $player['cha'],
            'profession' => [
                'key' => (string) ($snap['profession_key'] ?? ''),
                'label' => (string) ($snap['profession_label'] ?? '无'),
                'str_mult' => (float) ($snap['profession_str_mult'] ?? 1.0),
                'int_mult' => (float) ($snap['profession_int_mult'] ?? 1.0),
            ],
            'ac' => $snap['ac'],
            'hp_max' => $snap['hp_max'],
            'weapon_dice' => $snap['weapon_dice'],
            'weapon_roll_min' => $snap['weapon_roll_min'],
            'weapon_roll_max' => $snap['weapon_roll_max'],
            'weapon_hit_dmg_min' => $snap['weapon_hit_dmg_min'],
            'weapon_hit_dmg_max' => $snap['weapon_hit_dmg_max'],
            'armor_dice' => $snap['armor_dice'],
            'armor_roll_min' => $snap['armor_roll_min'],
            'armor_roll_max' => $snap['armor_roll_max'],
            'armor_ac_bonus' => $snap['armor_ac_bonus'],
            'armor_die_d' => $snap['armor_die_d'],
            'armor_ac_effectiveness' => $snap['armor_ac_effectiveness'],
            'armor_ac_applied' => $snap['armor_ac_applied'],
            'armor_combat_hint' => $snap['armor_combat_hint'],
            'weapon_damage_mult' => $snap['weapon_damage_mult'],
            'weapon_combat_hint' => $snap['weapon_combat_hint'],
            'dual_wield_master' => $snap['dual_wield_master'],
            'dual_sword_master' => $snap['dual_sword_master'],
            'trap_raw_min' => $snap['trap_raw_min'],
            'trap_raw_max' => $snap['trap_raw_max'],
            'trap_mitig_min' => $snap['trap_mitig_min'],
            'trap_mitig_max' => $snap['trap_mitig_max'],
            'trap_final_dmg_min' => $snap['trap_final_dmg_min'],
            'trap_final_dmg_max' => $snap['trap_final_dmg_max'],
            'boots_rank_score' => $snap['boots_rank_score'],
            'dungeon_spawn_floor' => max(1, (int) ($player['dungeon_spawn_floor'] ?? 1)),
            'dungeon_peak_floor' => $peakOut,
            'adventurer_title' => $advMeta,
            'guild' => $guildSnap,
            'owned_titles' => $titlesSlice['owned'],
            'equipped_title' => $titlesSlice['equipped'],
            'stamina' => $staminaOut,
            'avatar_sheet' => player_scene_avatar_sheet_effective($pdo, $player, $inv),
            'active_skin_id' => (string) ($player['active_skin_id'] ?? 'm1001'),
            'unlocked_skins' => $unlockedSkins,
            'dungeon_global_lock' => 0,
            'xp_gain_blocked' => (function_exists('game_world_xp_gain_blocked') && game_world_xp_gain_blocked($pdo)) ? 1 : 0,
        ],
        'inventory' => $inv,
        'knapsack_inventory' => enrich_inventory_rows($knapsackRows),
        'skills' => $skillsSlice,
        'battle_deck' => $deckSlice,
        'auto_action_preset' => $autoActions,
        'limits' => [
            'bag_capacity' => (int) ($limitsCfg['bag_capacity'] ?? 100),
            'warehouse_capacity' => (int) ($limitsCfg['warehouse_capacity'] ?? 100),
            'auction_post_limit' => (int) ($limitsCfg['auction_post_limit'] ?? 10),
            'bag_used' => (int) ($limitsCnt['bag'] ?? 0),
            'warehouse_used' => (int) ($limitsCnt['warehouse'] ?? 0),
        ],
        'dungeon_world' => function_exists('fetch_dungeon_world_for_player')
            ? fetch_dungeon_world_for_player($pdo, $player)
            : fetch_dungeon_world_safe($pdo),
    ];
}

/** 每 10 层线性「档」（陷阱 UI、非魔物曲线）：1–10 → 1，11–20 → 2… */
function dungeon_floor_tier_mult(int $floor): int
{
    $f = max(1, min(100000, $floor));

    return (int) floor(($f - 1) / 10) + 1;
}

/**
 * 魔物 HP / 反击强度：1–100 层为线性档 ⌊(f−1)/10⌋+1；101 层起以 10 为基准每 10 层 ×2。
 * 翻倍次数封顶与 js MONSTER_POST100_DOUBLE_CAP 一致。
 */
function dungeon_monster_floor_tier_mult(int $floor): int
{
    $f = max(1, min(100000, $floor));
    if ($f <= 100) {
        return (int) floor(($f - 1) / 10) + 1;
    }
    $base100 = 10;
    $doubleSteps = (int) floor(($f - 101) / 10) + 1;
    $exp = min(40, $doubleSteps);

    return $base100 * (2 ** $exp);
}

/** 与 js scaleMonster 一致的基础缩放（1–100 线性档，101+ 每 10 层在基准上 ×2），用于世界首领推算 */
function scale_monster_base_stats_for_floor(array $def, int $floor): array
{
    $f = max(1, min(100000, $floor));
    $mul = 1 + ($f - 1) * 0.11;
    $tm = dungeon_monster_floor_tier_mult($f);
    $baseHp = (float) ($def['hp'] ?? 12);
    $hp = max(1, (int) round($baseHp * $mul + ($f - 1) * 2));
    $hp = max(1, (int) round($hp * $tm));
    $bracket = intdiv($f - 1, 10);
    $acCap = min(46, 30 + intdiv($bracket, 2));
    $toCap = min(40, 20 + intdiv($bracket, 2));
    $ac = min($acCap, (int) round((float) ($def['ac'] ?? 11) + floor(($f - 1) / 2)));
    $toHit = min($toCap, (int) round((float) ($def['to_hit'] ?? 3) + floor(($f - 1) / 3)));
    $damage = (string) ($def['damage'] ?? '1d6');

    return ['hp' => $hp, 'ac' => $ac, 'to_hit' => $toHit, 'damage' => $damage];
}

function world_boss_template_def(): array
{
    $path = dirname(__DIR__, 2) . '/data/monsters.json';
    if (!is_readable($path)) {
        return ['hp' => 18, 'ac' => 12, 'to_hit' => 4, 'damage' => '1d6+1'];
    }
    $j = json_decode((string) file_get_contents($path), true);
    if (is_array($j) && isset($j['ginger_grunt']) && is_array($j['ginger_grunt'])) {
        return $j['ginger_grunt'];
    }

    return ['hp' => 18, 'ac' => 12, 'to_hit' => 4, 'damage' => '1d6+1'];
}

function ensure_world_dungeon_row(PDO $pdo): void
{
    try {
        $pdo->exec('INSERT IGNORE INTO world_dungeon (id, max_unlocked_floor) VALUES (1, 10)');
    } catch (Throwable $e) {
    }
}

function world_dungeon_max_floor(PDO $pdo): int
{
    try {
        $st = $pdo->query('SELECT max_unlocked_floor FROM world_dungeon WHERE id = 1 LIMIT 1');
        $v = $st ? $st->fetchColumn() : false;
        $n = $v !== false ? (int) $v : 10;
        if ($n < 1) {
            return 10;
        }

        return min(100000, $n);
    } catch (Throwable $e) {
        return 100000;
    }
}

/** @return array<string,mixed>|null */
function get_or_create_world_boss_row(PDO $pdo, int $milestone): ?array
{
    if ($milestone < 10 || $milestone % 10 !== 0) {
        return null;
    }
    try {
        $st = $pdo->prepare('SELECT * FROM world_boss WHERE milestone = ? LIMIT 1');
        $st->execute([$milestone]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
        $def = world_boss_template_def();
        $scaled = scale_monster_base_stats_for_floor($def, $milestone);
        $hpMax = max(1, (int) round($scaled['hp'] * 1000));
        $ac = min(40, (int) round($scaled['ac'] * 1.3));
        $toHit = min(30, (int) round($scaled['to_hit'] * 1.3));
        $damage = substr(preg_replace('/[^0-9dD+\-]/', '', $scaled['damage']), 0, 24);
        if ($damage === '') {
            $damage = '1d6';
        }
        $label = '世界首领 · 第' . $milestone . '层';
        $ins = $pdo->prepare(
            'INSERT INTO world_boss (milestone, hp_current, hp_max, ac, to_hit, damage_dice, monster_key, label, defeated)
             VALUES (?,?,?,?,?,?,?,?,0)'
        );
        $ins->execute([$milestone, $hpMax, $hpMax, $ac, $toHit, $damage, 'world_boss', $label]);
        $st->execute([$milestone]);

        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function fetch_dungeon_world_safe(PDO $pdo): array
{
    try {
        ensure_world_dungeon_row($pdo);
        $max = world_dungeon_max_floor($pdo);
        $bosses = [];
        for ($m = 10; $m <= $max; $m += 10) {
            $b = get_or_create_world_boss_row($pdo, $m);
            if ($b === null) {
                break;
            }
            $bosses[] = [
                'milestone' => (int) $b['milestone'],
                'hp' => (int) $b['hp_current'],
                'max_hp' => (int) $b['hp_max'],
                'ac' => (int) $b['ac'],
                'to_hit' => (int) $b['to_hit'],
                'damage' => (string) $b['damage_dice'],
                'label' => (string) $b['label'],
                'monster_key' => (string) $b['monster_key'],
                'defeated' => (int) $b['defeated'],
                'first_killer_username' => isset($b['first_killer_username']) ? (string) $b['first_killer_username'] : '',
                'first_killed_at' => isset($b['first_killed_at']) ? (string) $b['first_killed_at'] : '',
            ];
        }

        return ['max_unlocked_floor' => $max, 'bosses' => $bosses];
    } catch (Throwable $e) {
        return ['max_unlocked_floor' => 100000, 'bosses' => []];
    }
}

/**
 * @return array{ok:bool,error?:string,hp?:int,max_hp?:int,defeated?:int,dungeon_world?:array}
 */
function boss_hit_apply(PDO $pdo, array $playerRow, array $body): array
{
    $pid = (int) $playerRow['id'];
    $milestone = (int) ($body['milestone'] ?? 0);
    if ($milestone < 10 || $milestone % 10 !== 0) {
        return ['ok' => false, 'error' => '首领关卡无效'];
    }
    $damage = (int) ($body['damage'] ?? 0);
    $chip = (int) ($body['chip'] ?? 0) === 1;
    if ($damage < 1) {
        return ['ok' => false, 'error' => '伤害无效'];
    }
    $inv = fetch_inventory($pdo, $pid);
    $snap = compute_combat_snapshot($pdo, $playerRow, $inv);
    $maxDmg = max(8, $snap['weapon_hit_dmg_max'] + $snap['str_mod'] + 10);
    if ($chip) {
        if ($damage !== 1) {
            return ['ok' => false, 'error' => '擦伤仅能为 1'];
        }
    } else {
        $damage = min($damage, $maxDmg);
    }
    $pre = get_or_create_world_boss_row($pdo, $milestone);
    if ($pre === null) {
        return ['ok' => false, 'error' => '世界首领表未就绪，请执行 sql/upgrade_world_boss.sql'];
    }
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT * FROM world_boss WHERE milestone = ? FOR UPDATE');
        $st->execute([$milestone]);
        $boss = $st->fetch(PDO::FETCH_ASSOC);
        if (!$boss) {
            $pdo->rollBack();

            return ['ok' => false, 'error' => '首领数据不存在'];
        }
        if ((int) $boss['defeated'] === 1) {
            $pdo->rollBack();

            return [
                'ok' => true,
                'hp' => 0,
                'max_hp' => (int) $boss['hp_max'],
                'defeated' => 1,
                'already_beaten' => 1,
                'dungeon_world' => fetch_dungeon_world_safe($pdo),
            ];
        }
        $hp = max(0, (int) $boss['hp_current'] - $damage);
        $defeated = 0;
        if ($hp <= 0) {
            $hp = 0;
            $defeated = 1;
            $pdo->prepare('UPDATE world_boss SET hp_current = 0, defeated = 1 WHERE milestone = ?')->execute([$milestone]);
            $newMax = min(100000, $milestone + 10);
            $pdo->prepare('UPDATE world_dungeon SET max_unlocked_floor = GREATEST(max_unlocked_floor, ?) WHERE id = 1')->execute([$newMax]);
            $fkUser = substr(trim((string) ($_SESSION['username'] ?? '')), 0, 32);
            if ($fkUser === '') {
                $fkUser = substr((string) ($playerRow['display_name'] ?? '冒险者'), 0, 32);
            }
            $pdo->prepare(
                'UPDATE world_boss SET first_killer_player_id = ?, first_killer_username = ?, first_killed_at = CURRENT_TIMESTAMP WHERE milestone = ? AND first_killer_player_id IS NULL'
            )->execute([$pid, $fkUser, $milestone]);
        } else {
            $pdo->prepare('UPDATE world_boss SET hp_current = ? WHERE milestone = ?')->execute([$hp, $milestone]);
        }
        $pdo->commit();
        $lg = $pdo->prepare('INSERT INTO event_log (player_id, kind, detail) VALUES (?,?,?)');
        $lg->execute([$pid, 'boss_hit', json_encode(['milestone' => $milestone, 'damage' => $damage, 'chip' => $chip ? 1 : 0, 'hp_after' => $hp], JSON_UNESCAPED_UNICODE)]);

        return [
            'ok' => true,
            'hp' => $hp,
            'max_hp' => (int) $boss['hp_max'],
            'defeated' => $defeated,
            'dungeon_world' => fetch_dungeon_world_safe($pdo),
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'error' => '首领同步失败'];
    }
}

function mint_rewards_for_floor(int $floor, string $type): array
{
    $f = max(1, min(100000, $floor));
    $baseXp = $type === 'chest' ? random_int(5, 22) : random_int(3, 16);
    $baseGold = $type === 'chest' ? random_int(2, 14) : random_int(1, 11);
    $xpGain = $baseXp + (int) floor(($f - 1) * ($type === 'chest' ? 3.2 : 2.6));
    $goldGain = $baseGold + (int) floor(($f - 1) * ($type === 'chest' ? 2.0 : 1.5));
    $sqrt = (int) floor(sqrt($f) * 3.5);
    $chance = $type === 'chest'
        ? min(88, 28 + min(58, $sqrt + (int) floor($f / 50)))
        : min(82, 12 + min(68, $sqrt + (int) floor($f / 45)));
    return [$xpGain, $goldGain, $chance];
}

/** 从 +n 强化到 +(n+1) 消耗金币：50,100,200… */
function enhance_gold_cost(int $currentPlus): int
{
    return 50 * (2 ** max(0, $currentPlus));
}

/** 成功需 random_int(1, denom)==1，denom = 目标等级 × 骰子难度 */
function enhance_success_denominator(int $targetPlus, string $damageDice): int
{
    return max(1, $targetPlus * damage_dice_difficulty_mult($damageDice));
}
