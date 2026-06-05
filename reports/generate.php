<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/pdf.php';

startSession();
requireLogin();

$db       = getDB();
$clientId = (int)($_GET['client_id'] ?? $_POST['client_id'] ?? 0);
$clients  = $db->query("SELECT id, name FROM clients ORDER BY name")->fetchAll();
$errors   = [];
$success  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $clientId      = (int)($_POST['client_id'] ?? 0);
    $year          = (int)($_POST['year'] ?? date('Y'));
    $quarterNumber = (int)($_POST['quarter_number'] ?? 1);
    $commRate      = (float)($_POST['commission_rate'] ?? 5.00);

    if (!$clientId)      $errors[] = 'בחר לקוח.';
    if ($year < 2020)    $errors[] = 'שנה לא תקינה.';
    if ($quarterNumber < 1 || $quarterNumber > 4) $errors[] = 'בחר רבעון תקין.';

    if (empty($errors)) {
        $client = $db->prepare("SELECT * FROM clients WHERE id=?");
        $client->execute([$clientId]);
        $client = $client->fetch();

        $dates  = quarterDates($year, $quarterNumber);
        $quarter = "{$year}-Q{$quarterNumber}";

        // בדוק אם דוח קיים
        $existingStmt = $db->prepare("SELECT id FROM reports WHERE client_id=? AND quarter=?");
        $existingStmt->execute([$clientId, $quarter]);
        $existing = $existingStmt->fetch();

        // שלוף רכישות לתקופה
        $purchasesStmt = $db->prepare("
            SELECT p.*, c.name AS course_name
            FROM purchases p
            JOIN courses c ON p.course_id = c.id
            WHERE c.client_id=?
              AND p.purchase_date BETWEEN ? AND ?
            ORDER BY p.purchase_date ASC
        ");
        $purchasesStmt->execute([$clientId, $dates['start'] . ' 00:00:00', $dates['end'] . ' 23:59:59']);
        $purchases = $purchasesStmt->fetchAll();

        $totalSales  = count($purchases);
        $totalAmount = array_sum(array_column($purchases, 'amount'));
        $commAmount  = round($totalAmount * $commRate / 100, 2);
        $netAmount   = round($totalAmount - $commAmount, 2);

        // סיכום לפי קורסים
        $courseBreakdowns = [];
        foreach ($purchases as $p) {
            $cn = $p['course_name'];
            if (!isset($courseBreakdowns[$cn])) {
                $courseBreakdowns[$cn] = ['course_name' => $cn, 'count' => 0, 'total' => 0];
            }
            $courseBreakdowns[$cn]['count']++;
            $courseBreakdowns[$cn]['total'] += $p['amount'];
        }
        $courseBreakdowns = array_values($courseBreakdowns);

        // שמור / עדכן דוח
        if ($existing) {
            $stmt = $db->prepare("UPDATE reports SET total_sales=?, total_amount=?, commission_rate=?, commission_amount=?, net_amount=?, period_start=?, period_end=? WHERE id=?");
            $stmt->execute([$totalSales, $totalAmount, $commRate, $commAmount, $netAmount, $dates['start'], $dates['end'], $existing['id']]);
            $reportId = $existing['id'];
        } else {
            $stmt = $db->prepare("INSERT INTO reports (client_id, quarter, year, quarter_number, period_start, period_end, total_sales, total_amount, commission_rate, commission_amount, net_amount) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$clientId, $quarter, $year, $quarterNumber, $dates['start'], $dates['end'], $totalSales, $totalAmount, $commRate, $commAmount, $netAmount]);
            $reportId = $db->lastInsertId();
        }

        // הפק PDF
        $reportRow = $db->prepare("SELECT * FROM reports WHERE id=?");
        $reportRow->execute([$reportId]);
        $reportRow = $reportRow->fetch();

        try {
            $pdfPath = generateReportPDF($reportRow, $client, $courseBreakdowns, $purchases);
            $db->prepare("UPDATE reports SET pdf_path=? WHERE id=?")->execute([$pdfPath, $reportId]);
            flashMessage('success', "הדוח הופק בהצלחה: {$quarter}");
            redirect("/admin/reports/view.php?id={$reportId}");
        } catch (Exception $e) {
            $errors[] = 'שגיאה בהפקת PDF: ' . $e->getMessage();
        }
    }
}

$currentQ = currentQuarter();
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>הפקת דוח — CoursyLand Admin</title>
  <link rel="stylesheet" href="/admin/assets/style.css">
</head>
<body>
<div class="layout">
  <?php require_once __DIR__ . '/../includes/layout.php'; renderSidebar('reports'); ?>
  <div class="main-content">
    <div class="topbar">
      <h2>הפקת דוח רבעוני</h2>
      <div class="topbar-actions"><a href="list.php" class="btn btn-ghost btn-sm">← חזרה</a></div>
    </div>
    <div class="page-body">
      <?php foreach ($errors as $e): ?>
        <div class="alert alert-error"><?= escape($e) ?></div>
      <?php endforeach; ?>

      <div class="card" style="max-width:540px;">
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <div class="form-group">
            <label>לקוח *</label>
            <select name="client_id" class="form-control" required>
              <option value="">בחר לקוח</option>
              <?php foreach ($clients as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $c['id'] === $clientId ? 'selected' : '' ?>><?= escape($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>שנה *</label>
              <select name="year" class="form-control">
                <?php for ($y = date('Y'); $y >= 2023; $y--): ?>
                  <option value="<?= $y ?>" <?= $y === $currentQ['year'] ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
              </select>
            </div>
            <div class="form-group">
              <label>רבעון *</label>
              <select name="quarter_number" class="form-control">
                <?php for ($q = 1; $q <= 4; $q++): ?>
                  <option value="<?= $q ?>" <?= $q === $currentQ['quarter'] ? 'selected' : '' ?>>
                    Q<?= $q ?> (<?= ['', 'ינואר–מרץ', 'אפריל–יוני', 'יולי–ספטמבר', 'אוקטובר–דצמבר'][$q] ?>)
                  </option>
                <?php endfor; ?>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label>אחוז עמלה (%)</label>
            <input type="number" step="0.01" name="commission_rate" value="5.00" class="form-control">
            <div class="text-muted text-small mt-1">5% לעוסק מורשה | 23% לעוסק פטור</div>
          </div>
          <div style="display:flex;gap:10px;">
            <button type="submit" class="btn btn-primary">הפק דוח PDF</button>
            <a href="list.php" class="btn btn-ghost">ביטול</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<script src="/admin/assets/script.js"></script>
</body>
</html>
