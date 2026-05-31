<?php
declare(strict_types=1);

require __DIR__ . '/php/common/bootstrap.php';
require_once __DIR__ . '/php/common/rpg_mail.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$body = json_in();
if ($action === '' && isset($body['action'])) {
    $action = (string) $body['action'];
}

$publicActions = ['register', 'login', 'session', 'logout', 'build_info', 'verify_email', 'resend_verify_email'];

try {
    $pdo = db();

    if (!in_array($action, $publicActions, true) && empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => '需要登录']);
        exit;
    }

    $playerRow = null;
    if (!empty($_SESSION['user_id'])) {
        $playerRow = get_player_by_user_id($pdo, (int) $_SESSION['user_id']);
    }

    if (!in_array($action, $publicActions, true)) {
        if (!$playerRow) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => '需要登录']);
            exit;
        }
        if (player_is_black_room($playerRow)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => '账号已被限制，无法继续游戏']);
            exit;
        }
    }

    $pid = $playerRow ? (int) $playerRow['id'] : 0;

    switch ($action) {
        case 'build_info':
            echo json_encode(['ok' => true, 'build' => app_build_id()]);
            break;

        case 'session':
            if (!$playerRow) {
                echo json_encode(['ok' => true, 'logged_in' => false]);
                break;
            }
            if (player_is_black_room($playerRow)) {
                unset($_SESSION['user_id'], $_SESSION['username']);
                echo json_encode([
                    'ok' => true,
                    'logged_in' => false,
                    'restricted' => true,
                    'error' => '账号已被限制，无法进入游戏',
                ]);
                break;
            }
            $out = player_payload($pdo, $playerRow);
            $out['logged_in'] = true;
            $out['username'] = (string) ($_SESSION['username'] ?? '');
            echo json_encode($out);
            break;

        case 'register':
            $u = trim((string) ($body['username'] ?? ''));
            $pw = (string) ($body['password'] ?? '');
            $email = trim((string) ($body['email'] ?? ''));
            if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $u)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => '用户名须为 3～32 位字母、数字或下划线']);
                break;
            }
            if (strlen($pw) < 6) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => '密码至少需要 6 位']);
                break;
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => '邮箱格式无效；可留空稍后在主城「· 邮箱」处补填']);
                break;
            }
            $hash = password_hash($pw, PASSWORD_DEFAULT);
            $verifyToken = '';
            $accountId = 0;
            try {
                $pdo->beginTransaction();
                if ($email === '') {
                    $pdo->prepare(
                        'INSERT INTO accounts (username, pass_hash, email, email_verified, email_verify_token, email_verify_expires) VALUES (?,?,NULL,1,NULL,NULL)'
                    )->execute([$u, $hash]);
                } else {
                    $verifyToken = bin2hex(random_bytes(24));
                    $exp = date('Y-m-d H:i:s', time() + 86400 * 2);
                    $pdo->prepare(
                        'INSERT INTO accounts (username, pass_hash, email, email_verified, email_verify_token, email_verify_expires) VALUES (?,?,?,?,?,?)'
                    )->execute([$u, $hash, $email, 0, $verifyToken, $exp]);
                }
                $accountId = (int) $pdo->lastInsertId();
                try {
                    $pdo->prepare('INSERT INTO players (user_id, display_name, xp, xp_t2, xp_t3, gold, level_cached) VALUES (?,?,0,0,0,100,1)')->execute([$accountId, $u]);
                } catch (Throwable $e) {
                    $pdo->prepare('INSERT INTO players (user_id, display_name, xp, gold, level_cached) VALUES (?,?,0,100,1)')->execute([$accountId, $u]);
                }
                $newPlayerId = (int) $pdo->lastInsertId();
                if ($newPlayerId > 0 && function_exists('player_limits_ensure_row')) {
                    player_limits_ensure_row($pdo, $newPlayerId);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                http_response_code(409);
                echo json_encode(['ok' => false, 'error' => '该用户名已被注册']);
                break;
            }
            if ($email !== '') {
                rpg_send_registration_verify_mail($cfg, $email, $u, $verifyToken);
                $hint = rpg_mail_disabled_player_hint($cfg);
                echo json_encode([
                    'ok' => true,
                    'need_email_verify' => true,
                    'message' => '注册成功。建议查收邮箱完成验证；也可直接使用用户名与密码登录（不验证亦可）。' . $hint,
                ]);
                break;
            }
            $_SESSION['user_id'] = $accountId;
            $_SESSION['username'] = $u;
            $fresh = get_player_by_user_id($pdo, $accountId);
            if (!$fresh) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => '角色创建异常']);
                break;
            }
            $out = player_payload($pdo, $fresh);
            $out['username'] = $u;
            echo json_encode($out);
            break;

        case 'login':
            $u = trim((string) ($body['username'] ?? ''));
            $pw = (string) ($body['password'] ?? '');
            $st = $pdo->prepare('SELECT id, username, pass_hash FROM accounts WHERE username = ? LIMIT 1');
            $st->execute([$u]);
            $acc = $st->fetch();
            if (!$acc || !password_verify($pw, (string) $acc['pass_hash'])) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => '用户名或密码错误']);
                break;
            }
            $_SESSION['user_id'] = (int) $acc['id'];
            $_SESSION['username'] = (string) $acc['username'];
            $fresh = get_player_by_user_id($pdo, (int) $acc['id']);
            if (!$fresh) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => '角色数据缺失，请重新注册或联系管理员']);
                break;
            }
            if (player_is_black_room($fresh)) {
                unset($_SESSION['user_id'], $_SESSION['username']);
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => '账号已被限制，无法登录']);
                break;
            }
            $out = player_payload($pdo, $fresh);
            $out['username'] = $_SESSION['username'];
            echo json_encode($out);
            break;

        case 'logout':
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
            }
            session_destroy();
            echo json_encode(['ok' => true]);
            break;

        case 'verify_email':
            $token = trim((string) ($body['token'] ?? $_GET['token'] ?? ''));
            if (strlen($token) < 16) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => '验证链接无效']);
                break;
            }
            $st = $pdo->prepare(
                'SELECT id FROM accounts WHERE email_verify_token = ? AND email_verify_expires > NOW() LIMIT 1'
            );
            $st->execute([$token]);
            $row = $st->fetch();
            if (!$row) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => '链接已失效或已使用，请重新申请验证邮件']);
                break;
            }
            $pdo->prepare(
                'UPDATE accounts SET email_verified = 1, email_verify_token = NULL, email_verify_expires = NULL WHERE id = ?'
            )->execute([(int) $row['id']]);
            echo json_encode(['ok' => true, 'message' => '邮箱已验证，请返回登录页登录。']);
            break;

        case 'resend_verify_email':
            $u = trim((string) ($body['username'] ?? ''));
            $pw = (string) ($body['password'] ?? '');
            $st = $pdo->prepare(
                'SELECT id, username, pass_hash, email, email_verified FROM accounts WHERE username = ? LIMIT 1'
            );
            $st->execute([$u]);
            $acc = $st->fetch();
            if (!$acc || !password_verify($pw, (string) $acc['pass_hash'])) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => '用户名或密码错误']);
                break;
            }
            if ((int) ($acc['email_verified'] ?? 1) === 1) {
                echo json_encode(['ok' => false, 'error' => '该账号已验证，请直接登录']);
                break;
            }
            $em = trim((string) ($acc['email'] ?? ''));
            if ($em === '' || !filter_var($em, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => '账号尚未填写邮箱；请登录后在主城角色名旁的「· 邮箱」进入账号邮箱页补填。']);
                break;
            }
            $token = bin2hex(random_bytes(24));
            $exp = date('Y-m-d H:i:s', time() + 86400 * 2);
            $pdo->prepare(
                'UPDATE accounts SET email_verify_token = ?, email_verify_expires = ? WHERE id = ?'
            )->execute([$token, $exp, (int) $acc['id']]);
            rpg_send_registration_verify_mail($cfg, $em, (string) $acc['username'], $token);
            $mh = rpg_mail_disabled_player_hint($cfg);
            echo json_encode(['ok' => true, 'message' => '验证邮件已重新发送，请查收。' . $mh]);
            break;

        case 'player':
            $out = player_payload($pdo, $playerRow);
            $out['username'] = (string) ($_SESSION['username'] ?? '');
            echo json_encode($out);
            break;

        case 'account_bind_email':
            $email = trim((string) ($body['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => '请填写有效的邮箱地址']);
                break;
            }
            $uid = (int) ($_SESSION['user_id'] ?? 0);
            if ($uid < 1) {
                http_response_code(401);
                echo json_encode(['ok' => false, 'error' => '需要登录']);
                break;
            }
            $stA = $pdo->prepare('SELECT id, username FROM accounts WHERE id = ? LIMIT 1');
            $stA->execute([$uid]);
            $accB = $stA->fetch(PDO::FETCH_ASSOC);
            if (!$accB) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => '账号不存在']);
                break;
            }
            $token = bin2hex(random_bytes(24));
            $exp = date('Y-m-d H:i:s', time() + 86400 * 2);
            $pdo->prepare(
                'UPDATE accounts SET email = ?, email_verified = 0, email_verify_token = ?, email_verify_expires = ? WHERE id = ?'
            )->execute([$email, $token, $exp, $uid]);
            rpg_send_registration_verify_mail($cfg, $email, (string) $accB['username'], $token);
            $fresh = get_player_by_user_id($pdo, $uid);
            $mh = rpg_mail_disabled_player_hint($cfg);
            if (!$fresh) {
                echo json_encode(['ok' => true, 'message' => '绑定已保存。若已启用发信，请查收邮箱完成验证。' . $mh]);
                break;
            }
            $out = player_payload($pdo, $fresh);
            $out['username'] = (string) ($_SESSION['username'] ?? '');
            $out['message'] = '绑定已保存。未验证也可照常登录；验证后可降低被误判为垃圾信的风险，并便于接收拍卖等通知。' . $mh;
            echo json_encode($out);
            break;

        case 'account_resend_verify':
            $uid = (int) ($_SESSION['user_id'] ?? 0);
            if ($uid < 1) {
                http_response_code(401);
                echo json_encode(['ok' => false, 'error' => '需要登录']);
                break;
            }
            $stR = $pdo->prepare('SELECT id, username, email, email_verified FROM accounts WHERE id = ? LIMIT 1');
            $stR->execute([$uid]);
            $accR = $stR->fetch(PDO::FETCH_ASSOC);
            if (!$accR) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => '账号不存在']);
                break;
            }
            if ((int) ($accR['email_verified'] ?? 0) === 1) {
                echo json_encode(['ok' => false, 'error' => '当前账号邮箱已验证，无需重发。']);
                break;
            }
            $emR = trim((string) ($accR['email'] ?? ''));
            if ($emR === '' || !filter_var($emR, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => '请先在账号邮箱页填写并绑定邮箱。']);
                break;
            }
            $tokenR = bin2hex(random_bytes(24));
            $expR = date('Y-m-d H:i:s', time() + 86400 * 2);
            $pdo->prepare(
                'UPDATE accounts SET email_verify_token = ?, email_verify_expires = ? WHERE id = ?'
            )->execute([$tokenR, $expR, $uid]);
            rpg_send_registration_verify_mail($cfg, $emR, (string) $accR['username'], $tokenR);
            $freshR = get_player_by_user_id($pdo, $uid);
            $mhR = rpg_mail_disabled_player_hint($cfg);
            if (!$freshR) {
                echo json_encode(['ok' => true, 'message' => '验证邮件已重新发送。' . $mhR]);
                break;
            }
            $outR = player_payload($pdo, $freshR);
            $outR['username'] = (string) ($_SESSION['username'] ?? '');
            $outR['message'] = '验证邮件已重新发送，请查收。' . $mhR;
            echo json_encode($outR);
            break;

        case 'dungeon_world':
            echo json_encode(['ok' => true, 'dungeon_world' => fetch_dungeon_world_safe($pdo)]);
            break;

        case 'death':
            $deathFloor = (int) ($body['floor'] ?? 0);
            $stPl = $pdo->prepare('SELECT * FROM players WHERE id = ? FOR UPDATE');
            $pdo->beginTransaction();
            try {
                $stPl->execute([$pid]);
                $plRow = $stPl->fetch();
                if (!$plRow) {
                    throw new RuntimeException('角色不存在');
                }
                $today = date('Y-m-d');
                $campF = (int) ($plRow['campfire_floor'] ?? 0);
                $campD = isset($plRow['campfire_date']) ? (string) $plRow['campfire_date'] : '';
                $spawn = ($campD === $today && $campF > 0) ? $campF : 1;
                $pdo->prepare('UPDATE players SET dungeon_spawn_floor = ? WHERE id = ?')->execute([$spawn, $pid]);
                $pdo->prepare('INSERT INTO event_log (player_id, kind, detail) VALUES (?,?,?)')->execute([
                    $pid,
                    'death',
                    json_encode(['floor' => $deathFloor, 'respawn_floor' => $spawn], JSON_UNESCAPED_UNICODE),
                ]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
                break;
            }
            $stFresh = $pdo->prepare('SELECT * FROM players WHERE id = ?');
            $stFresh->execute([$pid]);
            $fresh = $stFresh->fetch();
            $invD = enrich_inventory_rows(fetch_inventory($pdo, $pid));
            stamina_ensure_today($pdo, $fresh);
            $respawn = max(1, (int) ($fresh['dungeon_spawn_floor'] ?? 1));
            echo json_encode([
                'ok' => true,
                'respawn_floor' => $respawn,
                'stamina' => stamina_snapshot_for_player($pdo, $fresh, $invD),
            ]);
            break;

        case 'dungeon_prepare':
            $route = strtolower(trim((string) ($body['route'] ?? 'floor1')));
            $stP = $pdo->prepare('SELECT * FROM players WHERE id = ? FOR UPDATE');
            $pdo->beginTransaction();
            try {
                $stP->execute([$pid]);
                $pr = $stP->fetch();
                if (!$pr) {
                    throw new RuntimeException('角色不存在');
                }
                if ($route === 'campfire') {
                    $td = date('Y-m-d');
                    $cf = (int) ($pr['campfire_floor'] ?? 0);
                    $cd = isset($pr['campfire_date']) ? (string) $pr['campfire_date'] : '';
                    if ($cd !== $td || $cf < 1) {
                        throw new RuntimeException('今日尚无有效记录层');
                    }
                    $pdo->prepare('UPDATE players SET dungeon_spawn_floor = ? WHERE id = ?')->execute([$cf, $pid]);
                } else {
                    $pdo->prepare('UPDATE players SET dungeon_spawn_floor = 1 WHERE id = ?')->execute([$pid]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
                break;
            }
            $st2 = $pdo->prepare('SELECT * FROM players WHERE id = ?');
            $st2->execute([$pid]);
            daily_tasks_mark_dungeon_visit($pdo, $pid);
            echo json_encode(player_payload($pdo, $st2->fetch()));
            break;

        case 'campfire_set':
            $cfloor = (int) ($body['floor'] ?? 0);
            if ($cfloor < 1) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => '层数无效']);
                break;
            }
            $invC = fetch_inventory($pdo, $pid);
            if (!player_has_misc_item_key($invC, 'bonfire_blade')) {
                echo json_encode(['ok' => false, 'error' => '条件不足，无法建立篝火']);
                break;
            }
            $todayC = date('Y-m-d');
            $pdo->prepare('UPDATE players SET campfire_floor = ?, campfire_date = ? WHERE id = ?')->execute([$cfloor, $todayC, $pid]);
            $pdo->prepare('INSERT INTO event_log (player_id, kind, detail) VALUES (?,?,?)')->execute([
                $pid,
                'campfire_set',
                json_encode(['floor' => $cfloor], JSON_UNESCAPED_UNICODE),
            ]);
            $stCf = $pdo->prepare('SELECT * FROM players WHERE id = ?');
            $stCf->execute([$pid]);
            echo json_encode(player_payload($pdo, $stCf->fetch()));
            break;

        case 'sanctuary_set':
            if (strtolower(trim((string) ($body['origin'] ?? ''))) !== 'dungeon') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => '避难所锚点仅可在地下城内设立']);
                break;
            }
            $sfloor = (int) ($body['floor'] ?? 0);
            $maxSan = world_dungeon_max_floor($pdo);
            if ($sfloor < 1 || $sfloor > $maxSan) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => '避难所层须为 1～' . $maxSan . ' 的整数（与当前所在层一致）']);
                break;
            }
            $invS = fetch_inventory($pdo, $pid);
            if (!player_has_misc_item_key($invS, 'sanctuary_scepter')) {
                echo json_encode(['ok' => false, 'error' => '条件不足，无法设立锚点']);
                break;
            }
            $pdo->prepare('UPDATE players SET shelter_floor = ? WHERE id = ?')->execute([$sfloor, $pid]);
            $pdo->prepare('INSERT INTO event_log (player_id, kind, detail) VALUES (?,?,?)')->execute([
                $pid,
                'sanctuary_set',
                json_encode(['floor' => $sfloor], JSON_UNESCAPED_UNICODE),
            ]);
            $stSf = $pdo->prepare('SELECT * FROM players WHERE id = ?');
            $stSf->execute([$pid]);
            echo json_encode(player_payload($pdo, $stSf->fetch()));
            break;

        case 'dungeon_peak_report':
            $f = (int) ($body['floor'] ?? 0);
            if ($f < 1) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => '层数无效']);
                break;
            }
            try {
                $pdo->prepare('UPDATE players SET dungeon_peak_floor = GREATEST(COALESCE(dungeon_peak_floor, 1), ?) WHERE id = ?')->execute([$f, $pid]);
            } catch (Throwable $e) {
                echo json_encode(['ok' => false, 'error' => '请先执行数据库升级 sql/upgrade_social_guild_peak.sql']);
                break;
            }
            $stPk = $pdo->prepare('SELECT * FROM players WHERE id = ?');
            $stPk->execute([$pid]);
            echo json_encode(player_payload($pdo, $stPk->fetch()));
            break;

        case 'dungeon_save':
            $ds = dungeon_save_commit($pdo, $playerRow, is_array($body) ? $body : []);
            if (empty($ds['ok']) && isset($ds['error'])) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => (string) $ds['error']]);
                break;
            }
            if (isset($ds['error'])) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => (string) $ds['error']]);
                break;
            }
            if (!empty($ds['save']['xp_granted'])) {
                daily_tasks_add_dungeon_xp($pdo, $pid, (int) $ds['save']['xp_granted']);
            }
            echo json_encode($ds);
            break;

        case 'guild_create':
            $gname = (string) ($body['name'] ?? '');
            try {
                $r = guild_create($pdo, $pid, $gname);
            } catch (Throwable $e) {
                echo json_encode(['ok' => false, 'error' => '公会功能需数据库升级 sql/upgrade_social_guild_peak.sql']);
                break;
            }
            if (!$r['ok']) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => (string) ($r['error'] ?? '创建失败')]);
                break;
            }
            $stG = $pdo->prepare('SELECT * FROM players WHERE id = ?');
            $stG->execute([$pid]);
            echo json_encode(player_payload($pdo, $stG->fetch()));
            break;

        case 'guild_join':
            $gname = (string) ($body['name'] ?? '');
            try {
                $r = guild_join_by_name($pdo, $pid, $gname);
            } catch (Throwable $e) {
                echo json_encode(['ok' => false, 'error' => '公会功能需数据库升级']);
                break;
            }
            if (!$r['ok']) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => (string) ($r['error'] ?? '加入失败')]);
                break;
            }
            $stG = $pdo->prepare('SELECT * FROM players WHERE id = ?');
            $stG->execute([$pid]);
            echo json_encode(player_payload($pdo, $stG->fetch()));
            break;

        case 'guild_leave':
            try {
                $r = guild_leave($pdo, $pid);
            } catch (Throwable $e) {
                echo json_encode(['ok' => false, 'error' => '公会功能需数据库升级']);
                break;
            }
            if (!$r['ok']) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => (string) ($r['error'] ?? '离开失败')]);
                break;
            }
            $stG = $pdo->prepare('SELECT * FROM players WHERE id = ?');
            $stG->execute([$pid]);
            echo json_encode(player_payload($pdo, $stG->fetch()));
            break;

        case 'title_equip':
            $ptid = (int) ($body['player_title_id'] ?? 0);
            if ($ptid < 1) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => '参数无效']);
                break;
            }
            $rT = title_equip_player_row($pdo, $pid, $ptid);
            if (!$rT['ok']) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => (string) ($rT['error'] ?? '装备失败')]);
                break;
            }
            $stT = $pdo->prepare('SELECT * FROM players WHERE id = ?');
            $stT->execute([$pid]);
            echo json_encode(player_payload($pdo, $stT->fetch()));
            break;

        case 'title_unequip':
            $rU = title_unequip_all($pdo, $pid);
            if (!$rU['ok']) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => (string) ($rU['error'] ?? '卸下失败')]);
                break;
            }
            $stT = $pdo->prepare('SELECT * FROM players WHERE id = ?');
            $stT->execute([$pid]);
            echo json_encode(player_payload($pdo, $stT->fetch()));
            break;

        case 'profession_set':
            $pk = (string) ($body['profession_key'] ?? '');
            $rP = profession_apply_choice($pdo, $pid, $pk);
            if (!$rP['ok']) {
                http_response_code(400);
                echo json_encode($rP);
                break;
            }
            $stPp = $pdo->prepare('SELECT * FROM players WHERE id = ?');
            $stPp->execute([$pid]);
            echo json_encode(player_payload($pdo, $stPp->fetch()));
            break;

        case 'forget_preview':
            $mode = (string) ($body['mode'] ?? '');
            $sk = (string) ($body['skill_key'] ?? '');
            echo json_encode(forget_preview($pdo, $pid, $mode, $sk));
            break;

        case 'forget_commit':
            $mode = (string) ($body['mode'] ?? '');
            $sk = (string) ($body['skill_key'] ?? '');
            $rF = forget_commit($pdo, $pid, $mode, $sk);
            if (!$rF['ok']) {
                http_response_code(400);
                echo json_encode($rF);
                break;
            }
            $stF = $pdo->prepare('SELECT * FROM players WHERE id = ?');
            $stF->execute([$pid]);
            $payload = player_payload($pdo, $stF->fetch());
            $payload['message'] = (string) ($rF['message'] ?? '已完成');
            echo json_encode($payload);
            break;

        case 'skills_catalog':
            echo json_encode(['ok' => true, 'skills_catalog' => skills_catalog_rows($pdo)]);
            break;

        case 'combat_action_catalog':
            echo json_encode(['ok' => true, 'combat_actions' => combat_action_catalog_rows($pdo)]);
            break;

        case 'skills_learn_book':
            $itemIdSk = (int) ($body['item_id'] ?? 0);
            if ($itemIdSk < 1) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => '技能书参数无效']);
                break;
            }
            $rSk = skill_try_learn_from_book($pdo, $pid, $itemIdSk);
            if (!$rSk['ok']) {
                http_response_code(400);
                echo json_encode($rSk);
                break;
            }
            $stSk = $pdo->prepare('SELECT * FROM players WHERE id = ?');
            $stSk->execute([$pid]);
            $outSk = player_payload($pdo, $stSk->fetch());
            $outSk['skill_message'] = '已学习/升级技能：' . (string) ($rSk['skill_key'] ?? '');
            echo json_encode($outSk);
            break;

        case 'knapsack_set':
            $rawK = $body['item_ids'] ?? [];
            if (!is_array($rawK)) {
                $rawK = [];
            }
            $rK = player_knapsack_set($pdo, $pid, $rawK);
            if (!$rK['ok']) {
                http_response_code(400);
                echo json_encode($rK);
                break;
            }
            $stK = $pdo->prepare('SELECT * FROM players WHERE id = ?');
            $stK->execute([$pid]);
            echo json_encode(player_payload($pdo, $stK->fetch()));
            break;

        case 'auto_actions_set':
            $rawA = $body['actions'] ?? [];
            if (!is_array($rawA)) {
                $rawA = [];
            }
            $rA = player_auto_actions_set($pdo, $pid, $rawA);
            if (!$rA['ok']) {
                http_response_code(400);
                echo json_encode($rA);
                break;
            }
            $stA = $pdo->prepare('SELECT * FROM players WHERE id = ?');
            $stA->execute([$pid]);
            echo json_encode(player_payload($pdo, $stA->fetch()));
            break;

        case 'daily_tasks_status':
            echo json_encode(daily_tasks_status_payload($pdo, $pid, $playerRow));
            break;

        case 'daily_tasks_claim':
            $tk = (string) ($body['task_key'] ?? '');
            $rT = daily_tasks_claim($pdo, $pid, $playerRow, $tk);
            if (!$rT['ok']) {
                http_response_code(400);
                echo json_encode($rT);
                break;
            }
            $stTc = $pdo->prepare('SELECT * FROM players WHERE id = ?');
            $stTc->execute([$pid]);
            $outTc = player_payload($pdo, $stTc->fetch() ?: $playerRow);
            $outTc['message'] = (string) ($rT['message'] ?? '已领取');
            $outTc['daily_tasks'] = daily_tasks_status_payload($pdo, $pid, $playerRow)['tasks'] ?? [];
            echo json_encode($outTc);
            break;

        case 'jump_catalog':
            echo json_encode([
                'ok' => true,
                'catalog' => fetch_jump_equiv_catalog_rows($pdo),
                'max_floor' => world_dungeon_max_floor($pdo),
            ]);
            break;

        case 'jump_spawn_ack':
            try {
                $pdo->prepare('UPDATE players SET dungeon_spawn_floor = 1 WHERE id = ?')->execute([$pid]);
            } catch (Throwable $e) {
            }
            $stJ = $pdo->prepare('SELECT * FROM players WHERE id = ?');
            $stJ->execute([$pid]);
            echo json_encode(player_payload($pdo, $stJ->fetch()));
            break;

        case 'jump_submit':
            $target = (int) ($body['target_floor'] ?? 0);
            $rawIds = $body['item_ids'] ?? [];
            if (!is_array($rawIds)) {
                $rawIds = [];
            }
            $ids = [];
            foreach ($rawIds as $x) {
                $n = (int) $x;
                if ($n > 0) {
                    $ids[$n] = true;
                }
            }
            $idList = array_keys($ids);
            $stJumpPl = $pdo->prepare('SELECT * FROM players WHERE id = ?');
            $stJumpPl->execute([$pid]);
            $plJump = $stJumpPl->fetch();
            if (!$plJump) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => '角色不存在']);
                break;
            }
            stamina_ensure_today($pdo, $plJump);
            $maxF = world_dungeon_max_floor($pdo);
            $shelter = (int) ($playerRow['shelter_floor'] ?? 0);
            $shelterJump = $shelter > 0 && $target === $shelter;
            if ($target < 1 || $target > $maxF) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => '目标层须为 1～' . $maxF . ' 之间的整数']);
                break;
            }
            if (!$shelterJump && ($maxF < 10 || $target < 10 || $target % 10 !== 0)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => '非避难所锚点时，目标层须为 10～' . $maxF . ' 的 10 的倍数']);
                break;
            }
            if ($shelterJump) {
                $pdo->prepare('UPDATE players SET dungeon_spawn_floor = ? WHERE id = ?')->execute([$target, $pid]);
                $pdo->prepare('INSERT INTO event_log (player_id, kind, detail) VALUES (?,?,?)')->execute([
                    $pid,
                    'jump_shelter',
                    json_encode(['target_floor' => $target], JSON_UNESCAPED_UNICODE),
                ]);
                $stSh = $pdo->prepare('SELECT * FROM players WHERE id = ?');
                $stSh->execute([$pid]);
                $outS = player_payload($pdo, $stSh->fetch());
                $outS['jump'] = ['target_floor' => $target, 'shelter_free' => true];
                echo json_encode($outS);
                break;
            }
            $needEquiv = (int) ($target / 10);
            if ($needEquiv < 1 || count($idList) < 1) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => '请选择等价物并指定有效目标层']);
                break;
            }
            $eqMap = jump_equiv_units_map($pdo);
            if ($eqMap === []) {
                http_response_code(503);
                echo json_encode(['ok' => false, 'error' => '跳跃目录未配置，请联系管理员']);
                break;
            }
            $totalEquiv = 0;
            $pdo->beginTransaction();
            try {
                $total = 0;
                foreach ($idList as $iid) {
                    $stIt = $pdo->prepare('SELECT * FROM player_items WHERE id = ? AND player_id = ? FOR UPDATE');
                    $stIt->execute([$iid, $pid]);
                    $rowIt = $stIt->fetch();
                    if (!$rowIt) {
                        throw new RuntimeException('物品不存在或不属于你');
                    }
                    if ((int) $rowIt['equipped'] === 1) {
                        throw new RuntimeException('请先卸下装备再放入法阵');
                    }
                    if ((int) ($rowIt['in_warehouse'] ?? 0) === 1) {
                        throw new RuntimeException('请从仓库取出物品再放入法阵');
                    }
                    if (($rowIt['slot'] ?? '') !== 'misc') {
                        throw new RuntimeException('仅杂物可作为等价物');
                    }
                    $key = (string) ($rowIt['item_key'] ?? '');
                    if (!isset($eqMap[$key]) || $eqMap[$key] < 1) {
                        throw new RuntimeException('该物品不在跳跃允许表内：' . $key);
                    }
                    $total += $eqMap[$key];
                }
                if ($total < $needEquiv) {
                    throw new RuntimeException('等价物不足：需要 ' . $needEquiv . '，当前选中合计 ' . $total);
                }
                $totalEquiv = $total;
                foreach ($idList as $iid) {
                    $pdo->prepare('DELETE FROM player_items WHERE id = ? AND player_id = ?')->execute([$iid, $pid]);
                }
                $pdo->prepare('UPDATE players SET dungeon_spawn_floor = ? WHERE id = ?')->execute([$target, $pid]);
                $pdo->prepare('INSERT INTO event_log (player_id, kind, detail) VALUES (?,?,?)')->execute([
                    $pid,
                    'jump_portal',
                    json_encode(['target_floor' => $target, 'item_ids' => $idList, 'equiv_spent' => $totalEquiv], JSON_UNESCAPED_UNICODE),
                ]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
                break;
            }
            $stJp = $pdo->prepare('SELECT * FROM players WHERE id = ?');
            $stJp->execute([$pid]);
            $out = player_payload($pdo, $stJp->fetch());
            $out['jump'] = ['target_floor' => $target, 'equiv_needed' => $needEquiv, 'equiv_consumed' => $totalEquiv];
            echo json_encode($out);
            break;

        case 'warehouse_set':
            $wItemId = (int) ($body['item_id'] ?? 0);
            $wFlag = (int) ($body['warehouse'] ?? -1);
            if ($wItemId < 1 || ($wFlag !== 0 && $wFlag !== 1)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => '参数无效']);
                break;
            }
            $wst = $pdo->prepare('SELECT * FROM player_items WHERE id = ? AND player_id = ? LIMIT 1');
            $wst->execute([$wItemId, $pid]);
            $wrow = $wst->fetch();
            if (!$wrow) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => '未找到目标']);
                break;
            }
            if ((int) $wrow['equipped'] === 1) {
                echo json_encode(['ok' => false, 'error' => '已装备的物品请先卸下再放入仓库']);
                break;
            }
            $lim = game_limits_for_player($pdo, $pid);
            $cnt = player_storage_counts($pdo, $pid);
            $curWh = (int) ($wrow['in_warehouse'] ?? 0);
            if ($wFlag === 1 && $curWh !== 1 && $cnt['warehouse'] >= (int) $lim['warehouse_capacity']) {
                echo json_encode(['ok' => false, 'error' => '仓库已满（上限 ' . (int) $lim['warehouse_capacity'] . '）']);
                break;
            }
            if ($wFlag === 0 && $curWh === 1 && $cnt['bag'] >= (int) $lim['bag_capacity']) {
                echo json_encode(['ok' => false, 'error' => '背包已满（上限 ' . (int) $lim['bag_capacity'] . '）']);
                break;
            }
            $pdo->prepare('UPDATE player_items SET in_warehouse = ? WHERE id = ? AND player_id = ?')->execute([$wFlag, $wItemId, $pid]);
            $stWh = $pdo->prepare('SELECT * FROM players WHERE id = ?');
            $stWh->execute([$pid]);
            echo json_encode(player_payload($pdo, $stWh->fetch()));
            break;

        case 'warehouse_misc_all':
            $limM = game_limits_for_player($pdo, $pid);
            $cntM = player_storage_counts($pdo, $pid);
            $freeWh = max(0, (int) $limM['warehouse_capacity'] - (int) $cntM['warehouse']);
            $movedMisc = 0;
            if ($freeWh > 0) {
                $selMisc = $pdo->prepare(
                    "SELECT id FROM player_items WHERE player_id = ? AND slot = 'misc' AND equipped = 0 AND COALESCE(in_warehouse, 0) = 0 ORDER BY id ASC LIMIT " . (int) $freeWh
                );
                $selMisc->execute([$pid]);
                $ids = array_map('intval', array_column($selMisc->fetchAll(PDO::FETCH_ASSOC) ?: [], 'id'));
                foreach ($ids as $iid) {
                    $pdo->prepare('UPDATE player_items SET in_warehouse = 1 WHERE id = ? AND player_id = ?')->execute([$iid, $pid]);
                    $movedMisc++;
                }
            }
            $stWhM = $pdo->prepare('SELECT * FROM players WHERE id = ?');
            $stWhM->execute([$pid]);
            $miscWhOut = player_payload($pdo, $stWhM->fetch());
            $miscWhOut['warehouse_misc_moved'] = $movedMisc;
            echo json_encode($miscWhOut);
            break;

        case 'warehouse_recall_all':
            $limR = game_limits_for_player($pdo, $pid);
            $cntR = player_storage_counts($pdo, $pid);
            $freeBag = max(0, (int) $limR['bag_capacity'] - (int) $cntR['bag']);
            $movedRecall = 0;
            if ($freeBag > 0) {
                $selRecall = $pdo->prepare(
                    'SELECT id FROM player_items WHERE player_id = ? AND COALESCE(in_warehouse, 0) = 1 AND equipped = 0 ORDER BY id ASC LIMIT ' . (int) $freeBag
                );
                $selRecall->execute([$pid]);
                $ids = array_map('intval', array_column($selRecall->fetchAll(PDO::FETCH_ASSOC) ?: [], 'id'));
                foreach ($ids as $iid) {
                    $pdo->prepare('UPDATE player_items SET in_warehouse = 0 WHERE id = ? AND player_id = ?')->execute([$iid, $pid]);
                    $movedRecall++;
                }
            }
            $stRecallPl = $pdo->prepare('SELECT * FROM players WHERE id = ?');
            $stRecallPl->execute([$pid]);
            $recallOut = player_payload($pdo, $stRecallPl->fetch());
            $recallOut['warehouse_recall_moved'] = $movedRecall;
            echo json_encode($recallOut);
            break;

        case 'equip':
            $itemId = (int) ($body['item_id'] ?? 0);
            if ($itemId < 1) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => '请提供有效的物品编号']);
                break;
            }
            $st = $pdo->prepare('SELECT * FROM player_items WHERE id = ? AND player_id = ? LIMIT 1');
            $st->execute([$itemId, $pid]);
            $row = $st->fetch();
            if (!$row) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => '未找到目标']);
                break;
            }
            $slot = $row['slot'];
            if ($slot === 'misc' && (string) ($row['item_key'] ?? '') === 'man_sheet_1002') {
                if ((int) ($row['in_warehouse'] ?? 0) === 1) {
                    echo json_encode(['ok' => false, 'error' => '请先将物品从仓库取出到背包再装备']);
                    break;
                }
                $pdo->prepare("UPDATE player_items SET equipped = 0 WHERE player_id = ? AND item_key = 'man_sheet_1002'")->execute([$pid]);
                $pdo->prepare('UPDATE player_items SET equipped = 1, weapon_hand = NULL WHERE id = ? AND player_id = ?')->execute([$itemId, $pid]);
                $st2 = $pdo->prepare('SELECT * FROM players WHERE id = ?');
                $st2->execute([$pid]);
                echo json_encode(player_payload($pdo, $st2->fetch()));
                break;
            }
            if (!in_array($slot, ['weapon', 'armor', 'ring', 'boots'], true)) {
                echo json_encode(['ok' => false, 'error' => '杂物无法装备，仅武器、护甲、戒指、鞋可装备']);
                break;
            }
            if ((int) ($row['in_warehouse'] ?? 0) === 1) {
                echo json_encode(['ok' => false, 'error' => '请先将物品从仓库取出到背包再装备']);
                break;
            }
            $hand = strtolower(trim((string) ($body['hand'] ?? 'main')));
            if ($slot === 'weapon') {
                if (!in_array($hand, ['main', 'off'], true)) {
                    $hand = 'main';
                }
                $allow = weapon_row_allow($row);
                if ($allow === 'main' && $hand !== 'main') {
                    echo json_encode(['ok' => false, 'error' => '该武器只能装备在主手（右）']);
                    break;
                }
                if ($allow === 'off' && $hand !== 'off') {
                    echo json_encode(['ok' => false, 'error' => '该武器只能装备在副手（左）']);
                    break;
                }
                if ($hand === 'main') {
                    $pdo->prepare(
                        'UPDATE player_items SET equipped = 0, weapon_hand = NULL WHERE player_id = ? AND slot = ? AND equipped = 1 AND (weapon_hand = ? OR weapon_hand IS NULL)'
                    )->execute([$pid, 'weapon', 'main']);
                } else {
                    $pdo->prepare(
                        'UPDATE player_items SET equipped = 0, weapon_hand = NULL WHERE player_id = ? AND slot = ? AND equipped = 1 AND weapon_hand = ?'
                    )->execute([$pid, 'weapon', 'off']);
                }
                $pdo->prepare(
                    'UPDATE player_items SET equipped = 1, weapon_hand = ? WHERE id = ? AND player_id = ?'
                )->execute([$hand, $itemId, $pid]);
            } else {
                $pdo->prepare('UPDATE player_items SET equipped = 0, weapon_hand = NULL WHERE player_id = ? AND slot = ?')->execute([$pid, $slot]);
                $pdo->prepare('UPDATE player_items SET equipped = 1, weapon_hand = NULL WHERE id = ? AND player_id = ?')->execute([$itemId, $pid]);
            }
            $st2 = $pdo->prepare('SELECT * FROM players WHERE id = ?');
            $st2->execute([$pid]);
            echo json_encode(player_payload($pdo, $st2->fetch()));
            break;

        case 'unequip':
            $itemId = (int) ($body['item_id'] ?? 0);
            if ($itemId < 1) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => '请提供有效的物品编号']);
                break;
            }
            $st = $pdo->prepare('SELECT * FROM player_items WHERE id = ? AND player_id = ? LIMIT 1');
            $st->execute([$itemId, $pid]);
            $row = $st->fetch();
            if (!$row) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => '未找到目标']);
                break;
            }
            if ((int) $row['equipped'] !== 1) {
                echo json_encode(['ok' => false, 'error' => '该物品未装备']);
                break;
            }
            $slot = $row['slot'];
            if ($slot === 'misc' && (string) ($row['item_key'] ?? '') === 'man_sheet_1002') {
                $pdo->prepare('UPDATE player_items SET equipped = 0, weapon_hand = NULL WHERE id = ? AND player_id = ?')->execute([$itemId, $pid]);
                $st2 = $pdo->prepare('SELECT * FROM players WHERE id = ?');
                $st2->execute([$pid]);
                echo json_encode(player_payload($pdo, $st2->fetch()));
                break;
            }
            if (!in_array($slot, ['weapon', 'armor', 'ring', 'boots'], true)) {
                echo json_encode(['ok' => false, 'error' => '无法卸下该类型物品']);
                break;
            }
            $pdo->prepare('UPDATE player_items SET equipped = 0, weapon_hand = NULL WHERE id = ? AND player_id = ?')->execute([$itemId, $pid]);
            $st2 = $pdo->prepare('SELECT * FROM players WHERE id = ?');
            $st2->execute([$pid]);
            echo json_encode(player_payload($pdo, $st2->fetch()));
            break;

        case 'sell':
            $itemId = (int) ($body['item_id'] ?? 0);
            if ($itemId < 1) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => '请提供有效的物品编号']);
                break;
            }
            $st = $pdo->prepare('SELECT * FROM player_items WHERE id = ? AND player_id = ? LIMIT 1');
            $st->execute([$itemId, $pid]);
            $row = $st->fetch();
            if (!$row) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => '未找到目标']);
                break;
            }
            if ((int) $row['equipped'] === 1) {
                echo json_encode(['ok' => false, 'error' => '请先卸下该装备再出售或上架']);
                break;
            }
            if ((int) ($row['in_warehouse'] ?? 0) === 1) {
                echo json_encode(['ok' => false, 'error' => '仓库中的物品请先取出到背包再出售']);
                break;
            }
            if (player_item_cannot_sell_or_auction($row)) {
                echo json_encode(['ok' => false, 'error' => '该物品不可出售']);
                break;
            }
            $pay = shop_sell_price($row);
            $pdo->prepare('DELETE FROM player_items WHERE id = ? AND player_id = ?')->execute([$itemId, $pid]);
            $pdo->prepare('UPDATE players SET gold = gold + ? WHERE id = ?')->execute([$pay, $pid]);
            $st2 = $pdo->prepare('SELECT * FROM players WHERE id = ?');
            $st2->execute([$pid]);
            $out = player_payload($pdo, $st2->fetch());
            $out['sold_for'] = $pay;
            echo json_encode($out);
            break;

        case 'shop_buy_bonfire_blade':
            $pdo->beginTransaction();
            $stP = $pdo->prepare('SELECT * FROM players WHERE id = ? FOR UPDATE');
            $stP->execute([$pid]);
            $plBuy = $stP->fetch();
            if (!$plBuy) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => '角色不存在']);
                break;
            }
            $invBuy = fetch_inventory($pdo, $pid);
            $snapBuy = compute_combat_snapshot($pdo, $plBuy, $invBuy);
            $lvlBuy = max(1, (int) ($snapBuy['level'] ?? 1));
            $priceBuy = $lvlBuy * 10;
            $cntSt = $pdo->prepare("SELECT COUNT(*) FROM player_items WHERE player_id = ? AND item_key = 'bonfire_blade'");
            $cntSt->execute([$pid]);
            $haveBlades = (int) $cntSt->fetchColumn();
            if ($haveBlades >= 3) {
                $pdo->rollBack();
                echo json_encode(['ok' => false, 'error' => '篝火长剑最多持有 3 把']);
                break;
            }
            if ((int) $plBuy['gold'] < $priceBuy) {
                $pdo->rollBack();
                echo json_encode(['ok' => false, 'error' => '金币不足（需要 ' . $priceBuy . ' 金币）']);
                break;
            }
            $tplBon = null;
            foreach (load_item_templates() as $t) {
                if (($t['id'] ?? '') === 'bonfire_blade') {
                    $tplBon = $t;
                    break;
                }
            }
            if (!$tplBon) {
                $pdo->rollBack();
                echo json_encode(['ok' => false, 'error' => '物品数据缺失']);
                break;
            }
            $pdo->prepare('UPDATE players SET gold = gold - ? WHERE id = ?')->execute([$priceBuy, $pid]);
            insert_generated_item($pdo, $pid, $tplBon);
            $pdo->commit();
            $st2 = $pdo->prepare('SELECT * FROM players WHERE id = ?');
            $st2->execute([$pid]);
            $out = player_payload($pdo, $st2->fetch());
            $out['shop_paid_gold'] = $priceBuy;
            echo json_encode($out);
            break;

        case 'shop_buy_man_sheet_1002':
            $pdo->beginTransaction();
            $stP = $pdo->prepare('SELECT * FROM players WHERE id = ? FOR UPDATE');
            $stP->execute([$pid]);
            $plBuy = $stP->fetch();
            if (!$plBuy) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => '角色不存在']);
                break;
            }
            $invBuy = fetch_inventory($pdo, $pid);
            $snapBuy = compute_combat_snapshot($pdo, $plBuy, $invBuy);
            $lvlBuy = max(1, (int) ($snapBuy['level'] ?? 1));
            $priceBuy = $lvlBuy * 100;
            $cntSt = $pdo->prepare("SELECT COUNT(*) FROM player_items WHERE player_id = ? AND item_key = 'man_sheet_1002'");
            $cntSt->execute([$pid]);
            $have = (int) $cntSt->fetchColumn();
            if ($have >= 1) {
                $pdo->rollBack();
                echo json_encode(['ok' => false, 'error' => '每位冒险者最多持有一份 MZ行走图授权']);
                break;
            }
            if ((int) $plBuy['gold'] < $priceBuy) {
                $pdo->rollBack();
                echo json_encode(['ok' => false, 'error' => '金币不足（需要 ' . $priceBuy . ' 金币）']);
                break;
            }
            $tpl = null;
            foreach (load_item_templates() as $t) {
                if (($t['id'] ?? '') === 'man_sheet_1002') {
                    $tpl = $t;
                    break;
                }
            }
            if (!$tpl) {
                $pdo->rollBack();
                echo json_encode(['ok' => false, 'error' => '物品数据缺失']);
                break;
            }
            $pdo->prepare('UPDATE players SET gold = gold - ? WHERE id = ?')->execute([$priceBuy, $pid]);
            insert_generated_item($pdo, $pid, $tpl);
            $pdo->commit();
            $st2 = $pdo->prepare('SELECT * FROM players WHERE id = ?');
            $st2->execute([$pid]);
            $out = player_payload($pdo, $st2->fetch());
            $out['shop_paid_gold'] = $priceBuy;
            echo json_encode($out);
            break;

        case 'sell_all':
            $inv = fetch_inventory($pdo, $pid);
            $toSell = array_values(array_filter($inv, static function ($r) {
                if ((int) $r['equipped'] === 1 || (int) ($r['in_warehouse'] ?? 0) === 1) {
                    return false;
                }

                return !player_item_cannot_sell_or_auction($r);
            }));
            if ($toSell === []) {
                echo json_encode(['ok' => false, 'error' => '没有可出售的背包物品（已装备需先卸下）']);
                break;
            }
            $total = 0;
            foreach ($toSell as $r) {
                $total += shop_sell_price($r);
            }
            $sellIds = [];
            foreach ($toSell as $r) {
                $iid = (int) ($r['id'] ?? 0);
                if ($iid > 0) {
                    $sellIds[] = $iid;
                }
            }
            $pdo->beginTransaction();
            foreach ($sellIds as $iid) {
                $pdo->prepare('DELETE FROM player_items WHERE id = ? AND player_id = ? AND equipped = 0 AND COALESCE(in_warehouse, 0) = 0')->execute([$iid, $pid]);
            }
            $pdo->prepare('UPDATE players SET gold = gold + ? WHERE id = ?')->execute([$total, $pid]);
            $pdo->commit();
            $st2 = $pdo->prepare('SELECT * FROM players WHERE id = ?');
            $st2->execute([$pid]);
            $out = player_payload($pdo, $st2->fetch());
            $out['sell_all_count'] = count($toSell);
            $out['sell_all_gold'] = $total;
            echo json_encode($out);
            break;

        case 'sell_all_except_equiv':
            $protKeys = protected_equiv_item_keys_for_bulk_sell($pdo);
            $inv = fetch_inventory($pdo, $pid);
            $toSellEq = array_values(array_filter($inv, static function ($r) use ($protKeys) {
                if ((int) $r['equipped'] === 1 || (int) ($r['in_warehouse'] ?? 0) === 1) {
                    return false;
                }
                if (player_item_cannot_sell_or_auction($r)) {
                    return false;
                }
                $ik = (string) ($r['item_key'] ?? '');
                if ($ik !== '' && isset($protKeys[$ik])) {
                    return false;
                }

                return true;
            }));
            if ($toSellEq === []) {
                echo json_encode(['ok' => false, 'error' => '没有可出售的物品（已装备、在仓库、等价表内或不可售物品均会跳过）']);
                break;
            }
            $totalEq = 0;
            foreach ($toSellEq as $r) {
                $totalEq += shop_sell_price($r);
            }
            $sellIdsEq = [];
            foreach ($toSellEq as $r) {
                $iid = (int) ($r['id'] ?? 0);
                if ($iid > 0) {
                    $sellIdsEq[] = $iid;
                }
            }
            $pdo->beginTransaction();
            foreach ($sellIdsEq as $iid) {
                $pdo->prepare('DELETE FROM player_items WHERE id = ? AND player_id = ? AND equipped = 0 AND COALESCE(in_warehouse, 0) = 0')->execute([$iid, $pid]);
            }
            $pdo->prepare('UPDATE players SET gold = gold + ? WHERE id = ?')->execute([$totalEq, $pid]);
            $pdo->commit();
            $st2Eq = $pdo->prepare('SELECT * FROM players WHERE id = ?');
            $st2Eq->execute([$pid]);
            $outEq = player_payload($pdo, $st2Eq->fetch());
            $outEq['sell_except_equiv_count'] = count($toSellEq);
            $outEq['sell_except_equiv_gold'] = $totalEq;
            echo json_encode($outEq);
            break;

        case 'enhance_preview':
            $itemId = (int) ($body['item_id'] ?? 0);
            if ($itemId < 1) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => '请提供有效的物品编号']);
                break;
            }
            $st = $pdo->prepare('SELECT * FROM player_items WHERE id = ? AND player_id = ? LIMIT 1');
            $st->execute([$itemId, $pid]);
            $row = $st->fetch();
            if (!$row) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => '未找到目标']);
                break;
            }
            if (!in_array($row['slot'], ['weapon', 'armor', 'ring', 'boots'], true)) {
                echo json_encode(['ok' => false, 'error' => '仅武器、护甲、戒指、鞋可强化']);
                break;
            }
            if ((int) ($row['in_warehouse'] ?? 0) === 1) {
                echo json_encode(['ok' => false, 'error' => '仓库中的物品请先取出再强化']);
                break;
            }
            $pl = (int) ($row['plus_level'] ?? 0);
            if ($pl >= 20) {
                echo json_encode(['ok' => false, 'error' => '该装备已达最高强化 +20']);
                break;
            }
            $next = $pl + 1;
            $dice = (string) ($row['damage_dice'] ?? '1d4');
            $denom = enhance_success_denominator($next, $dice);
            $cost = enhance_gold_cost($pl);
            echo json_encode([
                'ok' => true,
                'current_plus' => $pl,
                'next_plus' => $next,
                'gold_cost' => $cost,
                'success_denom' => $denom,
                'chance_percent' => (int) floor(100 / $denom),
                'dice_difficulty' => damage_dice_difficulty_mult($dice),
            ]);
            break;

        case 'enhance':
            $itemId = (int) ($body['item_id'] ?? 0);
            if ($itemId < 1) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => '请提供有效的物品编号']);
                break;
            }
            $st = $pdo->prepare('SELECT * FROM player_items WHERE id = ? AND player_id = ? LIMIT 1');
            $st->execute([$itemId, $pid]);
            $row = $st->fetch();
            if (!$row) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => '未找到目标']);
                break;
            }
            if (!in_array($row['slot'], ['weapon', 'armor', 'ring', 'boots'], true)) {
                echo json_encode(['ok' => false, 'error' => '仅武器、护甲、戒指、鞋可强化']);
                break;
            }
            if ((int) ($row['in_warehouse'] ?? 0) === 1) {
                echo json_encode(['ok' => false, 'error' => '仓库中的物品请先取出再强化']);
                break;
            }
            $pl = (int) ($row['plus_level'] ?? 0);
            if ($pl >= 20) {
                echo json_encode(['ok' => false, 'error' => '该装备已达最高强化 +20']);
                break;
            }
            $next = $pl + 1;
            $dice = (string) ($row['damage_dice'] ?? '1d4');
            $denom = enhance_success_denominator($next, $dice);
            $cost = enhance_gold_cost($pl);
            $gold = (int) $playerRow['gold'];
            if ($gold < $cost) {
                echo json_encode(['ok' => false, 'error' => '金币不足（需要 ' . $cost . '）']);
                break;
            }
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE players SET gold = gold - ? WHERE id = ?')->execute([$cost, $pid]);
            $roll = random_int(1, $denom);
            if ($roll !== 1) {
                $pdo->commit();
                $st2 = $pdo->prepare('SELECT * FROM players WHERE id = ?');
                $st2->execute([$pid]);
                $pay = player_payload($pdo, $st2->fetch());
                echo json_encode([
                    'ok' => true,
                    'enhance_failed' => true,
                    'gold_spent' => $cost,
                    'message' => '强化失败，已消耗 ' . $cost . ' 金币（本次成功率约 ' . (int) floor(100 / $denom) . '%）',
                    'chance_percent' => (int) floor(100 / $denom),
                    'player' => $pay['player'],
                    'inventory' => $pay['inventory'],
                ]);
                break;
            }
            $desc = (string) ($row['item_desc'] ?? '');
            $who = (string) ($_SESSION['username'] ?? '');
            if ($who === '') {
                $who = (string) ($playerRow['display_name'] ?? '冒险者');
            }
            if (($row['slot'] ?? '') === 'weapon' && $next > 0 && $next % 5 === 0) {
                $desc = append_weapon_enhance_lore($desc, $who, $next);
            }
            if ($next > 10) {
                $desc = append_legendary_lore_once($desc);
            }
            $rc = item_rank_checksum(array_merge($row, ['plus_level' => $next]));
            $pdo->prepare('UPDATE player_items SET plus_level = ?, item_desc = ?, rank_checksum = ? WHERE id = ? AND player_id = ?')->execute([$next, $desc, $rc, $itemId, $pid]);
            $pdo->commit();
            $st2 = $pdo->prepare('SELECT * FROM players WHERE id = ?');
            $st2->execute([$pid]);
            $out = player_payload($pdo, $st2->fetch());
            $out['enhance'] = ['success' => true, 'plus_level' => $next, 'gold_spent' => $cost];
            echo json_encode($out);
            break;

        case 'skill_enhance_preview':
            $skKey = trim((string) ($body['skill_key'] ?? ''));
            if ($skKey === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => '请指定技能']);
                break;
            }
            $prev = skill_enhance_preview($pdo, $pid, $skKey, (int) ($playerRow['level_cached'] ?? 1));
            echo json_encode($prev);
            break;

        case 'skill_enhance':
            $skKey = trim((string) ($body['skill_key'] ?? ''));
            if ($skKey === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => '请指定技能']);
                break;
            }
            $res = skill_enhance_commit(
                $pdo,
                $pid,
                $skKey,
                (int) $playerRow['gold'],
                (int) ($playerRow['level_cached'] ?? 1)
            );
            if (empty($res['ok'])) {
                echo json_encode($res);
                break;
            }
            $st2 = $pdo->prepare('SELECT * FROM players WHERE id = ?');
            $st2->execute([$pid]);
            $out = player_payload($pdo, $st2->fetch());
            $out['skill_enhance'] = $res;
            if (!empty($res['message'])) {
                $out['message'] = (string) $res['message'];
            }
            echo json_encode($out);
            break;

        case 'sell_all_preview':
            $inv = fetch_inventory($pdo, $pid);
            $toSell = array_values(array_filter($inv, static function ($r) {
                if ((int) $r['equipped'] === 1 || (int) ($r['in_warehouse'] ?? 0) === 1) {
                    return false;
                }

                return !player_item_cannot_sell_or_auction($r);
            }));
            $total = 0;
            foreach ($toSell as $r) {
                $total += shop_sell_price($r);
            }
            echo json_encode([
                'ok' => true,
                'count' => count($toSell),
                'total_gold' => $total,
            ]);
            break;

        case 'sell_all_except_equiv_preview':
            $protKeysPv = protected_equiv_item_keys_for_bulk_sell($pdo);
            $inv = fetch_inventory($pdo, $pid);
            $toSellPv = array_values(array_filter($inv, static function ($r) use ($protKeysPv) {
                if ((int) $r['equipped'] === 1 || (int) ($r['in_warehouse'] ?? 0) === 1) {
                    return false;
                }
                if (player_item_cannot_sell_or_auction($r)) {
                    return false;
                }
                $ik = (string) ($r['item_key'] ?? '');
                if ($ik !== '' && isset($protKeysPv[$ik])) {
                    return false;
                }

                return true;
            }));
            $totalPv = 0;
            foreach ($toSellPv as $r) {
                $totalPv += shop_sell_price($r);
            }
            echo json_encode([
                'ok' => true,
                'count' => count($toSellPv),
                'total_gold' => $totalPv,
            ]);
            break;

        case 'auction_list':
            $aucCity = max(1, (int) ($playerRow['active_city_id'] ?? 1));
            $scope = trim((string) ($body['scope'] ?? 'browse'));
            if ($scope === 'mine') {
                $st = $pdo->prepare(
                    'SELECT a.id, a.price_gold, a.item_snapshot, a.seller_id, p.display_name AS seller_name, acc.username AS seller_username
                     FROM auctions a
                     JOIN players p ON p.id = a.seller_id
                     JOIN accounts acc ON acc.id = p.user_id
                     WHERE a.city_id = ? AND a.seller_id = ?
                     ORDER BY a.id DESC
                     LIMIT 500'
                );
                $st->execute([$aucCity, $pid]);
                $listings = $st->fetchAll();
                foreach ($listings as &$L) {
                    [, $net] = auction_sale_fee_and_net((int) ($L['price_gold'] ?? 0));
                    $L['seller_receives_gold'] = $net;
                }
                unset($L);
                $n = count($listings);
                echo json_encode([
                    'ok' => true,
                    'scope' => 'mine',
                    'listings' => $listings,
                    'total' => $n,
                    'page' => 1,
                    'page_size' => $n,
                    'total_pages' => 1,
                    'auction_fee_bps' => auction_sale_fee_bps(),
                ]);
                break;
            }

            $page = max(1, (int) ($body['page'] ?? 1));
            $pageSize = min(50, max(5, (int) ($body['page_size'] ?? 20)));
            $offset = ($page - 1) * $pageSize;

            $slot = trim((string) ($body['slot'] ?? ''));
            $allowedSlots = ['weapon', 'armor', 'ring', 'boots', 'misc'];
            $slotOk = $slot !== '' && in_array($slot, $allowedSlots, true);

            $qRaw = trim((string) ($body['q'] ?? ''));
            $qNorm = $qRaw !== '' ? mb_strtolower($qRaw, 'UTF-8') : '';

            $where = ['a.city_id = ?', 'a.seller_id <> ?'];
            $params = [$aucCity, $pid];
            if ($slotOk) {
                $where[] = 'JSON_UNQUOTE(JSON_EXTRACT(a.item_snapshot, \'$.slot\')) = ?';
                $params[] = $slot;
            }
            if ($qNorm !== '') {
                $where[] = '(LOWER(JSON_UNQUOTE(JSON_EXTRACT(a.item_snapshot, \'$.label\'))) LIKE ? OR LOWER(JSON_UNQUOTE(JSON_EXTRACT(a.item_snapshot, \'$.item_key\'))) LIKE ?)';
                $esc = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $qNorm);
                $like = '%' . $esc . '%';
                $params[] = $like;
                $params[] = $like;
            }
            $whereSql = implode(' AND ', $where);

            $cntSt = $pdo->prepare('SELECT COUNT(*) FROM auctions a WHERE ' . $whereSql);
            $cntSt->execute($params);
            $total = (int) $cntSt->fetchColumn();
            $totalPages = $total > 0 ? (int) ceil($total / $pageSize) : 0;
            if ($totalPages > 0 && $page > $totalPages) {
                $page = $totalPages;
                $offset = ($page - 1) * $pageSize;
            }

            $sql =
                'SELECT a.id, a.price_gold, a.item_snapshot, a.seller_id, p.display_name AS seller_name, acc.username AS seller_username
                 FROM auctions a
                 JOIN players p ON p.id = a.seller_id
                 JOIN accounts acc ON acc.id = p.user_id
                 WHERE ' . $whereSql . ' ORDER BY a.id DESC LIMIT ' . (int) $pageSize . ' OFFSET ' . (int) $offset;
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $listings = $st->fetchAll();
            foreach ($listings as &$L) {
                [, $net] = auction_sale_fee_and_net((int) ($L['price_gold'] ?? 0));
                $L['seller_receives_gold'] = $net;
            }
            unset($L);
            echo json_encode([
                'ok' => true,
                'scope' => 'browse',
                'listings' => $listings,
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
                'total_pages' => $totalPages,
                'auction_fee_bps' => auction_sale_fee_bps(),
            ]);
            break;

        case 'auction_cancel':
            $aid = (int) ($body['auction_id'] ?? 0);
            if ($aid < 1) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => '拍卖编号无效']);
                break;
            }
            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT * FROM auctions WHERE id = ? FOR UPDATE');
            $st->execute([$aid]);
            $auc = $st->fetch();
            if (!$auc) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => '未找到该拍卖']);
                break;
            }
            if ((int) $auc['seller_id'] !== $pid) {
                $pdo->rollBack();
                echo json_encode(['ok' => false, 'error' => '只能下架自己上架的物品']);
                break;
            }
            $snap = json_decode((string) $auc['item_snapshot'], true);
            if (!is_array($snap)) {
                $pdo->rollBack();
                echo json_encode(['ok' => false, 'error' => '拍卖物品数据损坏']);
                break;
            }
            $pdo->prepare('DELETE FROM auctions WHERE id = ?')->execute([$aid]);
            auction_restore_player_item_from_snapshot($pdo, $pid, $snap);
            $pdo->commit();
            $st2 = $pdo->prepare('SELECT * FROM players WHERE id = ?');
            $st2->execute([$pid]);
            echo json_encode(player_payload($pdo, $st2->fetch()));
            break;

        case 'auction_cancel_all':
            $aucCity = max(1, (int) ($playerRow['active_city_id'] ?? 1));
            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT * FROM auctions WHERE seller_id = ? AND city_id = ? FOR UPDATE');
            $st->execute([$pid, $aucCity]);
            $rows = $st->fetchAll();
            $cancelAllOk = true;
            foreach ($rows as $auc) {
                $snap = json_decode((string) $auc['item_snapshot'], true);
                if (!is_array($snap)) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'error' => '拍卖物品数据损坏']);
                    $cancelAllOk = false;
                    break;
                }
                $pdo->prepare('DELETE FROM auctions WHERE id = ?')->execute([(int) $auc['id']]);
                auction_restore_player_item_from_snapshot($pdo, $pid, $snap);
            }
            if (!$cancelAllOk) {
                break;
            }
            $pdo->commit();
            $st2 = $pdo->prepare('SELECT * FROM players WHERE id = ?');
            $st2->execute([$pid]);
            $out = player_payload($pdo, $st2->fetch());
            $out['auction_cancel_all_count'] = count($rows);
            echo json_encode($out);
            break;

        case 'auction_post':
            $itemId = (int) ($body['item_id'] ?? 0);
            $price = (int) ($body['price_gold'] ?? 0);
            if ($itemId < 1 || $price < 1) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => '请填写物品与有效的金币价格']);
                break;
            }
            $st = $pdo->prepare('SELECT * FROM player_items WHERE id = ? AND player_id = ? LIMIT 1');
            $st->execute([$itemId, $pid]);
            $row = $st->fetch();
            if (!$row) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => '未找到目标']);
                break;
            }
            if ((int) $row['equipped'] === 1) {
                echo json_encode(['ok' => false, 'error' => '请先卸下该装备再出售或上架']);
                break;
            }
            if ((int) ($row['in_warehouse'] ?? 0) === 1) {
                echo json_encode(['ok' => false, 'error' => '仓库中的物品请先取出再上架拍卖']);
                break;
            }
            if (player_item_cannot_sell_or_auction($row)) {
                echo json_encode(['ok' => false, 'error' => '该物品不可上架拍卖']);
                break;
            }
            $limA = game_limits_for_player($pdo, $pid);
            $postLim = max(1, (int) ($limA['auction_post_limit'] ?? 10));
            $stCntA = $pdo->prepare('SELECT COUNT(*) FROM auctions WHERE seller_id = ?');
            $stCntA->execute([$pid]);
            $posted = (int) $stCntA->fetchColumn();
            if ($posted >= $postLim) {
                echo json_encode(['ok' => false, 'error' => '最多上架 ' . $postLim . ' 件商品']);
                break;
            }
            $minGold = shop_sell_price($row);
            if ($price < $minGold) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => '上架价不能低于商店收购价（' . $minGold . ' 金币）']);
                break;
            }
            $snap = json_encode($row, JSON_UNESCAPED_UNICODE);
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM player_items WHERE id = ? AND player_id = ?')->execute([$itemId, $pid]);
            $postCity = max(1, (int) ($playerRow['active_city_id'] ?? 1));
            $pdo->prepare('INSERT INTO auctions (seller_id, city_id, item_snapshot, price_gold) VALUES (?,?,?,?)')->execute([$pid, $postCity, $snap, $price]);
            $pdo->commit();
            echo json_encode(['ok' => true]);
            break;

        case 'auction_buy':
            $aid = (int) ($body['auction_id'] ?? 0);
            if ($aid < 1) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => '拍卖编号无效']);
                break;
            }
            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT * FROM auctions WHERE id = ? FOR UPDATE');
            $st->execute([$aid]);
            $auc = $st->fetch();
            if (!$auc) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => '未找到目标']);
                break;
            }
            if ((int) $auc['seller_id'] === $pid) {
                $pdo->rollBack();
                echo json_encode(['ok' => false, 'error' => '不能购买自己上架的物品']);
                break;
            }
            $buyerCity = max(1, (int) ($playerRow['active_city_id'] ?? 1));
            $listCity = max(1, (int) ($auc['city_id'] ?? 1));
            if ($listCity !== $buyerCity) {
                $pdo->rollBack();
                echo json_encode(['ok' => false, 'error' => '该物品上架于其他主城的拍卖行，当前主城无法购买']);
                break;
            }
            $buyer = $pdo->prepare('SELECT * FROM players WHERE id = ? FOR UPDATE');
            $buyer->execute([$pid]);
            $b = $buyer->fetch();
            $price = (int) $auc['price_gold'];
            if ((int) $b['gold'] < $price) {
                $pdo->rollBack();
                echo json_encode(['ok' => false, 'error' => '金币不足']);
                break;
            }
            $snap = json_decode((string) $auc['item_snapshot'], true);
            if (!is_array($snap)) {
                $pdo->rollBack();
                echo json_encode(['ok' => false, 'error' => '拍卖物品数据损坏']);
                break;
            }
            [, $sellerNet] = auction_sale_fee_and_net($price);
            $pdo->prepare('UPDATE players SET gold = gold - ? WHERE id = ?')->execute([$price, $pid]);
            $pdo->prepare('UPDATE players SET gold = gold + ? WHERE id = ?')->execute([$sellerNet, (int) $auc['seller_id']]);
            $pdo->prepare('DELETE FROM auctions WHERE id = ?')->execute([$aid]);
            $wa = strtolower(trim((string) ($snap['weapon_allow'] ?? 'both')));
            if (!in_array($wa, ['main', 'off', 'both'], true)) {
                $wa = 'both';
            }
            $rcBuy = item_rank_checksum([
                'damage_dice' => (string) ($snap['damage_dice'] ?? '1d4'),
                'plus_level' => (int) ($snap['plus_level'] ?? 0),
            ]);
            $ins = $pdo->prepare(
                'INSERT INTO player_items (player_id, item_key, image_num, label, slot, rarity, bonus_str, bonus_dex, bonus_con, bonus_ac, bonus_trap, damage_dice, item_desc, equipped, weapon_hand, weapon_allow, plus_level, rank_checksum, in_warehouse)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,0,NULL,?,?,?,0)'
            );
            $ins->execute([
                $pid,
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
                $rcBuy,
            ]);
            $sellerPid = (int) $auc['seller_id'];
            $pdo->commit();
            try {
                $mSt = $pdo->prepare(
                    'SELECT a.email FROM players p INNER JOIN accounts a ON a.id = p.user_id WHERE p.id = ? LIMIT 1'
                );
                $mSt->execute([$sellerPid]);
                $sellerEmail = $mSt->fetchColumn();
                $sellerEmail = is_string($sellerEmail) ? trim($sellerEmail) : '';
                if ($sellerEmail !== '' && filter_var($sellerEmail, FILTER_VALIDATE_EMAIL)) {
                    rpg_send_auction_sold_mail(
                        $cfg,
                        $sellerEmail,
                        (string) ($snap['label'] ?? '物品'),
                        $price,
                        $sellerNet
                    );
                }
            } catch (Throwable $e) {
            }
            $st2 = $pdo->prepare('SELECT * FROM players WHERE id = ?');
            $st2->execute([$pid]);
            echo json_encode(player_payload($pdo, $st2->fetch()));
            break;

        case 'leaderboard':
            backfill_rank_checksum_column($pdo);
            $lim = 10;
            $xpOrd = player_xp_leaderboard_order_sql($pdo);
            $stXp = $pdo->query(
                'SELECT p.*, acc.username FROM players p
                 INNER JOIN accounts acc ON acc.id = p.user_id
                 ORDER BY ' . $xpOrd . ', acc.username ASC LIMIT ' . $lim
            );
            $xpBoard = [];
            foreach ($stXp->fetchAll() as $pr) {
                $inv = fetch_inventory($pdo, (int) $pr['id']);
                $snap = compute_combat_snapshot($pdo, $pr, $inv);
                $xpBoard[] = leaderboard_player_row_effective($pr, $snap);
            }
            $stGold = $pdo->query(
                'SELECT p.*, acc.username FROM players p
                 INNER JOIN accounts acc ON acc.id = p.user_id
                 ORDER BY p.gold DESC, ' . $xpOrd . ', acc.username ASC LIMIT ' . $lim
            );
            $goldBoard = [];
            foreach ($stGold->fetchAll() as $pr) {
                $inv = fetch_inventory($pdo, (int) $pr['id']);
                $snap = compute_combat_snapshot($pdo, $pr, $inv);
                $goldBoard[] = leaderboard_player_row_effective($pr, $snap);
            }
            $weaponBoard = leaderboard_top_gear_by_slot($pdo, 'weapon', $lim);
            $armorBoard = leaderboard_top_gear_by_slot($pdo, 'armor', $lim);
            $ringBoard = leaderboard_top_gear_by_slot($pdo, 'ring', $lim);
            $bootsBoard = leaderboard_top_gear_by_slot($pdo, 'boots', $lim);
            $bossFirstBoard = leaderboard_world_boss_first_kills($pdo, $lim);
            echo json_encode([
                'ok' => true,
                'boards' => [
                    'xp' => $xpBoard,
                    'gold' => $goldBoard,
                    'weapon' => $weaponBoard,
                    'armor' => $armorBoard,
                    'ring' => $ringBoard,
                    'boots' => $bootsBoard,
                    'boss_first' => $bossFirstBoard,
                ],
            ]);
            break;

        case 'shop_catalog':
            echo json_encode(['ok' => true, 'items' => general_shop_catalog($pdo, $playerRow)]);
            break;

        case 'shop_buy_item':
            $ik = (string) ($body['item_key'] ?? '');
            $qty = (int) ($body['quantity'] ?? $body['qty'] ?? 1);
            $buy = general_shop_buy($pdo, $playerRow, $ik, $qty);
            if (empty($buy['ok'])) {
                http_response_code(400);
            }
            echo json_encode($buy);
            break;

        case 'skin_set_active':
            $sid = substr(preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($body['skin_id'] ?? '')), 0, 48);
            if ($sid === '' || !avatar_skins_id_valid($sid)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => '皮肤无效']);
                break;
            }
            if (!player_has_unlocked_skin($pdo, $pid, $sid)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => '尚未解锁该皮肤']);
                break;
            }
            try {
                $pdo->prepare('UPDATE players SET active_skin_id=? WHERE id=?')->execute([$sid, $pid]);
            } catch (Throwable $e) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => '无法保存皮肤选择']);
                break;
            }
            $st = $pdo->prepare('SELECT * FROM players WHERE id = ?');
            $st->execute([$pid]);
            echo json_encode(player_payload($pdo, $st->fetch(PDO::FETCH_ASSOC) ?: $playerRow));
            break;

        case 'skin_board_build_toll':
            echo json_encode(gacha_skin_board_build_toll($pdo, $playerRow, (int) ($body['cell_index'] ?? -1)));
            break;

        case 'skin_board_buyout_toll':
            echo json_encode(gacha_skin_board_buyout_toll($pdo, $playerRow, (int) ($body['cell_index'] ?? -1)));
            break;

        case 'avatar_skins_catalog':
            echo json_encode(['ok' => true, 'skins' => avatar_skins_catalog()]);
            break;

        case 'skin_board_config':
            echo json_encode(gacha_skin_board_config($pdo, $playerRow));
            break;

        case 'skin_board_roll':
            echo json_encode(gacha_skin_board_roll($pdo, $playerRow));
            break;

        case 'branch_dungeon_enter':
            $fresh = !empty($body['fresh_start']) || !empty($body['fresh']);
            echo json_encode(branch_dungeon_enter($pdo, $pid, $fresh));
            break;

        case 'branch_dungeon_save':
            $runId = (int) ($body['run_id'] ?? 0);
            if ($runId < 1) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'run_id 无效']);
                break;
            }
            echo json_encode(branch_dungeon_save_quit($pdo, $pid, $runId));
            break;

        case 'branch_dungeon_use_item':
            $runId = (int) ($body['run_id'] ?? 0);
            $ik = (string) ($body['item_key'] ?? '');
            if ($runId < 1) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'run_id 无效']);
                break;
            }
            echo json_encode(branch_dungeon_use_item($pdo, $pid, $runId, $ik));
            break;

        case 'branch_dungeon_loadout':
            echo json_encode(branch_dungeon_loadout($pdo, $pid));
            break;

        case 'branch_dungeon_start':
            $counts = $body['carry_counts'] ?? $body['item_counts'] ?? null;
            if (is_array($counts) && $counts !== []) {
                echo json_encode(branch_dungeon_start($pdo, $pid, [], $counts));
                break;
            }
            $ids = $body['item_ids'] ?? $body['items'] ?? [];
            if (!is_array($ids)) {
                $ids = [];
            }
            echo json_encode(branch_dungeon_start($pdo, $pid, $ids));
            break;

        case 'branch_dungeon_finish':
            $runId = (int) ($body['run_id'] ?? 0);
            if ($runId < 1) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'run_id 无效']);
                break;
            }
            $finishMode = (string) ($body['mode'] ?? 'claim');
            $brFin = branch_dungeon_finish_run($pdo, $pid, $runId, $finishMode);
            if (!empty($brFin['ok']) && $finishMode === 'claim') {
                daily_tasks_mark_branch_success($pdo, $pid);
            }
            echo json_encode($brFin);
            break;

        case 'surface_branch_enter':
            $fresh = !empty($body['fresh_start']) || !empty($body['fresh']);
            echo json_encode(branch_dungeon_enter($pdo, $pid, $fresh));
            break;

        case 'surface_branch_choose':
            $runId = (int) ($body['run_id'] ?? 0);
            $opt = (int) ($body['option_index'] ?? 0);
            if ($runId < 1) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'run_id 无效']);
                break;
            }
            echo json_encode(surface_branch_choose_next($pdo, $pid, $runId, $opt));
            break;

        case 'surface_branch_resolve_room':
            $runId = (int) ($body['run_id'] ?? 0);
            $strategy = (string) ($body['strategy'] ?? '');
            if ($runId < 1) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'run_id 无效']);
                break;
            }
            echo json_encode(surface_branch_resolve_room($pdo, $pid, $runId, $strategy));
            break;

        case 'battle_deck_get':
            echo json_encode(['ok' => true, 'deck' => battle_deck_payload_slice($pdo, $pid)]);
            break;

        case 'battle_deck_set':
            $ds = battle_deck_set($pdo, $pid, is_array($body) ? $body : []);
            if (empty($ds['ok'])) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => (string) ($ds['error'] ?? '保存失败')]);
                break;
            }
            echo json_encode($ds);
            break;

        case 'battle_deck_consume_potion':
            $iid = (int) ($body['item_id'] ?? 0);
            $cp = battle_deck_consume_potion($pdo, $pid, $iid);
            if (empty($cp['ok'])) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => (string) ($cp['error'] ?? '消耗失败')]);
                break;
            }
            $stD = $pdo->prepare('SELECT * FROM players WHERE id = ?');
            $stD->execute([$pid]);
            $outD = player_payload($pdo, $stD->fetch() ?: $playerRow);
            $outD['consumed_item_id'] = $iid;
            echo json_encode($outD);
            break;

        case 'pk_match':
            echo json_encode(match_pk_find_opponent($pdo, $playerRow));
            break;

        case 'pk_finish':
            $pk = match_pk_finish($pdo, $playerRow, is_array($body) ? $body : []);
            if (!empty($pk['ok']) && !empty($pk['reward']['won'])) {
                daily_tasks_mark_pk_win($pdo, $pid);
            }
            echo json_encode($pk);
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => '未知的接口操作']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '服务器错误', 'message' => $e->getMessage()]);
}
