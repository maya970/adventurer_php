<?php

declare(strict_types=1);

function guild_fetch_player_snapshot(PDO $pdo, int $playerId): ?array
{
    try {
        $st = $pdo->prepare(
            'SELECT g.id, g.name, gm.role FROM guild_members gm INNER JOIN guilds g ON g.id = gm.guild_id WHERE gm.player_id = ? LIMIT 1'
        );
        $st->execute([$playerId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return null;
        }

        return [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'role' => (string) $r['role'],
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function guild_player_in_guild(PDO $pdo, int $playerId): bool
{
    try {
        $st = $pdo->prepare('SELECT 1 FROM guild_members WHERE player_id = ? LIMIT 1');
        $st->execute([$playerId]);

        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/** @return array{ok: bool, error?: string} */
function guild_create(PDO $pdo, int $playerId, string $name): array
{
    $name = trim($name);
    if (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 32) {
        return ['ok' => false, 'error' => '公会名称须为 2～32 字'];
    }
    if (guild_player_in_guild($pdo, $playerId)) {
        return ['ok' => false, 'error' => '已在公会中，请先退出'];
    }
    try {
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO guilds (name, leader_player_id) VALUES (?, ?)')->execute([$name, $playerId]);
        $gid = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO guild_members (guild_id, player_id, role) VALUES (?,?,?)')->execute([$gid, $playerId, 'leader']);
        $pdo->prepare('UPDATE players SET guild_id = ? WHERE id = ?')->execute([$gid, $playerId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (str_contains($e->getMessage(), 'Duplicate')) {
            return ['ok' => false, 'error' => '该公会名称已被占用'];
        }

        return ['ok' => false, 'error' => '创建失败'];
    }

    return ['ok' => true];
}

/** @return array{ok: bool, error?: string} */
function guild_join_by_name(PDO $pdo, int $playerId, string $name): array
{
    $name = trim($name);
    if ($name === '') {
        return ['ok' => false, 'error' => '请输入公会名称'];
    }
    if (guild_player_in_guild($pdo, $playerId)) {
        return ['ok' => false, 'error' => '已在公会中'];
    }
    $st = $pdo->prepare('SELECT id FROM guilds WHERE name = ? LIMIT 1');
    $st->execute([$name]);
    $gid = (int) $st->fetchColumn();
    if ($gid < 1) {
        return ['ok' => false, 'error' => '未找到该公会'];
    }
    try {
        $pdo->prepare('INSERT INTO guild_members (guild_id, player_id, role) VALUES (?,?,?)')->execute([$gid, $playerId, 'member']);
        $pdo->prepare('UPDATE players SET guild_id = ? WHERE id = ?')->execute([$gid, $playerId]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => '加入失败（可能已在该公会）'];
    }

    return ['ok' => true];
}

/** @return array{ok: bool, error?: string} */
function guild_leave(PDO $pdo, int $playerId): array
{
    $st = $pdo->prepare('SELECT guild_id, role FROM guild_members WHERE player_id = ? LIMIT 1');
    $st->execute([$playerId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'error' => '当前未加入公会'];
    }
    $gid = (int) $row['guild_id'];
    $role = (string) $row['role'];
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM guild_members WHERE player_id = ?')->execute([$playerId]);
        $pdo->prepare('UPDATE players SET guild_id = NULL WHERE id = ?')->execute([$playerId]);
        $stc = $pdo->prepare('SELECT COUNT(*) FROM guild_members WHERE guild_id = ?');
        $stc->execute([$gid]);
        $cnt = (int) $stc->fetchColumn();
        if ($cnt === 0) {
            $pdo->prepare('UPDATE players SET guild_id = NULL WHERE guild_id = ?')->execute([$gid]);
            $pdo->prepare('DELETE FROM guilds WHERE id = ?')->execute([$gid]);
        } elseif ($role === 'leader') {
            $st2 = $pdo->prepare('SELECT player_id FROM guild_members WHERE guild_id = ? ORDER BY joined_at ASC, player_id ASC LIMIT 1');
            $st2->execute([$gid]);
            $newLeader = (int) $st2->fetchColumn();
            if ($newLeader > 0) {
                $pdo->prepare('UPDATE guild_members SET role = ? WHERE guild_id = ? AND player_id = ?')->execute(['leader', $gid, $newLeader]);
                $pdo->prepare('UPDATE guilds SET leader_player_id = ? WHERE id = ?')->execute([$newLeader, $gid]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'error' => '离开公会失败'];
    }

    return ['ok' => true];
}
