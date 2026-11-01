<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/logik.php';
require_login();
check_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['aktion'] ?? '';
    if ($a === 'schnell') {
        $id = (int)$_POST['id'];
        $b = row('SELECT * FROM buchungen WHERE id=?', [$id]);
        q('DELETE FROM zuordnungen WHERE buchung_id=?', [$id]);
        if ($b && $_POST['kostenart_id'] !== '') {
            q('INSERT INTO zuordnungen (buchung_id, kostenart_id, jahr, betrag, datum) VALUES (?,?,?,?,?)',
              [$id, (int)$_POST['kostenart_id'], (int)$_POST['jahr'], -(float)$b['betrag'], $b['datum']]);
        }
        flash('Zuordnung gespeichert.');
    } elseif ($a === 'regeln') {
        $n = 0;
        foreach (rows('SELECT b.id FROM buchungen b WHERE NOT EXISTS (SELECT 1 FROM zuordnungen z WHERE z.buchung_id=b.id)') as $b) {
            if (auto_zuordnen((int)$b['id'])) $n++;
        }
        flash("$n Buchungen über Regeln zugeordnet.");
    }
    redirect('buchungen.php?' . http_build_query(array_intersect_key($_GET, array_flip(['jahr', 'ka', 'filter', 's']))));
}

$jahr = $_GET['jahr'] ?? (empty($_GET) ? (string)val('SELECT MAX(YEAR(datum)) FROM buchungen') : '');
$ka = $_GET['ka'] ?? '';
$filter = $_GET['filter'] ?? '';
$s = trim($_GET['s'] ?? '');

$where = []; $p = [];
if ($jahr !== '') { $where[] = 'YEAR(b.datum)=?'; $p[] = (int)$jahr; }
if ($ka !== '') { $where[] = 'EXISTS (SELECT 1 FROM zuordnungen z2 WHERE z2.buchung_id=b.id AND z2.kostenart_id=?)'; $p[] = (int)$ka; }
if ($filter === 'offen') $where[] = 'NOT EXISTS (SELECT 1 FROM zuordnungen z3 WHERE z3.buchung_id=b.id)';
if ($s !== '') { $where[] = '(b.name LIKE ? OR b.zweck LIKE ? OR b.notiz LIKE ?)'; array_push($p, "%$s%", "%$s%", "%$s%"); }
$sql = 'SELECT b.* FROM buchungen b' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY b.datum DESC, b.id DESC';
$liste = rows($sql, $p);

$zu = [];
if ($liste) {
    $ids = implode(',', array_map(fn($b) => (int)$b['id'], $liste));
    foreach (rows("SELECT z.*, k.bezeichnung, k.gruppe, k.umlagefaehig FROM zuordnungen z JOIN kostenarten k ON k.id=z.kostenart_id WHERE z.buchung_id IN ($ids)") as $z) $zu[$z['buchung_id']][] = $z;
}
$jahre = array_column(rows('SELECT DISTINCT YEAR(datum) j FROM buchungen ORDER BY j DESC'), 'j');
$summe = array_sum(array_column($liste, 'betrag'));

page_head('Kontobuchungen', 'buchungen.php');
?>
<div class="head-row">
  <h1>Kontobuchungen</h1>
  <div>
    <a class="btn" href="buchung.php">+ Buchung manuell</a>
    <a class="btn ghost" href="import.php">Kontoauszug importieren</a>
  </div>
</div>

<form method="get" class="filters">
  <select name="jahr"><option value="">alle Jahre</option><?php foreach ($jahre as $j): ?><option <?= (string)$j === (string)$jahr ? 'selected' : '' ?>><?= $j ?></option><?php endforeach; ?></select>
  <select name="ka"><option value="">alle Kostenarten</option><?= str_replace('<option value="">– nicht zugeordnet –</option>', '', kostenart_options($ka !== '' ? (int)$ka : null)) ?></select>
  <label class="chk"><input type="checkbox" name="filter" value="offen" <?= $filter === 'offen' ? 'checked' : '' ?>> nur nicht zugeordnete</label>
  <input type="search" name="s" value="<?= h($s) ?>" placeholder="Suche Name / Zweck">
  <button class="btn ghost">Filtern</button>
</form>

<form method="post" class="inline right">
  <?= csrf_field() ?><input type="hidden" name="aktion" value="regeln">
  <button class="btn small ghost" title="Regeln unter Stammdaten">Regeln auf nicht zugeordnete anwenden</button>
</form>

<p class="muted"><?= count($liste) ?> Buchungen · Summe <?= eur($summe) ?></p>
<table class="list buchungen">
  <thead><tr><th>Datum</th><th>Name / Verwendungszweck</th><th class="r">Betrag</th><th>Zuordnung (Kostenart · Abrechnungsjahr)</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($liste as $b): $z = $zu[$b['id']] ?? []; ?>
    <tr class="<?= $z ? '' : 'unassigned' ?>">
      <td class="nowrap"><?= dt($b['datum']) ?></td>
      <td><b><?= h($b['name']) ?></b><div class="small muted"><?= h($b['zweck']) ?></div><?php if ($b['notiz']): ?><div class="small note"><?= h($b['notiz']) ?></div><?php endif; ?></td>
      <td class="r nowrap <?= $b['betrag'] < 0 ? 'neg' : 'pos' ?>"><?= eur($b['betrag']) ?></td>
      <td>
        <?php if (count($z) > 1): ?>
          <?php foreach ($z as $x): ?><div class="small"><span class="tag <?= $x['umlagefaehig'] ? 'nk' : '' ?>"><?= h(kostenart_label($x)) ?></span> <?= $x['jahr'] ?> · <?= eur($x['betrag']) ?></div><?php endforeach; ?>
          <a class="small" href="buchung.php?id=<?= $b['id'] ?>">aufgeteilt – bearbeiten</a>
        <?php else: $x = $z[0] ?? null; ?>
          <form method="post" class="quick">
            <?= csrf_field() ?><input type="hidden" name="aktion" value="schnell"><input type="hidden" name="id" value="<?= $b['id'] ?>">
            <select name="kostenart_id" class="<?= $x && $x['umlagefaehig'] ? 'nk' : '' ?>"><?= kostenart_options($x ? (int)$x['kostenart_id'] : null) ?></select>
            <input type="number" name="jahr" value="<?= $x['jahr'] ?? vorschlag_jahr($b) ?>" class="w5" title="Abrechnungsjahr">
            <button class="btn small" title="Speichern">✓</button>
          </form>
        <?php endif; ?>
      </td>
      <td><a href="buchung.php?id=<?= $b['id'] ?>" class="small">Details</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php page_foot();
