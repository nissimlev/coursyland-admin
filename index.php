<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

startSession();
requireLogin();

$db = getDB();

// סטטיסטיקות
$totalClients = $db->query("SELECT COUNT(*) FROM clients")->fetchColumn();
$totalCourses = $db->query("SELECT COUNT(*) FROM courses WHERE status='active'")->fetchColumn();

$monthStart = date('Y-m-01');
$monthEnd   = date('Y-m-t');
$monthStats = $db->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(amount),0) as total FROM purchases WHERE purchase_date BETWEEN ? AND ?");
$monthStats->execute([$monthStart . ' 00:00:00', $monthEnd . ' 23:59:59']);
$monthData = $monthStats->fetch();

$pendingReports = $db->query("SELECT COUNT(*) FROM reports WHERE sent_at IS NULL AND pdf_path IS NOT NULL")->fetchColumn();

// 10 רכישות אחרונות
$recentPurchases = $db->query("
  SELECT p.*, c.name AS course_name, cl.name AS client_name
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
          <span class="label">מכירות החודש</span>
          <span class="value"><?= formatMoney((float)$monthData['total']) ?></span>
          <span class="sub"><?= $monthData['cnt'] ?> עסקאות</span>
        </div>
        <div class="stat-card">
          <span class="label">דוחות ממתינים</span>
          <span class="value" style="color:<?= $pendingReports > 0 ? 'var(--yellow)' : 'var(--green)' ?>">
            <?= $pendingReports ?>
          </span>
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
                    <td>
                      <a href="/admin/clients/view.php?id=<?= $p['client_id'] ?? '' ?>">
                        <?= escape($p['client_name']) ?>
                      </a>
                    </td>
                    <td>
                      <?= escape($p['buyer_name']) ?>
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
