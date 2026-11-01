<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();
check_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'status') {
    $ids = array_map('intval', $_POST['ids'] ?? []);
    if ($ids) {
        $st = in_array($_POST['status'], ['offen', 'eingereicht', 'erstattet'], true) ? $_POST['status'] : 'offen';
        $datum = $st === 'erstattet' ? ($_POST['erstattet_am'] ?: date('Y-m-d')) : null;
        $bid = $st === 'erstattet' && ($_POST['buchung_id'] ?? '') !== '' ? (int)$_POST['buchung_id'] : null;
        $in = implode(',', $ids);
        q("UPDATE auslagen SET status=?, erstattet_am=?, buchung_id=? WHERE id IN ($in)", [$st, $datum, $bid]);
        flash(count($ids) . ' Auslage(n) auf „' . $st . '“ gesetzt.');
    }
    redirect('auslagen.php?' . http_build_query(array_intersect_key($_GET, array_flip(['jahr', 'status']))));
}

$jahr = $_GET['jahr'] ?? '';
$status = $_GET['status'] ?? '';
$w = []; $p = [];
if ($jahr !== '') { $w[] = 'YEAR(a.datum)=?'; $p[] = (int)$jahr; }
if ($status === 'nicht_erstattet') $w[] = "a.status<>'erstattet'";
elseif ($status !== '') { $w[] = 'a.status=?'; $p[] = $status; }
$liste = rows('SELECT a.*, b.datum bdatum FROM auslagen a LEFT JOIN buchungen b ON b.id=a.buchung_id' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY a.datum DESC, a.id DESC', $p);
$sum = ['offen' => 0, 'eingereicht' => 0, 'erstattet' => 0];
foreach (rows('SELECT status, SUM(betrag) s FROM auslagen GROUP BY status') as $r) $sum[$r['status']] = (float)$r['s'];
$jahre = array_column(rows('SELECT DISTINCT YEAR(datum) j FROM auslagen ORDER BY j DESC'), 'j');
$erstattungen = rows("SELECT b.id, b.datum, b.betrag, b.name, b.zweck FROM buchungen b JOIN zuordnungen z ON z.buchung_id=b.id WHERE z.kostenart_id=23 OR b.zweck LIKE '%Auslage%' GROUP BY b.id ORDER BY b.datum DESC");

page_head('Auslagen', 'auslagen.php');
?>
<div class="head-row">
  <h1>Meine Auslagen</h1>
  <div>
    <a class="btn" href="auslage.php">+ Auslage</a>
    <a class="btn ghost" href="auslage.php?fahrt=1">+ Fahrt</a>
    <a class="btn ghost" href="auslage.php?porto=1">+ Porto</a>
  </div>
</div>
<div class="kpis">
  <div class="kpi warn"><span>Offen</span><b><?= eur($sum['offen']) ?></b><small><a href="?status=offen">anzeigen</a></small></div>
  <div class="kpi"><span>Eingereicht, noch nicht erstattet</span><b><?= eur($sum['eingereicht']) ?></b><small><a href="?status=eingereicht">anzeigen</a></small></div>
  <div class="kpi"><span>Erstattet (gesamt)</span><b><?= eur($sum['erstattet']) ?></b><small><a href="?status=erstattet">anzeigen</a></small></div>
</div>

<form method="get" class="filters">
  <select name="jahr"><option value="">alle Jahre</option><?php foreach ($jahre as $j): ?><option <?= (string)$j === (string)$jahr ? 'selected' : '' ?>><?= $j ?></option><?php endforeach; ?></select>
  <select name="status">
    <?php foreach (['' => 'alle', 'nicht_erstattet' => 'noch nicht erstattet', 'offen' => 'offen', 'eingereicht' => 'eingereicht', 'erstattet' => 'erstattet'] as $k => $l): ?>
      <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $l ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn ghost">Filtern</button>
  <a class="btn ghost" target="_blank" href="auslagen_druck.php?<?= h(http_build_query(['jahr' => $jahr, 'status' => $status])) ?>">Aufstellung drucken</a>
</form>

<form method="post">
  <?= csrf_field() ?><input type="hidden" name="aktion" value="status">
  <table class="list">
    <thead><tr><th><input type="checkbox" data-check-all></th><th>Datum</th><th>Kategorie</th><th>Beschreibung</th><th class="r">Betrag</th><th>Beleg</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php $s = 0; foreach ($liste as $a): $s += $a['betrag']; ?>
      <tr>
        <td><input type="checkbox" name="ids[]" value="<?= $a['id'] ?>"></td>
        <td class="nowrap"><?= dt($a['datum']) ?></td>
        <td><?= h($a['kategorie']) ?></td>
        <td><?= h($a['beschreibung']) ?>
          <?php if ($a['km']): ?><div class="small muted"><?= num($a['km'], 1) ?> km<?= $a['hin_rueck'] ? ' × 2 (Hin- und Rückfahrt)' : '' ?> × <?= num($a['km_satz']) ?> €</div><?php endif; ?>
          <?php if ($a['empfaenger']): ?><div class="small muted">an <?= h($a['empfaenger']) ?></div><?php endif; ?></td>
        <td class="r nowrap"><?= eur($a['betrag']) ?></td>
        <td><?= $a['beleg_datei'] ? '<a href="beleg.php?id=' . $a['id'] . '" target="_blank">📎</a>' : '' ?></td>
        <td><span class="tag <?= $a['status'] ?>"><?= $a['status'] ?></span><?php if ($a['erstattet_am']): ?><div class="small muted"><?= dt($a['erstattet_am']) ?></div><?php endif; ?></td>
        <td><a class="small" href="auslage.php?id=<?= $a['id'] ?>">bearbeiten</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$liste): ?><tr><td colspan="8" class="muted">Keine Auslagen gefunden.</td></tr><?php endif; ?>
    </tbody>
    <tfoot><tr><td colspan="4" class="r">Summe Auswahl</td><td class="r"><b><?= eur($s) ?></b></td><td colspan="3"></td></tr></tfoot>
  </table>
  <div class="card inline wrap">
    <b>Markierte setzen auf</b>
    <select name="status"><option value="eingereicht">eingereicht</option><option value="erstattet">erstattet</option><option value="offen">offen</option></select>
    <label class="inline">am <input type="date" name="erstattet_am" value="<?= date('Y-m-d') ?>"></label>
    <select name="buchung_id"><option value="">– Erstattungsbuchung (optional) –</option>
      <?php foreach ($erstattungen as $b): ?><option value="<?= $b['id'] ?>"><?= dt($b['datum']) ?> <?= eur($b['betrag']) ?> <?= h(mb_strimwidth($b['zweck'], 0, 40, '…')) ?></option><?php endforeach; ?>
    </select>
    <button class="btn">Übernehmen</button>
  </div>
</form>
<?php page_foot();
