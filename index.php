<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

startSession();
requireLogin();

$db = getDB();

// תאריכי החודש הנוכחי
$monthStart  = date('Y-m-01');
$monthEnd    = date('Y-m-t');
$monthLabel  = date('F Y'); // שם החודש באנגלית
$hebrewMonths = ['','ינואר','פברואר','מרץ','אפריל','מאי','יוני','יולי','אוגוסט','ספטמבר','אוקטובר','נובמבר','דצמבר'];
$monthLabelHe = $hebrewMonths[(int)date('n')] . ' ' . date('Y');

// סטטיסטיקות כלליות
$totalClients = $db->query("SELECT COUNT(*) FROM clients")->fetchColumn();
$totalCourses = $db->query("SELECT COUNT(*) FROM courses WHERE status='active'")->fetchColumn();
$pendingReports = $db->query("SELECT COUNT(*) FROM reports WHERE sent_at IS NULL AND pdf_path IS NOT NULL")->fetchColumn();

// מכירות החודש
$monthStats = $db->prepare("
    SELECT COUNT(*) as cnt, COALESCE(SUM(p.amount),0) as total
    FROM purchases p
    WHERE p.purchase_date BETWEEN ? AND ?
");
$monthStats->execute([$monthStart . ' 00:00:00', $monthEnd . ' 23:59:59']);
$monthData = $monthStats->fetch();

// חישוב לפי סוג לקוח — עמלה + נטו לחודש
$monthByClient = $db->prepare("
    SELECT cl.subscription_type,
           COALESCE(SUM(p.amount),0) as total
    FROM purchases p
    JOIN courses c ON p.course_id = c.id
    JOIN clients cl ON c.client_id = cl.id
    WHERE p.purchase_date BETWEEN ? AND ?
    GROUP BY cl.subscription_type
");
$monthByClient->execute([$monthStart . ' 00:00:00', $monthEnd . ' 23:59:59']);
$byType = $monthByClient->fetchAll(PDO::FETCH_KEY_PAIR);

$totalGross    = (float)$monthData['total'];
$totalCommission = 0;
foreach ($byType as $type => $amount) {
    $rate = subscriptionCommissionRate($type);
    $totalCommission += round($amount * $rate / 100, 2);
}
$totalNet = $totalGross - $totalCommission;

// 5 לקוחות מובילים החודש
$topClients = $db->prepare("
    SELECT cl.id, cl.name, COUNT(p.id) as sales, COALESCE(SUM(p.amount),0) as total
    FROM purchases p
    JOIN courses c ON p.course_id = c.id
    JOIN clients cl ON c.client_id = cl.id
    WHERE p.purchase_date BETWEEN ? AND ?
    GROUP BY cl.id, cl.name
    ORDER BY total DESC
    LIMIT 5
");
$topClients->execute([$monthStart . ' 00:00:00', $monthEnd . ' 23:59:59']);
$topClients = $topClients->fetchAll();

// 5 קורסים נמכרים ביותר החודש
$topCourses = $db->prepare("
    SELECT c.name AS course_name, cl.name AS client_name, COUNT(p.id) as sales, COALESCE(SUM(p.amount),0) as total
    FROM purchases p
    JOIN courses c ON p.course_id = c.id
    JOIN clients cl ON c.client_id = cl.id
    WHERE p.purchase_date BETWEEN ? AND ?
    GROUP BY c.id, c.name, cl.name
    ORDER BY sales DESC
    LIMIT 5
");
$topCourses->execute([$monthStart . ' 00:00:00', $monthEnd . ' 23:59:59']);
$topCourses = $topCourses->fetchAll();

// 10 רכישות אחרונות
$recentPurchases = $db->query("
    SELECT p.*, c.name AS course_name, cl.name AS client_name, cl.id AS client_id
    FROM purchases p
    JOIN courses c ON p.course_id = c.id
    JOIN clients cl ON c.client_id = cl.id
    ORDER BY p.purchase_date DESC
    LIMIT 10
")->fetchAll();

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= csrfToken() ?>">
  <title>דשבורד — CoursyLand Admin</title>
  <link rel="stylesheet" href="/admin/assets/style.css">
</head>
<body>
<div class="layout">
  <?php require_once __DIR__ . '/includes/layout.php'; renderSidebar('dashboard'); ?>
  <div class="main-content">
    <div class="topbar">
      <h2>דשבורד</h2>
      <div class="topbar-actions">
        <span class="badge badge-purple" style="font-size:.9rem;padding:6px 14px;">📅 <?= $monthLabelHe ?></span>
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

      <!-- כרטיסי סיכום -->
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
          <span class="label">מכירות <?= $monthLabelHe ?></span>
          <span class="value"><?= formatMoney($totalGross) ?></span>
          <span class="sub"><?= $monthData['cnt'] ?> עסקאות</span>
        </div>
        <div class="stat-card">
          <span class="label">עמלות <?= $monthLabelHe ?></span>
          <span class="value purple"><?= formatMoney($totalCommission) ?></span>
          <span class="sub">הכנסת CoursyLand</span>
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

      <!-- טבלאות מובילים -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">

        <!-- 5 לקוחות מובילים -->
        <div class="card">
          <div class="card-header">
            <h3>🏆 5 בעלי הקורסים המובילים</h3>
            <span class="text-muted text-small"><?= $monthLabelHe ?></span>
          </div>
          <?php if (empty($topClients)): ?>
            <div class="empty-state"><p>אין נתונים החודש</p></div>
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

        <!-- 5 קורסים נמכרים ביותר -->
        <div class="card">
          <div class="card-header">
            <h3>📈 5 הקורסים הנמכרים ביותר</h3>
            <span class="text-muted text-small"><?= $monthLabelHe ?></span>
          </div>
          <?php if (empty($topCourses)): ?>
            <div class="empty-state"><p>אין נתונים החודש</p></div>
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

      <!-- רכישות אחרונות -->
      <div class="card">
        <div class="card-header">
          <h3>10 רכישות אחרונות</h3>
          <a href="/admin/sales/dashboard.php" class="btn btn-ghost btn-sm">כל המכירות</a>
        </div>
        <?php if (empty($recentPurchases)): ?>
          <div class="empty-state">
            <p>אין רכישות עדיין. <a href="/admin/sales/dashboard.php">סנכרן מ-iCount</a></p>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>תאריך</th>
                  <th>שם קורס</th>
                  <th>לקוח</th>
                  <th>קונה</th>
                  <th>סכום</th>
                </tr>
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
</body>
</html>
