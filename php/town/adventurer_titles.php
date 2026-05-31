<?php

declare(strict_types=1);

/**
 * 根据历史最深到达层与全服解锁上限，生成 UI 用称号（比例分档）。
 *
 * @return array{label: string, slug: string, peak: int, world_max: int}
 */
function adventurer_hud_meta(int $peakFloor, int $worldMaxUnlocked): array
{
    $w = max(1, $worldMaxUnlocked);
    $p = max(1, $peakFloor);
    if ($p >= $w) {
        return ['label' => '首席冒险者', 'slug' => 'chief', 'peak' => $p, 'world_max' => $w];
    }
    $r = $p / $w;
    $bands = [
        [0.9, '王者级冒险者', 'king'],
        [0.8, '大师级冒险者', 'master'],
        [0.7, '钻石级冒险者', 'diamond'],
        [0.6, '铂金级冒险者', 'platinum'],
        [0.5, '黄金级冒险者', 'gold'],
        [0.4, '秘银级冒险者', 'mithril'],
        [0.3, '精钢级冒险者', 'steel'],
        [0.2, '青铜级冒险者', 'bronze'],
        [0.1, '黑铁级冒险者', 'iron'],
    ];
    foreach ($bands as [$min, $label, $slug]) {
        if ($r >= $min) {
            return ['label' => $label, 'slug' => $slug, 'peak' => $p, 'world_max' => $w];
        }
    }

    return ['label' => '白瓷级冒险者', 'slug' => 'porcelain', 'peak' => $p, 'world_max' => $w];
}
