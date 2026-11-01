<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();
check_csrf();

$jahr = (int)($_GET['jahr'] ?? ((int)date('Y') - 1));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['aktion'] ?? '';
    if ($a === 'beleg') {
        q('INSERT INTO zuordnungen (buchung_id, kostenart_id, jahr, betrag, datum, beschreibung) VALUES (NULL,?,?,?,?,?)',
          [(int)$_POST['kostenart_id'], $jahr, round(parse_num($_POST['betrag']), 2), $_POST['datum'] ?: null, trim($_POST['beschreibung'])]);
        flash('Kostenposition erfasst.');
    } elseif ($a === 'loeschen') {
        q('DELETE FROM zuordnungen WHERE id=? AND buchung_id IS NULL', [(int)$_POST['id']]);
        flash('Kostenposition gelöscht.');
    } elseif ($a === 'jahr') {
        q('UPDATE zuordnungen SET jahr=? WHERE id=?', [(int)$_POST['neues_jahr'], (int)$_POST['id']]);
        flash('Abrechnungsjahr geändert.');
    }
    redirect('kosten.php?jahr=' . $jahr);
}

$ka = rows('SELECT * FROM kostenarten ORDER BY umlagefaehig DESC, sortierung, id');
$posten = [];
foreach (rows('SELECT z.*, b.name, b.zweck, b.datum bdatum FROM zuordnungen z LEFT JOIN buchungen b ON b.id=z.buchung_id WHERE z.jahr=? ORDER BY COALESCE(z.datum,b.datum), z.id', [$jahr]) as $z) {
    $posten[$z['kostenart_id']][] = $z;
}
$vorjahr = [];
foreach (rows('SELECT kostenart_id, SUM(betrag) s FROM zuordnungen WHERE jahr=? GROUP BY kostenart_id', [$jahr - 1]) as $r) $vorjahr[$r['kostenart_id']] = (float)$r['s'];

page_head("Kosten $jahr", 'kosten.php');
?>
<div class="head-row">
  <h1>Kosten je Abrechnungsjahr</h1>
  <form method="get" class="inline">
    <a class="btn ghost small" href="?jahr=<?= $jahr - 1 ?>">← <?= $jahr - 1 ?></a>
    <input type="number" name="jahr" value="<?= $jahr ?>" class="w6" onchange="this.form.submit()">
    <a class="btn ghost small" href="?jahr=<?= $jahr + 1 ?>"><?= $jahr + 1 ?> →</a>
  </form>
</div>

<section class="card">
  <h2>Kostenposition ohne Kontobuchung erfassen</h2>
  <p class="muted small">Für Beträge aus Bescheiden/Jahresrechnungen, z. B. die ZWA-Jahresrechnung aufgeteilt in Grundgebühr, Verbrauch und Niederschlagswasser – die monatlichen ZWA-Abschläge selbst sind nicht umlagefähig. Erstattungen negativ eintragen.</p>
  <form method="post" class="inline wrap">
    <?= csrf_field() ?><input type="hidden" name="aktion" value="beleg">
    <select name="kostenart_id" required><?= kostenart_options(null, false) ?></select>
    <input name="betrag" placeholder="Betrag" class="w7 r" required>
    <input type="date" name="datum" value="<?= $jahr ?>-12-31">
    <input name="beschreibung" placeholder="z. B. ZWA Jahresrechnung <?= $jahr ?> lt. Bescheid vom …" class="grow" required>
    <button class="btn">Erfassen</button>
  </form>
</section>

<?php $grp = null; foreach ($ka as $k):
    $g = $k['umlagefaehig'] ? 'Umlagefähige Kosten' : 'Nicht umlagefähig (nur zur Übersicht)';
    if ($g !== $grp): if ($grp !== null) echo '</div>'; $grp = $g; ?>
      <h2 class="section"><?= h($g) ?></h2><div class="kosten">
    <?php endif;
    $liste = $posten[$k['id']] ?? [];
    $summe = array_sum(array_map(fn($z) => (float)$z['betrag'], $liste));
    if (!$liste && !$k['umlagefaehig']) continue;
    $vj = $vorjahr[$k['id']] ?? null; ?>
  <details class="card kost <?= $liste ? '' : 'leer' ?>" <?= $k['umlagefaehig'] && !$liste ? 'open' : '' ?>>
    <summary>
      <span class="ka"><?= h(kostenart_label($k)) ?> <span class="tag"><?= h(schluessel_label($k['schluessel'])) ?></span></span>
      <span class="r"><b><?= eur($summe) ?></b><?php if ($vj !== null): ?><small class="muted"> Vorjahr <?= eur($vj) ?></small><?php endif; ?></span>
    </summary>
    <?php if (!$liste): ?><p class="muted small">Keine Kosten für <?= $jahr ?> erfasst<?= $k['hinweis'] ? ' – ' . h($k['hinweis']) : '' ?>.</p><?php endif; ?>
    <?php if ($liste): ?>
    <table class="list small">
      <?php foreach ($liste as $z): ?>
        <tr>
          <td class="nowrap"><?= dt($z['datum'] ?? $z['bdatum']) ?></td>
          <td><?= $z['buchung_id'] ? '<a href="buchung.php?id=' . $z['buchung_id'] . '">' . h($z['name']) . '</a> <span class="muted">' . h($z['zweck']) . '</span>' : '<span class="tag">Beleg</span> ' . h($z['beschreibung']) ?>
              <?= $z['buchung_id'] && $z['beschreibung'] ? '<div class="muted">' . h($z['beschreibung']) . '</div>' : '' ?></td>
          <td class="r nowrap"><?= eur($z['betrag']) ?></td>
          <td class="nowrap">
            <form method="post" class="inline">
              <?= csrf_field() ?><input type="hidden" name="aktion" value="jahr"><input type="hidden" name="id" value="<?= $z['id'] ?>">
              <input type="number" name="neues_jahr" value="<?= $jahr ?>" class="w5" title="Abrechnungsjahr ändern"><button class="btn small ghost">↷</button>
            </form>
            <?php if (!$z['buchung_id']): ?>
            <form method="post" class="inline" onsubmit="return confirm('Position löschen?')">
              <?= csrf_field() ?><input type="hidden" name="aktion" value="loeschen"><input type="hidden" name="id" value="<?= $z['id'] ?>">
              <button class="btn small danger">×</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </details>
<?php endforeach; ?>
</div>
<?php page_foot();
