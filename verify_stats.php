<?php
declare(strict_types=1);

session_start();

$configPath = __DIR__ . '/config.php';
if (!is_readable($configPath)) {
    exit('Missing config.php');
}
/** @var array $cfg */
$cfg = require $configPath;
$expected = (string) ($cfg['admin']['password'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_pass'])) {
    $p = (string) ($_POST['admin_pass'] ?? '');
    if ($expected !== '' && hash_equals($expected, $p)) {
        $_SESSION['rpg_verify_stats_ok'] = true;
        header('Location: verify_stats.php');
        exit;
    }
}
if (isset($_GET['logout'])) {
    unset($_SESSION['rpg_verify_stats_ok']);
    header('Location: verify_stats.php');
    exit;
}

$ok = !empty($_SESSION['rpg_verify_stats_ok']);

if ($ok && ($_GET['data'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    if ($expected === '') {
        echo json_encode(['ok' => false, 'error' => '未配置 admin.password']);
        exit;
    }
    try {
        require_once __DIR__ . '/php/dungeon/dungeon_save.php';
        $d = $cfg['db'];
        $dsn = 'mysql:host=' . $d['host'] . ';dbname=' . $d['name'] . ';charset=' . $d['charset'];
        $pdo = new PDO($dsn, $d['user'], $d['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        dungeon_save_ensure_schema($pdo);
        $st = $pdo->query(
            'SELECT s.id, s.created_at, s.level_before, s.level_after, s.items_gained,
                    s.xp_granted, s.gold_granted, s.peak_floor, a.username
             FROM dungeon_save_log s
             INNER JOIN players p ON p.id = s.player_id
             INNER JOIN accounts a ON a.id = p.user_id
             ORDER BY s.id DESC
             LIMIT 500'
        );
        $rows = $st->fetchAll();
        echo json_encode(['ok' => true, 'rows' => $rows, 'n' => count($rows)], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="zh-Hans">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>地下城存档校验</title>
  <?php if ($ok) { ?>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <?php } ?>
  <style>
    body { font-family: system-ui, sans-serif; background: #0d1117; color: #e6edf3; margin: 16px; max-width: 1100px; }
    .meta { color: #8b949e; font-size: 14px; line-height: 1.5; margin: 10px 0 16px; }
    .toolbar { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; margin-bottom: 16px; }
    .toolbar label { font-size: 14px; color: #c9d1d9; }
    select { padding: 8px 10px; border-radius: 6px; background: #21262d; color: #e6edf3; border: 1px solid #30363d; min-width: 160px; }
    button.primary { padding: 10px 18px; cursor: pointer; background: #238636; color: #fff; border: none; border-radius: 6px; font-size: 15px; }
    button.primary:disabled { opacity: 0.5; cursor: not-allowed; }
    #err { color: #f85149; margin: 8px 0; min-height: 1.2em; }
    #brief { background: #161b22; border: 1px solid #30363d; border-radius: 8px; padding: 12px 14px; font-size: 14px; margin-bottom: 16px; line-height: 1.6; }
    .chart-wrap { background: #161b22; border: 1px solid #30363d; border-radius: 8px; padding: 14px; margin-bottom: 20px; }
    .chart-wrap h2 { margin: 0 0 10px; font-size: 15px; font-weight: 600; }
    .chart-wrap canvas { display: block; height: 260px !important; width: 100% !important; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 12px; }
    th, td { border: 1px solid #30363d; padding: 8px 10px; text-align: left; }
    th { background: #161b22; }
    tr:nth-child(even) { background: #11161c; }
    .login-card { max-width: 420px; }
    .login-card input[type=password] { padding: 8px; width: 220px; margin-left: 8px; }
    .flag { color: #f85149; font-weight: 600; }
  </style>
</head>
<body>
<?php if (!$ok) { ?>
<div class="login-card">
  <h1>地下城存档校验</h1>
  <p class="meta">需管理员密码（与 <code>config.php</code> → <code>admin.password</code> 相同）。</p>
  <form method="post">
    <label>管理密码 <input type="password" name="admin_pass" autocomplete="current-password"></label>
    <div style="margin-top:12px"><button type="submit" class="primary">登录</button></div>
  </form>
</div>
<?php } else { ?>
<h1>地下城存档校验</h1>
<p class="meta"><a href="?logout=1">退出</a> · 仅统计每次<strong>存档</strong>：该次升了多少级、获得多少件装备（不再按单次击杀记录）。可筛选玩家，查看升级与装备异常。</p>

<div class="toolbar">
  <button type="button" class="primary" id="btn-load">加载近期存档记录</button>
  <label>玩家 <select id="sel-player" disabled><option value="all">全部玩家</option></select></label>
</div>
<div id="err"></div>
<div id="brief" hidden></div>
<div class="chart-wrap">
  <h2>每次存档：升级数 &amp; 装备件数</h2>
  <canvas id="chart"></canvas>
</div>
<table id="tbl" hidden>
  <thead>
    <tr>
      <th>时间</th>
      <th>玩家</th>
      <th>等级变化</th>
      <th>装备</th>
      <th>经验</th>
      <th>金币</th>
      <th>最深到达</th>
    </tr>
  </thead>
  <tbody id="tbl-body"></tbody>
</table>

<script>
(function () {
  var rawRows = null;
  var chartInst = null;
  var LEVEL_FLAG = 3;
  var ITEM_FLAG = 8;

  function parseRow(r) {
    var lb = Number(r.level_before) || 1;
    var la = Number(r.level_after) || lb;
    var lg = Math.max(0, la - lb);
    var ig = Number(r.items_gained) || 0;
    return {
      id: Number(r.id) || 0,
      time: String(r.created_at || ''),
      username: String(r.username || '?'),
      levelBefore: lb,
      levelAfter: la,
      levelGain: lg,
      itemsGained: ig,
      xp: Number(r.xp_granted) || 0,
      gold: Number(r.gold_granted) || 0,
      peak: Number(r.peak_floor) || 1
    };
  }

  function buildPlayerOptions(rec) {
    var names = {};
    rec.forEach(function (x) { names[x.username] = 1; });
    var sel = document.getElementById('sel-player');
    sel.innerHTML = '<option value="all">全部玩家</option>';
    Object.keys(names).sort().forEach(function (u) {
      var o = document.createElement('option');
      o.value = u;
      o.textContent = u;
      sel.appendChild(o);
    });
  }

  function renderTable(filtered) {
    var tbl = document.getElementById('tbl');
    var body = document.getElementById('tbl-body');
    body.innerHTML = '';
    filtered.slice().reverse().forEach(function (x) {
      var tr = document.createElement('tr');
      var lgCls = x.levelGain >= LEVEL_FLAG ? ' class="flag"' : '';
      var igCls = x.itemsGained >= ITEM_FLAG ? ' class="flag"' : '';
      tr.innerHTML =
        '<td>' + escapeHtml(x.time) + '</td>' +
        '<td>' + escapeHtml(x.username) + '</td>' +
        '<td' + lgCls + '>' + x.levelBefore + ' → ' + x.levelAfter + '（+' + x.levelGain + '）</td>' +
        '<td' + igCls + '>' + x.itemsGained + ' 件</td>' +
        '<td>' + x.xp + '</td>' +
        '<td>' + x.gold + '</td>' +
        '<td>' + x.peak + '</td>';
      body.appendChild(tr);
    });
    tbl.hidden = !filtered.length;
  }

  function analyze() {
    var errEl = document.getElementById('err');
    var brief = document.getElementById('brief');
    errEl.textContent = '';
    if (!rawRows || !rawRows.length) {
      brief.hidden = true;
      if (chartInst) { chartInst.destroy(); chartInst = null; }
      return;
    }
    var parsed = rawRows.map(parseRow);
    parsed.reverse();
    var playerSel = document.getElementById('sel-player').value;
    var filtered = playerSel === 'all' ? parsed : parsed.filter(function (x) { return x.username === playerSel; });
    if (!filtered.length) {
      brief.hidden = false;
      brief.textContent = '当前筛选下无记录。';
      renderTable([]);
      if (chartInst) { chartInst.destroy(); chartInst = null; }
      return;
    }
    var gains = filtered.map(function (x) { return x.levelGain; });
    var items = filtered.map(function (x) { return x.itemsGained; });
    var flags = filtered.filter(function (x) {
      return x.levelGain >= LEVEL_FLAG || x.itemsGained >= ITEM_FLAG;
    });
    brief.hidden = false;
    brief.textContent =
      '存档 ' + filtered.length + ' 次 · 单次升级 ≥' + LEVEL_FLAG + ' 或装备 ≥' + ITEM_FLAG + ' 件标红（共 ' + flags.length + ' 条）';

    renderTable(filtered);

    var labels = filtered.map(function (_, i) { return String(i + 1); });
    if (chartInst) chartInst.destroy();
    chartInst = new Chart(document.getElementById('chart'), {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [
          {
            label: '升级数',
            data: gains,
            backgroundColor: 'rgba(88,166,255,0.55)',
            yAxisID: 'y'
          },
          {
            label: '装备件数',
            data: items,
            backgroundColor: 'rgba(63,185,80,0.5)',
            yAxisID: 'y1'
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          x: { ticks: { color: '#8b949e', maxTicksLimit: 20 }, grid: { color: '#30363d' } },
          y: {
            position: 'left',
            title: { display: true, text: '升级', color: '#8b949e' },
            ticks: { color: '#8b949e', stepSize: 1 },
            grid: { color: '#30363d' }
          },
          y1: {
            position: 'right',
            title: { display: true, text: '装备件数', color: '#8b949e' },
            ticks: { color: '#8b949e', stepSize: 1 },
            grid: { drawOnChartArea: false }
          }
        },
        plugins: {
          legend: { labels: { color: '#e6edf3' } },
          tooltip: {
            callbacks: {
              title: function (items) {
                if (!items.length) return '';
                var i = items[0].dataIndex;
                var x = filtered[i];
                return x ? x.username + ' · ' + x.time : '';
              }
            }
          }
        }
      }
    });
  }

  function escapeHtml(t) {
    var d = document.createElement('div');
    d.textContent = t == null ? '' : String(t);
    return d.innerHTML;
  }

  document.getElementById('btn-load').addEventListener('click', async function () {
    var btn = this;
    var errEl = document.getElementById('err');
    errEl.textContent = '';
    btn.disabled = true;
    try {
      var res = await fetch('verify_stats.php?data=1', { credentials: 'same-origin' });
      var data = await res.json().catch(function () { return {}; });
      if (!res.ok || !data.ok) {
        errEl.textContent = (data && data.error) ? data.error : '加载失败（HTTP ' + res.status + '）';
        return;
      }
      rawRows = data.rows || [];
      if (!rawRows.length) {
        errEl.textContent = '暂无存档记录。请玩家在地城内点击「存档」或「回城并存档」。';
        document.getElementById('brief').hidden = true;
        return;
      }
      var parsed = rawRows.map(parseRow);
      document.getElementById('sel-player').disabled = false;
      buildPlayerOptions(parsed);
      analyze();
    } catch (e) {
      errEl.textContent = String(e.message || e);
    } finally {
      btn.disabled = false;
    }
  });

  document.getElementById('sel-player').addEventListener('change', analyze);
})();
</script>
<?php } ?>
</body>
</html>
