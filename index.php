<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();

$saldo = (float)val('SELECT COALESCE(SUM(betrag),0) FROM buchungen');
$letzte = row('SELECT MAX(datum) d FROM buchungen')['d'] ?? null;
$offenZuord = (int)val('SELECT COUNT(*) FROM buchungen b WHERE NOT EXISTS (SELECT 1 FROM zuordnungen z WHERE z.buchung_id=b.id)');
$auslagenOffen = row("SELECT COUNT(*) n, COALESCE(SUM(betrag),0) s FROM auslagen WHERE status<>'erstattet'");
$jahre = rows('SELECT YEAR(datum) j, SUM(CASE WHEN betrag>0 THEN betrag ELSE 0 END) ein, SUM(CASE WHEN betrag<0 THEN betrag ELSE 0 END) aus, COUNT(*) n
               FROM buchungen GROUP BY YEAR(datum) ORDER BY j DESC');
$nk = [];
foreach (rows('SELECT z.jahr, SUM(z.betrag) s FROM zuordnungen z JOIN kostenarten k ON k.id=z.kostenart_id WHERE k.umlagefaehig=1 AND ' . zaehlt_sql() . ' GROUP BY z.jahr') as $r) $nk[$r['jahr']] = (float)$r['s'];
$abr = [];
foreach (rows('SELECT a.*, v.mieter_name FROM abrechnungen a JOIN vertraege v ON v.id=a.vertrag_id ORDER BY a.jahr DESC, a.id DESC') as $a) $abr[$a['jahr']][] = $a;
$vertraege = rows('SELECT * FROM vertraege WHERE ende IS NULL OR ende >= CURDATE() ORDER BY mieter_name');
$vorjahr = (int)date('Y') - 1;

page_head('Übersicht', 'index.php');
?>
<h1>Übersicht</h1>
<div class="kpis">
  <div class="kpi"><span>Kontostand lt. Buchungen</span><b><?= eur($saldo) ?></b><small>Stand <?= dt($letzte) ?></small></div>
  <div class="kpi <?= $offenZuord ? 'warn' : '' ?>"><span>Nicht zugeordnete Buchungen</span><b><?= $offenZuord ?></b><small><a href="buchungen.php?filter=offen">anzeigen</a></small></div>
  <div class="kpi"><span>Offene Auslagen</span><b><?= eur($auslagenOffen['s']) ?></b><small><?= (int)$auslagenOffen['n'] ?> Posten · <a href="auslagen.php">verwalten</a></small></div>
</div>

<section class="card">
  <h2>Abrechnung erstellen</h2>
  <form method="get" action="abrechnung.php" class="inline">
    <input type="hidden" name="neu" value="1">
    <label>Vertrag
      <select name="vertrag"><?php foreach ($vertraege as $v): ?><option value="<?= $v['id'] ?>"><?= h($v['mieter_name']) ?> (<?= h($v['einheit']) ?>)</option><?php endforeach; ?></select>
    </label>
    <label>Jahr <input type="number" name="jahr" value="<?= $vorjahr ?>" min="2000" max="2100" class="w6"></label>
    <button class="btn">Abrechnung berechnen</button>
  </form>
  <p class="muted">Vorher unter <a href="kosten.php?jahr=<?= $vorjahr ?>">Kosten je Jahr</a> prüfen, ob alle Positionen vollständig sind (z. B. ZWA-Jahresrechnung).</p>
</section>

<section class="card">
  <h2>Jahre im Überblick</h2>
  <table class="list">
    <thead><tr><th>Jahr</th><th class="r">Buchungen</th><th class="r">Einnahmen</th><th class="r">Ausgaben</th><th class="r">Umlagefähige Kosten</th><th>Abrechnung</th></tr></thead>
    <tbody>
    <?php foreach ($jahre as $j): ?>
      <tr>
        <td><a href="buchungen.php?jahr=<?= $j['j'] ?>"><?= $j['j'] ?></a></td>
        <td class="r"><?= $j['n'] ?></td>
        <td class="r pos"><?= eur($j['ein']) ?></td>
        <td class="r neg"><?= eur($j['aus']) ?></td>
        <td class="r"><a href="kosten.php?jahr=<?= $j['j'] ?>"><?= eur($nk[$j['j']] ?? 0) ?></a></td>
        <td><?php foreach ($abr[$j['j']] ?? [] as $a): ?>
              <a class="tag <?= h($a['status']) ?>" href="abrechnung_druck.php?id=<?= $a['id'] ?>"><?= h($a['status']) ?> · <?= eur($a['ergebnis'], true) ?></a>
            <?php endforeach; ?>
            <?php if (empty($abr[$j['j']])): ?><span class="muted">–</span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php page_foot();
