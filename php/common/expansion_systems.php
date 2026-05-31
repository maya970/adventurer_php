<?php
declare(strict_types=1);

/** 背包/仓库/拍卖默认上限（schema 种子与缺省回退共用） */
function game_limits_default_values(): array
{
    return ['bag_capacity' => 100, 'warehouse_capacity' => 100, 'auction_post_limit' => 10];
}

require_once __DIR__ . '/branch_encounters.php';

function expansion_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS professions_catalog (
            profession_key VARCHAR(32) PRIMARY KEY,
            label VARCHAR(64) NOT NULL,
            str_scale_per_level DECIMAL(8,4) NOT NULL DEFAULT 0,
            int_scale_per_level DECIMAL(8,4) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS player_profession (
            player_id INT UNSIGNED PRIMARY KEY,
            profession_key VARCHAR(32) NOT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS skills_catalog (
            skill_key VARCHAR(64) PRIMARY KEY,
            label VARCHAR(80) NOT NULL,
            desc_text VARCHAR(255) NOT NULL DEFAULT '',
            max_level INT NOT NULL DEFAULT 10,
            int_weight DECIMAL(8,4) NOT NULL DEFAULT 0.2,
            wis_weight DECIMAL(8,4) NOT NULL DEFAULT 0.1,
            book_item_key VARCHAR(64) NOT NULL DEFAULT '',
            mutex_keys_json TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS player_skills (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            player_id INT UNSIGNED NOT NULL,
            skill_key VARCHAR(64) NOT NULL,
            level INT NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_player_skill (player_id, skill_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS player_knapsack (
            player_id INT UNSIGNED NOT NULL,
            slot_index TINYINT UNSIGNED NOT NULL,
            item_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (player_id, slot_index),
            UNIQUE KEY uq_player_item (player_id, item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS player_auto_actions (
            player_id INT UNSIGNED PRIMARY KEY,
            action_keys_json TEXT NULL,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS surface_branch_runs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            player_id INT UNSIGNED NOT NULL,
            hex_q INT NOT NULL,
            hex_r INT NOT NULL,
            room_depth INT NOT NULL DEFAULT 0,
            room_seed INT NOT NULL DEFAULT 1,
            room_kind VARCHAR(24) NOT NULL DEFAULT 'combat',
            options_json TEXT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'active',
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_player_status (player_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        try {
            $pdo->exec("ALTER TABLE players ADD COLUMN active_skin_id VARCHAR(48) NOT NULL DEFAULT 'm1001'");
        } catch (Throwable $e) {
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS combat_action_catalog (
            action_key VARCHAR(64) PRIMARY KEY,
            label VARCHAR(80) NOT NULL,
            time_cost INT NOT NULL DEFAULT 4,
            base_power DECIMAL(10,4) NOT NULL DEFAULT 1,
            kind VARCHAR(24) NOT NULL DEFAULT 'attack'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS game_limits_config (
            id TINYINT UNSIGNED PRIMARY KEY,
            bag_capacity INT NOT NULL DEFAULT 100,
            warehouse_capacity INT NOT NULL DEFAULT 100,
            auction_post_limit INT NOT NULL DEFAULT 10
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS player_limits (
            player_id INT UNSIGNED PRIMARY KEY,
            bag_capacity INT NOT NULL DEFAULT 100,
            warehouse_capacity INT NOT NULL DEFAULT 100,
            auction_post_limit INT NOT NULL DEFAULT 10,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
    }
    try {
        $pdo->prepare("INSERT IGNORE INTO professions_catalog (profession_key,label,str_scale_per_level,int_scale_per_level) VALUES
            ('body','体修',0.01,-0.01),('spirit','灵修',-0.01,0.01)")->execute();
        $pdo->prepare("INSERT IGNORE INTO skills_catalog (skill_key,label,desc_text,max_level,int_weight,wis_weight,book_item_key,mutex_keys_json) VALUES
            ('fireball','火球术','造成火焰伤害，障碍判定有加成',10,0.40,0.10,'skill_book_fireball','[\"ice_cone\"]'),
            ('ice_cone','冰锥术','造成冰系伤害并有减速概率',10,0.35,0.15,'skill_book_ice_cone','[\"fireball\"]'),
            ('trap_disarm','解除陷阱','提高陷阱与障碍检定通过率',10,0.10,0.40,'skill_book_trap_disarm','[]'),
            ('dual_wield_mastery','双持武器精通','强化双持动作效率',10,0.15,0.10,'skill_book_dual_wield','[\"single_wield_mastery\"]'),
            ('single_wield_mastery','单持武器精通','强化单手高爆发动作',10,0.10,0.10,'skill_book_single_wield','[\"dual_wield_mastery\"]'),
            ('focus','凝神','提高后续回合命中效率',10,0.20,0.25,'skill_book_focus','[]'),
            ('mind_eye','心眼','提高后续回合暴击/穿透效率',10,0.22,0.22,'skill_book_mind_eye','[]'),
            ('arcane_bolt','奥术飞弹','智力驱动的魔法弹，伤害随智力提升',10,0.55,0.08,'skill_book_arcane_bolt','[]'),
            ('mana_shield','法力护盾','本回合减伤并略增 AC',10,0.45,0.12,'skill_book_mana_shield','[]'),
            ('intellect_surge','智识涌动','下几回合法术伤害提升',10,0.50,0.10,'skill_book_intellect_surge','[]'),
            ('mystic_lens','神秘透镜','提高智力检定与法术命中',10,0.38,0.20,'skill_book_mystic_lens','[]')
        ")->execute();
        $pdo->prepare("INSERT IGNORE INTO combat_action_catalog (action_key,label,time_cost,base_power,kind) VALUES
            ('main_attack','主手攻击',4,1.00,'attack'),
            ('off_attack','副手攻击',2,0.70,'attack'),
            ('skill_fireball','火球术',5,1.15,'skill'),
            ('skill_ice_cone','冰锥术',5,1.05,'skill'),
            ('skill_focus','凝神',2,0.00,'stance'),
            ('skill_mind_eye','心眼',2,0.00,'stance'),
            ('use_potion','使用药剂',3,0.00,'recover'),
            ('defend','防御',2,0.00,'defense'),
            ('skill_arcane_bolt','奥术飞弹',4,1.20,'skill'),
            ('skill_mana_shield','法力护盾',3,0.00,'stance'),
            ('skill_intellect_surge','智识涌动',4,0.00,'stance')
        ")->execute();
        $lim = game_limits_default_values();
        $pdo->prepare('INSERT IGNORE INTO game_limits_config (id, bag_capacity, warehouse_capacity, auction_post_limit) VALUES (1,?,?,?)')
            ->execute([$lim['bag_capacity'], $lim['warehouse_capacity'], $lim['auction_post_limit']]);
        try {
            $pdo->exec("INSERT IGNORE INTO game_settings (k, v_int) VALUES
                ('dungeon_lock_all', 0),
                ('xp_gain_disabled', 0),
                ('default_bag_capacity', 100),
                ('default_warehouse_capacity', 100),
                ('default_auction_post_limit', 10)");
        } catch (Throwable $e) {
        }
    } catch (Throwable $e) {
    }
}

/** 新角色/缺行时的默认上限（来自 game_settings，缺省见 game_limits_default_values）。 */
function game_limits_defaults_row(PDO $pdo): array
{
    $d = game_limits_default_values();
    if (!function_exists('game_setting_int')) {
        return $d;
    }
    try {
        expansion_ensure_schema($pdo);
        $st = $pdo->query('SELECT bag_capacity, warehouse_capacity, auction_post_limit FROM game_limits_config WHERE id=1 LIMIT 1');
        $legacy = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
        $bagD = $legacy ? max(1, (int) ($legacy['bag_capacity'] ?? $d['bag_capacity'])) : $d['bag_capacity'];
        $whD = $legacy ? max(1, (int) ($legacy['warehouse_capacity'] ?? $d['warehouse_capacity'])) : $d['warehouse_capacity'];
        $aucD = $legacy ? max(1, (int) ($legacy['auction_post_limit'] ?? $d['auction_post_limit'])) : $d['auction_post_limit'];

        return [
            'bag_capacity' => max(1, game_setting_int($pdo, 'default_bag_capacity', $bagD)),
            'warehouse_capacity' => max(1, game_setting_int($pdo, 'default_warehouse_capacity', $whD)),
            'auction_post_limit' => max(1, game_setting_int($pdo, 'default_auction_post_limit', $aucD)),
        ];
    } catch (Throwable $e) {
        return $d;
    }
}

function player_limits_ensure_row(PDO $pdo, int $playerId): void
{
    if ($playerId < 1) {
        return;
    }
    expansion_ensure_schema($pdo);
    $d = game_limits_defaults_row($pdo);
    try {
        $pdo->prepare(
            'INSERT IGNORE INTO player_limits (player_id, bag_capacity, warehouse_capacity, auction_post_limit) VALUES (?,?,?,?)'
        )->execute([$playerId, $d['bag_capacity'], $d['warehouse_capacity'], $d['auction_post_limit']]);
    } catch (Throwable $e) {
    }
}

/** 每名玩家独立背包/仓库格子上限与拍卖上架上限。 */
function game_limits_for_player(PDO $pdo, int $playerId): array
{
    expansion_ensure_schema($pdo);
    player_limits_ensure_row($pdo, $playerId);
    try {
        $st = $pdo->prepare('SELECT bag_capacity, warehouse_capacity, auction_post_limit FROM player_limits WHERE player_id = ? LIMIT 1');
        $st->execute([$playerId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return game_limits_defaults_row($pdo);
        }

        return [
            'bag_capacity' => max(1, (int) ($r['bag_capacity'] ?? 100)),
            'warehouse_capacity' => max(1, (int) ($r['warehouse_capacity'] ?? 100)),
            'auction_post_limit' => max(1, (int) ($r['auction_post_limit'] ?? 10)),
        ];
    } catch (Throwable $e) {
        return game_limits_defaults_row($pdo);
    }
}

function player_storage_counts(PDO $pdo, int $playerId): array
{
    $bag = 0;
    $wh = 0;
    try {
        $st = $pdo->prepare("SELECT COALESCE(in_warehouse,0) iw, COUNT(*) c FROM player_items WHERE player_id=? GROUP BY iw");
        $st->execute([$playerId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            if ((int)($r['iw'] ?? 0) === 1) $wh += (int)($r['c'] ?? 0);
            else $bag += (int)($r['c'] ?? 0);
        }
    } catch (Throwable $e) {
    }
    return ['bag' => $bag, 'warehouse' => $wh];
}

function can_player_receive_item(PDO $pdo, int $playerId, int $preferWarehouse = 0): array
{
    $cfg = game_limits_for_player($pdo, $playerId);
    $cnt = player_storage_counts($pdo, $playerId);
    if ($preferWarehouse === 1) {
        if ($cnt['warehouse'] < $cfg['warehouse_capacity']) return ['ok' => true, 'in_warehouse' => 1];
        if ($cnt['bag'] < $cfg['bag_capacity']) return ['ok' => true, 'in_warehouse' => 0];
    } else {
        if ($cnt['bag'] < $cfg['bag_capacity']) return ['ok' => true, 'in_warehouse' => 0];
        if ($cnt['warehouse'] < $cfg['warehouse_capacity']) return ['ok' => true, 'in_warehouse' => 1];
    }
    return ['ok' => false, 'error' => '背包与仓库均已满，请先清理空间'];
}

function combat_action_catalog_rows(PDO $pdo): array
{
    expansion_ensure_schema($pdo);
    try {
        $st = $pdo->query("SELECT action_key, label, time_cost, base_power, kind FROM combat_action_catalog ORDER BY action_key");
        return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        return [];
    }
}

function profession_snapshot_for_player(PDO $pdo, int $playerId, int $level): array
{
    expansion_ensure_schema($pdo);
    $default = ['profession_key' => '', 'label' => '无', 'str_mult' => 1.0, 'int_mult' => 1.0];
    try {
        $st = $pdo->prepare("SELECT pc.* FROM player_profession pp JOIN professions_catalog pc ON pc.profession_key=pp.profession_key WHERE pp.player_id=? LIMIT 1");
        $st->execute([$playerId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return $default;
        $lv = max(1, $level);
        $strM = 1 + (float)$r['str_scale_per_level'] * $lv;
        $intM = 1 + (float)$r['int_scale_per_level'] * $lv;
        return [
            'profession_key' => (string)$r['profession_key'],
            'label' => (string)$r['label'],
            'str_mult' => max(0.1, $strM),
            'int_mult' => max(0.1, $intM),
        ];
    } catch (Throwable $e) {
        return $default;
    }
}

function profession_apply_choice(PDO $pdo, int $playerId, string $professionKey): array
{
    expansion_ensure_schema($pdo);
    $k = strtolower(trim($professionKey));
    if (!in_array($k, ['body', 'spirit'], true)) {
        return ['ok' => false, 'error' => '职业无效'];
    }
    try {
        $st = $pdo->prepare("SELECT profession_key FROM player_profession WHERE player_id=? LIMIT 1");
        $st->execute([$playerId]);
        $cur = $st->fetchColumn();
        if ($cur !== false && trim((string)$cur) !== '') {
            if ((string)$cur === $k) {
                return ['ok' => true];
            }
            return ['ok' => false, 'error' => '职业已锁定，确认转职后无法再切换'];
        }
        $pdo->prepare("INSERT INTO player_profession (player_id, profession_key) VALUES (?,?)")
            ->execute([$playerId, $k]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function player_sunbeam_crystal_count(PDO $pdo, int $playerId): int
{
    try {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM player_items WHERE player_id=? AND item_key='sunbeam_crystal' AND equipped=0 AND COALESCE(in_warehouse,0)=0"
        );
        $st->execute([$playerId]);

        return max(0, (int) $st->fetchColumn());
    } catch (Throwable $e) {
        return 0;
    }
}

function consume_sunbeam_crystals(PDO $pdo, int $playerId, int $count): bool
{
    if ($count < 1) {
        return true;
    }
    $st = $pdo->prepare(
        "SELECT id FROM player_items WHERE player_id=? AND item_key='sunbeam_crystal' AND equipped=0 AND COALESCE(in_warehouse,0)=0 ORDER BY id ASC LIMIT " . (int) $count . ' FOR UPDATE'
    );
    $st->execute([$playerId]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    if (count($ids) < $count) {
        return false;
    }
    $del = $pdo->prepare('DELETE FROM player_items WHERE id=? AND player_id=?');
    foreach ($ids as $id) {
        $del->execute([(int) $id, $playerId]);
    }

    return true;
}

/** @return int 实际发放的经验（全服暂停时为 0） */
function branch_apply_gold_xp(PDO $pdo, int $playerId, int $gold, int $xp): int
{
    if (function_exists('game_world_xp_gain_blocked') && game_world_xp_gain_blocked($pdo)) {
        $xp = 0;
    }
    if ($gold > 0) {
        $pdo->prepare('UPDATE players SET gold=gold+? WHERE id=?')->execute([$gold, $playerId]);
    }
    if ($xp > 0) {
        player_xp_apply_gain($pdo, $playerId, $xp);
    }

    return $xp;
}

function forget_skill_crystal_cost(int $level): int
{
    return max(1, 1 + max(0, $level) * 2);
}

function forget_profession_crystal_cost(int $playerLevel): int
{
    return max(5, 5 + max(1, $playerLevel));
}

function forget_preview(PDO $pdo, int $playerId, string $mode, string $skillKey = ''): array
{
    expansion_ensure_schema($pdo);
    $mode = strtolower(trim($mode));
    if ($mode === 'skill') {
        $key = strtolower(trim($skillKey));
        if ($key === '') {
            return ['ok' => false, 'error' => '请选择技能'];
        }
        $st = $pdo->prepare('SELECT level FROM player_skills WHERE player_id=? AND skill_key=? LIMIT 1');
        $st->execute([$playerId, $key]);
        $lv = (int) ($st->fetchColumn() ?: 0);
        if ($lv < 1) {
            return ['ok' => false, 'error' => '未学会该技能'];
        }
        $label = $key;
        foreach (skills_catalog_rows($pdo) as $row) {
            if ((string) ($row['skill_key'] ?? '') === $key) {
                $label = (string) ($row['label'] ?? $key);
                break;
            }
        }
        $cost = forget_skill_crystal_cost($lv);
        $have = player_sunbeam_crystal_count($pdo, $playerId);

        return [
            'ok' => true,
            'mode' => 'skill',
            'skill_key' => $key,
            'label' => $label,
            'level' => $lv,
            'crystal_cost' => $cost,
            'crystal_have' => $have,
            'can_forget' => $have >= $cost,
        ];
    }
    if ($mode === 'profession') {
        $st = $pdo->prepare('SELECT profession_key FROM player_profession WHERE player_id=? LIMIT 1');
        $st->execute([$playerId]);
        $pk = trim((string) ($st->fetchColumn() ?: ''));
        if ($pk === '') {
            return ['ok' => false, 'error' => '当前无职业可遗忘'];
        }
        $pl = $pdo->prepare('SELECT level FROM players WHERE id=? LIMIT 1');
        $pl->execute([$playerId]);
        $playerLevel = max(1, (int) ($pl->fetchColumn() ?: 1));
        $prof = profession_snapshot_for_player($pdo, $playerId, $playerLevel);
        $cost = forget_profession_crystal_cost($playerLevel);
        $have = player_sunbeam_crystal_count($pdo, $playerId);

        return [
            'ok' => true,
            'mode' => 'profession',
            'profession_key' => $pk,
            'label' => (string) ($prof['label'] ?? $pk),
            'player_level' => $playerLevel,
            'crystal_cost' => $cost,
            'crystal_have' => $have,
            'can_forget' => $have >= $cost,
        ];
    }

    return ['ok' => false, 'error' => '无效模式'];
}

function forget_commit(PDO $pdo, int $playerId, string $mode, string $skillKey = ''): array
{
    expansion_ensure_schema($pdo);
    $prev = forget_preview($pdo, $playerId, $mode, $skillKey);
    if (!$prev['ok']) {
        return $prev;
    }
    if (empty($prev['can_forget'])) {
        return ['ok' => false, 'error' => '曦光晶屑不足（需要 ' . (int) ($prev['crystal_cost'] ?? 0) . '）'];
    }
    $cost = (int) ($prev['crystal_cost'] ?? 0);
    try {
        $pdo->beginTransaction();
        if (!$prev['can_forget'] || !consume_sunbeam_crystals($pdo, $playerId, $cost)) {
            throw new RuntimeException('曦光晶屑不足');
        }
        if (($prev['mode'] ?? '') === 'skill') {
            $key = (string) ($prev['skill_key'] ?? '');
            $pdo->prepare('DELETE FROM player_skills WHERE player_id=? AND skill_key=?')->execute([$playerId, $key]);
            $msg = '已遗忘技能「' . (string) ($prev['label'] ?? $key) . '」，消耗曦光晶屑×' . $cost;
        } else {
            $pdo->prepare('DELETE FROM player_profession WHERE player_id=?')->execute([$playerId]);
            $msg = '已遗忘职业「' . (string) ($prev['label'] ?? '') . '」，可前往转职页重新选择，消耗曦光晶屑×' . $cost;
        }
        $pdo->commit();

        return ['ok' => true, 'message' => $msg];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function player_skills_rows(PDO $pdo, int $playerId): array
{
    expansion_ensure_schema($pdo);
    try {
        $st = $pdo->prepare('SELECT skill_key, level FROM player_skills WHERE player_id = ? ORDER BY skill_key ASC');
        $st->execute([$playerId]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function skills_catalog_rows(PDO $pdo): array
{
    expansion_ensure_schema($pdo);
    try {
        $st = $pdo->query("SELECT * FROM skills_catalog ORDER BY skill_key");
        return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        return [];
    }
}

function skill_try_learn_from_book(PDO $pdo, int $playerId, int $itemId): array
{
    expansion_ensure_schema($pdo);
    try {
        $pdo->beginTransaction();
        $itSt = $pdo->prepare("SELECT * FROM player_items WHERE id=? AND player_id=? FOR UPDATE");
        $itSt->execute([$itemId, $playerId]);
        $it = $itSt->fetch(PDO::FETCH_ASSOC);
        if (!$it) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => '技能书不存在'];
        }
        if ((int)($it['equipped'] ?? 0) === 1 || (int)($it['in_warehouse'] ?? 0) === 1) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => '只能学习背包中的技能书'];
        }
        $book = (string)($it['item_key'] ?? '');
        $cat = $pdo->prepare("SELECT * FROM skills_catalog WHERE book_item_key=? LIMIT 1");
        $cat->execute([$book]);
        $sk = $cat->fetch(PDO::FETCH_ASSOC);
        if (!$sk) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => '该物品不是技能书'];
        }
        $skillKey = (string)$sk['skill_key'];
        $mutex = json_decode((string)($sk['mutex_keys_json'] ?? '[]'), true);
        if (!is_array($mutex)) $mutex = [];
        foreach ($mutex as $mk) {
            $ck = $pdo->prepare("SELECT 1 FROM player_skills WHERE player_id=? AND skill_key=? LIMIT 1");
            $ck->execute([$playerId, (string)$mk]);
            if ($ck->fetch()) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => '与已学习技能互斥：' . (string)$mk];
            }
        }
        $curSt = $pdo->prepare("SELECT * FROM player_skills WHERE player_id=? AND skill_key=? FOR UPDATE");
        $curSt->execute([$playerId, $skillKey]);
        $cur = $curSt->fetch(PDO::FETCH_ASSOC);
        $maxLv = max(1, (int)($sk['max_level'] ?? 10));
        if ($cur) {
            $lv = (int)$cur['level'];
            if ($lv >= $maxLv) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => '技能已满级'];
            }
            $pdo->prepare("UPDATE player_skills SET level=level+1 WHERE id=?")->execute([(int)$cur['id']]);
        } else {
            $pdo->prepare("INSERT INTO player_skills (player_id, skill_key, level) VALUES (?,?,1)")
                ->execute([$playerId, $skillKey]);
        }
        $pdo->prepare("DELETE FROM player_items WHERE id=? AND player_id=?")->execute([$itemId, $playerId]);
        $pdo->commit();
        return ['ok' => true, 'skill_key' => $skillKey];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (Throwable $e2) {}
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}


function player_knapsack_sync(PDO $pdo, int $playerId): array
{
    expansion_ensure_schema($pdo);
    try {
        $st = $pdo->prepare("SELECT pk.slot_index, pi.* FROM player_knapsack pk
            JOIN player_items pi ON pi.id = pk.item_id AND pi.player_id = pk.player_id
            WHERE pk.player_id=? ORDER BY pk.slot_index");
        $st->execute([$playerId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows) return $rows;
        $inv = fetch_inventory($pdo, $playerId);
        $cand = array_values(array_filter($inv, static function ($it) {
            return (int)($it['equipped'] ?? 0) !== 1 && (int)($it['in_warehouse'] ?? 0) !== 1;
        }));
        $cand = array_slice($cand, 0, 16);
        $ins = $pdo->prepare("INSERT IGNORE INTO player_knapsack (player_id, slot_index, item_id) VALUES (?,?,?)");
        foreach ($cand as $i => $it) {
            $ins->execute([$playerId, $i, (int)$it['id']]);
        }
        $st->execute([$playerId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function player_knapsack_set(PDO $pdo, int $playerId, array $itemIds): array
{
    expansion_ensure_schema($pdo);
    $ids = array_values(array_unique(array_map('intval', $itemIds)));
    $ids = array_values(array_filter($ids, static function ($n) {
        return $n > 0;
    }));
    $ids = array_slice($ids, 0, 16);
    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM player_knapsack WHERE player_id=?")->execute([$playerId]);
        $ins = $pdo->prepare("INSERT INTO player_knapsack (player_id, slot_index, item_id) VALUES (?,?,?)");
        foreach ($ids as $i => $iid) {
            $chk = $pdo->prepare("SELECT 1 FROM player_items WHERE id=? AND player_id=? AND equipped=0 AND COALESCE(in_warehouse,0)=0 LIMIT 1");
            $chk->execute([$iid, $playerId]);
            if (!$chk->fetch()) continue;
            $ins->execute([$playerId, $i, $iid]);
        }
        $pdo->commit();
        return ['ok' => true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (Throwable $e2) {}
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function player_auto_actions_get(PDO $pdo, int $playerId): array
{
    expansion_ensure_schema($pdo);
    try {
        $st = $pdo->prepare("SELECT action_keys_json FROM player_auto_actions WHERE player_id=? LIMIT 1");
        $st->execute([$playerId]);
        $j = $st->fetchColumn();
        $a = json_decode((string)$j, true);
        if (!is_array($a) || !$a) return ['main_attack'];
        return array_slice(array_values(array_map('strval', $a)), 0, 5);
    } catch (Throwable $e) {
        return ['main_attack'];
    }
}

function player_auto_actions_set(PDO $pdo, int $playerId, array $actions): array
{
    expansion_ensure_schema($pdo);
    $allow = ['main_attack', 'off_attack', 'skill_fireball', 'skill_ice_cone', 'skill_focus', 'skill_mind_eye', 'use_potion'];
    $a = [];
    foreach ($actions as $x) {
        $k = strtolower(trim((string)$x));
        if (in_array($k, $allow, true)) $a[] = $k;
    }
    if (!$a) $a = ['main_attack'];
    $a = array_slice($a, 0, 5);
    try {
        $pdo->prepare("INSERT INTO player_auto_actions (player_id, action_keys_json) VALUES (?,?) ON DUPLICATE KEY UPDATE action_keys_json=VALUES(action_keys_json)")
            ->execute([$playerId, json_encode($a, JSON_UNESCAPED_UNICODE)]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/** @return list<string> */
function branch_dungeon_shop_item_keys(): array
{
    return ['wood_axe', 'iron_axe', 'healing_potion', 'greater_healing_potion', 'master_trap_key'];
}

/** @return list<string> */
function branch_dungeon_carry_item_keys(): array
{
    return array_merge(['axe', 'wood_axe', 'iron_axe', 'master_trap_key'], branch_dungeon_shop_item_keys());
}

/** @param list<array<string,mixed>> $inv @return list<array<string,mixed>> */
function branch_dungeon_loadout_candidates(array $inv): array
{
    $allowed = array_flip(branch_dungeon_carry_item_keys());
    $out = [];
    foreach ($inv as $it) {
        if ((int) ($it['equipped'] ?? 0) !== 0 || (int) ($it['in_warehouse'] ?? 0) !== 0) {
            continue;
        }
        $key = (string) ($it['item_key'] ?? '');
        $slot = (string) ($it['slot'] ?? '');
        if (!isset($allowed[$key]) && $slot !== 'consumable') {
            continue;
        }
        $out[] = [
            'id' => (int) ($it['id'] ?? 0),
            'item_key' => $key,
            'label' => (string) ($it['label'] ?? $key),
            'slot' => $slot,
            'rarity' => (string) ($it['rarity'] ?? 'common'),
        ];
    }

    return $out;
}

/** @param array<string,mixed> $row */
function surface_branch_item_snapshot(array $row): array
{
    return [
        'item_key' => (string) ($row['item_key'] ?? ''),
        'image_num' => (int) ($row['image_num'] ?? 0),
        'label' => (string) ($row['label'] ?? ''),
        'slot' => (string) ($row['slot'] ?? 'consumable'),
        'rarity' => (string) ($row['rarity'] ?? 'common'),
        'bonus_str' => (int) ($row['bonus_str'] ?? 0),
        'bonus_dex' => (int) ($row['bonus_dex'] ?? 0),
        'bonus_con' => (int) ($row['bonus_con'] ?? 0),
        'bonus_ac' => (int) ($row['bonus_ac'] ?? 0),
        'bonus_trap' => (int) ($row['bonus_trap'] ?? 0),
        'damage_dice' => (string) ($row['damage_dice'] ?? '1d4'),
        'item_desc' => (string) ($row['item_desc'] ?? ''),
        'weapon_allow' => (string) ($row['weapon_allow'] ?? 'both'),
        'plus_level' => (int) ($row['plus_level'] ?? 0),
        'rank_checksum' => (string) ($row['rank_checksum'] ?? ''),
    ];
}

/** @param array<string,mixed> $snap */
function surface_branch_restore_item_snapshot(PDO $pdo, int $playerId, array $snap): int
{
    if (((string) ($snap['slot'] ?? '')) !== 'weapon') {
        $snap['weapon_allow'] = 'both';
    }
    auction_restore_player_item_from_snapshot($pdo, $playerId, $snap);

    return (int) $pdo->lastInsertId();
}

/** @return list<array{item_key:string,label:string,rarity:string,available:int}> */
function branch_dungeon_loadout_grouped(PDO $pdo, int $playerId): array
{
    $inv = fetch_inventory($pdo, $playerId);
    $candidates = branch_dungeon_loadout_candidates($inv);
    $grouped = [];
    foreach ($candidates as $it) {
        $key = (string) ($it['item_key'] ?? '');
        if ($key === '') {
            continue;
        }
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'item_key' => $key,
                'label' => (string) ($it['label'] ?? $key),
                'rarity' => (string) ($it['rarity'] ?? 'common'),
                'available' => 0,
            ];
        }
        $grouped[$key]['available']++;
    }

    return array_values($grouped);
}

/** @param array<string,int> $countsByKey @return list<array<string,mixed>> */
function surface_branch_move_items_to_bag_by_counts(PDO $pdo, int $playerId, array $countsByKey): array
{
    $allowed = array_flip(branch_dungeon_carry_item_keys());
    $grouped = [];
    foreach ($countsByKey as $rawKey => $needRaw) {
        $key = preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string) $rawKey)));
        $need = max(0, (int) $needRaw);
        if ($key === '' || $need < 1) {
            continue;
        }
        if (!isset($allowed[$key])) {
            $stProbe = $pdo->prepare('SELECT slot FROM player_items WHERE player_id=? AND item_key=? LIMIT 1');
            $stProbe->execute([$playerId, $key]);
            $slot = (string) ($stProbe->fetchColumn() ?: '');
            if ($slot !== 'consumable') {
                throw new RuntimeException(surface_branch_item_label($key) . ' 不能带入分支地牢');
            }
        }
        $st = $pdo->prepare(
            'SELECT * FROM player_items WHERE player_id=? AND item_key=? AND equipped=0 AND COALESCE(in_warehouse,0)=0 ORDER BY id ASC'
        );
        $st->execute([$playerId, $key]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($rows) < $need) {
            throw new RuntimeException(
                surface_branch_item_label($key) . ' 背包仅有 ' . count($rows) . ' 个，无法带入 ' . $need . ' 个'
            );
        }
        for ($i = 0; $i < $need; $i++) {
            $row = $rows[$i];
            $pdo->prepare('DELETE FROM player_items WHERE id=? AND player_id=?')->execute([(int) $row['id'], $playerId]);
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'item_key' => $key,
                    'label' => (string) ($row['label'] ?? $key),
                    'stacks' => [],
                ];
            }
            $grouped[$key]['stacks'][] = surface_branch_item_snapshot($row);
        }
    }

    return array_values($grouped);
}

/** @param list<int> $itemIds @return list<array<string,mixed>> */
function surface_branch_move_items_to_bag(PDO $pdo, int $playerId, array $itemIds): array
{
    $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn($x) => $x > 0)));
    if ($itemIds === []) {
        return [];
    }
    $allowed = array_flip(branch_dungeon_carry_item_keys());
    $grouped = [];
    sort($itemIds);
    foreach ($itemIds as $iid) {
        $st = $pdo->prepare('SELECT * FROM player_items WHERE id=? AND player_id=? AND equipped=0 AND COALESCE(in_warehouse,0)=0 LIMIT 1 FOR UPDATE');
        $st->execute([$iid, $playerId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('物品 #' . $iid . ' 不可放入行囊（不存在、已装备或在仓库）');
        }
        $key = (string) ($row['item_key'] ?? '');
        $slot = (string) ($row['slot'] ?? '');
        if (!isset($allowed[$key]) && $slot !== 'consumable') {
            throw new RuntimeException((string) ($row['label'] ?? $key) . ' 不能带入分支地牢');
        }
        $pdo->prepare('DELETE FROM player_items WHERE id=? AND player_id=?')->execute([$iid, $playerId]);
        if (!isset($grouped[$key])) {
            $grouped[$key] = ['item_key' => $key, 'label' => (string) ($row['label'] ?? $key), 'stacks' => []];
        }
        $grouped[$key]['stacks'][] = surface_branch_item_snapshot($row);
    }

    return array_values($grouped);
}

/** @param list<array<string,mixed>> $bag @return list<string> */
function surface_branch_restore_bag_to_inventory(PDO $pdo, int $playerId, array $bag): array
{
    $labels = [];
    foreach ($bag as $row) {
        foreach (is_array($row['stacks'] ?? null) ? $row['stacks'] : [] as $stack) {
            if (!is_array($stack)) {
                continue;
            }
            if (!empty($stack['from_template'])) {
                $tplId = (string) $stack['from_template'];
                foreach (load_item_templates() as $tpl) {
                    if ((string) ($tpl['id'] ?? '') === $tplId) {
                        insert_generated_item($pdo, $playerId, $tpl);
                        $labels[] = (string) ($tpl['label'] ?? $tplId);
                        break;
                    }
                }
                continue;
            }
            surface_branch_restore_item_snapshot($pdo, $playerId, $stack);
            $labels[] = (string) ($stack['label'] ?? $stack['item_key'] ?? '物品');
        }
    }

    return $labels;
}

function surface_branch_bag_row_count(array $row): int
{
    if (isset($row['stacks']) && is_array($row['stacks'])) {
        return count($row['stacks']);
    }

    return max(0, (int) ($row['count'] ?? 0));
}

/** @param array<string,mixed> $runState */
function surface_branch_bag_wipe(array &$runState): void
{
    $runState['bag'] = [];
}

function surface_branch_stat_label(string $stat): string
{
    $map = ['str' => '力量', 'dex' => '敏捷', 'con' => '体质', 'int' => '智力', 'wis' => '感知'];

    return $map[strtolower($stat)] ?? $stat;
}

/** @return array{stat:string,dc:int,label:string} */
function surface_branch_build_stat_check(string $stat, int $depth, int $seed): array
{
    $stat = strtolower(trim($stat));
    if (!in_array($stat, ['str', 'dex', 'con', 'int', 'wis'], true)) {
        $stat = 'con';
    }
    $dc = 10 + (int) floor($depth / 2) + (abs(crc32('dc|' . $seed . '|' . $stat)) % 4);

    return ['stat' => $stat, 'dc' => $dc, 'label' => surface_branch_stat_label($stat)];
}

/** @return array{stat:string,dc:int,label:string} */
function surface_branch_roll_weighted_stat_check(int $seed, int $depth): array
{
    $pool = ['str', 'dex', 'con', 'con', 'con', 'int', 'int', 'int', 'wis', 'wis', 'wis'];
    $stat = $pool[abs(crc32('wstat|' . $seed . '|' . $depth)) % count($pool)];

    return surface_branch_build_stat_check($stat, $depth, $seed);
}

/** @param array<string,mixed> $meta */
function surface_branch_assign_room_check(array &$meta, string $kind, int $seed, int $depth): void
{
    if ($kind === 'obstacle') {
        $meta['stat_check'] = surface_branch_build_stat_check('str', $depth, $seed);
    } elseif ($kind === 'trap') {
        $pick = (abs(crc32($seed . '|trap')) % 2) === 0 ? 'dex' : 'wis';
        $meta['stat_check'] = surface_branch_build_stat_check($pick, $depth, $seed);
    } elseif ($kind === 'event' || $kind === 'trial') {
        $meta['stat_check'] = surface_branch_roll_weighted_stat_check($seed, $depth);
    } elseif ($kind === 'mixed' && (abs(crc32($seed . '|mix')) % 100) < 55) {
        $meta['stat_check'] = surface_branch_roll_weighted_stat_check($seed, $depth);
    } else {
        unset($meta['stat_check']);
    }
}

/** @param array<string,mixed> $runState @return array{roll:int,mod:int,total:int,success:bool,crit:bool} */
function surface_branch_d20_check(array $runState, string $stat, int $dc): array
{
    $stats = is_array($runState['stats'] ?? null) ? $runState['stats'] : [];
    $val = (int) ($stats[strtolower($stat)] ?? 10);
    $mod = (int) floor(($val - 10) / 2);
    $roll = random_int(1, 20);
    $total = $roll + $mod;
    $success = $total >= $dc;

    return [
        'roll' => $roll,
        'mod' => $mod,
        'total' => $total,
        'success' => $success,
        'crit' => $roll === 20 || ($success && $total >= $dc + 5),
    ];
}

function surface_branch_unpack_options(?array $opts): array
{
    if (!is_array($opts)) {
        return [[], []];
    }
    $meta = is_array($opts['_meta'] ?? null) ? $opts['_meta'] : [];
    $doors = [];
    if (isset($opts['doors']) && is_array($opts['doors'])) {
        foreach ($opts['doors'] as $d) {
            if (is_array($d) && isset($d['option_index'])) {
                $doors[] = $d;
            }
        }
    } else {
        foreach ($opts as $k => $v) {
            if ($k === '_meta' || $k === 'doors') {
                continue;
            }
            if (is_array($v) && isset($v['option_index'])) {
                $doors[] = $v;
            }
        }
        usort($doors, static fn($a, $b) => ((int) ($a['option_index'] ?? 0)) <=> ((int) ($b['option_index'] ?? 0)));
    }
    usort($doors, static fn($a, $b) => ((int) ($a['option_index'] ?? 0)) <=> ((int) ($b['option_index'] ?? 0)));

    return [$meta, $doors];
}

/** @return list<array{option_index:int,label:string,floor_jump:int,door_type:string}> */
function surface_branch_seeded_range(int $seed, string $salt, int $min, int $max): int
{
    if ($max <= $min) {
        return $min;
    }
    $span = $max - $min + 1;

    return $min + (abs(crc32($seed . '|' . $salt)) % $span);
}

function surface_branch_generate_doors(int $optsN, int $depth, int $seed): array
{
    $optsN = max(1, min(3, $optsN));
    $luckyIdx = abs(crc32($seed . '|lucky|' . $depth)) % $optsN;
    $doors = [];
    for ($i = 0; $i < $optsN; $i++) {
        if ($i === $luckyIdx) {
            $jump = surface_branch_seeded_range($seed, 'surge|' . $depth . '|' . $i, 3, 20);
            $label = '捷径';
            $type = 'surge';
        } else {
            $forward = (abs(crc32($seed . '|dir|' . $depth . '|' . $i)) % 100) < 62;
            $jumpMag = (abs(crc32($seed . '|step|' . $depth . '|' . $i)) % 3) + 1;
            $jump = $forward ? $jumpMag : -$jumpMag;
            $label = '普通门';
            $type = 'normal';
        }
        $doors[] = [
            'option_index' => $i,
            'label' => $label,
            'floor_jump' => $jump,
            'door_type' => $type,
        ];
    }

    return $doors;
}

function surface_branch_pack_options(array $meta, int $optsN, int $depth, int $seed): array
{
    return ['_meta' => $meta, 'doors' => surface_branch_generate_doors($optsN, $depth, $seed)];
}

function surface_branch_random_door_count(): int
{
    return 3;
}

function surface_branch_can_finish_with_loot(int $depth, array $meta): bool
{
    if ($depth >= 50 && $depth % 50 === 0) {
        return true;
    }
    $extras = is_array($meta['safe_layers'] ?? null) ? $meta['safe_layers'] : [];

    return in_array($depth, $extras, true);
}

function surface_branch_maybe_mark_safe_layer(array &$meta, int $depth): void
{
    if ($depth < 8) {
        return;
    }
    if (!is_array($meta['safe_layers'] ?? null)) {
        $meta['safe_layers'] = [];
    }
    if (in_array($depth, $meta['safe_layers'], true)) {
        return;
    }
    if (surface_branch_can_finish_with_loot($depth, $meta)) {
        return;
    }
    if (random_int(1, 100) <= 5) {
        $meta['safe_layers'][] = $depth;
    }
}

function surface_branch_safe_exit_hint(int $depth, array $meta): string
{
    if (surface_branch_can_finish_with_loot($depth, $meta)) {
        return '当前为安全撤离点：可「结束冒险」将行囊物品归还背包。';
    }
    $nextFixed = max(50, ((int) floor($depth / 50) + 1) * 50);
    $extras = is_array($meta['safe_layers'] ?? null) ? $meta['safe_layers'] : [];
    $nearRand = null;
    foreach ($extras as $d) {
        $d = (int) $d;
        if ($d > $depth && ($nearRand === null || $d < $nearRand)) {
            $nearRand = $d;
        }
    }
    $hint = '安全撤离点：固定每 50 层（下一固定点约第 ' . $nextFixed . ' 层）';
    if ($nearRand !== null) {
        $hint .= '，或随机安全层（最近约第 ' . $nearRand . ' 层）';
    } else {
        $hint .= '，途中也可能出现随机安全层';
    }
    $hint .= '。当前仅能「无功而返」（行囊战利品不带回）。';

    return $hint;
}

/** @param list<array<string,mixed>> $bag */
function surface_branch_create_run_state(PDO $pdo, array $player, array $bag): array
{
    $inv = fetch_inventory($pdo, (int) ($player['id'] ?? 0));
    $snap = compute_combat_snapshot($pdo, $player, $inv);

    return [
        'hp' => (int) ($snap['hp_max'] ?? 100),
        'hp_max' => (int) ($snap['hp_max'] ?? 100),
        'stats' => [
            'level' => (int) ($snap['level'] ?? 1),
            'str' => (int) ($snap['str_effective'] ?? 10),
            'dex' => (int) ($snap['dex_effective'] ?? 10),
            'con' => (int) ($snap['con_effective'] ?? 10),
            'int' => (int) ($snap['int_effective'] ?? 10),
            'wis' => (int) ($player['wis'] ?? 10),
            'ac' => (int) ($snap['ac'] ?? 10),
        ],
        'bag' => $bag,
        'resolved' => false,
    ];
}

/** @param array<string,mixed> $runState @param list<array<string,mixed>> $bag */
function surface_branch_bag_consume(array &$runState, string $itemKey): bool
{
    $bag = is_array($runState['bag'] ?? null) ? $runState['bag'] : [];
    foreach ($bag as $i => $row) {
        if ((string) ($row['item_key'] ?? '') !== $itemKey) {
            continue;
        }
        $stacks = is_array($row['stacks'] ?? null) ? $row['stacks'] : [];
        if ($stacks === [] && (int) ($row['count'] ?? 0) > 0) {
            $row['count'] = (int) $row['count'] - 1;
            if ($row['count'] < 1) {
                array_splice($bag, $i, 1);
            } else {
                $bag[$i] = $row;
            }
            $runState['bag'] = array_values($bag);

            return true;
        }
        if ($stacks === []) {
            return false;
        }
        array_pop($stacks);
        if ($stacks === []) {
            array_splice($bag, $i, 1);
        } else {
            $bag[$i]['stacks'] = array_values($stacks);
        }
        $runState['bag'] = array_values($bag);

        return true;
    }

    return false;
}

function surface_branch_bag_add(array &$runState, string $itemKey, string $label, int $count = 1): void
{
    if ($count < 1) {
        return;
    }
    $bag = is_array($runState['bag'] ?? null) ? $runState['bag'] : [];
    foreach ($bag as $i => $row) {
        if ((string) ($row['item_key'] ?? '') === $itemKey) {
            $stacks = is_array($row['stacks'] ?? null) ? $row['stacks'] : [];
            for ($n = 0; $n < $count; $n++) {
                $stacks[] = ['from_template' => $itemKey];
            }
            $bag[$i]['stacks'] = $stacks;
            $bag[$i]['label'] = $label;
            $runState['bag'] = array_values($bag);

            return;
        }
    }
    $stacks = [];
    for ($n = 0; $n < $count; $n++) {
        $stacks[] = ['from_template' => $itemKey];
    }
    $bag[] = ['item_key' => $itemKey, 'label' => $label, 'stacks' => $stacks];
    $runState['bag'] = array_values($bag);
}

/** @param array<string,mixed> $meta */
function surface_branch_run_state(array $meta): array
{
    return is_array($meta['run_state'] ?? null) ? $meta['run_state'] : [];
}

/** @return array{danger_bonus:int,reward_mult:float,damage_mult:float,trap_death_chance:int} */
function surface_branch_depth_scaling(int $depth): array
{
    $d = max(0, $depth);

    return [
        'danger_bonus' => (int) floor($d * 2),
        'reward_mult' => 1.0 + ($d * 0.14),
        'damage_mult' => 1.0 + ($d * 0.10),
        'trap_death_chance' => min(28, 4 + (int) floor($d / 2)),
    ];
}

function surface_branch_effective_danger(array $meta, int $depth): int
{
    $base = max(1, (int) ($meta['danger'] ?? 10));
    $scale = surface_branch_depth_scaling($depth);

    return $base + $scale['danger_bonus'];
}

function surface_branch_item_label(string $itemKey): string
{
    foreach (load_item_templates() as $t) {
        if ((string) ($t['id'] ?? '') === $itemKey) {
            return (string) ($t['label'] ?? $itemKey);
        }
    }

    return $itemKey;
}

function surface_branch_persist_run_state(PDO $pdo, int $runId, int $playerId, array $meta, int $doorCount, int $depth, int $seed): void
{
    $st = $pdo->prepare('SELECT options_json FROM surface_branch_runs WHERE id=? AND player_id=? LIMIT 1');
    $st->execute([$runId, $playerId]);
    $prevRaw = $st->fetchColumn();
    $prev = is_string($prevRaw) ? json_decode($prevRaw, true) : [];
    if (!is_array($prev)) {
        $prev = [];
    }
    [, $prevDoors] = surface_branch_unpack_options($prev);
    $runState = surface_branch_run_state($meta);
    if (!empty($runState['resolved']) && $prevDoors !== []) {
        $opts = ['_meta' => $meta, 'doors' => $prevDoors];
    } else {
        $opts = surface_branch_pack_options($meta, $doorCount, $depth, $seed);
    }
    $pdo->prepare('UPDATE surface_branch_runs SET options_json=? WHERE id=? AND player_id=?')
        ->execute([json_encode($opts, JSON_UNESCAPED_UNICODE), $runId, $playerId]);
}

function surface_branch_run_payload(array $row, array $optsPacked, ?PDO $pdo = null, ?int $playerId = null): array
{
    [$meta, $doors] = surface_branch_unpack_options($optsPacked);
    $runState = surface_branch_run_state($meta);
    $depth = (int) ($row['room_depth'] ?? 0);
    $seed = (int) ($row['room_seed'] ?? 0);
    $kind = (string) ($row['room_kind'] ?? 'event');
    $scale = surface_branch_depth_scaling($depth);
    $effDanger = surface_branch_effective_danger($meta, $depth);
    $canLoot = surface_branch_can_finish_with_loot($depth, $meta);
    $encounter = is_array($meta['encounter'] ?? null)
        ? $meta['encounter']
        : branch_encounter_roll($kind, $seed, $depth);
    $roomCheck = is_array($meta['stat_check'] ?? null) ? $meta['stat_check'] : null;
    $skillRows = ($pdo && $playerId) ? player_skills_rows($pdo, $playerId) : [];
    $resolveOptions = branch_encounter_build_options($kind, $encounter, $roomCheck, $runState, $skillRows, $depth, $effDanger);
    $skillsLearned = [];
    $skillCat = branch_skill_catalog_map();
    foreach ($skillRows as $sk) {
        $k = (string) ($sk['skill_key'] ?? '');
        if ($k === '') {
            continue;
        }
        $def = is_array($skillCat[$k] ?? null) ? $skillCat[$k] : null;
        $skillsLearned[] = [
            'skill_key' => $k,
            'level' => max(1, (int) ($sk['level'] ?? 1)),
            'label' => (string) ($def['label'] ?? $k),
        ];
    }

    return [
        'id' => (int) ($row['id'] ?? 0),
        'hex_q' => (int) ($row['hex_q'] ?? 0),
        'hex_r' => (int) ($row['hex_r'] ?? 0),
        'depth' => $depth,
        'room_kind' => $kind,
        'danger' => (int) ($meta['danger'] ?? 10),
        'effective_danger' => $effDanger,
        'max_depth' => null,
        'infinite' => true,
        'reward_mult' => round($scale['reward_mult'], 2),
        'status' => (string) ($row['status'] ?? 'active'),
        'doors' => $doors,
        'options' => $doors,
        'run_state' => $runState,
        'resolved' => !empty($runState['resolved']),
        'finished' => false,
        'can_save' => ((int) ($runState['hp'] ?? 0)) > 0,
        'can_finish_with_loot' => $canLoot,
        'can_retreat_empty' => ((int) ($runState['hp'] ?? 0)) > 0,
        'safe_exit_hint' => surface_branch_safe_exit_hint($depth, $meta),
        'stat_check' => $roomCheck,
        'encounter' => $encounter,
        'resolve_options' => $resolveOptions,
        'skills_learned' => $skillsLearned,
        'recent_log' => is_array($meta['recent_log'] ?? null) ? $meta['recent_log'] : [],
    ];
}

function surface_branch_room_roll_kind(int $seed, int $depth): string
{
    $v = abs(crc32($seed . '|' . $depth)) % 100;
    if ($v < 35) return 'combat';
    if ($v < 50) return 'treasure';
    if ($v < 62) return 'trap';
    if ($v < 72) return 'obstacle';
    if ($v < 82) return 'trial';
    if ($v < 94) return 'event';
    return 'mixed';
}

function branch_dungeon_loadout(PDO $pdo, int $playerId): array
{
    expansion_ensure_schema($pdo);

    return ['ok' => true, 'items' => branch_dungeon_loadout_grouped($pdo, $playerId)];
}

function branch_dungeon_start(PDO $pdo, int $playerId, array $itemIds, array $carryCounts = []): array
{
    expansion_ensure_schema($pdo);
    try {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE surface_branch_runs SET status='aborted' WHERE player_id=? AND status IN ('active','saved')")
            ->execute([$playerId]);
        $stPl = $pdo->prepare('SELECT * FROM players WHERE id = ? LIMIT 1');
        $stPl->execute([$playerId]);
        $pl = $stPl->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($pl === []) {
            throw new RuntimeException('角色不存在');
        }
        if ($carryCounts !== []) {
            $bag = surface_branch_move_items_to_bag_by_counts($pdo, $playerId, $carryCounts);
        } else {
            $bag = surface_branch_move_items_to_bag($pdo, $playerId, $itemIds);
        }
        $snap = compute_combat_snapshot($pdo, $pl, fetch_inventory($pdo, $playerId));
        $danger = max(10, (int) ($snap['level'] ?? 1));
        $seed = abs(crc32($playerId . ':branch:' . time())) % 2147483647;
        $kind = surface_branch_room_roll_kind($seed, 0);
        $optsN = surface_branch_random_door_count();
        $runState = surface_branch_create_run_state($pdo, $pl, $bag);
        $meta = ['danger' => $danger, 'run_state' => $runState, 'started_at' => time(), 'safe_layers' => []];
        surface_branch_assign_room_check($meta, $kind, $seed, 0);
        surface_branch_assign_encounter($meta, $kind, $seed, 0);
        surface_branch_maybe_mark_safe_layer($meta, 0);
        $opts = surface_branch_pack_options($meta, $optsN, 0, $seed);
        $ins = $pdo->prepare("INSERT INTO surface_branch_runs (player_id,hex_q,hex_r,room_depth,room_seed,room_kind,options_json,status) VALUES (?,?,?,?,?,?,?,'active')");
        $ins->execute([$playerId, 0, 0, 0, $seed, $kind, json_encode($opts, JSON_UNESCAPED_UNICODE)]);
        $id = (int) $pdo->lastInsertId();
        $st2 = $pdo->prepare('SELECT * FROM surface_branch_runs WHERE id=? LIMIT 1');
        $st2->execute([$id]);
        $r = $st2->fetch(PDO::FETCH_ASSOC);
        $pdo->commit();
        $payload = surface_branch_run_payload($r ?: [], $opts, $pdo, $playerId);
        $payload['resumed'] = false;

        return ['ok' => true, 'run' => $payload];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function branch_dungeon_enter(PDO $pdo, int $playerId, bool $freshStart = false): array
{
    if ($freshStart) {
        try {
            $pdo->prepare("UPDATE surface_branch_runs SET status='aborted' WHERE player_id=? AND status IN ('active','saved')")
                ->execute([$playerId]);
        } catch (Throwable $e) {
        }

        return ['ok' => true, 'need_loadout' => true];
    }

    return surface_branch_enter($pdo, $playerId, 0, 0);
}

function surface_branch_enter(PDO $pdo, int $playerId, int $hexQ, int $hexR): array
{
    expansion_ensure_schema($pdo);
    try {
        $st = $pdo->prepare(
            "SELECT * FROM surface_branch_runs WHERE player_id=? AND hex_q=? AND hex_r=? AND status IN ('active','saved') ORDER BY FIELD(status,'saved'), id DESC LIMIT 1"
        );
        $st->execute([$playerId, $hexQ, $hexR]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return ['ok' => true, 'need_loadout' => true];
        }
        if ((string) ($r['status'] ?? '') === 'saved') {
            $pdo->prepare("UPDATE surface_branch_runs SET status='active' WHERE id=? AND player_id=?")
                ->execute([(int) $r['id'], $playerId]);
            $r['status'] = 'active';
        }
        $opts = json_decode((string)($r['options_json'] ?? '[]'), true);
        if (!is_array($opts)) {
            $opts = [];
        }
        [$meta] = surface_branch_unpack_options($opts);
        if (!is_array($meta['run_state'] ?? null)) {
            try {
                $pdo->prepare("UPDATE surface_branch_runs SET status='aborted' WHERE id=? AND player_id=?")
                    ->execute([(int) ($r['id'] ?? 0), $playerId]);
            } catch (Throwable $e) {
            }

            return ['ok' => true, 'need_loadout' => true];
        }
        $payload = surface_branch_run_payload($r, $opts, $pdo, $playerId);
        $payload['resumed'] = ((int) ($r['room_depth'] ?? 0)) > 0 || !empty($meta['run_state']['resolved']);

        return ['ok' => true, 'run' => $payload];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function surface_branch_choose_next(PDO $pdo, int $playerId, int $runId, int $optionIdx): array
{
    expansion_ensure_schema($pdo);
    try {
        $st = $pdo->prepare("SELECT * FROM surface_branch_runs WHERE id=? AND player_id=? AND status='active' LIMIT 1");
        $st->execute([$runId, $playerId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return ['ok' => false, 'error' => '分支地牢记录不存在'];
        $seed = (int)$r['room_seed'];
        $prevOpts = json_decode((string)($r['options_json'] ?? '[]'), true);
        if (!is_array($prevOpts)) {
            $prevOpts = [];
        }
        [$meta, $prevDoors] = surface_branch_unpack_options($prevOpts);
        if (empty(surface_branch_run_state($meta)['resolved'])) {
            return ['ok' => false, 'error' => '请先处理当前房间事件'];
        }
        $jump = 1;
        foreach ($prevDoors as $door) {
            if ((int) ($door['option_index'] ?? -1) === $optionIdx) {
                $jump = (int) ($door['floor_jump'] ?? 1);
                break;
            }
        }
        $depth = max(0, (int) $r['room_depth'] + $jump);
        $meta['peak_depth'] = max($depth, (int)($meta['peak_depth'] ?? 0));
        $nextSeed = abs(crc32($seed . ':next:' . $optionIdx . ':' . $depth)) % 2147483647;
        $kind = surface_branch_room_roll_kind($nextSeed, $depth);
        $optsN = surface_branch_random_door_count();
        $runState = surface_branch_run_state($meta);
        $runState['resolved'] = false;
        $meta['run_state'] = $runState;
        surface_branch_maybe_mark_safe_layer($meta, $depth);
        surface_branch_assign_room_check($meta, $kind, $nextSeed, $depth);
        surface_branch_assign_encounter($meta, $kind, $nextSeed, $depth);
        $opts = surface_branch_pack_options($meta, $optsN, $depth, $nextSeed);
        $pdo->prepare("UPDATE surface_branch_runs SET room_depth=?, room_seed=?, room_kind=?, options_json=?, status='active' WHERE id=?")
            ->execute([$depth, $nextSeed, $kind, json_encode($opts, JSON_UNESCAPED_UNICODE), $runId]);
        $room = surface_branch_run_payload(array_merge($r, [
            'room_depth' => $depth,
            'room_seed' => $nextSeed,
            'room_kind' => $kind,
            'status' => 'active',
        ]), $opts, $pdo, $playerId);
        $room['id'] = $runId;

        return ['ok' => true, 'room' => $room];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function surface_branch_resolve_room(PDO $pdo, int $playerId, int $runId, string $strategy): array
{
    expansion_ensure_schema($pdo);
    try {
        $st = $pdo->prepare("SELECT * FROM surface_branch_runs WHERE id=? AND player_id=? AND status='active' LIMIT 1");
        $st->execute([$runId, $playerId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return ['ok' => false, 'error' => '分支地牢记录不存在'];
        $kind = (string)($r['room_kind'] ?? 'combat');
        $depth = (int)($r['room_depth'] ?? 0);
        $seed = (int)($r['room_seed'] ?? 1);
        $effects = [];
        $loot = [];
        $optsMeta = json_decode((string)($r['options_json'] ?? '[]'), true);
        if (!is_array($optsMeta)) {
            $optsMeta = [];
        }
        [$meta] = surface_branch_unpack_options($optsMeta);
        $scale = surface_branch_depth_scaling($depth);
        $effDanger = surface_branch_effective_danger($meta, $depth);
        $runState = surface_branch_run_state($meta);
        if (!is_array($runState) || $runState === []) {
            return ['ok' => false, 'error' => '冒险状态异常'];
        }
        if (!empty($runState['resolved'])) {
            return ['ok' => false, 'error' => '当前房间已结算'];
        }
        $hp = (int)($runState['hp'] ?? 1);
        $hpMax = max(1, (int)($runState['hp_max'] ?? $hp));
        $strategy = strtolower(trim($strategy));
        if ($strategy === '') {
            $strategy = 'force';
        }
        $roomCheck = is_array($meta['stat_check'] ?? null) ? $meta['stat_check'] : null;
        $encounter = is_array($meta['encounter'] ?? null)
            ? $meta['encounter']
            : branch_encounter_roll($kind, $seed, $depth);
        $meta['encounter'] = $encounter;
        $itemSkillClear = false;
        $skillRows = player_skills_rows($pdo, $playerId);

        if (preg_match('/^item:([a-z0-9_]+)$/', $strategy, $mItem)) {
            $itemKey = (string) $mItem[1];
            $bagKeys = branch_encounter_bag_item_keys($runState);
            if (!branch_encounter_item_can_solve($encounter, $itemKey, $bagKeys)) {
                return ['ok' => false, 'error' => '该物品无法应对此遭遇'];
            }
            if (!surface_branch_bag_consume($runState, $itemKey)) {
                return ['ok' => false, 'error' => '消耗物品失败'];
            }
            $effects[] = '使用「' . surface_branch_item_label($itemKey) . '」——' . (string) ($encounter['title'] ?? '遭遇') . '迎刃而解';
            $itemSkillClear = true;
        } elseif (preg_match('/^skill:([a-z0-9_]+)$/', $strategy, $mSk)) {
            $skKey = (string) $mSk[1];
            $skLv = 0;
            foreach ($skillRows as $sk) {
                if ((string) ($sk['skill_key'] ?? '') === $skKey) {
                    $skLv = (int) ($sk['level'] ?? 0);
                    break;
                }
            }
            $cat = branch_skill_catalog_entry($skKey);
            if (!$cat || $skLv < 1) {
                return ['ok' => false, 'error' => '未学会该技能'];
            }
            $tags = is_array($encounter['tags'] ?? null) ? $encounter['tags'] : [];
            $need = branch_encounter_need_value($roomCheck, $encounter, $depth, $effDanger);
            $power = branch_skill_effective_power($skKey, $skLv, (string) ($cat['affinity'] ?? 'utility'), $runState);
            $ov = branch_skill_overpower_eval((string) ($cat['affinity'] ?? 'utility'), $tags, $power, $need);
            if (!$ov['allowed']) {
                return ['ok' => false, 'error' => (string) ($ov['reason'] ?? '当前技能强度不足以应对此遭遇')];
            }
            $effects[] = (string) ($cat['label'] ?? $skKey) . ' Lv' . $skLv . '：' . (string) ($ov['message'] ?? '以压倒性优势破局');
            $itemSkillClear = true;
        }

        $maybeDead = false;
        if (!$itemSkillClear && ($kind === 'trial' || ($kind === 'event' && $strategy === 'check'))) {
            $check = $roomCheck ?: surface_branch_roll_weighted_stat_check($seed, $depth);
            $chk = surface_branch_d20_check($runState, (string) $check['stat'], (int) $check['dc']);
            $effects[] = surface_branch_stat_label((string) $check['stat']) . '检定 d20=' . $chk['roll'] . '+' . $chk['mod'] . '=' . $chk['total'] . '（DC' . (int) $check['dc'] . '）';
            if ($chk['success']) {
                if ($chk['crit']) {
                    $effects[] = '检定大成功';
                } else {
                    $effects[] = '检定成功';
                }
                $gold = (int) ceil((12 + $depth * 4) * $scale['reward_mult']);
                $xp = branch_apply_gold_xp($pdo, $playerId, $gold, (int) ceil((18 + $depth * 6) * $scale['reward_mult']));
                $effects[] = $xp > 0 ? ('获得金币 +' . $gold . '，经验 +' . $xp) : ('获得金币 +' . $gold);
            } else {
                $effects[] = '检定失败';
                $dmg = (int) ceil((3 + $depth * 2 + (int) floor($effDanger / 30)) * $scale['damage_mult']);
                $hp = max(0, $hp - $dmg);
                $effects[] = '受到 ' . $dmg . ' 点伤害';
            }
        } elseif ($kind === 'obstacle' && !$itemSkillClear) {
            $baseDmg = (int) ceil((4 + $depth * 2 + (int) floor($effDanger / 25)) * $scale['damage_mult']);
            if ($strategy === 'str' || $strategy === 'check') {
                $check = $roomCheck ?: surface_branch_build_stat_check('str', $depth, $seed);
                $chk = surface_branch_d20_check($runState, 'str', (int) $check['dc']);
                $effects[] = '力量检定 d20=' . $chk['roll'] . '+' . $chk['mod'] . '=' . $chk['total'] . '（DC' . (int) $check['dc'] . '）';
                if ($chk['success']) {
                    $effects[] = $chk['crit'] ? '检定大成功，障碍迎刃而解' : '力量检定成功，顺利通过障碍';
                } else {
                    $effects[] = '力量检定失败';
                    $dmg = (int) ceil($baseDmg * 1.5);
                    $hp = max(0, $hp - $dmg);
                    $effects[] = '受到 ' . $dmg . ' 点伤害（1.5 倍承伤）';
                }
            } elseif ($strategy === 'axe') {
                $used = false;
                foreach (['iron_axe', 'wood_axe', 'axe'] as $ak) {
                    if (surface_branch_bag_consume($runState, $ak)) {
                        $effects[] = '行囊消耗：' . surface_branch_item_label($ak);
                        $used = true;
                        $effects[] = '斧刃开路，顺利通过';
                        break;
                    }
                }
                if (!$used) {
                    $effects[] = '行囊中没有斧子';
                    $hp = max(0, $hp - $baseDmg);
                    $effects[] = '标准承伤 -' . $baseDmg . ' HP';
                }
            } elseif ($strategy === 'fireball') {
                $fireLv = 0;
                foreach ($skillRows as $sk) {
                    if ((string) ($sk['skill_key'] ?? '') === 'fireball') {
                        $fireLv = (int) ($sk['level'] ?? 0);
                    }
                }
                $base = 0.60 + min(0.12, $depth * 0.004) + $fireLv * 0.03;
                $pass = (mt_rand(1, 1000) / 1000) <= min(0.95, max(0.05, $base));
                $effects[] = $pass ? '火球轰开障碍' : '火球未能完全清障';
                if (!$pass) {
                    $hp = max(0, $hp - $baseDmg);
                    $effects[] = '标准承伤 -' . $baseDmg . ' HP';
                }
            } else {
                $pInt = $pdo->prepare('SELECT int_stat, wis FROM players WHERE id=? LIMIT 1');
                $pInt->execute([$playerId]);
                $pr = $pInt->fetch(PDO::FETCH_ASSOC) ?: [];
                $intS = (int) ($pr['int_stat'] ?? 10);
                $wis = (int) ($pr['wis'] ?? 10);
                $base = 0.32 + min(0.18, $effDanger * 0.002) - min(0.08, $depth * 0.003);
                $base += max(0, $intS - 10) * 0.005 + max(0, $wis - 10) * 0.004;
                $pass = (mt_rand(1, 1000) / 1000) <= min(0.95, max(0.05, $base));
                if ($pass) {
                    $effects[] = '徒手硬闯成功';
                } else {
                    $effects[] = '徒手硬闯受阻';
                    $hp = max(0, $hp - $baseDmg);
                    $effects[] = '标准承伤 -' . $baseDmg . ' HP';
                }
            }
        } elseif ($kind === 'treasure' || $kind === 'mixed' || $kind === 'combat') {
            if (!$itemSkillClear && $kind === 'mixed' && $roomCheck && in_array($strategy, ['check', 'stat'], true)) {
                $chk = surface_branch_d20_check($runState, (string) $roomCheck['stat'], (int) $roomCheck['dc']);
                $effects[] = surface_branch_stat_label((string) $roomCheck['stat']) . '检定 d20=' . $chk['roll'] . '+' . $chk['mod'] . '=' . $chk['total'] . '（DC' . (int) $roomCheck['dc'] . '）';
                if (!$chk['success']) {
                    $dmg = (int) ceil((5 + $depth * 2) * $scale['damage_mult']);
                    $hp = max(0, $hp - $dmg);
                    $effects[] = '检定失败，额外受伤 -' . $dmg;
                } else {
                    $effects[] = '检定成功，战斗更有利';
                }
            }
            if (!$itemSkillClear && ($kind === 'combat' || $kind === 'mixed')) {
                $dmg = (int) ceil((6 + $depth * 2 + (int)floor($effDanger / 20)) * $scale['damage_mult']);
                $hurtChance = min(55, 28 + (int)floor($depth / 2));
                if (mt_rand(1, 100) <= $hurtChance) {
                    $hp = max(0, $hp - $dmg);
                    $effects[] = '战斗受伤 -' . $dmg . ' HP';
                } else {
                    $effects[] = '战斗顺利，未受伤';
                }
            }
            $bookChance = branch_skill_book_drop_per_mille($depth);
            if (mt_rand(1, 1000) <= $bookChance) {
                $tpl = skill_pick_book_template_for_drop($pdo, max(1, $depth + 1));
                if ($tpl) {
                    $iid = insert_generated_item($pdo, $playerId, $tpl);
                    $loot[] = ['id' => $iid, 'label' => (string)($tpl['label'] ?? '技能书')];
                }
            }
            $gold = (int) ceil((20 + ($depth * 5) + (int)floor($effDanger * 1.2)) * $scale['reward_mult']);
            $xp = branch_apply_gold_xp($pdo, $playerId, $gold, (int) ceil((30 + ($depth * 8) + (int)floor($effDanger * 1.5)) * $scale['reward_mult']));
            $effects[] = $xp > 0
                ? ('结算获得金币 +' . $gold . '，经验 +' . $xp . '（层数加成 ×' . round($scale['reward_mult'], 1) . '）')
                : ('结算获得金币 +' . $gold . '（经验奖励已暂停）');
            if ($kind === 'treasure' && mt_rand(1, 100) <= min(70, 25 + (int)floor($depth / 2))) {
                if (mt_rand(1, 100) <= 40) {
                    surface_branch_bag_add($runState, 'healing_potion', surface_branch_item_label('healing_potion'), 1);
                    $effects[] = '行囊获得：治疗药水';
                } else {
                    surface_branch_bag_add($runState, 'greater_healing_potion', surface_branch_item_label('greater_healing_potion'), 1);
                    $effects[] = '行囊获得：强效治疗药水';
                }
            }
            if ($strategy === 'potion' || $strategy === 'heal') {
                foreach (['greater_healing_potion', 'healing_potion'] as $pk) {
                    if (surface_branch_bag_consume($runState, $pk)) {
                        $heal = $pk === 'greater_healing_potion' ? 40 : 20;
                        $hp = min($hpMax, $hp + $heal);
                        $effects[] = '使用' . surface_branch_item_label($pk) . '恢复 ' . $heal . ' HP';
                        break;
                    }
                }
            }
        } elseif ($kind === 'trap' && !$itemSkillClear) {
            $baseDmg = (int) ceil((5 + $depth * 2 + (int) floor($effDanger / 18)) * $scale['damage_mult']);
            if (in_array($strategy, ['check', 'dex', 'wis', 'stat'], true)) {
                $check = $roomCheck ?: surface_branch_build_stat_check((abs(crc32($seed . '|trap')) % 2) === 0 ? 'dex' : 'wis', $depth, $seed);
                $chk = surface_branch_d20_check($runState, (string) $check['stat'], (int) $check['dc']);
                $effects[] = surface_branch_stat_label((string) $check['stat']) . '检定 d20=' . $chk['roll'] . '+' . $chk['mod'] . '=' . $chk['total'] . '（DC' . (int) $check['dc'] . '）';
                if ($chk['success']) {
                    if ($chk['crit']) {
                        $effects[] = '检定大成功，陷阱完全失效';
                        $gold = (int) ceil((6 + $depth * 2) * $scale['reward_mult']);
                        branch_apply_gold_xp($pdo, $playerId, $gold, 0);
                        $effects[] = '额外获得金币 +' . $gold;
                    } else {
                        $effects[] = '成功避开陷阱';
                    }
                } else {
                    $effects[] = '未能避开陷阱';
                    $dmg = (int) ceil($baseDmg * 1.5);
                    $hp = max(0, $hp - $dmg);
                    $effects[] = '陷阱触发：-' . $dmg . ' HP（1.5 倍承伤）';
                    $goldLoss = min(80, 5 + (int) floor($depth * 1.5));
                    if ($goldLoss > 0) {
                        $pdo->prepare('UPDATE players SET gold = GREATEST(0, gold - ?) WHERE id = ?')->execute([$goldLoss, $playerId]);
                        $effects[] = '额外损失金币 ' . $goldLoss;
                    }
                }
            } else {
                $hp = max(0, $hp - $baseDmg);
                $effects[] = '硬抗通过，标准承伤 -' . $baseDmg . ' HP';
                $goldLoss = min(80, 5 + (int) floor($depth * 1.5));
                if ($goldLoss > 0 && mt_rand(1, 100) <= 40) {
                    $pdo->prepare('UPDATE players SET gold = GREATEST(0, gold - ?) WHERE id = ?')->execute([$goldLoss, $playerId]);
                    $effects[] = '额外损失金币 ' . $goldLoss;
                }
            }
            if ($hp <= 0 || mt_rand(1, 100) <= $scale['trap_death_chance']) {
                $maybeDead = true;
                $effects[] = '伤势过重，被迫结束本次冒险';
            }
        } elseif ($kind === 'event' && !$itemSkillClear) {
            $check = $roomCheck ?: surface_branch_roll_weighted_stat_check($seed, $depth);
            $chk = surface_branch_d20_check($runState, (string) $check['stat'], (int) $check['dc']);
            $effects[] = surface_branch_stat_label((string) $check['stat']) . '奇遇检定 d20=' . $chk['roll'] . '+' . $chk['mod'] . '=' . $chk['total'] . '（DC' . (int) $check['dc'] . '）';
            if ($chk['success']) {
                $effects[] = $chk['crit'] ? '奇遇大成功' : '奇遇顺利';
                $gold = (int) ceil((8 + $depth * 3) * $scale['reward_mult']);
                branch_apply_gold_xp($pdo, $playerId, $gold, 0);
                $effects[] = '获得金币 +' . $gold;
            } else {
                $effects[] = '奇遇受挫';
                $dmg = (int) ceil((2 + $depth) * $scale['damage_mult']);
                $hp = max(0, $hp - $dmg);
                $effects[] = '受到 ' . $dmg . ' 点伤害';
            }
        } elseif ($itemSkillClear && in_array($kind, ['trial', 'event'], true)) {
            $gold = (int) ceil((10 + $depth * 3) * $scale['reward_mult']);
            $xp = branch_apply_gold_xp($pdo, $playerId, $gold, (int) ceil((12 + $depth * 4) * $scale['reward_mult']));
            $effects[] = $xp > 0 ? ('额外获得金币 +' . $gold . '，经验 +' . $xp) : ('额外获得金币 +' . $gold);
        } else {
        }

        if ($hp <= 0) {
            $maybeDead = true;
            $effects[] = '生命归零';
        }
        $runState['hp'] = $hp;
        $runState['hp_max'] = $hpMax;
        $runState['resolved'] = true;
        if ($maybeDead) {
            surface_branch_bag_wipe($runState);
            $effects[] = '行囊物品已全部遗失';
        }
        $meta['run_state'] = $runState;
        $meta['peak_depth'] = max($depth, (int)($meta['peak_depth'] ?? 0));
        surface_branch_push_recent_log($meta, $depth, $encounter, $effects, $kind);
        [, $doors] = surface_branch_unpack_options($optsMeta);
        surface_branch_persist_run_state($pdo, $runId, $playerId, $meta, count($doors), $depth, $seed);
        $stOpts = $pdo->prepare('SELECT options_json FROM surface_branch_runs WHERE id=? AND player_id=? LIMIT 1');
        $stOpts->execute([$runId, $playerId]);
        $optsFresh = json_decode((string) ($stOpts->fetchColumn() ?: '[]'), true);
        if (!is_array($optsFresh)) {
            $optsFresh = [];
        }
        [, $doorsOut] = surface_branch_unpack_options($optsFresh);

        $roomSig = $runId . '|' . $depth . '|' . $seed . '|resolved';
        $pdo->prepare('INSERT INTO event_log (player_id,kind,detail) VALUES (?,?,?)')
            ->execute([$playerId, 'surface_branch_room', json_encode(['sig' => $roomSig, 'kind' => $kind, 'effects' => $effects], JSON_UNESCAPED_UNICODE)]);
        if ($maybeDead) {
            $pdo->prepare("UPDATE surface_branch_runs SET status='aborted' WHERE id=? AND player_id=?")->execute([$runId, $playerId]);
            $roomRefresh = surface_branch_run_payload($r, $optsFresh, $pdo, $playerId);

            return [
                'ok' => true,
                'dead' => true,
                'dead_returned_town' => true,
                'run_state' => $runState,
                'doors' => $doorsOut,
                'recent_log' => is_array($meta['recent_log'] ?? null) ? $meta['recent_log'] : [],
                'resolution' => [
                    'room_kind' => $kind,
                    'danger' => $effDanger,
                    'effective_danger' => $effDanger,
                    'reward_mult' => round($scale['reward_mult'], 2),
                    'monster_count' => 0,
                    'effects' => $effects,
                    'loot' => [],
                ],
            ];
        }
        $monsterCount = $kind === 'combat' || $kind === 'mixed' ? min(9, 4 + (int)floor($effDanger / 30) + ($depth % 2)) : 0;

        return [
            'ok' => true,
            'run_state' => $runState,
            'doors' => $doorsOut,
            'recent_log' => is_array($meta['recent_log'] ?? null) ? $meta['recent_log'] : [],
            'resolution' => [
                'room_kind' => $kind,
                'danger' => $effDanger,
                'effective_danger' => $effDanger,
                'reward_mult' => round($scale['reward_mult'], 2),
                'monster_count' => $monsterCount,
                'effects' => $effects,
                'loot' => $loot,
            ],
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function branch_dungeon_save_quit(PDO $pdo, int $playerId, int $runId): array
{
    expansion_ensure_schema($pdo);
    try {
        $st = $pdo->prepare("SELECT * FROM surface_branch_runs WHERE id=? AND player_id=? AND status='active' LIMIT 1");
        $st->execute([$runId, $playerId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return ['ok' => false, 'error' => '没有可存档的冒险'];
        }
        $opts = json_decode((string)($r['options_json'] ?? '[]'), true);
        [$meta] = surface_branch_unpack_options(is_array($opts) ? $opts : []);
        $rs = surface_branch_run_state($meta);
        if ((int)($rs['hp'] ?? 0) < 1) {
            return ['ok' => false, 'error' => '已阵亡，无法存档'];
        }
        $pdo->prepare("UPDATE surface_branch_runs SET status='saved' WHERE id=? AND player_id=?")->execute([$runId, $playerId]);
        $depth = (int)($r['room_depth'] ?? 0);

        return [
            'ok' => true,
            'message' => '已存档（第 ' . $depth . ' 层），行囊物品仍留在冒险中，下次可继续',
            'depth' => $depth,
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function branch_dungeon_finish_run(PDO $pdo, int $playerId, int $runId, string $mode = 'claim'): array
{
    expansion_ensure_schema($pdo);
    $mode = strtolower(trim($mode));
    if (!in_array($mode, ['claim', 'retreat', 'empty'], true)) {
        $mode = 'claim';
    }
    if ($mode === 'empty') {
        $mode = 'retreat';
    }
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT * FROM surface_branch_runs WHERE id=? AND player_id=? AND status IN ('active','saved') LIMIT 1");
        $st->execute([$runId, $playerId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            throw new RuntimeException('没有可结束的冒险');
        }
        $opts = json_decode((string)($r['options_json'] ?? '[]'), true);
        [$meta] = surface_branch_unpack_options(is_array($opts) ? $opts : []);
        $rs = surface_branch_run_state($meta);
        if ((int)($rs['hp'] ?? 0) < 1) {
            throw new RuntimeException('已阵亡，无法撤离');
        }
        $depth = (int)($r['room_depth'] ?? 0);
        $bag = is_array($rs['bag'] ?? null) ? $rs['bag'] : [];
        $restored = [];
        if ($mode === 'retreat') {
            $msg = '无功而返（第 ' . $depth . ' 层）：行囊战利品未带回主城，冒险进度已结束';
        } else {
            if (!surface_branch_can_finish_with_loot($depth, $meta)) {
                throw new RuntimeException(surface_branch_safe_exit_hint($depth, $meta));
            }
            $restored = surface_branch_restore_bag_to_inventory($pdo, $playerId, $bag);
            $msg = '已结束冒险（第 ' . $depth . ' 层）';
            if ($restored !== []) {
                $msg .= '，行囊物品已归还背包：' . implode('、', array_slice($restored, 0, 8));
                if (count($restored) > 8) {
                    $msg .= ' 等' . count($restored) . '件';
                }
            } else {
                $msg .= '，行囊为空';
            }
        }
        $pdo->prepare("UPDATE surface_branch_runs SET status='completed' WHERE id=? AND player_id=?")->execute([$runId, $playerId]);
        $pdo->commit();

        return [
            'ok' => true,
            'message' => $msg,
            'restored' => $restored,
            'depth' => $depth,
            'mode' => $mode,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function branch_dungeon_use_item(PDO $pdo, int $playerId, int $runId, string $itemKey): array
{
    expansion_ensure_schema($pdo);
    $itemKey = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($itemKey)));
    $usable = ['healing_potion' => 20, 'greater_healing_potion' => 40];
    if (!isset($usable[$itemKey])) {
        return ['ok' => false, 'error' => '该物品不能在行囊外直接使用（斧子请在障碍房事件中使用）'];
    }
    try {
        $st = $pdo->prepare("SELECT * FROM surface_branch_runs WHERE id=? AND player_id=? AND status='active' LIMIT 1");
        $st->execute([$runId, $playerId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return ['ok' => false, 'error' => '分支地牢记录不存在'];
        }
        $optsMeta = json_decode((string)($r['options_json'] ?? '[]'), true);
        if (!is_array($optsMeta)) {
            $optsMeta = [];
        }
        [$meta, $doors] = surface_branch_unpack_options($optsMeta);
        $runState = surface_branch_run_state($meta);
        if ($runState === []) {
            return ['ok' => false, 'error' => '冒险状态异常'];
        }
        if (!surface_branch_bag_consume($runState, $itemKey)) {
            return ['ok' => false, 'error' => '行囊中没有该物品'];
        }
        $hpMax = max(1, (int)($runState['hp_max'] ?? 100));
        $heal = $usable[$itemKey];
        $runState['hp'] = min($hpMax, (int)($runState['hp'] ?? 0) + $heal);
        $meta['run_state'] = $runState;
        $depth = (int)($r['room_depth'] ?? 0);
        $seed = (int)($r['room_seed'] ?? 1);
        surface_branch_persist_run_state($pdo, $runId, $playerId, $meta, count($doors), $depth, $seed);

        return [
            'ok' => true,
            'message' => '使用' . surface_branch_item_label($itemKey) . '，恢复 ' . $heal . ' HP',
            'run_state' => $runState,
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

