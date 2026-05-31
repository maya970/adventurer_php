<?php
declare(strict_types=1);

require_once __DIR__ . '/avatar_skins.php';

function gacha_sunbeam_prize_table_ensure(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS gacha_sunbeam_prizes (
            slot_index TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            label VARCHAR(80) NOT NULL,
            weight INT NOT NULL DEFAULT 1,
            grant_type ENUM("gold", "item", "skin") NOT NULL DEFAULT "gold",
            item_key VARCHAR(64) NULL,
            skin_id VARCHAR(48) NULL,
            gold_amount INT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    if ((int) ($pdo->query('SELECT COUNT(*) FROM gacha_sunbeam_prizes')->fetchColumn() ?: 0) >= 20) {
        return;
    }
    $ins = $pdo->prepare(
        'INSERT INTO gacha_sunbeam_prizes (slot_index, label, weight, grant_type, item_key, skin_id, gold_amount)
         VALUES (?,?,?,?,?,?,?)'
    );
    foreach (
        [
            [1, '50 金币', 18, 'gold', null, null, 50],
            [2, '25 金币', 10, 'gold', null, null, 25],
            [3, '100 金币', 14, 'gold', null, null, 100],
            [4, '200 金币', 10, 'gold', null, null, 200],
            [5, '30 金币', 20, 'gold', null, null, 30],
            [6, '500 金币', 4, 'gold', null, null, 500],
            [7, '80 金币', 12, 'gold', null, null, 80],
            [8, '皮肤·1002', 3, 'skin', null, 'm1002', null],
            [9, '150 金币', 8, 'gold', null, null, 150],
            [10, '曦光晶屑×1', 5, 'item', 'sunbeam_crystal', null, null],
            [11, '20 金币', 22, 'gold', null, null, 20],
            [12, '300 金币', 6, 'gold', null, null, 300],
            [13, '40 金币', 16, 'gold', null, null, 40],
            [14, '皮肤·1003', 2, 'skin', null, 'm1003', null],
            [15, '120 金币', 9, 'gold', null, null, 120],
            [16, '晶屑', 3, 'item', 'sunbeam_crystal', null, null],
            [17, '60 金币', 14, 'gold', null, null, 60],
            [18, '1000 金币', 1, 'gold', null, null, 1000],
            [19, '90 金币', 11, 'gold', null, null, 90],
            [20, '250 金币', 8, 'gold', null, null, 250],
        ] as $row
    ) {
        try {
            $ins->execute($row);
        } catch (Throwable $e) {
        }
    }
}

function gacha_sunbeam_config(PDO $pdo): array
{
    gacha_sunbeam_prize_table_ensure($pdo);
    $st = $pdo->query('SELECT slot_index, label FROM gacha_sunbeam_prizes ORDER BY slot_index ASC');
    $raw = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    $prizes = [];
    for ($i = 0; $i < 20; $i++) {
        $row = $raw[$i] ?? null;
        $prizes[] = ['index' => $i, 'label' => $row ? (string) $row['label'] : '—'];
    }

    return ['ok' => true, 'prizes' => $prizes, 'cost_item_key' => 'sunbeam_crystal', 'cost_count' => 1];
}

/** 发放一条奖励（转盘奖品行或棋盘格行），返回结果文案 */
function gacha_apply_reward(PDO $pdo, int $playerId, array $row, bool $isBoardCell = false): string
{
    $gtype = (string) ($row[$isBoardCell ? 'reward_type' : 'grant_type'] ?? 'gold');
    if ($gtype === 'gold') {
        $g = max(0, (int) ($row[$isBoardCell ? 'reward_gold' : 'gold_amount'] ?? 0));
        if ($g > 0) {
            $pdo->prepare('UPDATE players SET gold = gold + ? WHERE id = ?')->execute([$g, $playerId]);
        }

        return $g > 0 ? '获得金币 +' . $g : (string) ($row['label'] ?? '金币');
    }
    if ($gtype === 'item') {
        $ik = (string) ($row[$isBoardCell ? 'reward_item_key' : 'item_key'] ?? '');
        foreach (load_item_templates() as $t) {
            if ((string) ($t['id'] ?? '') === $ik) {
                insert_generated_item($pdo, $playerId, $t);

                return '获得物品：' . (string) ($t['label'] ?? $ik);
            }
        }
        throw new RuntimeException('物品模板缺失: ' . $ik);
    }
    if ($gtype === 'skin') {
        $sid = (string) ($row[$isBoardCell ? 'reward_skin_id' : 'skin_id'] ?? '');
        if (!avatar_skins_id_valid($sid)) {
            throw new RuntimeException('皮肤配置无效');
        }
        if (!player_has_unlocked_skin($pdo, $playerId, $sid)) {
            player_unlock_skin($pdo, $playerId, $sid);
            foreach (avatar_skins_catalog() as $sc) {
                if ($sc['id'] === $sid) {
                    return '解锁皮肤：' . $sc['label'];
                }
            }

            return '解锁皮肤：' . $sid;
        }
        $pdo->prepare('UPDATE players SET gold=gold+80 WHERE id=?')->execute([$playerId]);

        return '重复皮肤已转化为 80 金币';
    }

    return $isBoardCell ? '抵达空格' : (string) ($row['label'] ?? '—');
}

/** @param array<string,mixed> $extra */
function gacha_ok_player(PDO $pdo, array $playerRow, int $playerId, array $extra): array
{
    $st = $pdo->prepare('SELECT * FROM players WHERE id=? LIMIT 1');
    $st->execute([$playerId]);

    return array_merge(player_payload($pdo, $st->fetch(PDO::FETCH_ASSOC) ?: $playerRow), $extra);
}

function gacha_sunbeam_spin(PDO $pdo, array $playerRow): array
{
    gacha_sunbeam_prize_table_ensure($pdo);
    gacha_player_skins_ensure($pdo);
    $pid = (int) $playerRow['id'];
    $all = $pdo->query('SELECT * FROM gacha_sunbeam_prizes ORDER BY slot_index ASC')?->fetchAll(PDO::FETCH_ASSOC) ?? [];
    if ($all === []) {
        return ['ok' => false, 'error' => '奖池未配置'];
    }
    $pdo->beginTransaction();
    try {
        if (!consume_sunbeam_crystals($pdo, $pid, 1)) {
            throw new RuntimeException('需要背包中的曦光晶屑×1 作为抽一次的费用');
        }

        $totalW = max(1, array_sum(array_map(static fn ($r) => max(0, (int) ($r['weight'] ?? 0)), $all)));
        $roll = random_int(1, $totalW);
        $acc = 0;
        $pick = $all[0];
        foreach ($all as $r) {
            $acc += max(0, (int) ($r['weight'] ?? 0));
            if ($roll <= $acc) {
                $pick = $r;
                break;
            }
        }
        $si = max(1, min(20, (int) ($pick['slot_index'] ?? 1)));
        $summary = gacha_apply_reward($pdo, $pid, $pick, false);
        $pdo->commit();

        return gacha_ok_player($pdo, $playerRow, $pid, [
            'ok' => true,
            'slot_index' => $si - 1,
            'display_label' => (string) ($pick['label'] ?? '—'),
            'reward_type' => (string) ($pick['grant_type'] ?? 'gold'),
            'message' => $summary,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/* ---------- 皮肤大富翁棋盘 ---------- */

function gacha_skin_board_tables_ensure(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS gacha_skin_board_cells (
            cell_index TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            label VARCHAR(80) NOT NULL,
            reward_type ENUM("none","gold","item","skin") NOT NULL DEFAULT "none",
            reward_item_key VARCHAR(64) NULL,
            reward_skin_id VARCHAR(48) NULL,
            reward_gold INT NULL DEFAULT 0,
            reward_weight INT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS player_skin_board_state (
            player_id INT UNSIGNED NOT NULL PRIMARY KEY,
            pos_index TINYINT UNSIGNED NOT NULL DEFAULT 0,
            lap_count INT NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS skin_board_toll_stations (
            cell_index TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            owner_player_id INT UNSIGNED NOT NULL,
            toll_gold INT NOT NULL DEFAULT 25,
            build_cost INT NOT NULL DEFAULT 50,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_owner (owner_player_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    if ((int) ($pdo->query('SELECT COUNT(*) FROM gacha_skin_board_cells')->fetchColumn() ?: 0) >= 16) {
        return;
    }
    $ins = $pdo->prepare(
        'INSERT IGNORE INTO gacha_skin_board_cells (cell_index,label,reward_type,reward_item_key,reward_skin_id,reward_gold,reward_weight)
         VALUES (?,?,?,?,?,?,?)'
    );
    $seed = [
        [0, '起点', 'none', null, null, 0],
        [1, '碎金', 'gold', null, null, 30],
        [2, '空格', 'none', null, null, 0],
        [3, '皮肤·1002', 'skin', null, 'm1002', 0],
        [4, '小奖', 'gold', null, null, 50],
        [5, '空格', 'none', null, null, 0],
        [6, '曦光晶屑', 'item', 'sunbeam_crystal', null, 0],
        [7, '空格', 'none', null, null, 0],
        [8, '中奖', 'gold', null, null, 90],
        [9, '空格', 'none', null, null, 0],
        [10, '皮肤·1003', 'skin', null, 'm1003', 0],
        [11, '金币', 'gold', null, null, 120],
        [12, '空格', 'none', null, null, 0],
        [13, '随机杂物', 'item', 'sunbeam_crystal', null, 0],
        [14, '空格', 'none', null, null, 0],
        [15, '大奖', 'gold', null, null, 220],
    ];
    foreach ($seed as $r) {
        try {
            $ins->execute([$r[0], $r[1], $r[2], $r[3], $r[4], $r[5], 1]);
        } catch (Throwable $e) {
        }
    }
}

function skin_board_get_cell(PDO $pdo, int $cellIndex): ?array
{
    $st = $pdo->prepare('SELECT * FROM gacha_skin_board_cells WHERE cell_index=? LIMIT 1');
    $st->execute([$cellIndex]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function skin_board_player_position(PDO $pdo, int $playerId): int
{
    gacha_skin_board_tables_ensure($pdo);
    $st = $pdo->prepare('SELECT pos_index FROM player_skin_board_state WHERE player_id=? LIMIT 1');
    $st->execute([$playerId]);
    $v = $st->fetchColumn();

    return $v === false ? 0 : max(0, min(15, (int) $v));
}

function skin_board_require_at_position(PDO $pdo, int $playerId, int $cellIndex): ?array
{
    if ($cellIndex < 0 || $cellIndex > 15) {
        return ['ok' => false, 'error' => '格子无效'];
    }
    $pos = skin_board_player_position($pdo, $playerId);
    if ($pos !== $cellIndex) {
        return ['ok' => false, 'error' => '须先走到 #' . $cellIndex . '（当前在 #' . $pos . '）'];
    }

    return null;
}

function skin_board_prize_desc(array $row, array $unlocked): string
{
    $t = (string) ($row['reward_type'] ?? 'none');
    if ($t === 'none') {
        return '空格（可建收费站）';
    }
    if ($t === 'gold') {
        $g = (int) ($row['reward_gold'] ?? 0);

        return ($g > 0 ? $g . ' 金币' : '金币');
    }
    if ($t === 'skin') {
        $sid = (string) ($row['reward_skin_id'] ?? '');
        foreach (avatar_skins_catalog() as $sc) {
            if (($sc['id'] ?? '') === $sid) {
                return '皮肤：' . $sc['label'] . (isset($unlocked[$sid]) ? '（已拥有）' : '');
            }
        }

        return '皮肤：' . $sid;
    }
    if ($t === 'item') {
        $ik = (string) ($row['reward_item_key'] ?? '');
        foreach (load_item_templates() as $tpl) {
            if ((string) ($tpl['id'] ?? '') === $ik) {
                return '道具：' . (string) ($tpl['label'] ?? $ik);
            }
        }

        return '道具：' . $ik;
    }

    return '—';
}

function skin_board_pay_toll_if_needed(PDO $pdo, int $playerId, int $cellIndex): ?string
{
    $cell = skin_board_get_cell($pdo, $cellIndex);
    if (!$cell || (string) ($cell['reward_type'] ?? '') !== 'none') {
        return null;
    }
    $stT = $pdo->prepare(
        'SELECT t.owner_player_id, t.toll_gold, p.display_name FROM skin_board_toll_stations t
         LEFT JOIN players p ON p.id=t.owner_player_id WHERE t.cell_index=? LIMIT 1'
    );
    $stT->execute([$cellIndex]);
    $toll = $stT->fetch(PDO::FETCH_ASSOC);
    if (!$toll) {
        return null;
    }
    $owner = (int) ($toll['owner_player_id'] ?? 0);
    if ($owner < 1 || $owner === $playerId) {
        return null;
    }
    $fee = max(1, (int) ($toll['toll_gold'] ?? 25));
    $stGold = $pdo->prepare('SELECT gold FROM players WHERE id=? FOR UPDATE');
    $stGold->execute([$playerId]);
    if ((int) $stGold->fetchColumn() < $fee) {
        return '#' . $cellIndex . ' 收费站金币不足，需 ' . $fee . ' 金';
    }
    $pdo->prepare('UPDATE players SET gold=gold-? WHERE id=?')->execute([$fee, $playerId]);
    $pdo->prepare('UPDATE players SET gold=gold+? WHERE id=?')->execute([$fee, $owner]);
    $name = trim((string) ($toll['display_name'] ?? '')) ?: '玩家';

    return '#' . $cellIndex . ' 向「' . $name . '」缴费 ' . $fee . ' 金';
}

function gacha_skin_board_config(PDO $pdo, array $playerRow): array
{
    gacha_skin_board_tables_ensure($pdo);
    gacha_player_skins_ensure($pdo);
    $pid = (int) ($playerRow['id'] ?? 0);
    $unlocked = array_fill_keys(player_unlocked_skin_ids($pdo, $pid), true);
    $pst = $pdo->prepare('SELECT pos_index, lap_count FROM player_skin_board_state WHERE player_id=? LIMIT 1');
    $pst->execute([$pid]);
    $pr = $pst->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$pr) {
        $pdo->prepare('INSERT IGNORE INTO player_skin_board_state (player_id,pos_index,lap_count) VALUES (?,?,?)')->execute([$pid, 0, 0]);
        $pr = ['pos_index' => 0, 'lap_count' => 0];
    }
    $tolls = [];
    $stT = $pdo->query(
        'SELECT t.cell_index, t.owner_player_id, t.toll_gold, t.build_cost, p.display_name
         FROM skin_board_toll_stations t LEFT JOIN players p ON p.id=t.owner_player_id'
    );
    if ($stT) {
        while ($tr = $stT->fetch(PDO::FETCH_ASSOC)) {
            $ci = (int) $tr['cell_index'];
            $tolls[$ci] = [
                'owner_player_id' => (int) $tr['owner_player_id'],
                'owner_name' => (string) ($tr['display_name'] ?? '玩家'),
                'toll_gold' => (int) $tr['toll_gold'],
                'build_cost' => (int) $tr['build_cost'],
            ];
        }
    }
    $cells = [];
    $stC = $pdo->query('SELECT * FROM gacha_skin_board_cells ORDER BY cell_index ASC');
    foreach ($stC ? $stC->fetchAll(PDO::FETCH_ASSOC) : [] as $r) {
        $idx = (int) $r['cell_index'];
        $rtype = (string) ($r['reward_type'] ?? 'none');
        $sid = (string) ($r['reward_skin_id'] ?? '');
        $cells[] = [
            'index' => $idx,
            'prize_desc' => skin_board_prize_desc($r, $unlocked),
            'is_empty' => $rtype === 'none',
            'reward_type' => $rtype,
            'reward_skin_png' => ($rtype === 'skin' && $sid !== '') ? avatar_skins_man_png_by_id($sid) : null,
            'toll' => $tolls[$idx] ?? null,
        ];
    }

    return [
        'ok' => true,
        'cost_item_key' => 'sunbeam_crystal',
        'cost_count' => 1,
        'position' => (int) ($pr['pos_index'] ?? 0),
        'laps' => (int) ($pr['lap_count'] ?? 0),
        'cells' => $cells,
        'player_id' => $pid,
    ];
}

function gacha_skin_board_roll(PDO $pdo, array $playerRow): array
{
    gacha_skin_board_tables_ensure($pdo);
    gacha_player_skins_ensure($pdo);
    $pid = (int) ($playerRow['id'] ?? 0);
    $pdo->beginTransaction();
    try {
        if (!consume_sunbeam_crystals($pdo, $pid, 1)) {
            throw new RuntimeException('需要背包中的曦光晶屑×1');
        }
        $pst = $pdo->prepare('SELECT pos_index, lap_count FROM player_skin_board_state WHERE player_id=? FOR UPDATE');
        $pst->execute([$pid]);
        $ps = $pst->fetch(PDO::FETCH_ASSOC);
        if (!$ps) {
            $pdo->prepare('INSERT INTO player_skin_board_state (player_id,pos_index,lap_count) VALUES (?,?,?)')->execute([$pid, 0, 0]);
            $ps = ['pos_index' => 0, 'lap_count' => 0];
        }
        $roll = random_int(1, 6);
        $old = (int) ($ps['pos_index'] ?? 0);
        $new = ($old + $roll) % 16;
        $lap = (int) ($ps['lap_count'] ?? 0) + ($old + $roll >= 16 ? 1 : 0);
        $notes = [];
        for ($s = 1; $s <= $roll; $s++) {
            $m = skin_board_pay_toll_if_needed($pdo, $pid, ($old + $s) % 16);
            if ($m !== null) {
                $notes[] = $m;
            }
        }
        $pdo->prepare('UPDATE player_skin_board_state SET pos_index=?, lap_count=? WHERE player_id=?')->execute([$new, $lap, $pid]);
        $cell = skin_board_get_cell($pdo, $new);
        if ($cell && (string) ($cell['reward_type'] ?? '') !== 'none') {
            try {
                $notes[] = gacha_apply_reward($pdo, $pid, $cell, true);
            } catch (Throwable $e) {
                $notes[] = $e->getMessage();
            }
        } else {
            $notes[] = '抵达 #' . $new . ' 空格';
        }
        $pdo->commit();

        return gacha_ok_player($pdo, $playerRow, $pid, [
            'ok' => true,
            'roll' => $roll,
            'old_position' => $old,
            'new_position' => $new,
            'move_path' => array_map(static fn ($s) => ($old + $s) % 16, range(1, $roll)),
            'laps' => $lap,
            'message' => $notes !== [] ? implode('；', $notes) : '移动完成',
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function gacha_skin_board_build_toll(PDO $pdo, array $playerRow, int $cellIndex): array
{
    gacha_skin_board_tables_ensure($pdo);
    $pid = (int) ($playerRow['id'] ?? 0);
    $err = skin_board_require_at_position($pdo, $pid, $cellIndex);
    if ($err !== null) {
        return $err;
    }
    $cell = skin_board_get_cell($pdo, $cellIndex);
    if (!$cell || (string) ($cell['reward_type'] ?? '') !== 'none') {
        return ['ok' => false, 'error' => '仅可在空白格建造收费站'];
    }
    $buildCost = 50;
    $tollGold = 25;
    $pdo->beginTransaction();
    try {
        $stT = $pdo->prepare('SELECT 1 FROM skin_board_toll_stations WHERE cell_index=? FOR UPDATE');
        $stT->execute([$cellIndex]);
        if ($stT->fetchColumn()) {
            throw new RuntimeException('该格已有收费站');
        }
        $stP = $pdo->prepare('SELECT gold FROM players WHERE id=? FOR UPDATE');
        $stP->execute([$pid]);
        if ((int) $stP->fetchColumn() < $buildCost) {
            throw new RuntimeException('建造需要 ' . $buildCost . ' 金币');
        }
        $pdo->prepare('UPDATE players SET gold=gold-? WHERE id=?')->execute([$buildCost, $pid]);
        $pdo->prepare('INSERT INTO skin_board_toll_stations (cell_index,owner_player_id,toll_gold,build_cost) VALUES (?,?,?,?)')
            ->execute([$cellIndex, $pid, $tollGold, $buildCost]);
        $pdo->commit();

        return gacha_ok_player($pdo, $playerRow, $pid, [
            'ok' => true,
            'message' => '已在 #' . $cellIndex . ' 建造收费站（过路费 ' . $tollGold . ' 金币）',
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function gacha_skin_board_buyout_toll(PDO $pdo, array $playerRow, int $cellIndex): array
{
    gacha_skin_board_tables_ensure($pdo);
    $pid = (int) ($playerRow['id'] ?? 0);
    $err = skin_board_require_at_position($pdo, $pid, $cellIndex);
    if ($err !== null) {
        return $err;
    }
    $pdo->beginTransaction();
    try {
        $stT = $pdo->prepare('SELECT * FROM skin_board_toll_stations WHERE cell_index=? FOR UPDATE');
        $stT->execute([$cellIndex]);
        $toll = $stT->fetch(PDO::FETCH_ASSOC);
        if (!$toll) {
            throw new RuntimeException('该格尚无收费站');
        }
        $owner = (int) ($toll['owner_player_id'] ?? 0);
        if ($owner === $pid) {
            throw new RuntimeException('你已是该收费站主人');
        }
        $fee = max(1, (int) ($toll['toll_gold'] ?? 25)) * 2;
        $stP = $pdo->prepare('SELECT gold FROM players WHERE id=? FOR UPDATE');
        $stP->execute([$pid]);
        if ((int) $stP->fetchColumn() < $fee) {
            throw new RuntimeException('收购需要 ' . $fee . ' 金币');
        }
        $pdo->prepare('UPDATE players SET gold=gold-? WHERE id=?')->execute([$fee, $pid]);
        $pdo->prepare('UPDATE players SET gold=gold+? WHERE id=?')->execute([$fee, $owner]);
        $pdo->prepare('UPDATE skin_board_toll_stations SET owner_player_id=? WHERE cell_index=?')->execute([$pid, $cellIndex]);
        $pdo->commit();

        return gacha_ok_player($pdo, $playerRow, $pid, [
            'ok' => true,
            'message' => '已花费 ' . $fee . ' 金币收购 #' . $cellIndex . ' 收费站',
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
