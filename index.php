<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

startSession();
requireLogin();

$db = getDB();

// ===== פילטר =====
$filterMode = $_GET['filter'] ?? 'month'; // month | quarter | year
$filterYear  = (int)($_GET['year']    ?? date('Y'));
$filterMonth = (int)($_GET['month']   ?? date('n'));
$filterQ     = (int)($_GET['quarter'] ?? (int)ceil(date('n') / 3));

// חשב טווח תאריכים לפי מצב
switch ($filterMode) {
    case 'year':
        $periodStart = "{$filterYear}-01-01";
        $periodEnd   = "{$filterYear}-12-31";
        break;
    case 'quarter':
        $qStarts = ['', '01-01', '04-01', '07-01', '10-01'];
        $qEnds   = ['', '03-31', '06-30', '09-30', '12-31'];
        $periodStart = "{$filterYear}-{$qStarts[$filterQ]}";
        $periodEnd   = "{$filterYear}-{$qEnds[$filterQ]}";
        break;
    default: // month
        $periodStart = date('Y-m-01', mktime(0,0,0,$filterMonth,1,$filterYear));
        $periodEnd   = date('Y-m-t',  mktime(0,0,0,$filterMonth,1,$filterYear));
}

// תווית תקופה
$hebrewMonths = ['','ינואר','פברואר','מרץ','אפריל','מאי','יוני','יולי','אוגוסט','ספטמבר','אוקטובר','נובמבר','דצמבר'];
switch ($filterMode) {
    case 'year':    $periodLabel = "שנת {$filterYear}"; break;
    case 'quarter': $periodLabel = "Q{$filterQ} {$filterYear}"; break;
    default:        $periodLabel = $hebrewMonths[$filterMonth] . ' ' . $filterYear;
}

$ps = $periodStart . ' 00:00:00';
$pe = $periodEnd   . ' 23:59:59';

// ===== סטטיסטיקות כלליות =====
$totalClients   = $db->query("SELECT COUNT(*) FROM clients")->fetchColumn();
$totalCourses   = $db->query("SELECT COUNT(*) FROM courses WHERE status='active'")->fetchColumn();
$pendingReports = $db->query("SELECT COUNT(*) FROM reports WHERE sent_at IS NULL AND pdf_path IS NOT NULL")->fetchColumn();

// מכירות לתקופה
$periodStats = $db->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(amount),0) as total FROM purchases WHERE purchase_date BETWEEN ? AND ?");
$periodStats->execute([$ps, $pe]);
$periodData = $periodStats->fetch();

// עמלה + נטו לתקופה
$byType = $db->prepare("
    SELECT cl.subscription_type, COALESCE(SUM(p.amount),0) as total
    FROM purchases p
    JOIN courses c ON p.course_id = c.id
    JOIN clients cl ON c.client_id = cl.id
    WHERE p.purchase_date BETWEEN ? AND ?
    GROUP BY cl.subscription_type
");
$byType->execute([$ps, $pe]);
$byTypeData = $byType->fetchAll(PDO::FETCH_KEY_PAIR);

$totalGross = (float)$periodData['total'];
$totalCommission = 0;
foreach ($byTypeData as $type => $amount) {
    $rate = subscriptionCommissionRate($type);
    $totalCommission += round($amount * $rate / 100, 2);
}
$totalNet = $totalGross - $totalCommission;

// ===== נתוני גרף — ציר X מלא עם כל הנקודות =====
function buildChartData(\PDO $db, string $mode, string $periodStart, string $periodEnd, string $ps, string $pe): array {

    if ($mode === 'month') {
        // שלוף לפי יום
        $stmt = $db->prepare("
            SELECT DATE(purchase_date) as d, COALESCE(SUM(amount),0) as total
            FROM purchases WHERE purchase_date BETWEEN ? AND ?
            GROUP BY DATE(purchase_date)
        ");
        $stmt->execute([$ps, $pe]);
        $raw = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // מלא כל ימי החודש
        $labels = []; $values = [];
        $cur = new DateTime($periodStart);
        $end = new DateTime($periodEnd);
        while ($cur <= $end) {
            $key = $cur->format('Y-m-d');
            $labels[] = $cur->format('d/m');
            $values[] = (float)($raw[$key] ?? 0);
            $cur->modify('+1 day');
        }

    } elseif ($mode === 'quarter') {
        // שלוף לפי שבוע (YEARWEEK)
        $stmt = $db->prepare("
            SELECT YEARWEEK(purchase_date,1) as yw, MIN(DATE(purchase_date)) as week_start,
                   COALESCE(SUM(amount),0) as total
            FROM purchases WHERE purchase_date BETWEEN ? AND ?
            GROUP BY YEARWEEK(purchase_date,1)
            ORDER BY yw
        ");
        $stmt->execute([$ps, $pe]);
        $raw = [];
        foreach ($stmt->fetchAll() as $r) {
            $raw[$r['yw']] = ['start' => $r['week_start'], 'total' => (float)$r['total']];
        }

        // בנה את כל השבועות בתקופה
        $labels = []; $values = [];
        $cur = new DateTime($periodStart);
        $cur->modify('Monday this week'); // התחל מהשני של השבוע
        $end = new DateTime($periodEnd);
        while ($cur <= $end) {
            $yw  = $cur->format('oW'); // ISO year+week
            $lbl = $cur->format('d/m');
            $labels[] = $lbl;
            $values[] = isset($raw[$yw]) ? $raw[$yw]['total'] : 0.0;
            $cur->modify('+1 week');
        }

    } else {
        // שנה — לפי חודשים
        $stmt = $db->prepare("
            SELECT YEAR(purchase_date) as y, MONTH(purchase_date) as m,
                   COALESCE(SUM(amount),0) as total
            FROM purchases WHERE purchase_date BETWEEN ? AND ?
            GROUP BY y, m ORDER BY y, m
        ");
        $stmt->execute([$ps, $pe]);
        $raw = [];
        foreach ($stmt->fetchAll() as $r) {
            $raw[$r['y'] . '-' . str_pad($r['m'], 2, '0', STR_PAD_LEFT)] = (float)$r['total'];
        }

        $heMonths = ['','ינו','פבר','מרץ','אפר','מאי','יוני','יולי','אוג','ספט','אוק','נוב','דצמ'];
        $labels = []; $values = [];
        $startY = (int)date('Y', strtotime($periodStart));
        $endY   = (int)date('Y', strtotime($periodEnd));
        $startM = (int)date('n', strtotime($periodStart));
        $endM   = (int)date('n', strtotime($periodEnd));
        for ($y = $startY; $y <= $endY; $y++) {
            $mFrom = ($y === $startY) ? $startM : 1;
            $mTo   = ($y === $endY)   ? $endM   : 12;
            for ($m = $mFrom; $m <= $mTo; $m++) {
                $key = $y . '-' . str_pad($m, 2, '0', STR_PAD_LEFT);
                $labels[] = $heMonths[$m] . ' ' . $y;
                $values[] = $raw[$key] ?? 0.0;
            }
        }
    }

    return ['labels' => $labels, 'values' => $values];
}

$chartResult = buildChartData($db, $filterMode, $periodStart, $periodEnd, $ps, $pe);
$chartLabels = json_encode($chartResult['labels']);
$chartValues = json_encode($chartResult['values']);
$hasChartData = array_sum($chartResult['values']) > 0;

// ===== 5 לקוחות מובילים =====
$topClients = $db->prepare("
    SELECT cl.id, cl.name, COUNT(p.id) as sales, COALESCE(SUM(p.amount),0) as total
    FROM purchases p
    JOIN courses c ON p.course_id = c.id
    JOIN clients cl ON c.client_id = cl.id
    WHERE p.purchase_date BETWEEN ? AND ?
    GROUP BY cl.id, cl.name
    ORDER BY total DESC LIMIT 5
");
$topClients->execute([$ps, $pe]);
$topClients = $topClients->fetchAll();

// ===== 5 קורסים מובילים =====
$topCourses = $db->prepare("
    SELECT c.name AS course_name, cl.name AS client_name, COUNT(p.id) as sales, COALESCE(SUM(p.amount),0) as total
    FROM purchases p
    JOIN courses c ON p.course_id = c.id
    JOIN clients cl ON c.client_id = cl.id
    WHERE p.purchase_date BETWEEN ? AND ?
    GROUP BY c.id, c.name, cl.name
    ORDER BY sales DESC LIMIT 5
");
$topCourses->execute([$ps, $pe]);
$topCourses = $topCourses->fetchAll();

// ===== 10 רכישות אחרונות =====
$recentPurchases = $db->query("
    SELECT p.*, c.name AS course_name, cl.name AS client_name, cl.id AS client_id
    FROM purchases p
    JOIN courses c ON p.course_id = c.id
    JOIN clients cl ON c.client_id = cl.id
    ORDER BY p.purchase_date DESC LIMIT 10
")->fetchAll();

$flash = getFlash();

// שנים לסינון
$years = range(date('Y'), 2024);
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= csrfToken() ?>">
  <title>דשבורד — CoursyLand Admin</title>
  <link rel="stylesheet" href="/admin/assets/style.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <style>
    .filter-bar {
      display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
      background: #fff; border: 1px solid var(--gray-200); border-radius: 10px;
      padding: 14px 18px; margin-bottom: 20px;
    }
    .filter-bar label { font-size: .85rem; color: var(--gray-500); margin-left: 4px; }
    .filter-mode-btns { display: flex; gap: 0; border: 1px solid var(--gray-200); border-radius: 8px; overflow: hidden; }
    .filter-mode-btns a {
      padding: 7px 18px; font-size: .85rem; font-weight: 600; color: var(--gray-600);
      background: #fff; text-decoration: none; border-left: 1px solid var(--gray-200);
      transition: all .15s;
    }
    .filter-mode-btns a:first-child { border-left: none; }
    .filter-mode-btns a.active { background: var(--purple); color: #fff; }
    .chart-card { background:#fff; border:1px solid var(--gray-200); border-radius:10px; padding:20px; margin-bottom:20px; }
    .chart-card h3 { margin:0 0 16px; font-size:1rem; color:var(--gray-700); }
  </style>
</head>
<body>
<div class="layout">
  <?php require_once __DIR__ . '/includes/layout.php'; renderSidebar('dashboard'); ?>
  <div class="main-content">
    <div class="topbar">
      <h2>דשבורד</h2>
      <div class="topbar-actions">
        <?php if ($pendingReports > 0): ?>
          <a href="/admin/reports/list.php?pending=1" class="btn btn-primary btn-sm">
            🔔 <?= $pendingReports ?> דוחות ממתינים
          </a>
        <?php endif; ?>
      </div>
    </div>

    <div class="page-body">
      <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?>" data-auto-dismiss><?= escape($flash['msg']) ?></div>
      <?php endif; ?>

      <?php if ($pendingReports > 0): ?>
        <div class="alert alert-warning">
          ⚠️ יש <strong><?= $pendingReports ?> דוחות</strong> מוכנים ומחכים לשליחה.
          <a href="/admin/reports/list.php?pending=1" style="margin-right:8px;">עבור לדוחות</a>
        </div>
      <?php endif; ?>

      <!-- ===== פילטר ===== -->
      <form method="GET" id="filterForm">
        <div class="filter-bar">
          <!-- מצב -->
          <div class="filter-mode-btns">
            <a href="?filter=month&year=<?= $filterYear ?>&month=<?= $filterMonth ?>"
               class="<?= $filterMode==='month' ? 'active' : '' ?>">חודש</a>
            <a href="?filter=quarter&year=<?= $filterYear ?>&quarter=<?= $filterQ ?>"
               class="<?= $filterMode==='quarter' ? 'active' : '' ?>">רבעון</a>
            <a href="?filter=year&year=<?= $filterYear ?>"
               class="<?= $filterMode==='year' ? 'active' : '' ?>">שנה</a>
          </div>

          <!-- שנה -->
          <label>שנה:</label>
          <select name="year" class="form-control" style="width:auto;" onchange="this.form.submit()">
            <?php foreach ($years as $y): ?>
              <option value="<?= $y ?>" <?= $y===$filterYear ? 'selected' : '' ?>><?= $y ?></option>
            <?php endforeach; ?>
          </select>

          <?php if ($filterMode === 'month'): ?>
            <label>חודש:</label>
            <select name="month" class="form-control" style="width:auto;" onchange="this.form.submit()">
              <?php foreach ($hebrewMonths as $num => $name):
                if ($num === 0) continue; ?>
                <option value="<?= $num ?>" <?= $num===$filterMonth ? 'selected' : '' ?>><?= $name ?></option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>

          <?php if ($filterMode === 'quarter'): ?>
            <label>רבעון:</label>
            <select name="quarter" class="form-control" style="width:auto;" onchange="this.form.submit()">
              <?php foreach ([1=>'Q1 (ינואר–מרץ)',2=>'Q2 (אפריל–יוני)',3=>'Q3 (יולי–ספטמבר)',4=>'Q4 (אוקטובר–דצמבר)'] as $q => $label): ?>
                <option value="<?= $q ?>" <?= $q===$filterQ ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>

          <input type="hidden" name="filter" value="<?= escape($filterMode) ?>">

          <span class="badge badge-purple" style="margin-right:auto;padding:6px 14px;font-size:.85rem;">
            📅 <?= escape($periodLabel) ?>
          </span>
        </div>
      </form>

      <!-- ===== כרטיסי סיכום ===== -->
      <div class="stats-grid">
        <div class="stat-card">
          <span class="label">לקוחות פעילים</span>
          <span class="value purple"><?= $totalClients ?></span>
        </div>
        <div class="stat-card">
          <span class="label">קורסים פעילים</span>
          <span class="value"><?= $totalCourses ?></span>
        </div>
        <div class="stat-card">
          <span class="label">מכירות — <?= escape($periodLabel) ?></span>
          <span class="value"><?= formatMoney($totalGross) ?></span>
          <span class="sub"><?= $periodData['cnt'] ?> עסקאות</span>
        </div>
        <div class="stat-card">
          <span class="label">עמלות CoursyLand</span>
          <span class="value purple"><?= formatMoney($totalCommission) ?></span>
        </div>
        <div class="stat-card">
          <span class="label">לתשלום לבעלי קורסים</span>
          <span class="value" style="color:var(--green)"><?= formatMoney($totalNet) ?></span>
          <span class="sub">בניכוי עמלות</span>
        </div>
        <div class="stat-card">
          <span class="label">דוחות ממתינים</span>
          <span class="value" style="color:<?= $pendingReports > 0 ? 'var(--yellow)' : 'var(--green)' ?>">
            <?= $pendingReports ?>
          </span>
        </div>
      </div>

      <!-- ===== גרף מכירות ===== -->
      <div class="chart-card">
        <h3>📊 מכירות לפי סכום — <?= escape($periodLabel) ?></h3>
        <?php if (!$hasChartData): ?>
          <div class="empty-state"><p>אין נתונים לתקופה זו</p></div>
        <?php else: ?>
          <canvas id="salesChart" height="70"></canvas>
        <?php endif; ?>
      </div>

      <!-- ===== טבלאות מובילים ===== -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">
        <div class="card">
          <div class="card-header">
            <h3>🏆 5 בעלי הקורסים המובילים</h3>
            <span class="text-muted text-small"><?= escape($periodLabel) ?></span>
          </div>
          <?php if (empty($topClients)): ?>
            <div class="empty-state"><p>אין נתונים לתקופה זו</p></div>
          <?php else: ?>
            <div class="table-wrap">
              <table>
                <thead><tr><th>#</th><th>שם</th><th>מכירות</th><th>סה"כ</th></tr></thead>
                <tbody>
                  <?php foreach ($topClients as $i => $tc): ?>
                    <tr>
                      <td style="color:var(--purple);font-weight:700;"><?= $i+1 ?></td>
                      <td><a href="/admin/clients/view.php?id=<?= $tc['id'] ?>"><?= escape($tc['name']) ?></a></td>
                      <td><?= $tc['sales'] ?></td>
                      <td><strong><?= formatMoney((float)$tc['total']) ?></strong></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

        <div class="card">
          <div class="card-header">
            <h3>📈 5 הקורסים הנמכרים ביותר</h3>
            <span class="text-muted text-small"><?= escape($periodLabel) ?></span>
          </div>
          <?php if (empty($topCourses)): ?>
            <div class="empty-state"><p>אין נתונים לתקופה זו</p></div>
          <?php else: ?>
            <div class="table-wrap">
              <table>
                <thead><tr><th>#</th><th>קורס</th><th>מכירות</th><th>סה"כ</th></tr></thead>
                <tbody>
                  <?php foreach ($topCourses as $i => $tc): ?>
                    <tr>
                      <td style="color:var(--purple);font-weight:700;"><?= $i+1 ?></td>
                      <td>
                        <div><?= escape($tc['course_name']) ?></div>
                        <div class="text-muted text-small"><?= escape($tc['client_name']) ?></div>
                      </td>
                      <td><?= $tc['sales'] ?></td>
                      <td><strong><?= formatMoney((float)$tc['total']) ?></strong></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- ===== רכישות אחרונות ===== -->
      <div class="card">
        <div class="card-header">
          <h3>10 רכישות אחרונות</h3>
          <a href="/admin/sales/dashboard.php" class="btn btn-ghost btn-sm">כל המכירות</a>
        </div>
        <?php if (empty($recentPurchases)): ?>
          <div class="empty-state"><p>אין רכישות עדיין. <a href="/admin/sales/dashboard.php">סנכרן מ-iCount</a></p></div>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead>
                <tr><th>תאריך</th><th>שם קורס</th><th>לקוח</th><th>קונה</th><th>סכום</th></tr>
              </thead>
              <tbody>
                <?php foreach ($recentPurchases as $p): ?>
                  <tr>
                    <td class="text-muted text-small"><?= formatDateTime($p['purchase_date']) ?></td>
                    <td><?= escape($p['course_name']) ?></td>
                    <td><a href="/admin/clients/view.php?id=<?= $p['client_id'] ?>"><?= escape($p['client_name']) ?></a></td>
                    <td>
                      <?= escape($p['buyer_name'] ?: '—') ?>
                      <?php if ($p['buyer_email']): ?>
                        <div class="text-muted text-small"><?= escape($p['buyer_email']) ?></div>
                      <?php endif; ?>
                    </td>
                    <td><strong><?= formatMoney((float)$p['amount']) ?></strong></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<div class="toast-container"></div>
<script src="/admin/assets/script.js"></script>
<?php if ($hasChartData): ?>
<script>
const ctx = document.getElementById('salesChart').getContext('2d');

// גרדיאנט סגול
const gradient = ctx.createLinearGradient(0, 0, 0, 300);
gradient.addColorStop(0, 'rgba(124, 58, 237, 0.25)');
gradient.addColorStop(1, 'rgba(124, 58, 237, 0.0)');

new Chart(ctx, {
  type: 'line',
  data: {
    labels: <?= $chartLabels ?>,
    datasets: [{
      label: 'מכירות (₪)',
      data: <?= $chartValues ?>,
      borderColor: 'rgba(124, 58, 237, 1)',
      borderWidth: 2.5,
      backgroundColor: gradient,
      fill: true,
      tension: 0.4,
      pointBackgroundColor: 'rgba(124, 58, 237, 1)',
      pointRadius: <?= $filterMode === 'year' ? 5 : ($filterMode === 'quarter' ? 5 : 3) ?>,
      pointHoverRadius: 7,
      pointBorderColor: '#fff',
      pointBorderWidth: 2,
    }]
  },
  options: {
    responsive: true,
    interaction: { mode: 'index', intersect: false },
    plugins: {
      legend: { display: false },
      tooltip: {
        rtl: true,
        callbacks: {
          label: ctx => ' ₪' + ctx.parsed.y.toLocaleString('he-IL', {minimumFractionDigits: 2})
        }
      }
    },
    scales: {
      y: {
        beginAtZero: true,
        ticks: { callback: val => '₪' + val.toLocaleString('he-IL') },
        grid: { color: 'rgba(0,0,0,0.05)' }
      },
      x: {
        grid: { display: false },
        ticks: {
          maxRotation: 45,
          autoSkip: true,
          maxTicksLimit: <?= $filterMode === 'month' ? 31 : ($filterMode === 'quarter' ? 14 : 12) ?>
        }
      }
    }
  }
});
</script>
<?php endif; ?>
</body>
</html>
