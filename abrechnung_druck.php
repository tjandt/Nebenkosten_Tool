<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/abrechnung_view.php';
require_login();

$r = row('SELECT * FROM abrechnungen WHERE id=?', [(int)($_GET['id'] ?? 0)]);
if (!$r) redirect('abrechnung.php');
$d = json_decode($r['daten'], true);
$v = $d['vertrag']; $a = $d['absender'];
$monate = ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
$bd = strtotime($d['briefdatum'] ?: $r['erstellt_am']);
$ortDatum = trim(preg_replace('/^\d{5}\s*/', '', $a['ort'])) . ', ' . date('j', $bd) . '. ' . $monate[(int)date('n', $bd)] . ' ' . date('Y', $bd);
$kaNamen = [];
foreach (rows('SELECT id, bezeichnung, gruppe FROM kostenarten') as $k) $kaNamen[$k['id']] = ($k['gruppe'] ? $k['gruppe'] . ' – ' : '') . $k['bezeichnung'];
?><!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Betriebskostenabrechnung <?= $d['jahr'] ?> – <?= h($v['mieter_name']) ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="druck">
<div class="noprint toolbar">
  <a href="abrechnung.php" class="btn ghost">← Abrechnungen</a>
  <button class="btn" onclick="window.print()">Drucken / als PDF speichern</button>
  <?php if (!empty($d['archiv'])): ?><span class="tag">Archiv – Werte aus der damaligen Abrechnung übernommen</span><?php endif; ?>
</div>
<div class="blatt">
  <div class="briefkopf">
    <div class="empf">
      <div class="ruecksende"><?= h($a['name']) ?> · <?= h($a['strasse']) ?> · <?= h($a['ort']) ?></div>
      <b><?= h($v['mieter_name']) ?></b><br>
      <?= h($v['mieter_strasse']) ?><br><br>
      <b><?= h($v['mieter_plz'] . ' ' . $v['mieter_ort']) ?></b>
    </div>
    <div class="abs">
      <b class="gross"><?= h($a['name']) ?></b><br>
      <?= h($a['zusatz']) ?><br><br>
      <?= h($a['strasse']) ?><br><?= h($a['ort']) ?><br>
      <?php if ($a['telefon']): ?>Tel.: <?= h($a['telefon']) ?><br><?php endif; ?>
      <?php if ($a['email']): ?><?= h($a['email']) ?><br><?php endif; ?>
      <br><?= h($ortDatum) ?>
    </div>
  </div>

  <h1 class="betreff">Betriebskostenabrechnung <?= $d['jahr'] ?></h1>
  <p class="small">Mietobjekt: <?= h($d['objekt']['strasse'] . ', ' . $d['objekt']['plz'] . ' ' . $d['objekt']['ort']) ?><?= $v['einheit'] ? ' · ' . h($v['einheit']) : '' ?> ·
     Zeitraum <?= dt($d['zeitraum_von']) ?> – <?= dt($d['zeitraum_bis']) ?> · Wohnfläche <?= num($v['wohnflaeche']) ?> m² von <?= num($d['objekt']['gesamtflaeche']) ?> m²</p>

  <div class="text"><?= nl2br(h($d['einleitung'])) ?></div>
  <?php if (trim($d['hinweise']) !== '' && empty($d['archiv'])): ?><div class="text"><?= nl2br(h($d['hinweise'])) ?></div><?php endif; ?>

  <?php abrechnung_tabelle($d); ?>

  <div class="text"><?= nl2br(h($d['schluss'])) ?></div>
  <div class="text">Mit freundlichen Grüßen</div>
  <div class="unterschrift"><?= h($a['name']) ?></div>

  <div class="fuss">Zahlungsabwicklung: <?= h($a['name']) ?> – <?= h($a['zusatz']) ?><br>
    Konto IBAN: <?= h($a['iban']) ?><?= $a['bank'] ? ' · ' . h($a['bank']) : '' ?></div>
</div>

<?php if (!empty($d['belegliste'])): ?>
<div class="blatt neue-seite">
  <h2>Anlage: Belegliste <?= $d['jahr'] ?></h2>
  <p class="small">Zusammensetzung der Gesamtkosten (Kontobuchungen und Bescheide).</p>
  <table class="abr belege">
    <thead><tr><th>Kostenart</th><th>Datum</th><th>Beleg / Buchungstext</th><th class="r">Betrag</th></tr></thead>
    <tbody>
    <?php $last = null; $sum = 0;
    foreach ($d['belegliste'] as $b):
        if ($last !== null && $last !== $b['kostenart_id']) { echo '<tr class="sum"><td colspan="3" class="r">Summe</td><td class="r">' . eur($sum) . '</td></tr>'; $sum = 0; }
        $sum += $b['betrag']; ?>
      <tr><td><?= $last !== $b['kostenart_id'] ? h($kaNamen[$b['kostenart_id']] ?? '') : '' ?></td><td class="nowrap"><?= dt($b['datum']) ?></td><td class="small"><?= h($b['text']) ?></td><td class="r nowrap"><?= eur($b['betrag']) ?></td></tr>
    <?php $last = $b['kostenart_id']; endforeach; ?>
    <?php if ($last !== null): ?><tr class="sum"><td colspan="3" class="r">Summe</td><td class="r"><?= eur($sum) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
</body></html>
