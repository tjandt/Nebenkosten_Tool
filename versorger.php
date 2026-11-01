<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/versorger.php';
require_login();
check_csrf();

$ordner = $CFG['beleg_ordner'] ?? __DIR__ . '/belege';
$vertraege = rows('SELECT * FROM versorgungsvertraege ORDER BY bezeichnung, id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        [$datei, $name] = versorger_datei_speichern($_FILES['pdf'] ?? []);
        $text = trim((string)($_POST['text'] ?? '')) !== '' ? (string)$_POST['text'] : pdf_text($ordner . '/' . $datei);
        $e = versorger_erkennen($text);

        $vertragId = ($_POST['vertrag'] ?? '') !== '' ? (int)$_POST['vertrag'] : $e['vertrag_id'];
        $jahr = ($_POST['jahr'] ?? '') !== '' ? (int)$_POST['jahr'] : ($e['jahr'] ?: (int)date('Y') - 1);
        q('INSERT INTO versorger_abrechnungen (versorgungsvertrag_id, jahr, zeitraum_von, zeitraum_bis, rechnungsdatum, rechnungsnummer, datei, datei_name, text_auszug)
           VALUES (?,?,?,?,?,?,?,?,?)',
          [$vertragId, $jahr, $e['von'], $e['bis'], $e['rechnungsdatum'], $e['rechnungsnummer'], $datei, $name, $text !== '' ? $text : null]);
        $id = (int)db()->lastInsertId();

        $hinweis = $text === '' ? ' Text konnte nicht automatisch gelesen werden – Beträge bitte aus dem PDF eintragen.' : '';
        if ($text !== '') {
            $hinweis = ' Erkannt: ' . ($e['vertrag_id'] ? 'Vertrag' : 'kein Vertrag') . ($e['von'] ? ', Zeitraum ' . dt($e['von']) . '–' . dt($e['bis']) : ', kein Zeitraum') . '. Bitte prüfen.';
        }
        flash('PDF hochgeladen.' . $hinweis);
        redirect('versorger_abrechnung.php?id=' . $id);
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'err');
        redirect('versorger.php');
    }
}

$filterVertrag = (int)($_GET['vertrag'] ?? 0);
$filterJahr = (int)($_GET['jahr'] ?? 0);
$where = []; $p = [];
if ($filterVertrag) { $where[] = 'a.versorgungsvertrag_id=?'; $p[] = $filterVertrag; }
if ($filterJahr) { $where[] = 'a.jahr=?'; $p[] = $filterJahr; }
$liste = rows('SELECT a.id FROM versorger_abrechnungen a' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY a.jahr DESC, a.id DESC', $p);

$kannLesen = !in_array('shell_exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true)
    && function_exists('shell_exec') && trim((string)@shell_exec('command -v pdftotext 2>/dev/null')) !== '';

page_head('Versorger-Abrechnungen', 'versorger.php');
?>
<h1>Jahresabrechnungen der Versorger</h1>
<p class="muted">PDF der Jahresabrechnung (Wasser/Abwasser, Strom, Müll, Versicherung, Grundsteuerbescheid …) hochladen, Beträge erfassen und mit den Buchungen vom Kontoauszug abgleichen. Die geprüften Beträge werden anschließend als Kosten für die Nebenkostenabrechnung übernommen.</p>

<section class="card">
  <h2>PDF hochladen</h2>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="grid3">
      <label>PDF-Datei<input type="file" name="pdf" accept=".pdf,application/pdf,image/*" required></label>
      <label>Versorgungsvertrag
        <select name="vertrag"><option value="">– automatisch erkennen / später wählen –</option>
          <?php foreach ($vertraege as $v): ?><option value="<?= $v['id'] ?>" <?= $filterVertrag === (int)$v['id'] ? 'selected' : '' ?>><?= h(versorger_label($v)) ?></option><?php endforeach; ?>
        </select></label>
      <label>Abrechnungsjahr (leer = aus PDF)<input type="number" name="jahr" min="2000" max="2100" placeholder="<?= (int)date('Y') - 1 ?>"></label>
    </div>
    <details>
      <summary class="small muted" style="cursor:pointer">Text aus dem PDF einfügen (falls er nicht automatisch gelesen werden kann)</summary>
      <textarea name="text" rows="6" placeholder="Im PDF alles markieren, kopieren und hier einfügen – daraus werden Vertrag, Zeitraum, Rechnungsdatum und Beträge erkannt."></textarea>
    </details>
    <p class="muted small"><?= $kannLesen
        ? 'Der Text wird automatisch aus dem PDF gelesen (pdftotext).'
        : 'Auf diesem Server steht kein <code>pdftotext</code> zur Verfügung – der Text wird nicht automatisch gelesen. Die Beträge werden dann auf der nächsten Seite von Hand eingetragen (oder oben den kopierten PDF-Text einfügen).' ?> Erlaubt: PDF, jpg, png · max. 20 MB.</p>
    <div class="actions"><button class="btn">Hochladen &amp; weiter</button></div>
  </form>
</section>

<div class="head-row">
  <h2>Hochgeladene Abrechnungen</h2>
  <form method="get" class="inline wrap">
    <select name="vertrag" onchange="this.form.submit()"><option value="">alle Verträge</option>
      <?php foreach ($vertraege as $v): ?><option value="<?= $v['id'] ?>" <?= $filterVertrag === (int)$v['id'] ? 'selected' : '' ?>><?= h($v['bezeichnung']) ?></option><?php endforeach; ?>
    </select>
    <input type="number" name="jahr" value="<?= $filterJahr ?: '' ?>" placeholder="Jahr" class="w6" onchange="this.form.submit()">
  </form>
</div>
<table class="list">
  <thead><tr><th>Jahr</th><th>Vertrag</th><th>Zeitraum</th><th class="r">Gesamtkosten</th><th>Abgleich Kontoauszug</th><th>Kosten</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($liste as $r):
      $d = versorger_laden((int)$r['id']);
      $ab = versorger_abgleich($d);
      [$von, $bis] = versorger_zeitraum($d); ?>
    <tr>
      <td><b><?= $d['jahr'] ?></b></td>
      <td><?= $d['vertrag'] ? h($d['vertrag']['bezeichnung']) . '<div class="small muted">' . h($d['vertrag']['anbieter']) . '</div>' : '<span class="neg">kein Vertrag gewählt</span>' ?>
          <div class="small muted"><?= h($d['datei_name']) ?></div></td>
      <td class="small nowrap"><?= dt($von) ?> – <?= dt($bis) ?></td>
      <td class="r nowrap"><?= $d['positionen'] ? eur($d['gesamt']) : '<span class="muted">offen</span>' ?></td>
      <td class="small">
        <?php $warn = array_filter($ab['checks'], fn($c) => $c[0] === 'warn'); ?>
        <?php if ($ab['status'] === 'ok'): ?><span class="tag ausgeglichen">✓ stimmt überein</span>
        <?php elseif ($ab['status'] === 'warn'): ?><span class="tag entwurf">⚠ <?= count($warn) ?> Abweichung<?= count($warn) > 1 ? 'en' : '' ?></span>
        <?php else: ?><span class="tag">noch offen</span><?php endif; ?>
      </td>
      <td><?= $d['uebernommen'] ? '<span class="tag ausgeglichen">übernommen</span>' : '<span class="tag entwurf">nicht übernommen</span>' ?></td>
      <td class="nowrap"><a class="btn small" href="versorger_abrechnung.php?id=<?= $d['id'] ?>">Öffnen</a>
        <?php if ($d['datei']): ?><a class="btn small ghost" target="_blank" href="beleg.php?typ=versorger&id=<?= $d['id'] ?>">PDF</a><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$liste): ?><tr><td colspan="7" class="muted">Noch keine Abrechnungen hochgeladen.</td></tr><?php endif; ?>
  </tbody>
</table>
<?php if (!$vertraege): ?><p class="muted">Tipp: Lege zuerst unter <a href="stammdaten.php?tab=versorgung">Stammdaten → Versorgungsverträge</a> Wasser, Strom usw. mit Vertragsnummer an – dann werden PDFs und Buchungen automatisch zugeordnet.</p><?php endif; ?>
<?php page_foot();
