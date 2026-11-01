<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();

$jahr = $_GET['jahr'] ?? '';
$status = $_GET['status'] ?? '';
$w = []; $p = [];
if ($jahr !== '') { $w[] = 'YEAR(datum)=?'; $p[] = (int)$jahr; }
if ($status === 'nicht_erstattet') $w[] = "status<>'erstattet'";
elseif ($status !== '') { $w[] = 'status=?'; $p[] = $status; }
$liste = rows('SELECT * FROM auslagen' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY datum, id', $p);

$proKat = []; $offen = 0; $erst = 0;
foreach ($liste as $a) {
    $proKat[$a['kategorie']] = ($proKat[$a['kategorie']] ?? 0) + $a['betrag'];
    if ($a['status'] === 'erstattet') $erst += $a['betrag']; else $offen += $a['betrag'];
}
$titel = 'Aufstellung der Auslagen' . ($jahr ? " $jahr" : '') . match ($status) {
    'offen' => ' (offen)', 'eingereicht' => ' (eingereicht)', 'erstattet' => ' (erstattet)', 'nicht_erstattet' => ' (zur Erstattung)', default => ''
};
?><!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($titel) ?></title><link rel="stylesheet" href="assets/style.css"></head>
<body class="druck">
<div class="noprint toolbar"><a href="auslagen.php" class="btn ghost">← Auslagen</a> <button class="btn" onclick="window.print()">Drucken / als PDF speichern</button></div>
<div class="blatt">
  <div class="briefkopf">
    <div><b class="gross"><?= h(setting('absender_name')) ?></b><br><?= h(setting('absender_strasse')) ?><br><?= h(setting('absender_ort')) ?></div>
    <div class="abs"><?= h(setting('aktenzeichen')) ?><br>Objekt: Geithainer Str. 3, 09306 Rochlitz<br>Stand: <?= date('d.m.Y') ?></div>
  </div>
  <h1 class="betreff"><?= h($titel) ?></h1>
  <p class="small">Auslagen im Rahmen der treuhänderischen Verwaltung des Grundstücks. Fahrtkosten mit <?= num(setting('km_satz', '0.30')) ?> € je gefahrenem Kilometer. Belege liegen vor und können eingesehen werden.</p>
  <table class="abr">
    <thead><tr><th>Nr.</th><th>Datum</th><th>Kategorie</th><th>Beschreibung</th><th>Beleg</th><th>Status</th><th class="r">Betrag</th></tr></thead>
    <tbody>
    <?php foreach ($liste as $i => $a): ?>
      <tr class="sub">
        <td><?= $i + 1 ?></td><td class="nowrap"><?= dt($a['datum']) ?></td><td><?= h($a['kategorie']) ?></td>
        <td><?= h($a['beschreibung']) ?><?= $a['empfaenger'] ? ' – ' . h($a['empfaenger']) : '' ?>
          <?php if ($a['km']): ?><br><small><?= num($a['km'], 1) ?> km<?= $a['hin_rueck'] ? ' × 2' : '' ?> × <?= num($a['km_satz']) ?> €</small><?php endif; ?></td>
        <td><?= $a['beleg_datei'] ? 'ja' : '–' ?></td>
        <td><?= h($a['status']) ?><?= $a['erstattet_am'] ? ' ' . dt($a['erstattet_am']) : '' ?></td>
        <td class="r nowrap"><?= eur($a['betrag']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$liste): ?><tr><td colspan="7">Keine Auslagen im gewählten Zeitraum.</td></tr><?php endif; ?>
      <tr class="sp"><td colspan="7"></td></tr>
      <?php foreach ($proKat as $k => $s): ?><tr class="sum"><td colspan="6"><?= h($k) ?></td><td class="r"><?= eur($s) ?></td></tr><?php endforeach; ?>
      <tr class="tot"><td colspan="6" class="r">Summe</td><td class="r"><?= eur($offen + $erst) ?></td></tr>
      <?php if ($erst > 0): ?><tr class="sum"><td colspan="6" class="r">davon bereits erstattet</td><td class="r"><?= eur($erst) ?></td></tr><?php endif; ?>
      <tr class="tot final"><td colspan="6" class="r">Zur Erstattung offen</td><td class="r"><?= eur($offen) ?></td></tr>
    </tbody>
  </table>
  <p class="text">Ich versichere die Richtigkeit der Angaben.</p>
  <div class="unterschrift-linie"><span><?= h(preg_replace('/^\d{5}\s*/', '', setting('absender_ort'))) ?>, den ______________</span><span>__________________________<br><?= h(setting('absender_name')) ?></span></div>
</div>
</body></html>
