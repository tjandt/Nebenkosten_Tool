<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();
check_csrf();

$id = (int)($_GET['id'] ?? 0);
$a = $id ? row('SELECT * FROM auslagen WHERE id=?', [$id]) : null;
if ($id && !$a) redirect('auslagen.php');
$ordner = $CFG['beleg_ordner'] ?? __DIR__ . '/belege';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['aktion'] ?? '') === 'loeschen' && $a) {
        if ($a['beleg_datei']) @unlink($ordner . '/' . $a['beleg_datei']);
        q('DELETE FROM auslagen WHERE id=?', [$id]);
        flash('Auslage gelöscht.');
        redirect('auslagen.php');
    }
    $kat = in_array($_POST['kategorie'], AUSLAGE_KATEGORIEN, true) ? $_POST['kategorie'] : 'Sonstiges';
    $km = trim($_POST['km'] ?? '') === '' ? null : parse_num($_POST['km']);
    $hr = isset($_POST['hin_rueck']) ? 1 : 0;
    $satz = trim($_POST['km_satz'] ?? '') === '' ? null : parse_num($_POST['km_satz']);
    $betrag = parse_num($_POST['betrag'] ?? '');
    if ($kat === 'Fahrtkosten' && $km && $satz) $betrag = round($km * ($hr ? 2 : 1) * $satz, 2);

    $datei = $a['beleg_datei'] ?? null; $dname = $a['beleg_name'] ?? null;
    if (!empty($_FILES['beleg']['tmp_name']) && is_uploaded_file($_FILES['beleg']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['beleg']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'heic', 'webp'], true)) {
            flash('Beleg: nur PDF oder Bild (jpg, png, heic, webp).', 'err');
        } elseif ($_FILES['beleg']['size'] > 15 * 1024 * 1024) {
            flash('Beleg zu groß (max. 15 MB).', 'err');
        } else {
            if (!is_dir($ordner)) mkdir($ordner, 0770, true);
            if ($datei) @unlink($ordner . '/' . $datei);
            $datei = date('Ymd') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            move_uploaded_file($_FILES['beleg']['tmp_name'], $ordner . '/' . $datei);
            $dname = basename($_FILES['beleg']['name']);
        }
    }
    $st = in_array($_POST['status'], ['offen', 'eingereicht', 'erstattet'], true) ? $_POST['status'] : 'offen';
    $d = [$_POST['datum'], $kat, trim($_POST['beschreibung']), trim($_POST['empfaenger'] ?? ''), $km, $hr, $satz, $betrag,
          $datei, $dname, $st, $st === 'erstattet' ? ($_POST['erstattet_am'] ?: date('Y-m-d')) : null,
          $st === 'erstattet' && ($_POST['buchung_id'] ?? '') !== '' ? (int)$_POST['buchung_id'] : null, trim($_POST['notiz'])];
    if ($a) {
        q('UPDATE auslagen SET datum=?, kategorie=?, beschreibung=?, empfaenger=?, km=?, hin_rueck=?, km_satz=?, betrag=?, beleg_datei=?, beleg_name=?, status=?, erstattet_am=?, buchung_id=?, notiz=? WHERE id=?', [...$d, $id]);
    } else {
        q('INSERT INTO auslagen (datum, kategorie, beschreibung, empfaenger, km, hin_rueck, km_satz, betrag, beleg_datei, beleg_name, status, erstattet_am, buchung_id, notiz) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', $d);
    }
    flash('Auslage gespeichert: ' . eur($betrag));
    redirect(isset($_POST['weitere']) ? 'auslage.php?' . ($kat === 'Fahrtkosten' ? 'fahrt=1' : ($kat === 'Porto' ? 'porto=1' : '')) : 'auslagen.php');
}

$fahrt = isset($_GET['fahrt']); $porto = isset($_GET['porto']);
$a ??= [
    'datum' => date('Y-m-d'),
    'kategorie' => $fahrt ? 'Fahrtkosten' : ($porto ? 'Porto' : 'Sonstiges'),
    'beschreibung' => $fahrt ? 'Fahrt Melsungen – ' . setting('fahrt_standard_ziel') : ($porto ? 'Porto Brief an ' : ''),
    'empfaenger' => '', 'km' => $fahrt ? setting('fahrt_standard_km') : null, 'hin_rueck' => 1,
    'km_satz' => setting('km_satz', '0.30'), 'betrag' => $porto ? 0.95 : '', 'status' => 'offen', 'notiz' => '',
    'beleg_datei' => null, 'erstattet_am' => null, 'buchung_id' => null,
];
$buchungen = rows("SELECT b.id, b.datum, b.betrag, b.zweck FROM buchungen b WHERE b.betrag<0 AND (b.name LIKE '%Jandt%' OR b.zweck LIKE '%Auslage%' OR b.zweck LIKE '%Pauschale%') ORDER BY b.datum DESC");

page_head('Auslage', 'auslagen.php');
?>
<p><a href="auslagen.php">← Auslagen</a></p>
<h1><?= $id ? 'Auslage bearbeiten' : 'Neue Auslage' ?></h1>
<form method="post" enctype="multipart/form-data" class="card" id="auslageForm">
  <?= csrf_field() ?>
  <div class="grid3">
    <label>Datum<input type="date" name="datum" value="<?= h($a['datum']) ?>" required></label>
    <label>Kategorie<select name="kategorie" id="kat"><?php foreach (AUSLAGE_KATEGORIEN as $k): ?><option <?= $a['kategorie'] === $k ? 'selected' : '' ?>><?= h($k) ?></option><?php endforeach; ?></select></label>
    <label>Empfänger / Anlass<input name="empfaenger" value="<?= h($a['empfaenger']) ?>" placeholder="z. B. Nachlassgericht, Lechner, Mieterin"></label>
    <label class="span3">Beschreibung<input name="beschreibung" value="<?= h($a['beschreibung']) ?>" required></label>
  </div>
  <fieldset id="fahrtBox" class="<?= $a['kategorie'] === 'Fahrtkosten' ? '' : 'hidden' ?>">
    <legend>Fahrtkosten</legend>
    <div class="grid3">
      <label>Kilometer (einfache Strecke)<input name="km" id="km" value="<?= $a['km'] !== null && $a['km'] !== '' ? num($a['km'], 1) : '' ?>"></label>
      <label class="chk"><input type="checkbox" name="hin_rueck" id="hr" <?= $a['hin_rueck'] ? 'checked' : '' ?>> Hin- und Rückfahrt</label>
      <label>€ pro km<input name="km_satz" id="satz" value="<?= $a['km_satz'] !== null ? num($a['km_satz']) : '' ?>"></label>
    </div>
  </fieldset>
  <div class="grid3">
    <label>Betrag €<input name="betrag" id="betrag" value="<?= $a['betrag'] !== '' ? num($a['betrag']) : '' ?>" required></label>
    <label>Beleg (PDF/Foto)<input type="file" name="beleg" accept=".pdf,image/*">
      <?php if ($a['beleg_datei']): ?><small><a href="beleg.php?id=<?= $id ?>" target="_blank">vorhandener Beleg: <?= h($a['beleg_name']) ?></a></small><?php endif; ?></label>
    <label>Status<select name="status"><?php foreach (['offen', 'eingereicht', 'erstattet'] as $s): ?><option <?= $a['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></label>
    <label>Erstattet am<input type="date" name="erstattet_am" value="<?= h($a['erstattet_am']) ?>"></label>
    <label class="span2">Erstattungsbuchung<select name="buchung_id"><option value="">–</option>
      <?php foreach ($buchungen as $b): ?><option value="<?= $b['id'] ?>" <?= $a['buchung_id'] == $b['id'] ? 'selected' : '' ?>><?= dt($b['datum']) ?> <?= eur($b['betrag']) ?> <?= h($b['zweck']) ?></option><?php endforeach; ?></select></label>
    <label class="span3">Notiz<textarea name="notiz" rows="2"><?= h($a['notiz']) ?></textarea></label>
  </div>
  <div class="actions">
    <button class="btn">Speichern</button>
    <?php if (!$id): ?><button class="btn ghost" name="weitere" value="1">Speichern &amp; weitere erfassen</button><?php endif; ?>
  </div>
</form>
<?php if ($id): ?>
<form method="post" onsubmit="return confirm('Auslage löschen?')" class="right"><?= csrf_field() ?><input type="hidden" name="aktion" value="loeschen"><button class="btn small danger">Löschen</button></form>
<?php endif; ?>
<?php page_foot();
