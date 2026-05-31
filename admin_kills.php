<?php
/**
 * 地下城存档审计（浏览器打开 admin_kills.php，输入 config.php 里 admin.password）
 * 勿把密码提交到公开仓库；部署后立刻修改强密码。
 */
declare(strict_types=1);

session_start();

$configPath = __DIR__ . '/config.php';
if (!is_readable($configPath)) {
    exit('Missing config.php');
}
/** @var array $cfg */
$cfg = require $configPath;
$expected = (string) ($cfg['admin']['password'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $p = (string) ($_POST['admin_pass'] ?? '');
    if ($expected !== '' && hash_equals($expected, $p)) {
        $_SESSION['admin_kills_ok'] = true;
    }
}
if (isset($_GET['logout'])) {
    unset($_SESSION['admin_kills_ok']);
    header('Location: admin_kills.php');
    exit;
}

$ok = !empty($_SESSION['admin_kills_ok']);

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="zh-Hans">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>存档审计</title>
  <style>
    body { font-family: system-ui, sans-serif; background: #0d1117; color: #e6edf3; margin: 16px; }
    table { border-collapse: collapse; width: 100%; font-size: 12px; }
    th, td { border: 1px solid #30363d; padding: 6px 8px; vertical-align: top; word-break: break-all; }
    th { background: #161b22; text-align: left; }
    tr:nth-child(even) { background: #11161c; }
    form { margin-bottom: 16px; }
    input[type=password] { padding: 8px; width: 240px; }
    button { padding: 8px 14px; margin-left: 8px; }
    .meta { color: #8b949e; margin: 8px 0; }
  </style>
</head>
<body>
<h1>地下城存档审计</h1>
<p class="meta">查看 <a href="verify_stats.php" style="color:#58a6ff">verify_stats.php</a> 可筛选玩家与图表分析。</p>
<?php if (!$ok) { ?>
  <form method="post">
    <label>管理密码 <input type="password" name="admin_pass" autocomplete="current-password"></label>
    <button type="submit">登录</button>
  </form>
  <p class="meta">密码来自 config.php 的 admin.password</p>
<?php } else { ?>
  <p class="meta"><a href="?logout=1" style="color:#58a6ff">退出</a></p>
<?php
  try {
      $d = $cfg['db'];
      $dsn = 'mysql:host=' . $d['host'] . ';dbname=' . $d['name'] . ';charset=' . $d['charset'];
      $pdo = new PDO($dsn, $d['user'], $d['pass'], [
          PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
          PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      ]);
      require_once __DIR__ . '/php/dungeon/dungeon_save.php';
      dungeon_save_ensure_schema($pdo);
      $stSave = $pdo->query(
          'SELECT s.id, s.created_at, s.level_before, s.level_after, s.items_gained,
                  s.xp_granted, s.gold_granted, s.peak_floor,
                  a.username, p.display_name
           FROM dungeon_save_log s
           JOIN players p ON p.id = s.player_id
           JOIN accounts a ON a.id = p.user_id
           ORDER BY s.id DESC
           LIMIT 200'
      );
      $saveRows = $stSave->fetchAll();
      echo '<p class="meta">最近存档 ' . count($saveRows) . ' 条</p>';
      echo '<table><thead><tr>';
      echo '<th>时间</th><th>用户</th><th>等级</th><th>装备件数</th><th>XP</th><th>金</th><th>最深层</th>';
      echo '</tr></thead><tbody>';
      foreach ($saveRows as $r) {
          $lb = (int) $r['level_before'];
          $la = (int) $r['level_after'];
          echo '<tr>';
          echo '<td>' . htmlspecialchars((string) $r['created_at']) . '</td>';
          echo '<td>' . htmlspecialchars((string) $r['username']) . '</td>';
          echo '<td>' . $lb . ' → ' . $la . ' (+' . max(0, $la - $lb) . ')</td>';
          echo '<td>' . (int) $r['items_gained'] . '</td>';
          echo '<td>' . (int) $r['xp_granted'] . '</td>';
          echo '<td>' . (int) $r['gold_granted'] . '</td>';
          echo '<td>' . (int) $r['peak_floor'] . '</td>';
          echo '</tr>';
      }
      echo '</tbody></table>';
  } catch (Throwable $e) {
      echo '<p style="color:#f85149">数据库错误: ' . htmlspecialchars($e->getMessage()) . '</p>';
  }
} ?>
</body>
</html>
