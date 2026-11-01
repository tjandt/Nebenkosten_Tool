<?php
function page_head(string $title, string $aktiv = ''): void
{
    $nav = [
        'index.php' => 'Übersicht',
        'buchungen.php' => 'Kontobuchungen',
        'import.php' => 'Import',
        'versorger.php' => 'Versorger',
        'kosten.php' => 'Kosten je Jahr',
        'abrechnung.php' => 'Abrechnungen',
        'auslagen.php' => 'Auslagen',
        'vertraege.php' => 'Verträge',
        'stammdaten.php' => 'Stammdaten',
    ];
    ?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · Nebenkosten</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="top">
  <div class="brand">Nebenkosten<span>Geithainer Str. 3</span></div>
  <nav>
  <?php foreach ($nav as $url => $label): ?>
    <a href="<?= $url ?>" class="<?= $aktiv === $url ? 'on' : '' ?>"><?= h($label) ?></a>
  <?php endforeach; ?>
    <a href="login.php?abmelden=1" class="out">Abmelden</a>
  </nav>
</header>
<main>
<?php
    foreach ($_SESSION['flash'] ?? [] as [$typ, $msg]) {
        echo '<div class="flash ' . h($typ) . '">' . h($msg) . '</div>';
    }
    unset($_SESSION['flash']);
}

function page_foot(): void
{
    echo "</main>\n<script src=\"assets/app.js\"></script>\n</body></html>";
}

function kostenart_options(?int $sel = null, bool $leer = true): string
{
    static $ka = null;
    $ka ??= rows('SELECT * FROM kostenarten ORDER BY umlagefaehig DESC, sortierung, id');
    $o = $leer ? '<option value="">– nicht zugeordnet –</option>' : '';
    $grp = null;
    foreach ($ka as $k) {
        $g = $k['umlagefaehig'] ? 'Umlagefähig (Nebenkosten)' : 'Nicht umlagefähig';
        if ($g !== $grp) { if ($grp !== null) $o .= '</optgroup>'; $o .= '<optgroup label="' . h($g) . '">'; $grp = $g; }
        $label = ($k['gruppe'] ? $k['gruppe'] . ' – ' : '') . $k['bezeichnung'];
        $o .= '<option value="' . $k['id'] . '"' . ($sel === (int)$k['id'] ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    return $o . '</optgroup>';
}

function kostenart_label(array $k): string
{
    return ($k['gruppe'] ? $k['gruppe'] . ' – ' : '') . $k['bezeichnung'];
}
