<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/versorger.php';
require_login();
check_csrf();

$id = (int)($_GET['id'] ?? 0);
$d = $id ? versorger_laden($id) : null;
if (!$d) { flash('Abrechnung nicht gefunden.', 'err'); redirect('versorger.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['aktion'] ?? '';
    $f = fn($k) => trim((string)($_POST[$k] ?? ''));
    if ($a === 'speichern') {
        $zahl = fn($k) => $f($k) === '' ? null : round(parse_num($f($k)), 2);
        db()->beginTransaction();
        q('UPDATE versorger_abrechnungen SET versorgungsvertrag_id=?, jahr=?, zeitraum_von=?, zeitraum_bis=?, rechnungsdatum=?, rechnungsnummer=?, abschlaege=?, saldo=?, notiz=?, text_auszug=COALESCE(?, text_auszug) WHERE id=?',
          [$f('vertrag') === '' ? null : (int)$f('vertrag'), max(2000, (int)$f('jahr')), $f('zeitraum_von') ?: null, $f('zeitraum_bis') ?: null,
           $f('rechnungsdatum') ?: null, $f('rechnungsnummer') ?: null, $zahl('abschlaege'), $zahl('saldo'), $f('notiz') ?: null,
           $f('text_auszug') !== '' ? $f('text_auszug') : null, $id]);
        q('DELETE FROM versorger_positionen WHERE abrechnung_id=?', [$id]);
        foreach ($_POST['p'] ?? [] as $p) {
            if (trim((string)($p['bezeichnung'] ?? '')) === '' && trim((string)($p['betrag'] ?? '')) === '') continue;
            q('INSERT INTO versorger_positionen (abrechnung_id, kostenart_id, bezeichnung, betrag) VALUES (?,?,?,?)',
              [$id, ($p['kostenart_id'] ?? '') === '' ? null : (int)$p['kostenart_id'], trim((string)$p['bezeichnung']) ?: 'Position', round(parse_num($p['betrag'] ?? ''), 2)]);
        }
        db()->commit();
        $msg = 'Abrechnung gespeichert.';
        if ($d['uebernommen']) { $n = versorger_uebernehmen($id); $msg .= " Kosten aktualisiert ($n Positionen)."; }
        flash($msg);
    } elseif ($a === 'uebernehmen') {
        $n = versorger_uebernehmen($id);
        $ohne = count(array_filter($d['positionen'], fn($p) => !$p['kostenart_id']));
        flash("$n Positionen als Kosten $d[jahr] übernommen." . ($ohne ? " $ohne Position(en) ohne Kostenart wurden nicht übernommen." : ''), $ohne || !$n ? 'warn' : 'ok');
    } elseif ($a === 'zuruecknehmen') {
        q('DELETE FROM zuordnungen WHERE dokument_id=?', [$id]);
        flash('Übernahme zurückgenommen – für diese Kostenarten zählen wieder die einzelnen Kontobuchungen.');
    } elseif ($a === 'loeschen') {
        versorger_datei_loeschen($d['datei']);
        q('DELETE FROM versorger_abrechnungen WHERE id=?', [$id]);
        flash('Abrechnung gelöscht.');
        redirect('versorger.php');
    }
    redirect('versorger_abrechnung.php?id=' . $id);
}

$vertraege = rows('SELECT * FROM versorgungsvertraege ORDER BY bezeichnung, id');
$ab = versorger_abgleich($d);
$erkannt = versorger_erkennen((string)$d['text_auszug']);
$pos = $d['positionen'];
if (!$pos) {
    $ka = $d['vertrag']['kostenart_id'] ?? null;
    $umlage = $ka ? (int)val('SELECT umlagefaehig FROM kostenarten WHERE id=?', [$ka]) : 0;
    $pos = [['kostenart_id' => $umlage ? $ka : null, 'bezeichnung' => '', 'betrag' => '']];
} else {
    $pos[] = ['kostenart_id' => null, 'bezeichnung' => '', 'betrag' => ''];
}
$titel = ($d['vertrag']['bezeichnung'] ?? 'Jahresabrechnung') . ' ' . $d['jahr'];

page_head($titel, 'versorger.php');
?>
<p><a href="versorger.php">← Versorger</a></p>
<div class="head-row">
  <h1><?= h($titel) ?> <?= $d['uebernommen'] ? '<span class="tag ausgeglichen">als Kosten übernommen</span>' : '' ?></h1>
  <?php if ($d['datei']): ?><a class="btn ghost" target="_blank" href="beleg.php?typ=versorger&id=<?= $id ?>">PDF öffnen: <?= h($d['datei_name']) ?></a><?php endif; ?>
</div>

<form method="post" id="vaForm">
  <?= csrf_field() ?><input type="hidden" name="aktion" value="speichern">
  <section class="card">
    <h2>Angaben lt. Abrechnung</h2>
    <div class="grid3">
      <label>Versorgungsvertrag<select name="vertrag"><option value="">– wählen –</option>
        <?php foreach ($vertraege as $v): ?><option value="<?= $v['id'] ?>" <?= (int)$d['versorgungsvertrag_id'] === (int)$v['id'] ? 'selected' : '' ?>><?= h(versorger_label($v)) ?></option><?php endforeach; ?>
      </select></label>
      <label>Abrechnungsjahr (Kostenjahr)<input type="number" name="jahr" value="<?= h($d['jahr']) ?>" required></label>
      <label>Rechnungsnummer<input name="rechnungsnummer" value="<?= h($d['rechnungsnummer']) ?>"></label>
      <label>Zeitraum von<input type="date" name="zeitraum_von" value="<?= h($d['zeitraum_von']) ?>"></label>
      <label>Zeitraum bis<input type="date" name="zeitraum_bis" value="<?= h($d['zeitraum_bis']) ?>"></label>
      <label>Rechnungsdatum<input type="date" name="rechnungsdatum" value="<?= h($d['rechnungsdatum']) ?>"></label>
      <label>Bereits gezahlte Abschläge €<input name="abschlaege" value="<?= $d['abschlaege'] !== null ? num($d['abschlaege']) : '' ?>" class="r" placeholder="lt. Abrechnung"></label>
      <label>Saldo € (Nachzahlung +, Guthaben −)<input name="saldo" value="<?= $d['saldo'] !== null ? num($d['saldo']) : '' ?>" class="r" placeholder="lt. Abrechnung"></label>
      <label>Notiz<input name="notiz" value="<?= h($d['notiz']) ?>"></label>
    </div>
    <?php if ($erkannt['betraege']): ?>
      <details>
        <summary class="small muted" style="cursor:pointer">Im PDF-Text gefundene Beträge (zur Orientierung)</summary>
        <table class="list small"><?php foreach ($erkannt['betraege'] as $b): ?><tr><td><?= h($b['label']) ?></td><td class="r nowrap"><?= eur($b['betrag']) ?></td></tr><?php endforeach; ?></table>
      </details>
    <?php endif; ?>
    <details <?= $d['text_auszug'] ? '' : 'open' ?>>
      <summary class="small muted" style="cursor:pointer">PDF-Text <?= $d['text_auszug'] ? 'ersetzen' : 'einfügen (optional)' ?></summary>
      <textarea name="text_auszug" rows="4" placeholder="Text aus dem PDF – wird nur zur Anzeige erkannter Beträge verwendet."></textarea>
    </details>
  </section>

  <section class="card">
    <h2>Kostenpositionen</h2>
    <p class="muted small">Beträge lt. Abrechnung, jeweils der Kostenart zuordnen, unter der sie in der Nebenkostenabrechnung erscheinen (z. B. ZWA: Grundgebühr Wasser, Verbrauch Wasser, Grundgebühr Entwässerung, Niederschlagswasser). Gutschriften negativ. Nicht umlagefähige Kostenarten sind möglich, werden aber nicht abgerechnet.</p>
    <table class="list" id="postab">
      <thead><tr><th>Kostenart</th><th>Bezeichnung lt. Abrechnung</th><th class="r">Betrag €</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($pos as $i => $p): ?>
        <tr>
          <td><select name="p[<?= $i ?>][kostenart_id]"><?= kostenart_options($p['kostenart_id'] !== null ? (int)$p['kostenart_id'] : null) ?></select></td>
          <td><input name="p[<?= $i ?>][bezeichnung]" value="<?= h($p['bezeichnung']) ?>" placeholder="z. B. Verbrauch 85 m³"></td>
          <td><input name="p[<?= $i ?>][betrag]" value="<?= $p['betrag'] !== '' ? num($p['betrag']) : '' ?>" class="w7 r"></td>
          <td><button type="button" class="btn small ghost" data-del-row>×</button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td colspan="2" class="r"><b>Gesamtkosten</b></td><td class="r"><b><?= eur($d['gesamt']) ?></b></td><td></td></tr></tfoot>
    </table>
    <button type="button" class="btn small ghost" data-add-row="postab">+ Position</button>
    <div class="actions"><button class="btn">Speichern</button></div>
  </section>
</form>

<section class="card">
  <h2>Abgleich mit dem Kontoauszug</h2>
  <p class="muted small">Zeitraum <?= dt($ab['von']) ?> – <?= dt($ab['bis']) ?>.
    Berücksichtigt werden Buchungen, deren Text zu den Nummern bzw. dem Suchtext des Versorgungsvertrags passt oder die einer Kostenart dieser Abrechnung zugeordnet sind.</p>
  <ul class="checks">
    <?php foreach ($ab['checks'] as [$typ, $text]): ?>
      <li class="<?= $typ ?>"><span class="ic"><?= ['ok' => '✓', 'warn' => '⚠', 'info' => 'ℹ'][$typ] ?></span> <?= h($text) ?></li>
    <?php endforeach; ?>
  </ul>
  <?php foreach ([['Abschlagszahlungen', $ab['abschlaege'], $ab['summe_abschlaege']], ['Nachzahlung / Guthaben', $ab['ausgleich'], $ab['summe_ausgleich']]] as [$titel2, $liste, $summe]): if (!$liste) continue; ?>
    <h3><?= $titel2 ?></h3>
    <table class="list small">
      <?php foreach ($liste as $b): ?>
        <tr><td class="nowrap"><?= dt($b['datum']) ?></td>
            <td><a href="buchung.php?id=<?= $b['id'] ?>"><?= h($b['name']) ?></a> <span class="muted"><?= h(mb_strimwidth((string)$b['zweck'], 0, 90, '…')) ?></span></td>
            <td class="r nowrap <?= $b['betrag'] < 0 ? 'neg' : 'pos' ?>"><?= eur($b['betrag']) ?></td></tr>
      <?php endforeach; ?>
      <tr><td colspan="2" class="r"><b>Summe</b></td><td class="r nowrap"><b><?= eur($summe) ?></b></td></tr>
    </table>
  <?php endforeach; ?>
</section>

<section class="card">
  <h2>Übernahme in die Nebenkostenabrechnung</h2>
  <?php if ($d['uebernommen']):
      $uebernommen = rows('SELECT z.*, k.bezeichnung, k.gruppe, k.umlagefaehig FROM zuordnungen z JOIN kostenarten k ON k.id=z.kostenart_id WHERE z.dokument_id=? ORDER BY k.sortierung', [$id]); ?>
    <p>Diese Positionen zählen als Kosten <?= (int)$d['jahr'] ?>:</p>
    <table class="list small">
      <?php foreach ($uebernommen as $z): ?><tr><td><?= h(kostenart_label($z)) ?><?= $z['umlagefaehig'] ? '' : ' <span class="tag">nicht umlagefähig</span>' ?></td><td class="r"><?= eur($z['betrag']) ?></td></tr><?php endforeach; ?>
    </table>
    <p class="muted small">Für diese Kostenarten ersetzt die Jahresabrechnung die einzelnen Kontobuchungen (Abschläge, Nachzahlung), sie werden nicht doppelt gezählt. Unter <a href="kosten.php?jahr=<?= (int)$d['jahr'] ?>">Kosten <?= (int)$d['jahr'] ?></a> ansehen.</p>
    <form method="post" class="inline" onsubmit="return confirm('Übernahme zurücknehmen?')"><?= csrf_field() ?><input type="hidden" name="aktion" value="zuruecknehmen"><button class="btn ghost">Übernahme zurücknehmen</button></form>
  <?php else: ?>
    <p class="muted">Noch nicht übernommen. Beim Übernehmen werden die Positionen als Kosten <?= (int)$d['jahr'] ?> erfasst. Für diese Kostenarten zählt dann die Jahresabrechnung statt der einzelnen Kontobuchungen (Abschläge, Nachzahlung) – nichts wird doppelt gerechnet. Rückgängig machbar.</p>
    <?php if ($ab['status'] === 'warn'): ?><p class="note">Es gibt noch Abweichungen im Abgleich – bitte vorher prüfen.</p><?php endif; ?>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="aktion" value="uebernehmen"><button class="btn" <?= $d['positionen'] ? '' : 'disabled' ?>>Als Kosten <?= (int)$d['jahr'] ?> übernehmen</button></form>
  <?php endif; ?>
  <form method="post" class="right" onsubmit="return confirm('Abrechnung samt PDF löschen?')"><?= csrf_field() ?><input type="hidden" name="aktion" value="loeschen"><button class="btn small danger">Abrechnung löschen</button></form>
</section>
<?php page_foot();
