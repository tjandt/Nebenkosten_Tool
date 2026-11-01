<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/logik.php';
require_login();
check_csrf();

$vorschau = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (($_POST['aktion'] ?? '') === 'speichern') {
            $liste = json_decode((string)$_POST['daten'], true) ?: [];
            [$neu, $doppelt, $zug] = buchungen_speichern($liste);
            flash("$neu neue Buchungen übernommen ($zug automatisch zugeordnet), $doppelt bereits vorhanden.");
            redirect('buchungen.php' . ($neu - $zug > 0 ? '?filter=offen' : ''));
        }
        if (!empty($_FILES['csv']['tmp_name'])) {
            $vorschau = parse_csv(file_get_contents($_FILES['csv']['tmp_name']));
        } elseif (trim($_POST['text'] ?? '') !== '') {
            $vorschau = parse_n26_text($_POST['text']);
        }
        if ($vorschau === []) flash('Keine Buchungen erkannt. Bitte Format prüfen.', 'err');
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
    }
}

page_head('Import', 'import.php');
?>
<h1>Kontoauszug importieren</h1>
<?php if ($vorschau): ?>
  <section class="card">
    <h2>Vorschau: <?= count($vorschau) ?> Buchungen erkannt</h2>
    <p class="muted">Bereits vorhandene Buchungen werden beim Speichern übersprungen. Die Zuordnung zu Kostenarten erfolgt automatisch über die Regeln.</p>
    <table class="list">
      <thead><tr><th>Datum</th><th>Name</th><th>Zweck</th><th class="r">Betrag</th><th>Regel → Kostenart</th></tr></thead>
      <tbody>
      <?php $ka = array_column(rows('SELECT * FROM kostenarten'), null, 'id');
      foreach ($vorschau as $b): $k = regel_kostenart($b); ?>
        <tr><td class="nowrap"><?= dt($b['datum']) ?></td><td><?= h($b['name']) ?></td><td class="small"><?= h($b['zweck']) ?></td>
            <td class="r nowrap <?= $b['betrag'] < 0 ? 'neg' : 'pos' ?>"><?= eur($b['betrag']) ?></td>
            <td class="small"><?= $k ? h(kostenart_label($ka[$k])) . ' · ' . vorschlag_jahr($b) : '<span class="muted">–</span>' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <form method="post" class="actions">
      <?= csrf_field() ?><input type="hidden" name="aktion" value="speichern">
      <input type="hidden" name="daten" value="<?= h(json_encode($vorschau, JSON_UNESCAPED_UNICODE)) ?>">
      <button class="btn">Übernehmen</button> <a href="import.php" class="btn ghost">Abbrechen</a>
    </form>
  </section>
<?php else: ?>
<div class="grid2">
  <section class="card">
    <h2>CSV-Datei</h2>
    <p>Export aus dem Online-Banking (N26: <i>Konto → Kontoauszüge → CSV</i>; auch Sparkasse/Volksbank-Formate werden erkannt).</p>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="file" name="csv" accept=".csv,text/csv" required>
      <div class="actions"><button class="btn">Vorschau</button></div>
    </form>
  </section>
  <section class="card">
    <h2>Text aus PDF-Kontoauszug</h2>
    <p>Im N26-PDF alles markieren (Strg+A), kopieren und hier einfügen.</p>
    <form method="post">
      <?= csrf_field() ?>
      <textarea name="text" rows="10" placeholder="Runa Rantzuch&#10;Gutschriften&#10;IBAN: DE73… • BIC: …&#10;Miete+Nebenkosten&#10;Wertstellung 01.10.2026&#10;01.10.2026 +201,13€"></textarea>
      <div class="actions"><button class="btn">Vorschau</button></div>
    </form>
  </section>
</div>
<p class="muted">Einzelne Zahlungen (z. B. bar bezahlte Rechnungen) kannst du unter <a href="buchung.php">Buchung manuell</a> erfassen.</p>
<?php endif; ?>
<?php page_foot();
