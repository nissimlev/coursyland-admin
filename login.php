<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

startSession();

if (isLoggedIn()) {
    redirect('/admin/index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    if (login($password)) {
        redirect('/admin/index.php');
    } else {
        $error = 'סיסמה שגויה. נסה שוב.';
    }
}
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>כניסה — CoursyLand Admin</title>
  <link rel="stylesheet" href="/admin/assets/style.css">
</head>
<body>
<div class="login-page">
  <div class="login-card">
    <div class="logo">
      <h1>CoursyLand</h1>
      <p>מערכת ניהול פנימית</p>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-error" data-auto-dismiss>
        <?= escape($error) ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="">
      <div class="form-group">
        <label for="password">סיסמה</label>
        <div class="input-group">
          <input
            type="password"
            id="password"
            name="password"
            class="form-control"
            autocomplete="current-password"
            autofocus
            required
            placeholder="הזן סיסמה"
          >
          <button type="button" class="input-icon" data-toggle-password="password" title="הצג/הסתר סיסמה">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
            </svg>
          </button>
        </div>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:12px;">
        כניסה
      </button>
    </form>
  </div>
</div>
<script src="/admin/assets/script.js"></script>
</body>
</html>
