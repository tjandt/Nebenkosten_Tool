<?php
require __DIR__ . '/inc/bootstrap.php';

if (isset($_GET['abmelden'])) {
    $_SESSION = [];
    session_destroy();
    redirect('login.php');
}
$fehler = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $_SESSION['versuche'] = ($_SESSION['versuche'] ?? 0) + 1;
    if ($_SESSION['versuche'] > 5) sleep(2);
    if (check_password((string)($_POST['passwort'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['login'] = true;
        $_SESSION['versuche'] = 0;
        $z = (string)($_GET['zurueck'] ?? 'index.php');
        redirect(preg_match('~^/?[\w./?=&%-]*$~', $z) && !str_contains($z, '//') ? $z : 'index.php');
    }
    $fehler = 'Passwort falsch.';
}
?><!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Anmelden · Nebenkosten</title><link rel="stylesheet" href="assets/style.css"></head>
<body class="login"><div class="card narrow">
<h1>Nebenkosten</h1>
<p class="muted">Geithainer Str. 3, Rochlitz</p>
<?php if ($fehler): ?><div class="flash err"><?= h($fehler) ?></div><?php endif; ?>
<form method="post">
  <?= csrf_field() ?>
  <label>Passwort<input type="password" name="passwort" required autofocus></label>
  <button class="btn">Anmelden</button>
</form>
</div></body></html>
