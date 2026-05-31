<?php
declare(strict_types=1);

/** @return array<string,mixed> */
function branch_encounters_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $path = dirname(__DIR__, 2) . '/data/branch_encounters.json';
    if (!is_file($path)) {
        $cfg = ['kinds' => [], 'item_categories' => [], 'overpower' => []];

        return $cfg;
    }
    $raw = json_decode((string) file_get_contents($path), true);
    $cfg = is_array($raw) ? $raw : ['kinds' => [], 'item_categories' => [], 'overpower' => []];

    return $cfg;
}

function branch_encounter_photo_path(int $imageNum): string
{
    $n = max(1, $imageNum);

    return 'img/photo/' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
}

/** @return array<string,mixed> */
function branch_encounter_roll(string $kind, int $seed, int $depth): array
{
    $cfg = branch_encounters_config();
    $kinds = is_array($cfg['kinds'] ?? null) ? $cfg['kinds'] : [];
    $block = is_array($kinds[$kind] ?? null) ? $kinds[$kind] : null;
    $variants = is_array($block['variants'] ?? null) ? $block['variants'] : [];
    if ($variants === []) {
        return [
            'id' => $kind . '_default',
            'kind' => $kind,
            'kind_label' => (string) ($block['label'] ?? $kind),
            'title' => (string) ($block['label'] ?? $kind),
            'story' => '你遇到了一段尚未记录的遭遇。',
            'image_num' => 1 + (abs(crc32($kind)) % 19),
            'image_path' => branch_encounter_photo_path(1 + (abs(crc32($kind)) % 19)),
            'tags' => [$kind],
            'brute_level_base' => 6,
        ];
    }
    $idx = abs(crc32($seed . '|enc|' . $kind . '|' . $depth)) % count($variants);
    $v = is_array($variants[$idx]) ? $variants[$idx] : $variants[0];

    $imgNum = (int) ($v['image_num'] ?? 1);

    return [
        'id' => (string) ($v['id'] ?? ($kind . '_' . $idx)),
        'kind' => $kind,
        'kind_label' => (string) ($block['label'] ?? $kind),
        'title' => (string) ($v['title'] ?? $block['label'] ?? $kind),
        'story' => (string) ($v['story'] ?? ''),
        'image_num' => $imgNum,
        'image_path' => branch_encounter_photo_path($imgNum),
        'tags' => is_array($v['tags'] ?? null) ? array_values($v['tags']) : [$kind],
        'solve_items' => is_array($v['solve_items'] ?? null) ? array_values($v['solve_items']) : [],
        'solve_categories' => is_array($v['solve_categories'] ?? null) ? array_values($v['solve_categories']) : [],
        'brute_level_base' => (int) ($v['brute_level_base'] ?? 6),
    ];
}

/** @return array<string, array{label:string,affinity:string}> */
function branch_skill_catalog_map(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $path = dirname(__DIR__, 2) . '/data/skills.json';
    $map = [];
    if (is_file($path)) {
        $raw = json_decode((string) file_get_contents($path), true);
        $list = is_array($raw['skills'] ?? null) ? $raw['skills'] : [];
        foreach ($list as $sk) {
            if (!is_array($sk)) {
                continue;
            }
            $key = (string) ($sk['skill_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $map[$key] = [
                'label' => (string) ($sk['label'] ?? $key),
                'affinity' => (string) ($sk['affinity'] ?? 'utility'),
            ];
        }
    }

    return $map;
}

/** @return array{label:string,affinity:string}|null */
function branch_skill_catalog_entry(string $skillKey): ?array
{
    $map = branch_skill_catalog_map();

    return is_array($map[$skillKey] ?? null) ? $map[$skillKey] : null;
}

/** @param list<string> $tags */
function branch_skill_affinity_compatible(string $skillAffinity, array $tags): bool
{
    $skillAffinity = strtolower(trim($skillAffinity));
    if (in_array('magic_only', $tags, true) && $skillAffinity === 'physical') {
        return false;
    }
    if (in_array('physical_only', $tags, true) && $skillAffinity === 'arcane') {
        return false;
    }

    return true;
}

/** @return array{ratio_min:float,ratio_ignore_mismatch:float,ratio_crush_mismatch:float} */
function branch_overpower_config(): array
{
    $cfg = branch_encounters_config();
    $o = is_array($cfg['overpower'] ?? null) ? $cfg['overpower'] : [];

    return [
        'ratio_min' => max(1.0, (float) ($o['ratio_min'] ?? 1)),
        'ratio_ignore_mismatch' => max(3.0, (float) ($o['ratio_ignore_mismatch'] ?? 3)),
        'ratio_crush_mismatch' => max(10.0, (float) ($o['ratio_crush_mismatch'] ?? 10)),
    ];
}

/** @param array<string,mixed> $encounter */
function branch_encounter_need_value(?array $roomCheck, array $encounter, int $depth, int $effDanger): int
{
    $fromEnc = branch_encounter_brute_threshold($encounter, $depth, $effDanger);
    $fromDc = 0;
    if ($roomCheck && !empty($roomCheck['dc'])) {
        $fromDc = max(1, (int) $roomCheck['dc'] - 8);
    }

    return max(1, $fromEnc, $fromDc);
}

/** @param array<string,mixed> $encounter */
function branch_encounter_brute_threshold(array $encounter, int $depth, int $effDanger): int
{
    $base = (int) ($encounter['brute_level_base'] ?? 6);

    return $base + (int) floor($depth / 4) + (int) floor($effDanger / 25);
}

/** @param array<string,mixed> $runState */
function branch_skill_effective_power(string $skillKey, int $level, string $affinity, array $runState): float
{
    $stats = is_array($runState['stats'] ?? null) ? $runState['stats'] : [];
    $level = max(0, $level);
    $bonus = 0.0;
    $affinity = strtolower(trim($affinity));
    if ($affinity === 'arcane') {
        $bonus = max(0.0, ((int) ($stats['int'] ?? 10) - 10) / 4.0);
    } elseif ($affinity === 'physical') {
        $bonus = max(0.0, ((int) ($stats['str'] ?? 10) - 10) / 4.0);
    } else {
        $bonus = max(0.0, ((int) ($stats['wis'] ?? 10) - 10) / 4.0);
    }

    return (float) $level + $bonus;
}

/**
 * @param list<string> $tags
 * @return array{allowed:bool,ratio:float,hint:string,message:string,reason:string}
 */
function branch_skill_overpower_eval(string $affinity, array $tags, float $power, int $need): array
{
    $cfg = branch_overpower_config();
    $need = max(1, $need);
    $ratio = $power / $need;
    $ratioText = '×' . round($ratio, 1);
    $compatible = branch_skill_affinity_compatible($affinity, $tags);

    if ($ratio < $cfg['ratio_min']) {
        return [
            'allowed' => false,
            'ratio' => $ratio,
            'hint' => '',
            'message' => '',
            'reason' => '强度不足（需要约 ' . $need . '，当前约 ' . round($power, 1) . '）',
        ];
    }

    if ($compatible) {
        return [
            'allowed' => true,
            'ratio' => $ratio,
            'hint' => $ratioText . ' 优势 · 以技破局',
            'message' => $ratioText . ' 优势下以技能破局',
            'reason' => '',
        ];
    }

    if ($ratio >= $cfg['ratio_crush_mismatch']) {
        return [
            'allowed' => true,
            'ratio' => $ratio,
            'hint' => $ratioText . ' 压倒性 · 克制失效',
            'message' => $ratioText . ' 压倒性优势，属性相克已不起作用',
            'reason' => '',
        ];
    }

    if ($ratio >= $cfg['ratio_ignore_mismatch']) {
        return [
            'allowed' => true,
            'ratio' => $ratio,
            'hint' => $ratioText . ' 优势 · 属性不再相克',
            'message' => $ratioText . ' 优势足够，原本不适配的属性差异已不再重要',
            'reason' => '',
        ];
    }

    return [
        'allowed' => false,
        'ratio' => $ratio,
        'hint' => '',
        'message' => '',
        'reason' => '技能与遭遇属性相克，需至少 ' . $cfg['ratio_ignore_mismatch'] . ' 倍优势（当前 ' . $ratioText . '）',
    ];
}

/** @param array<string,mixed> $runState @return list<string> */
function branch_encounter_bag_item_keys(array $runState): array
{
    $keys = [];
    $bag = is_array($runState['bag'] ?? null) ? $runState['bag'] : [];
    foreach ($bag as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = (string) ($row['item_key'] ?? '');
        if ($key === '' || surface_branch_bag_row_count($row) < 1) {
            continue;
        }
        $keys[$key] = true;
    }

    return array_keys($keys);
}

/** @param array<string,mixed> $encounter @param list<string> $bagKeys */
function branch_encounter_item_can_solve(array $encounter, string $itemKey, array $bagKeys): bool
{
    if (!in_array($itemKey, $bagKeys, true)) {
        return false;
    }
    $solve = is_array($encounter['solve_items'] ?? null) ? $encounter['solve_items'] : [];
    if (in_array($itemKey, $solve, true)) {
        return true;
    }
    $cats = is_array($encounter['solve_categories'] ?? null) ? $encounter['solve_categories'] : [];
    $cfg = branch_encounters_config();
    $itemCats = is_array($cfg['item_categories'] ?? null) ? $cfg['item_categories'] : [];
    foreach ($cats as $cat) {
        $list = is_array($itemCats[$cat] ?? null) ? $itemCats[$cat] : [];
        if (in_array($itemKey, $list, true)) {
            return true;
        }
    }

    return false;
}

/** @param array<string,mixed> $encounter @param array<string,mixed> $runState @param list<array<string,mixed>> $skillRows */
function branch_encounter_build_options(
    string $roomKind,
    array $encounter,
    ?array $roomCheck,
    array $runState,
    array $skillRows,
    int $depth,
    int $effDanger
): array {
    $opts = [];
    $bagKeys = branch_encounter_bag_item_keys($runState);
    $need = branch_encounter_need_value($roomCheck, $encounter, $depth, $effDanger);
    $tags = is_array($encounter['tags'] ?? null) ? $encounter['tags'] : [];
    $skillCatalog = branch_skill_catalog_map();

    if ($roomKind === 'trap') {
        $statName = $roomCheck && !empty($roomCheck['label']) ? (string) $roomCheck['label'] : '敏捷';
        $dc = $roomCheck ? (int) ($roomCheck['dc'] ?? 10) : 10;
        $opts[] = [
            'id' => 'check',
            'label' => $statName . '检定避开',
            'strategy' => 'check',
            'hint' => 'DC' . $dc . ' · 失败则 1.5 倍承伤',
        ];
        $opts[] = [
            'id' => 'force',
            'label' => '硬抗通过',
            'strategy' => 'force',
            'hint' => '标准承伤',
        ];
    } elseif ($roomKind === 'obstacle') {
        $opts[] = ['id' => 'force', 'label' => '徒手硬闯', 'strategy' => 'force', 'hint' => '标准承伤'];
        $opts[] = [
            'id' => 'str_check',
            'label' => '力量检定',
            'strategy' => 'str',
            'hint' => '失败则 1.5 倍承伤',
        ];
        foreach (['iron_axe', 'wood_axe', 'axe'] as $ak) {
            if (in_array($ak, $bagKeys, true)) {
                $opts[] = [
                    'id' => 'item:' . $ak,
                    'label' => '消耗' . surface_branch_item_label($ak),
                    'strategy' => 'item:' . $ak,
                    'hint' => '消耗行囊',
                    'consumes' => true,
                ];
            }
        }
    } elseif ($roomKind === 'trial' || $roomKind === 'event') {
        $statName = $roomCheck && !empty($roomCheck['label']) ? (string) $roomCheck['label'] : '属性';
        $dc = $roomCheck ? (int) ($roomCheck['dc'] ?? 10) : 10;
        $opts[] = [
            'id' => 'check',
            'label' => $statName . '检定',
            'strategy' => 'check',
            'hint' => 'DC' . $dc,
        ];
    } elseif ($roomKind === 'mixed' && $roomCheck && !empty($roomCheck['stat'])) {
        $statName = (string) ($roomCheck['label'] ?? $roomCheck['stat']);
        $opts[] = [
            'id' => 'check',
            'label' => $statName . '检定后战斗',
            'strategy' => 'check',
            'hint' => 'DC' . (int) ($roomCheck['dc'] ?? 10),
        ];
        $opts[] = ['id' => 'force', 'label' => '直接战斗', 'strategy' => 'force', 'hint' => '跳过检定'];
    } else {
        $opts[] = ['id' => 'force', 'label' => '处理事件', 'strategy' => 'force', 'hint' => ''];
    }

    $seenItems = [];
    foreach ($bagKeys as $ik) {
        if (!branch_encounter_item_can_solve($encounter, $ik, $bagKeys)) {
            continue;
        }
        if (isset($seenItems[$ik])) {
            continue;
        }
        $seenItems[$ik] = true;
        $opts[] = [
            'id' => 'item:' . $ik,
            'label' => '使用' . surface_branch_item_label($ik),
            'strategy' => 'item:' . $ik,
            'hint' => '直接解决',
            'consumes' => true,
        ];
    }

    foreach ($skillRows as $sk) {
        $skKey = (string) ($sk['skill_key'] ?? '');
        $lv = (int) ($sk['level'] ?? 0);
        if ($skKey === '' || $lv < 1) {
            continue;
        }
        $def = is_array($skillCatalog[$skKey] ?? null) ? $skillCatalog[$skKey] : null;
        if (!$def) {
            continue;
        }
        $aff = (string) ($def['affinity'] ?? 'utility');
        $power = branch_skill_effective_power($skKey, $lv, $aff, $runState);
        $ov = branch_skill_overpower_eval($aff, $tags, $power, $need);
        if (!$ov['allowed']) {
            continue;
        }
        $opts[] = [
            'id' => 'skill:' . $skKey,
            'label' => (string) ($def['label'] ?? $skKey) . ' Lv' . $lv,
            'strategy' => 'skill:' . $skKey,
            'hint' => (string) ($ov['hint'] ?? ''),
        ];
    }

    return $opts;
}

/** @param array<string,mixed> $meta */
function surface_branch_assign_encounter(array &$meta, string $kind, int $seed, int $depth): void
{
    $meta['encounter'] = branch_encounter_roll($kind, $seed, $depth);
}

/** @param array<string,mixed> $meta @param list<string> $effects */
function surface_branch_push_recent_log(array &$meta, int $depth, array $encounter, array $effects, string $kind): void
{
    if (!is_array($meta['recent_log'] ?? null)) {
        $meta['recent_log'] = [];
    }
    array_unshift($meta['recent_log'], [
        'depth' => $depth,
        'kind' => $kind,
        'title' => (string) ($encounter['title'] ?? $kind),
        'effects' => array_slice($effects, 0, 6),
    ]);
    $meta['recent_log'] = array_slice($meta['recent_log'], 0, 12);
}
