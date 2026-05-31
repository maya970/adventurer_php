<?php
declare(strict_types=1);

/** @return list<array{item_key:string,label:string,price:int,owned:int,max_own:int}> */
function general_shop_catalog(PDO $pdo, array $playerRow): array
{
    $pid = (int) ($playerRow['id'] ?? 0);
    $inv = fetch_inventory($pdo, $pid);
    $snap = compute_combat_snapshot($pdo, $playerRow, $inv);
    $lvl = max(1, (int) ($snap['level'] ?? 1));
    $defs = [
        ['item_key' => 'wood_axe', 'price_mult' => 8, 'max_own' => 20],
        ['item_key' => 'iron_axe', 'price_mult' => 15, 'max_own' => 20],
        ['item_key' => 'axe', 'price_mult' => 6, 'max_own' => 20],
        ['item_key' => 'healing_potion', 'price_mult' => 5, 'max_own' => 30],
        ['item_key' => 'greater_healing_potion', 'price_mult' => 12, 'max_own' => 20],
        ['item_key' => 'master_trap_key', 'price_mult' => 45, 'max_own' => 5],
        ['item_key' => 'bonfire_blade', 'price_mult' => 10, 'max_own' => 3],
    ];
    $out = [];
    foreach ($defs as $d) {
        $key = (string) $d['item_key'];
        $label = $key;
        foreach (load_item_templates() as $t) {
            if ((string) ($t['id'] ?? '') === $key) {
                $label = (string) ($t['label'] ?? $key);
                break;
            }
        }
        $cntSt = $pdo->prepare('SELECT COUNT(*) FROM player_items WHERE player_id=? AND item_key=?');
        $cntSt->execute([$pid, $key]);
        $owned = (int) $cntSt->fetchColumn();
        $out[] = [
            'item_key' => $key,
            'label' => $label,
            'price' => $lvl * (int) $d['price_mult'],
            'owned' => $owned,
            'max_own' => (int) $d['max_own'],
        ];
    }

    return $out;
}

function general_shop_buy(PDO $pdo, array $playerRow, string $itemKey, int $quantity = 1): array
{
    $itemKey = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($itemKey)));
    $qty = max(1, min(99, $quantity));
    $pid = (int) $playerRow['id'];
    $catalog = general_shop_catalog($pdo, $playerRow);
    $allowed = array_column($catalog, 'item_key');
    if (!in_array($itemKey, $allowed, true)) {
        return ['ok' => false, 'error' => '该物品不在杂货铺出售'];
    }
    $row = null;
    foreach ($catalog as $c) {
        if ($c['item_key'] === $itemKey) {
            $row = $c;
            break;
        }
    }
    if (!$row) {
        return ['ok' => false, 'error' => '商品不存在'];
    }
    $owned = (int) $row['owned'];
    $maxOwn = (int) $row['max_own'];
    if ($owned + $qty > $maxOwn) {
        return ['ok' => false, 'error' => '最多还能购买 ' . max(0, $maxOwn - $owned) . ' 个'];
    }
    $unitPrice = (int) $row['price'];
    $totalPrice = $unitPrice * $qty;
    $pdo->beginTransaction();
    try {
        $stP = $pdo->prepare('SELECT * FROM players WHERE id=? FOR UPDATE');
        $stP->execute([$pid]);
        $pl = $stP->fetch(PDO::FETCH_ASSOC);
        if (!$pl) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => '角色不存在'];
        }
        if ((int) $pl['gold'] < $totalPrice) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => '金币不足（需要 ' . $totalPrice . ' 金币）'];
        }
        $tpl = null;
        foreach (load_item_templates() as $t) {
            if ((string) ($t['id'] ?? '') === $itemKey) {
                $tpl = $t;
                break;
            }
        }
        if (!$tpl) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => '物品数据缺失'];
        }
        $pdo->prepare('UPDATE players SET gold = gold - ? WHERE id = ?')->execute([$totalPrice, $pid]);
        for ($i = 0; $i < $qty; $i++) {
            insert_generated_item($pdo, $pid, $tpl);
        }
        $pdo->commit();
        $st2 = $pdo->prepare('SELECT * FROM players WHERE id = ?');
        $st2->execute([$pid]);
        $out = player_payload($pdo, $st2->fetch(PDO::FETCH_ASSOC) ?: $pl);
        $out['shop_paid_gold'] = $totalPrice;
        $out['shop_item_key'] = $itemKey;
        $out['shop_quantity'] = $qty;

        return $out;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
