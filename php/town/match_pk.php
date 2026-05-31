<?php
declare(strict_types=1);

/** @return array{ok:bool,error?:string,opponent?:array}&array */
function match_pk_find_opponent(PDO $pdo, array $playerRow): array
{
    $pid = (int) ($playerRow['id'] ?? 0);
    $inv = fetch_inventory($pdo, $pid);
    $snap = compute_combat_snapshot($pdo, $playerRow, $inv);
    $myLevel = (int) ($snap['level'] ?? 1);
    $lo = max(1, $myLevel - 8);
    $hi = $myLevel + 8;

    try {
        $st = $pdo->prepare(
            'SELECT p.*, a.username
             FROM players p
             INNER JOIN accounts a ON a.id = p.user_id
             WHERE p.id <> ?
             ORDER BY ABS(COALESCE(p.level_cached, 1) - ?) ASC, RAND()
             LIMIT 1'
        );
        $st->execute([$pid, $myLevel]);
        $opp = $st->fetch(PDO::FETCH_ASSOC);
        if (!$opp) {
            $opp = $playerRow;
            $opp['username'] = '镜像_' . substr((string) ($playerRow['display_name'] ?? '对手'), 0, 12);
        }
        $oppInv = fetch_inventory($pdo, (int) $opp['id']);
        $oppSnap = compute_combat_snapshot($pdo, $opp, $oppInv);
        $avatar = player_scene_avatar_sheet_effective($pdo, $opp, $oppInv);
        $maskName = match_pk_mask_username((string) ($opp['username'] ?? ''));
        $display = trim((string) ($opp['display_name'] ?? ''));
        if ($display === '') {
            $display = $maskName;
        }

        return [
            'ok' => true,
            'my_level' => $myLevel,
            'opponent' => [
                'player_id' => (int) $opp['id'],
                'username' => $maskName,
                'display_name' => $display,
                'level' => (int) ($oppSnap['level'] ?? 1),
                'hp_max' => (int) ($oppSnap['hp_max'] ?? 20),
                'ac' => (int) ($oppSnap['ac'] ?? 10),
                'str_mod' => (int) ($oppSnap['str_mod'] ?? 0),
                'dex_mod' => (int) ($oppSnap['dex_mod'] ?? 0),
                'int_effective' => (int) ($oppSnap['int_effective'] ?? 10),
                'weapon_dice' => (string) ($oppSnap['weapon_dice'] ?? '1d6'),
                'weapon_damage_mult' => (float) ($oppSnap['weapon_damage_mult'] ?? 1.0),
                'weapon_hit_dmg_max' => (int) ($oppSnap['weapon_hit_dmg_max'] ?? 4),
                'avatar_sheet' => $avatar,
                'profession_label' => (string) ($oppSnap['profession_label'] ?? '无'),
            ],
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function match_pk_mask_username(string $username): string
{
    $u = trim($username);
    if ($u === '') {
        return '神秘对手';
    }
    if (strlen($u) <= 2) {
        return $u[0] . '*';
    }

    return substr($u, 0, 2) . str_repeat('*', min(4, max(1, strlen($u) - 2)));
}

/** @return array{ok:bool,error?:string,reward?:array}&array */
function match_pk_finish(PDO $pdo, array $playerRow, array $body): array
{
    $won = (int) ($body['won'] ?? 0) === 1;
    $pid = (int) ($playerRow['id'] ?? 0);
  $xp = $won ? random_int(40, 120) : random_int(5, 25);
    $gold = $won ? random_int(15, 80) : random_int(2, 20);
    if (function_exists('game_world_xp_gain_blocked') && game_world_xp_gain_blocked($pdo)) {
        $xp = 0;
    }
    if ($xp > 0) {
        player_xp_apply_gain($pdo, $pid, $xp);
    }
    $pdo->prepare('UPDATE players SET gold = gold + ? WHERE id = ?')->execute([$gold, $pid]);
    $pdo->prepare('INSERT INTO event_log (player_id, kind, detail) VALUES (?,?,?)')->execute([
        $pid,
        'match_pk',
        json_encode(['won' => $won ? 1 : 0, 'xp' => $xp, 'gold' => $gold], JSON_UNESCAPED_UNICODE),
    ]);
    $st = $pdo->prepare('SELECT * FROM players WHERE id = ?');
    $st->execute([$pid]);
    $fresh = $st->fetch(PDO::FETCH_ASSOC);
    $out = player_payload($pdo, $fresh ?: $playerRow);
    $out['reward'] = ['won' => $won, 'xp' => $xp, 'gold' => $gold];

    return $out;
}
