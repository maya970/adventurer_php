<?php
declare(strict_types=1);

function skill_json_by_key(string $key): ?array
{
    foreach (load_skills_json()['skills'] as $row) {
        if ((string) ($row['skill_key'] ?? '') === $key) {
            return $row;
        }
    }

    return null;
}

function skill_enhance_config(): array
{
    $cfg = load_skills_json()['enhance'] ?? [];

    return [
        'base_gold' => max(1, (int) ($cfg['base_gold'] ?? 50)),
        'gold_scale' => max(1, (int) ($cfg['gold_scale'] ?? 2)),
        'physical_difficulty' => max(1, (int) ($cfg['physical_difficulty'] ?? 2)),
        'arcane_difficulty' => max(1, (int) ($cfg['arcane_difficulty'] ?? 3)),
        'utility_difficulty' => max(1, (int) ($cfg['utility_difficulty'] ?? 2)),
        'profession_bonus_denom' => max(0, (int) ($cfg['profession_bonus_denom'] ?? 3)),
    ];
}

function skill_affinity_for(string $skillKey, ?array $jsonRow = null): string
{
    $j = $jsonRow ?? skill_json_by_key($skillKey);
    $aff = strtolower(trim((string) ($j['affinity'] ?? 'utility')));

    return in_array($aff, ['physical', 'arcane', 'utility'], true) ? $aff : 'utility';
}

function skill_enhance_difficulty(string $skillKey, ?array $jsonRow = null): int
{
    $cfg = skill_enhance_config();
    $aff = skill_affinity_for($skillKey, $jsonRow);

    return match ($aff) {
        'physical' => $cfg['physical_difficulty'],
        'arcane' => $cfg['arcane_difficulty'],
        default => $cfg['utility_difficulty'],
    };
}

function skill_profession_enhance_bonus(string $professionKey, string $skillKey, ?array $jsonRow = null): int
{
    $pk = strtolower(trim($professionKey));
    if ($pk === '') {
        return 0;
    }
    $aff = skill_affinity_for($skillKey, $jsonRow);
    $bonus = skill_enhance_config()['profession_bonus_denom'];
    if ($pk === 'body' && $aff === 'physical') {
        return $bonus;
    }
    if ($pk === 'spirit' && $aff === 'arcane') {
        return $bonus;
    }

    return 0;
}

function skill_enhance_gold_cost(int $currentLevel): int
{
    $cfg = skill_enhance_config();
    $idx = max(0, $currentLevel - 1);

    return (int) ($cfg['base_gold'] * ($cfg['gold_scale'] ** $idx));
}

function skill_enhance_success_denominator(int $targetLevel, string $skillKey, int $profBonus, ?array $jsonRow = null): int
{
    $diff = skill_enhance_difficulty($skillKey, $jsonRow);

    return max(2, $targetLevel * $diff - max(0, $profBonus));
}

/** @param array<string,mixed>|null $playerSnap combat snapshot fields or null */
function skill_combat_power_mult(int $level, ?array $catRow, ?array $jsonRow, array $profSnap, ?array $playerSnap): float
{
    $lv = max(1, $level);
    $intW = $catRow ? (float) ($catRow['int_weight'] ?? 0.2) : 0.2;
    $wisW = $catRow ? (float) ($catRow['wis_weight'] ?? 0.1) : 0.1;
    $intEff = 10;
    $wis = 10;
    $strEff = 10;
    if ($playerSnap) {
        $intEff = (int) ($playerSnap['int_effective'] ?? $playerSnap['int_stat'] ?? 10);
        $wis = (int) ($playerSnap['wis'] ?? 10);
        $strEff = (int) ($playerSnap['str_effective'] ?? $playerSnap['str'] ?? 10);
    }
    $skillKey = (string) ($jsonRow['skill_key'] ?? ($catRow['skill_key'] ?? ''));
    $aff = skill_affinity_for($skillKey, $jsonRow);
    $scale = 1 + ($lv - 1) * 0.08 + $intW * ($intEff - 10) * 0.06 + $wisW * ($wis - 10) * 0.04;
    $strM = (float) ($profSnap['str_mult'] ?? $profSnap['profession_str_mult'] ?? 1.0);
    $intM = (float) ($profSnap['int_mult'] ?? $profSnap['profession_int_mult'] ?? 1.0);
    if ($aff === 'physical') {
        $scale *= 0.85 + 0.15 * max(0.1, $strM);
        $scale += max(0, $strEff - 10) * 0.012;
    } elseif ($aff === 'arcane') {
        $scale *= 0.85 + 0.15 * max(0.1, $intM);
    }
    $pk = (string) ($profSnap['profession_key'] ?? $profSnap['key'] ?? '');
    if ($pk === 'body' && $aff === 'physical') {
        $scale *= 1.06;
    } elseif ($pk === 'spirit' && $aff === 'arcane') {
        $scale *= 1.06;
    }

    return max(0.35, $scale);
}

function player_skills_enriched(PDO $pdo, int $playerId, int $playerLevel, ?array $playerSnap = null): array
{
    $rows = player_skills_rows($pdo, $playerId);
    $cats = [];
    foreach (skills_catalog_rows($pdo) as $c) {
        $cats[(string) ($c['skill_key'] ?? '')] = $c;
    }
    $prof = profession_snapshot_for_player($pdo, $playerId, max(1, $playerLevel));
    $profKey = (string) ($prof['profession_key'] ?? '');
    $out = [];
    foreach ($rows as $r) {
        $key = (string) ($r['skill_key'] ?? '');
        if ($key === '') {
            continue;
        }
        $lv = max(1, (int) ($r['level'] ?? 1));
        $cat = $cats[$key] ?? null;
        $json = skill_json_by_key($key);
        $maxLv = $cat ? (int) ($cat['max_level'] ?? 10) : 10;
        $aff = skill_affinity_for($key, $json);
        $label = (string) ($json['label'] ?? ($cat['label'] ?? $key));
        $powerMult = skill_combat_power_mult($lv, $cat, $json, $prof, $playerSnap);
        $canEnhance = $lv < $maxLv;
        $nextLv = $lv + 1;
        $profBonus = skill_profession_enhance_bonus($profKey, $key, $json);
        $denom = $canEnhance ? skill_enhance_success_denominator($nextLv, $key, $profBonus, $json) : 0;
        $out[] = [
            'skill_key' => $key,
            'label' => $label,
            'level' => $lv,
            'max_level' => $maxLv,
            'affinity' => $aff,
            'power_mult' => round($powerMult, 4),
            'profession_match' => $profBonus > 0,
            'can_enhance' => $canEnhance,
            'enhance_gold' => $canEnhance ? skill_enhance_gold_cost($lv) : 0,
            'enhance_chance_pct' => $canEnhance ? (int) floor(100 / max(1, $denom)) : 0,
            'enhance_denom' => $denom,
            'enhance_prof_bonus' => $profBonus,
            'action_key' => (string) ($json['action_key'] ?? ('skill_' . $key)),
        ];
    }

    return $out;
}

function skill_enhance_preview(PDO $pdo, int $playerId, string $skillKey, int $playerLevel): array
{
    $key = trim($skillKey);
    if ($key === '') {
        return ['ok' => false, 'error' => '请指定技能'];
    }
    expansion_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT level FROM player_skills WHERE player_id = ? AND skill_key = ? LIMIT 1');
    $st->execute([$playerId, $key]);
    $lv = $st->fetchColumn();
    if ($lv === false) {
        return ['ok' => false, 'error' => '尚未学会该技能'];
    }
    $cur = max(1, (int) $lv);
    $json = skill_json_by_key($key);
    $cats = skills_catalog_rows($pdo);
    $cat = null;
    foreach ($cats as $c) {
        if ((string) ($c['skill_key'] ?? '') === $key) {
            $cat = $c;
            break;
        }
    }
    $maxLv = $cat ? (int) ($cat['max_level'] ?? 10) : 10;
    if ($cur >= $maxLv) {
        return ['ok' => false, 'error' => '该技能已达最高等级'];
    }
    $next = $cur + 1;
    $prof = profession_snapshot_for_player($pdo, $playerId, max(1, $playerLevel));
    $profBonus = skill_profession_enhance_bonus((string) ($prof['profession_key'] ?? ''), $key, $json);
    $denom = skill_enhance_success_denominator($next, $key, $profBonus, $json);
    $cost = skill_enhance_gold_cost($cur);
    $aff = skill_affinity_for($key, $json);

    return [
        'ok' => true,
        'skill_key' => $key,
        'label' => (string) ($json['label'] ?? ($cat['label'] ?? $key)),
        'current_level' => $cur,
        'next_level' => $next,
        'max_level' => $maxLv,
        'gold_cost' => $cost,
        'success_denom' => $denom,
        'chance_percent' => (int) floor(100 / max(1, $denom)),
        'affinity' => $aff,
        'profession_match' => $profBonus > 0,
        'profession_bonus' => $profBonus,
    ];
}

function skill_enhance_commit(PDO $pdo, int $playerId, string $skillKey, int $playerGold, int $playerLevel): array
{
    $prev = skill_enhance_preview($pdo, $playerId, $skillKey, $playerLevel);
    if (empty($prev['ok'])) {
        return $prev;
    }
    $cost = (int) ($prev['gold_cost'] ?? 0);
    if ($playerGold < $cost) {
        return ['ok' => false, 'error' => '金币不足（需要 ' . $cost . '）'];
    }
    $key = (string) $prev['skill_key'];
    $denom = (int) ($prev['success_denom'] ?? 1);
    $next = (int) ($prev['next_level'] ?? 1);
    try {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE players SET gold = gold - ? WHERE id = ?')->execute([$cost, $playerId]);
        $roll = random_int(1, max(1, $denom));
        if ($roll !== 1) {
            $pdo->commit();

            return [
                'ok' => true,
                'enhance_failed' => true,
                'gold_spent' => $cost,
                'skill_key' => $key,
                'message' => '技能强化失败，已消耗 ' . $cost . ' 金币（成功率约 ' . (int) floor(100 / max(1, $denom)) . '%）',
                'chance_percent' => (int) floor(100 / max(1, $denom)),
            ];
        }
        $pdo->prepare('UPDATE player_skills SET level = ? WHERE player_id = ? AND skill_key = ?')
            ->execute([$next, $playerId, $key]);
        $pdo->commit();

        return [
            'ok' => true,
            'enhance_success' => true,
            'skill_key' => $key,
            'new_level' => $next,
            'gold_spent' => $cost,
            'message' => '技能强化成功！' . (string) ($prev['label'] ?? $key) . ' 升至 Lv.' . $next,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
