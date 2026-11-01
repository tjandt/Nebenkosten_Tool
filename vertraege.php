<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();
check_csrf();

$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$neu = isset($_GET['neu']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $f = fn($k) => trim((string)($_POST[$k] ?? ''));
    $daten = [
        (int)$_POST['objekt_id'], $f('mieter_name'), $f('mieter_strasse'), $f('mieter_plz'), $f('mieter_ort'),
        $f('mieter_iban'), $f('mieter_bank'), $f('einheit'), parse_num($f('wohnflaeche')), max(1, (int)$f('personen')),
        $f('anteil_prozent') === '' ? null : parse_num($f('anteil_prozent')),
        $f('beginn'), $f('ende') ?: null, parse_num($f('gesamtmiete')), parse_num($f('nk_vorauszahlung_jahr')),
        $f('zahler_suchtext'), $f('notiz'),
    ];
    db()->beginTransaction();
    if ($id) {
        q('UPDATE vertraege SET objekt_id=?, mieter_name=?, mieter_strasse=?, mieter_plz=?, mieter_ort=?, mieter_iban=?, mieter_bank=?, einheit=?,
           wohnflaeche=?, personen=?, anteil_prozent=?, beginn=?, ende=?, gesamtmiete=?, nk_vorauszahlung_jahr=?, zahler_suchtext=?, notiz=? WHERE id=?', [...$daten, $id]);
    } else {
        q('INSERT INTO vertraege (objekt_id, mieter_name, mieter_strasse, mieter_plz, mieter_ort, mieter_iban, mieter_bank, einheit,
           wohnflaeche, personen, anteil_prozent, beginn, ende, gesamtmiete, nk_vorauszahlung_jahr, zahler_suchtext, notiz) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', $daten);
        $id = (int)db()->lastInsertId();
    }
    q('DELETE FROM vertrag_sonderposten WHERE vertrag_id=?', [$id]);
    foreach ($_POST['sp'] ?? [] as $s) {
        if (trim($s['bezeichnung'] ?? '') === '') continue;
        q('INSERT INTO vertrag_sonderposten (vertrag_id, bezeichnung, erlaeuterung, betrag, art) VALUES (?,?,?,?,?)',
          [$id, trim($s['bezeichnung']), trim($s['erlaeuterung'] ?? ''), abs(parse_num($s['betrag'] ?? '0')), $s['art'] === 'belastung' ? 'belastung' : 'gutschrift']);
    }
    db()->commit();
    flash('Vertrag gespeichert.');
    redirect('vertraege.php?id=' . $id);
}

page_head('Verträge', 'vertraege.php');

if ($id || $neu):
    $v = $id ? row('SELECT * FROM vertraege WHERE id=?', [$id]) : ['objekt_id' => 1, 'personen' => 1, 'beginn' => date('Y-m-01')];
    $sp = $id ? rows('SELECT * FROM vertrag_sonderposten WHERE vertrag_id=? ORDER BY id', [$id]) : [];
    $sp[] = ['bezeichnung' => '', 'erlaeuterung' => '', 'betrag' => '', 'art' => 'gutschrift'];
    $objekte = rows('SELECT * FROM objekte ORDER BY bezeichnung');
    $o = row('SELECT * FROM objekte WHERE id=?', [$v['objekt_id']]);
    $eingaenge = $id && $v['zahler_suchtext'] ? rows("SELECT YEAR(datum) j, COUNT(*) n, SUM(betrag) s FROM buchungen WHERE betrag>0 AND name LIKE ? GROUP BY YEAR(datum) ORDER BY j DESC", ['%' . $v['zahler_suchtext'] . '%']) : [];
?>
<p><a href="vertraege.php">← alle Verträge</a></p>
<h1><?= $id ? h($v['mieter_name']) : 'Neuer Mietvertrag' ?></h1>
<form method="post" class="card">
  <?= csrf_field() ?>
  <h2>Mieter</h2>
  <div class="grid2">
    <label>Name (mit Anrede, z. B. „Frau Runa Rantzuch“)<input name="mieter_name" value="<?= h($v['mieter_name'] ?? '') ?>" required></label>
    <label>Objekt<select name="objekt_id"><?php foreach ($objekte as $ob): ?><option value="<?= $ob['id'] ?>" <?= $ob['id'] == $v['objekt_id'] ? 'selected' : '' ?>><?= h($ob['bezeichnung']) ?></option><?php endforeach; ?></select></label>
    <label>Straße<input name="mieter_strasse" value="<?= h($v['mieter_strasse'] ?? '') ?>"></label>
    <div class="grid2 tight"><label>PLZ<input name="mieter_plz" value="<?= h($v['mieter_plz'] ?? '') ?>"></label><label>Ort<input name="mieter_ort" value="<?= h($v['mieter_ort'] ?? '') ?>"></label></div>
    <label>IBAN (für Guthaben)<input name="mieter_iban" value="<?= h($v['mieter_iban'] ?? '') ?>"></label>
    <label>Bank<input name="mieter_bank" value="<?= h($v['mieter_bank'] ?? '') ?>"></label>
  </div>
  <h2>Mietsache &amp; Zahlungen</h2>
  <div class="grid3">
    <label>Einheit<input name="einheit" value="<?= h($v['einheit'] ?? '') ?>"></label>
    <label>Wohnfläche m²<input name="wohnflaeche" value="<?= isset($v['wohnflaeche']) ? num($v['wohnflaeche']) : '' ?>"></label>
    <label>Personen<input type="number" name="personen" value="<?= h($v['personen'] ?? 1) ?>" min="1"></label>
    <label>Flächenanteil % (leer = automatisch)<input name="anteil_prozent" value="<?= isset($v['anteil_prozent']) && $v['anteil_prozent'] !== null ? num($v['anteil_prozent'], 2) : '' ?>">
      <?php if ($o && $o['gesamtflaeche'] > 0 && !empty($v['wohnflaeche'])): ?><small class="muted">rechnerisch <?= num($v['wohnflaeche'] / $o['gesamtflaeche'] * 100, 2) ?> % von <?= num($o['gesamtflaeche']) ?> m²</small><?php endif; ?></label>
    <label>Mietbeginn<input type="date" name="beginn" value="<?= h($v['beginn'] ?? '') ?>" required></label>
    <label>Mietende<input type="date" name="ende" value="<?= h($v['ende'] ?? '') ?>"></label>
    <label>Monatliche Zahlung gesamt €<input name="gesamtmiete" value="<?= isset($v['gesamtmiete']) ? num($v['gesamtmiete']) : '' ?>"></label>
    <label>NK-Vorauszahlung pro Jahr €<input name="nk_vorauszahlung_jahr" value="<?= isset($v['nk_vorauszahlung_jahr']) ? num($v['nk_vorauszahlung_jahr']) : '' ?>">
      <?php if (!empty($v['nk_vorauszahlung_jahr'])): ?><small class="muted">= <?= num($v['nk_vorauszahlung_jahr'] / 12) ?> €/Monat · Kaltmiete ca. <?= num($v['gesamtmiete'] - $v['nk_vorauszahlung_jahr'] / 12) ?> €</small><?php endif; ?></label>
    <label>Suchtext Mieteingang<input name="zahler_suchtext" value="<?= h($v['zahler_suchtext'] ?? '') ?>" placeholder="Nachname"></label>
  </div>
  <label>Notiz<textarea name="notiz" rows="3"><?= h($v['notiz'] ?? '') ?></textarea></label>

  <h2>Sonderposten (jährlich, werden in jede Abrechnung übernommen)</h2>
  <table class="list" id="sptab">
    <thead><tr><th>Bezeichnung</th><th>Erläuterung</th><th>Art</th><th class="r">Betrag/Jahr</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($sp as $i => $s): ?>
      <tr>
        <td><input name="sp[<?= $i ?>][bezeichnung]" value="<?= h($s['bezeichnung']) ?>" placeholder="z. B. Gutschrift Hausmeister"></td>
        <td><input name="sp[<?= $i ?>][erlaeuterung]" value="<?= h($s['erlaeuterung']) ?>"></td>
        <td><select name="sp[<?= $i ?>][art]"><option value="gutschrift">Gutschrift für Mieter</option><option value="belastung" <?= $s['art'] === 'belastung' ? 'selected' : '' ?>>Belastung Mieter</option></select></td>
        <td><input name="sp[<?= $i ?>][betrag]" value="<?= $s['betrag'] !== '' ? num($s['betrag']) : '' ?>" class="w7 r"></td>
        <td><button type="button" class="btn small ghost" data-del-row>×</button></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <button type="button" class="btn small ghost" data-add-row="sptab">+ Sonderposten</button>
  <div class="actions"><button class="btn">Speichern</button></div>
</form>

<?php if ($eingaenge): ?>
<section class="card">
  <h2>Zahlungseingänge „<?= h($v['zahler_suchtext']) ?>“</h2>
  <table class="list"><thead><tr><th>Jahr</th><th class="r">Zahlungen</th><th class="r">Summe</th><th class="r">Soll (12 × <?= num($v['gesamtmiete']) ?>)</th></tr></thead><tbody>
  <?php foreach ($eingaenge as $e): ?>
    <tr><td><?= $e['j'] ?></td><td class="r"><?= $e['n'] ?></td><td class="r"><?= eur($e['s']) ?></td><td class="r muted"><?= eur($v['gesamtmiete'] * 12) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
</section>
<?php endif; ?>

<?php else:
    $liste = rows('SELECT v.*, o.bezeichnung objekt FROM vertraege v JOIN objekte o ON o.id=v.objekt_id ORDER BY (v.ende IS NULL OR v.ende>=CURDATE()) DESC, v.mieter_name'); ?>
<div class="head-row"><h1>Mietverträge</h1><a class="btn" href="?neu=1">+ Vertrag</a></div>
<table class="list">
  <thead><tr><th>Mieter</th><th>Objekt / Einheit</th><th class="r">Fläche</th><th>Laufzeit</th><th class="r">Zahlung/Monat</th><th class="r">NK-VZ/Jahr</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($liste as $v): ?>
    <tr>
      <td><a href="?id=<?= $v['id'] ?>"><b><?= h($v['mieter_name']) ?></b></a></td>
      <td><?= h($v['objekt']) ?> · <?= h($v['einheit']) ?></td>
      <td class="r"><?= num($v['wohnflaeche']) ?> m²</td>
      <td><?= dt($v['beginn']) ?> – <?= $v['ende'] ? dt($v['ende']) : 'unbefristet' ?></td>
      <td class="r"><?= eur($v['gesamtmiete']) ?></td>
      <td class="r"><?= eur($v['nk_vorauszahlung_jahr']) ?></td>
      <td><a class="btn small ghost" href="abrechnung.php?neu=1&vertrag=<?= $v['id'] ?>&jahr=<?= date('Y') - 1 ?>">Abrechnung</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif;
page_foot();
