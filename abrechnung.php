<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/logik.php';
require __DIR__ . '/inc/abrechnung_view.php';
require_login();
check_csrf();

$a = $_POST['aktion'] ?? '';

if ($a === 'status') {
    $id = (int)$_POST['id'];
    $bid = ($_POST['buchung_id'] ?? '') !== '' ? (int)$_POST['buchung_id'] : null;
    $st = in_array($_POST['status'], ['entwurf', 'versendet', 'ausgeglichen'], true) ? $_POST['status'] : 'entwurf';
    if ($bid) $st = 'ausgeglichen';
    q('UPDATE abrechnungen SET status=?, buchung_id=? WHERE id=?', [$st, $bid, $id]);
    flash('Status gespeichert.');
    redirect('abrechnung.php');
}
if ($a === 'loeschen') {
    q('DELETE FROM abrechnungen WHERE id=?', [(int)$_POST['id']]);
    flash('Abrechnung gelöscht.');
    redirect('abrechnung.php');
}

if (isset($_REQUEST['neu'])) {
    $vertragId = (int)($_REQUEST['vertrag'] ?? 0);
    $jahr = (int)($_REQUEST['jahr'] ?? date('Y') - 1);
    $v = row('SELECT * FROM vertraege WHERE id=?', [$vertragId]);
    if (!$v) { flash('Bitte einen Vertrag wählen.', 'err'); redirect('index.php'); }

    $eingaben = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : ['belegliste' => 1];
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['belegliste'])) $eingaben['belegliste'] = 0;
    // Schlusssatz neu erzeugen, wenn er noch dem Standard entspricht und sich das Vorzeichen geändert hat
    if (isset($eingaben['schluss'], $eingaben['schluss_auto']) && $eingaben['schluss'] === $eingaben['schluss_auto']) unset($eingaben['schluss']);
    $d = berechne_abrechnung($vertragId, $jahr, $eingaben);

    if ($a === 'speichern') {
        q('INSERT INTO abrechnungen (vertrag_id, jahr, erstellt_am, daten, ergebnis, status) VALUES (?,?,?,?,?,?)',
          [$vertragId, $jahr, $d['briefdatum'] ?: date('Y-m-d'), json_encode($d, JSON_UNESCAPED_UNICODE), $d['ergebnis'], 'entwurf']);
        $nid = (int)db()->lastInsertId();
        flash("Abrechnung $jahr gespeichert.");
        redirect('abrechnung_druck.php?id=' . $nid);
    }

    $fehlend = rows('SELECT k.* FROM kostenarten k WHERE k.umlagefaehig=1 AND NOT EXISTS (SELECT 1 FROM zuordnungen z WHERE z.kostenart_id=k.id AND z.jahr=?) ORDER BY sortierung', [$jahr]);
    $vorher = rows('SELECT id, erstellt_am, ergebnis, status FROM abrechnungen WHERE vertrag_id=? AND jahr=?', [$vertragId, $jahr]);
    $vorjahrSnap = row('SELECT daten FROM abrechnungen WHERE vertrag_id=? AND jahr=? ORDER BY id DESC LIMIT 1', [$vertragId, $jahr - 1]);
    $vj = $vorjahrSnap ? abrechnung_keys(json_decode($vorjahrSnap['daten'], true)['positionen']) : [];

    page_head("Abrechnung $jahr", 'abrechnung.php');
    ?>
    <p><a href="abrechnung.php">← Abrechnungen</a></p>
    <h1>Nebenkostenabrechnung <?= $jahr ?> · <?= h($v['mieter_name']) ?></h1>
    <?php if ($vorher): ?><div class="flash warn">Für <?= $jahr ?> gibt es bereits <?= count($vorher) ?> gespeicherte Abrechnung(en) – <a href="abrechnung_druck.php?id=<?= $vorher[0]['id'] ?>">ansehen</a>. Speichern legt eine weitere Version an.</div><?php endif; ?>
    <?php if ($fehlend): ?>
      <div class="flash warn">Für <?= $jahr ?> fehlen Kosten bei: <?= h(implode(', ', array_map('kostenart_label', $fehlend))) ?>. <a href="kosten.php?jahr=<?= $jahr ?>">Kosten ergänzen</a> (z. B. ZWA-Jahresrechnung) – oder ignorieren, wenn sie nicht anfallen.</div>
    <?php endif; ?>
    <?php if ($d['monate'] < 12): ?><div class="flash warn">Mietverhältnis nur <?= $d['monate'] ?> Monate im Jahr <?= $jahr ?> – Kosten und Vorauszahlungen anteilig.</div><?php endif; ?>

    <form method="post" class="abr-form">
      <?= csrf_field() ?>
      <input type="hidden" name="neu" value="1"><input type="hidden" name="vertrag" value="<?= $vertragId ?>"><input type="hidden" name="jahr" value="<?= $jahr ?>">
      <div class="grid-abr">
        <section class="card">
          <h2>Berechnung</h2>
          <?php abrechnung_tabelle($d, true, $vj); ?>
          <p class="muted small">Gesamtkosten aus <a href="kosten.php?jahr=<?= $jahr ?>">Kosten <?= $jahr ?></a>. Flächenanteil <?= num($d['flaechen_prozent'], 2) ?> % (im <a href="vertraege.php?id=<?= $vertragId ?>">Vertrag</a> änderbar).<?= $vj ? ' Grau: Anteil laut Vorjahresabrechnung.' : '' ?></p>
        </section>
        <section class="card">
          <h2>Anpassen</h2>
          <label>Vorauszahlungen €
            <input name="vorauszahlungen" value="<?= num($d['vorauszahlungen']) ?>" class="r">
            <small class="muted">Vertrag: <?= eur($d['vorauszahlungen_vorschlag']) ?> für <?= $d['monate'] ?> Monate</small></label>
          <h3>Sonderposten</h3>
          <table class="list" id="sptab">
            <tbody>
            <?php $sp = $d['sonderposten']; $sp[] = ['bezeichnung' => '', 'erlaeuterung' => '', 'betrag' => '', 'art' => 'gutschrift'];
            foreach ($sp as $i => $s): ?>
              <tr>
                <td><input name="sonderposten[<?= $i ?>][bezeichnung]" value="<?= h($s['bezeichnung']) ?>" placeholder="Bezeichnung"><input name="sonderposten[<?= $i ?>][erlaeuterung]" value="<?= h($s['erlaeuterung']) ?>" placeholder="Erläuterung" class="small"></td>
                <td><select name="sonderposten[<?= $i ?>][art]"><option value="gutschrift">Gutschrift</option><option value="belastung" <?= $s['art'] === 'belastung' ? 'selected' : '' ?>>Belastung</option></select>
                    <input name="sonderposten[<?= $i ?>][betrag]" value="<?= $s['betrag'] !== '' ? num($s['betrag']) : '' ?>" class="w7 r"></td>
                <td><button type="button" class="btn small ghost" data-del-row>×</button></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <button type="button" class="btn small ghost" data-add-row="sptab">+ Sonderposten</button>
          <h3>Brief</h3>
          <label>Briefdatum<input type="date" name="briefdatum" value="<?= h($d['briefdatum']) ?>"></label>
          <label>Einleitung<textarea name="einleitung" rows="4"><?= h($d['einleitung']) ?></textarea></label>
          <label>Hinweise (z. B. Änderungen bei Gebühren)<textarea name="hinweise" rows="3" placeholder="z. B. Zukünftig werden vom ZWA Niederschlagswasserentgelte in Rechnung gestellt."><?= h($d['hinweise']) ?></textarea></label>
          <label>Schlusssatz<textarea name="schluss" rows="3"><?= h($d['schluss']) ?></textarea></label>
          <input type="hidden" name="schluss_auto" value="<?= h(standard_schluss($v, $d['ergebnis'])) ?>">
          <label class="chk"><input type="checkbox" name="belegliste" value="1" <?= !empty($d['belegliste']) ? 'checked' : '' ?>> Belegliste als Anlage beifügen</label>
          <div class="actions">
            <button class="btn ghost" name="aktion" value="berechnen">Neu berechnen</button>
            <button class="btn" name="aktion" value="speichern">Speichern &amp; Druckansicht</button>
          </div>
        </section>
      </div>
    </form>
    <?php
    page_foot();
    exit;
}

/* Liste */
$liste = rows('SELECT a.*, v.mieter_name, b.datum bdatum, b.betrag bbetrag FROM abrechnungen a JOIN vertraege v ON v.id=a.vertrag_id LEFT JOIN buchungen b ON b.id=a.buchung_id ORDER BY a.jahr DESC, a.id DESC');
$vertraege = rows('SELECT * FROM vertraege ORDER BY mieter_name');
$kandidaten = rows("SELECT b.id, b.datum, b.betrag, b.name, b.zweck FROM buchungen b
    LEFT JOIN zuordnungen z ON z.buchung_id=b.id WHERE z.kostenart_id=22 OR b.zweck LIKE '%Nebenkosten%' OR b.zweck LIKE '%Abrechnung%' GROUP BY b.id ORDER BY b.datum DESC");

page_head('Abrechnungen', 'abrechnung.php');
?>
<div class="head-row"><h1>Nebenkostenabrechnungen</h1></div>
<section class="card">
  <form method="get" class="inline">
    <input type="hidden" name="neu" value="1">
    <select name="vertrag"><?php foreach ($vertraege as $v): ?><option value="<?= $v['id'] ?>"><?= h($v['mieter_name']) ?></option><?php endforeach; ?></select>
    <input type="number" name="jahr" value="<?= date('Y') - 1 ?>" class="w6">
    <button class="btn">Neue Abrechnung berechnen</button>
  </form>
</section>
<table class="list">
  <thead><tr><th>Jahr</th><th>Mieter</th><th>Erstellt</th><th class="r">Ergebnis</th><th>Status / Ausgleich</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($liste as $r): $snap = json_decode($r['daten'], true); ?>
    <tr>
      <td><b><?= $r['jahr'] ?></b><?= !empty($snap['archiv']) ? ' <span class="tag">Archiv</span>' : '' ?></td>
      <td><?= h($r['mieter_name']) ?></td>
      <td><?= dt($r['erstellt_am']) ?></td>
      <td class="r nowrap <?= $r['ergebnis'] >= 0 ? 'pos' : 'neg' ?>"><?= $r['ergebnis'] >= 0 ? 'Guthaben ' : 'Nachzahlung ' ?><?= eur(abs((float)$r['ergebnis'])) ?></td>
      <td>
        <form method="post" class="inline wrap">
          <?= csrf_field() ?><input type="hidden" name="aktion" value="status"><input type="hidden" name="id" value="<?= $r['id'] ?>">
          <select name="status"><?php foreach (['entwurf', 'versendet', 'ausgeglichen'] as $s): ?><option <?= $r['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select>
          <select name="buchung_id" title="Ausgleichsbuchung"><option value="">– Ausgleichsbuchung –</option>
            <?php foreach ($kandidaten as $k): ?><option value="<?= $k['id'] ?>" <?= $r['buchung_id'] == $k['id'] ? 'selected' : '' ?>><?= dt($k['datum']) ?> <?= eur($k['betrag']) ?> <?= h(mb_strimwidth($k['zweck'], 0, 40, '…')) ?></option><?php endforeach; ?>
          </select>
          <button class="btn small ghost">✓</button>
        </form>
      </td>
      <td class="nowrap">
        <a class="btn small" href="abrechnung_druck.php?id=<?= $r['id'] ?>">Ansehen / Drucken</a>
        <form method="post" class="inline" onsubmit="return confirm('Abrechnung löschen?')"><?= csrf_field() ?><input type="hidden" name="aktion" value="loeschen"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn small danger">×</button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$liste): ?><tr><td colspan="6" class="muted">Noch keine Abrechnungen gespeichert.</td></tr><?php endif; ?>
  </tbody>
</table>
<?php page_foot();
