<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();
check_csrf();

$tab = $_GET['tab'] ?? 'kostenarten';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['aktion'] ?? '';
    if ($a === 'einstellungen') {
        foreach ($_POST['e'] ?? [] as $k => $v) {
            q('INSERT INTO einstellungen (schluessel, wert) VALUES (?,?) ON DUPLICATE KEY UPDATE wert=VALUES(wert)', [$k, trim($v)]);
        }
        flash('Einstellungen gespeichert.');
    } elseif ($a === 'objekt') {
        $d = [trim($_POST['bezeichnung']), trim($_POST['strasse']), trim($_POST['plz']), trim($_POST['ort']),
              parse_num($_POST['gesamtflaeche']), max(1, (int)$_POST['anzahl_einheiten']), max(1, (int)$_POST['gesamtpersonen']), trim($_POST['notiz'])];
        if (!empty($_POST['id'])) q('UPDATE objekte SET bezeichnung=?, strasse=?, plz=?, ort=?, gesamtflaeche=?, anzahl_einheiten=?, gesamtpersonen=?, notiz=? WHERE id=?', [...$d, (int)$_POST['id']]);
        else q('INSERT INTO objekte (bezeichnung, strasse, plz, ort, gesamtflaeche, anzahl_einheiten, gesamtpersonen, notiz) VALUES (?,?,?,?,?,?,?,?)', $d);
        flash('Objekt gespeichert.');
    } elseif ($a === 'kostenart') {
        $d = [trim($_POST['bezeichnung']), trim($_POST['gruppe']) ?: null, $_POST['schluessel'],
              trim($_POST['prozent']) === '' ? null : parse_num($_POST['prozent']), isset($_POST['umlagefaehig']) ? 1 : 0,
              (int)$_POST['sortierung'], trim($_POST['hinweis']) ?: null];
        if (!empty($_POST['id'])) q('UPDATE kostenarten SET bezeichnung=?, gruppe=?, schluessel=?, prozent=?, umlagefaehig=?, sortierung=?, hinweis=? WHERE id=?', [...$d, (int)$_POST['id']]);
        else q('INSERT INTO kostenarten (bezeichnung, gruppe, schluessel, prozent, umlagefaehig, sortierung, hinweis) VALUES (?,?,?,?,?,?,?)', $d);
        flash('Kostenart gespeichert.');
    } elseif ($a === 'kostenart_loeschen') {
        if ((int)val('SELECT COUNT(*) FROM zuordnungen WHERE kostenart_id=?', [(int)$_POST['id']]) > 0) flash('Kostenart wird verwendet und kann nicht gelöscht werden.', 'err');
        else { q('DELETE FROM kostenarten WHERE id=?', [(int)$_POST['id']]); flash('Kostenart gelöscht.'); }
    } elseif ($a === 'regel') {
        $d = [trim($_POST['suchtext']), (int)$_POST['kostenart_id'], $_POST['nur_vorzeichen'], (int)$_POST['prioritaet']];
        if (!empty($_POST['id'])) q('UPDATE regeln SET suchtext=?, kostenart_id=?, nur_vorzeichen=?, prioritaet=? WHERE id=?', [...$d, (int)$_POST['id']]);
        else q('INSERT INTO regeln (suchtext, kostenart_id, nur_vorzeichen, prioritaet) VALUES (?,?,?,?)', $d);
        flash('Regel gespeichert.');
    } elseif ($a === 'regel_loeschen') {
        q('DELETE FROM regeln WHERE id=?', [(int)$_POST['id']]);
        flash('Regel gelöscht.');
    }
    redirect('stammdaten.php?tab=' . urlencode($tab));
}

page_head('Stammdaten', 'stammdaten.php');
$tabs = ['kostenarten' => 'Kostenarten & Schlüssel', 'regeln' => 'Zuordnungsregeln', 'objekt' => 'Objekt', 'einstellungen' => 'Absender & Einstellungen'];
?>
<h1>Stammdaten</h1>
<nav class="tabs"><?php foreach ($tabs as $k => $l): ?><a href="?tab=<?= $k ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= h($l) ?></a><?php endforeach; ?></nav>

<?php if ($tab === 'kostenarten'):
    $ka = rows('SELECT k.*, (SELECT COUNT(*) FROM zuordnungen z WHERE z.kostenart_id=k.id) n FROM kostenarten k ORDER BY umlagefaehig DESC, sortierung, id');
    $edit = isset($_GET['id']) ? row('SELECT * FROM kostenarten WHERE id=?', [(int)$_GET['id']]) : null; ?>
  <p class="muted">Positionen mit gleicher <i>Gruppe</i> erscheinen in der Abrechnung als Unterpunkte a), b) … (wie „Wasserversorgung“). Die Reihenfolge ergibt sich aus <i>Sortierung</i>.</p>
  <table class="list">
    <thead><tr><th>Sort.</th><th>Gruppe</th><th>Bezeichnung</th><th>Schlüssel</th><th>Umlage</th><th class="r">verwendet</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($ka as $k): ?>
      <tr class="<?= $k['umlagefaehig'] ? '' : 'muted' ?>">
        <td><?= $k['sortierung'] ?></td><td><?= h($k['gruppe']) ?></td><td><b><?= h($k['bezeichnung']) ?></b><div class="small muted"><?= h($k['hinweis']) ?></div></td>
        <td><?= h(schluessel_label($k['schluessel'])) ?><?= $k['prozent'] !== null ? ' ' . num($k['prozent'], 2) . ' %' : '' ?></td>
        <td><?= $k['umlagefaehig'] ? 'ja' : 'nein' ?></td><td class="r"><?= $k['n'] ?></td>
        <td class="nowrap"><a class="btn small ghost" href="?tab=kostenarten&id=<?= $k['id'] ?>#form">bearbeiten</a>
          <?php if (!$k['n']): ?><form method="post" class="inline" onsubmit="return confirm('Löschen?')"><?= csrf_field() ?><input type="hidden" name="aktion" value="kostenart_loeschen"><input type="hidden" name="id" value="<?= $k['id'] ?>"><button class="btn small danger">×</button></form><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <form method="post" class="card" id="form">
    <?= csrf_field() ?><input type="hidden" name="aktion" value="kostenart"><input type="hidden" name="id" value="<?= h($edit['id'] ?? '') ?>">
    <h2><?= $edit ? 'Kostenart bearbeiten' : 'Neue Kostenart' ?></h2>
    <div class="grid3">
      <label>Bezeichnung<input name="bezeichnung" value="<?= h($edit['bezeichnung'] ?? '') ?>" required></label>
      <label>Gruppe (optional)<input name="gruppe" value="<?= h($edit['gruppe'] ?? '') ?>" placeholder="z. B. Wasserversorgung"></label>
      <label>Sortierung<input type="number" name="sortierung" value="<?= h($edit['sortierung'] ?? 100) ?>"></label>
      <label>Verteilerschlüssel<select name="schluessel">
        <?php foreach (['flaeche' => 'Fläche (Wohnfläche/Gesamtfläche bzw. Anteil % im Vertrag)', 'direkt' => 'Direkt (100 % oder % unten)', 'personen' => 'Personen', 'einheiten' => 'Einheiten (1/Anzahl)', 'prozent' => 'Fester Prozentsatz'] as $k => $l): ?>
          <option value="<?= $k ?>" <?= ($edit['schluessel'] ?? 'flaeche') === $k ? 'selected' : '' ?>><?= h($l) ?></option>
        <?php endforeach; ?></select></label>
      <label>Prozent (für „fest“/„direkt“)<input name="prozent" value="<?= isset($edit['prozent']) && $edit['prozent'] !== null ? num($edit['prozent'], 2) : '' ?>"></label>
      <label class="chk"><input type="checkbox" name="umlagefaehig" <?= ($edit['umlagefaehig'] ?? 1) ? 'checked' : '' ?>> umlagefähig (erscheint in der Abrechnung)</label>
      <label class="span3">Hinweis<input name="hinweis" value="<?= h($edit['hinweis'] ?? '') ?>" placeholder="enthält „Zähler“ → Schlüsseltext „Zähler (100 %)“"></label>
    </div>
    <div class="actions"><button class="btn">Speichern</button> <?php if ($edit): ?><a class="btn ghost" href="?tab=kostenarten">Neu</a><?php endif; ?></div>
  </form>

<?php elseif ($tab === 'regeln'):
    $rg = rows('SELECT r.*, k.bezeichnung, k.gruppe FROM regeln r JOIN kostenarten k ON k.id=r.kostenart_id ORDER BY r.prioritaet, r.id');
    $edit = isset($_GET['id']) ? row('SELECT * FROM regeln WHERE id=?', [(int)$_GET['id']]) : null; ?>
  <p class="muted">Beim Import wird der Text (Name + Verwendungszweck) mit den Suchtexten verglichen – die erste passende Regel (kleinste Priorität) bestimmt die Kostenart. Das Abrechnungsjahr ist das Buchungsjahr, außer der Zweck nennt einen Zeitraum (z. B. Versicherung 01.12.2025 – 01.12.2026 → 2026).</p>
  <table class="list">
    <thead><tr><th>Prio</th><th>Suchtext</th><th>nur</th><th>→ Kostenart</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rg as $r): ?>
      <tr><td><?= $r['prioritaet'] ?></td><td><code><?= h($r['suchtext']) ?></code></td><td><?= h($r['nur_vorzeichen']) ?></td><td><?= h(kostenart_label($r)) ?></td>
        <td class="nowrap"><a class="btn small ghost" href="?tab=regeln&id=<?= $r['id'] ?>#form">bearbeiten</a>
          <form method="post" class="inline" onsubmit="return confirm('Löschen?')"><?= csrf_field() ?><input type="hidden" name="aktion" value="regel_loeschen"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn small danger">×</button></form></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <form method="post" class="card" id="form">
    <?= csrf_field() ?><input type="hidden" name="aktion" value="regel"><input type="hidden" name="id" value="<?= h($edit['id'] ?? '') ?>">
    <h2><?= $edit ? 'Regel bearbeiten' : 'Neue Regel' ?></h2>
    <div class="inline wrap">
      <input name="suchtext" value="<?= h($edit['suchtext'] ?? '') ?>" placeholder="Suchtext" required>
      <select name="kostenart_id" required><?= kostenart_options(isset($edit['kostenart_id']) ? (int)$edit['kostenart_id'] : null, false) ?></select>
      <select name="nur_vorzeichen"><?php foreach (['alle' => 'Ein- und Ausgänge', 'ausgabe' => 'nur Ausgänge', 'einnahme' => 'nur Eingänge'] as $k => $l): ?><option value="<?= $k ?>" <?= ($edit['nur_vorzeichen'] ?? '') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
      <input type="number" name="prioritaet" value="<?= h($edit['prioritaet'] ?? 50) ?>" class="w5" title="Priorität">
      <button class="btn">Speichern</button>
    </div>
  </form>

<?php elseif ($tab === 'objekt'):
    foreach (rows('SELECT * FROM objekte ORDER BY id') as $o): ?>
  <form method="post" class="card">
    <?= csrf_field() ?><input type="hidden" name="aktion" value="objekt"><input type="hidden" name="id" value="<?= $o['id'] ?>">
    <div class="grid3">
      <label class="span3">Bezeichnung<input name="bezeichnung" value="<?= h($o['bezeichnung']) ?>"></label>
      <label>Straße<input name="strasse" value="<?= h($o['strasse']) ?>"></label>
      <label>PLZ<input name="plz" value="<?= h($o['plz']) ?>"></label>
      <label>Ort<input name="ort" value="<?= h($o['ort']) ?>"></label>
      <label>Gesamtfläche m²<input name="gesamtflaeche" value="<?= num($o['gesamtflaeche']) ?>"></label>
      <label>Anzahl Einheiten/Haushalte<input type="number" name="anzahl_einheiten" value="<?= $o['anzahl_einheiten'] ?>"></label>
      <label>Personen gesamt<input type="number" name="gesamtpersonen" value="<?= $o['gesamtpersonen'] ?>"></label>
      <label class="span3">Notiz<textarea name="notiz" rows="2"><?= h($o['notiz']) ?></textarea></label>
    </div>
    <div class="actions"><button class="btn">Speichern</button></div>
  </form>
<?php endforeach; ?>

<?php else:
    $felder = [
        'absender_name' => 'Absender Name', 'absender_zusatz' => 'Zusatz (z. B. Hausverwaltung)', 'absender_strasse' => 'Straße',
        'absender_ort' => 'PLZ Ort', 'absender_telefon' => 'Telefon', 'absender_email' => 'E-Mail',
        'verwaltung_iban' => 'IBAN Verwaltungskonto', 'verwaltung_bank' => 'Bank Verwaltungskonto',
        'aktenzeichen' => 'Aktenzeichen (für Auslagenaufstellung)',
        'km_satz' => 'Kilometersatz € (Auslagen)', 'fahrt_standard_ziel' => 'Standard-Fahrtziel', 'fahrt_standard_km' => 'Standard-Strecke km (einfach)',
    ]; ?>
  <form method="post" class="card">
    <?= csrf_field() ?><input type="hidden" name="aktion" value="einstellungen">
    <div class="grid2">
    <?php foreach ($felder as $k => $l): ?>
      <label><?= h($l) ?><input name="e[<?= $k ?>]" value="<?= h(setting($k)) ?>"></label>
    <?php endforeach; ?>
    </div>
    <div class="actions"><button class="btn">Speichern</button></div>
  </form>
<?php endif;
page_foot();
