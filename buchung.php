<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/logik.php';
require_login();
check_csrf();

$id = (int)($_GET['id'] ?? 0);
$b = $id ? row('SELECT * FROM buchungen WHERE id=?', [$id]) : null;
if ($id && !$b) redirect('buchungen.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['aktion'] ?? '') === 'loeschen' && $b) {
        q('DELETE FROM buchungen WHERE id=?', [$id]);
        flash('Buchung gelöscht.');
        redirect('buchungen.php');
    }
    $daten = [
        $_POST['datum'], round(parse_num($_POST['betrag']), 2), trim($_POST['name']), trim($_POST['typ'] ?? ''),
        trim($_POST['iban'] ?? ''), trim($_POST['zweck']), trim($_POST['notiz']),
    ];
    db()->beginTransaction();
    if ($b) {
        q('UPDATE buchungen SET datum=?, betrag=?, name=?, typ=?, iban=?, zweck=?, notiz=? WHERE id=?', [...$daten, $id]);
    } else {
        q('INSERT INTO buchungen (datum, betrag, name, typ, iban, zweck, notiz) VALUES (?,?,?,?,?,?,?)', $daten);
        $id = (int)db()->lastInsertId();
    }
    q('DELETE FROM zuordnungen WHERE buchung_id=?', [$id]);
    $summe = 0.0;
    foreach ($_POST['z'] ?? [] as $z) {
        if (($z['kostenart_id'] ?? '') === '') continue;
        $betr = round(parse_num($z['betrag']), 2);
        $summe += $betr;
        q('INSERT INTO zuordnungen (buchung_id, kostenart_id, jahr, betrag, datum, beschreibung) VALUES (?,?,?,?,?,?)',
          [$id, (int)$z['kostenart_id'], (int)$z['jahr'], $betr, $daten[0], trim($z['beschreibung'] ?? '') ?: null]);
    }
    db()->commit();
    if (!empty($_POST['z']) && abs($summe + $daten[1]) > 0.009 && $summe != 0) {
        flash('Hinweis: Die Summe der Zuordnungen (' . eur($summe) . ') entspricht nicht dem Buchungsbetrag (' . eur(-$daten[1]) . ' als Kosten).', 'warn');
    }
    if (!$b && empty(array_filter(array_column($_POST['z'] ?? [], 'kostenart_id')))) auto_zuordnen($id);
    flash('Buchung gespeichert.');
    redirect('buchung.php?id=' . $id);
}

$zuordnungen = $b ? rows('SELECT * FROM zuordnungen WHERE buchung_id=? ORDER BY id', [$id]) : [];
if (!$zuordnungen) $zuordnungen = [['kostenart_id' => null, 'jahr' => $b ? vorschlag_jahr($b) : date('Y'), 'betrag' => $b ? -$b['betrag'] : '', 'beschreibung' => '']];
$verkn = $b ? rows('SELECT id, jahr FROM abrechnungen WHERE buchung_id=?', [$id]) : [];
$verknA = $b ? rows('SELECT id, beschreibung FROM auslagen WHERE buchung_id=?', [$id]) : [];

page_head($b ? 'Buchung' : 'Neue Buchung', 'buchungen.php');
?>
<p><a href="buchungen.php">← Kontobuchungen</a></p>
<h1><?= $b ? 'Buchung bearbeiten' : 'Neue Buchung' ?></h1>
<form method="post" class="card">
  <?= csrf_field() ?>
  <div class="grid2">
    <label>Datum<input type="date" name="datum" value="<?= h($b['datum'] ?? date('Y-m-d')) ?>" required></label>
    <label>Betrag (Eingang +, Ausgang −)<input name="betrag" value="<?= $b ? num($b['betrag']) : '' ?>" required placeholder="-79,42" id="betrag"></label>
    <label>Name / Gegenpartei<input name="name" value="<?= h($b['name'] ?? '') ?>"></label>
    <label>IBAN<input name="iban" value="<?= h($b['iban'] ?? '') ?>"></label>
    <label class="span2">Verwendungszweck<input name="zweck" value="<?= h($b['zweck'] ?? '') ?>"></label>
    <label class="span2">Notiz<textarea name="notiz" rows="2"><?= h($b['notiz'] ?? '') ?></textarea></label>
  </div>
  <input type="hidden" name="typ" value="<?= h($b['typ'] ?? 'manuell') ?>">

  <h2>Zuordnung</h2>
  <p class="muted small">Kosten werden positiv eingetragen, Erstattungen negativ. Eine Buchung kann auf mehrere Kostenarten und Jahre aufgeteilt werden (z. B. eine ZWA-Rechnung in Grundgebühr/Verbrauch).</p>
  <table class="list" id="ztab">
    <thead><tr><th>Kostenart</th><th>Jahr</th><th class="r">Betrag</th><th>Beschreibung (optional)</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($zuordnungen as $i => $z): ?>
      <tr>
        <td><select name="z[<?= $i ?>][kostenart_id]"><?= kostenart_options($z['kostenart_id'] ? (int)$z['kostenart_id'] : null) ?></select></td>
        <td><input type="number" name="z[<?= $i ?>][jahr]" value="<?= h($z['jahr']) ?>" class="w5"></td>
        <td><input name="z[<?= $i ?>][betrag]" value="<?= $z['betrag'] !== '' ? num($z['betrag']) : '' ?>" class="w7 r"></td>
        <td><input name="z[<?= $i ?>][beschreibung]" value="<?= h($z['beschreibung']) ?>"></td>
        <td><button type="button" class="btn small ghost" data-del-row>×</button></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <button type="button" class="btn small ghost" data-add-row="ztab">+ Aufteilung</button>
  <div class="actions"><button class="btn">Speichern</button></div>
</form>

<?php if ($verkn || $verknA): ?>
<section class="card"><h2>Verknüpft mit</h2>
  <?php foreach ($verkn as $v): ?><p>Ausgleich der <a href="abrechnung_druck.php?id=<?= $v['id'] ?>">Abrechnung <?= $v['jahr'] ?></a></p><?php endforeach; ?>
  <?php foreach ($verknA as $v): ?><p>Erstattung der Auslage <a href="auslage.php?id=<?= $v['id'] ?>"><?= h($v['beschreibung']) ?></a></p><?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($b): ?>
<form method="post" onsubmit="return confirm('Buchung wirklich löschen?')" class="right">
  <?= csrf_field() ?><input type="hidden" name="aktion" value="loeschen">
  <button class="btn small danger">Buchung löschen</button>
</form>
<?php endif; ?>
<?php page_foot();
