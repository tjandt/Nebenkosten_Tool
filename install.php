<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/logik.php';

$vorhanden = (bool)rows("SHOW TABLES LIKE 'einstellungen'");
if ($vorhanden) {
    require_login();
}

$meldungen = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (!$vorhanden && !check_password((string)($_POST['passwort'] ?? ''))) {
        $meldungen[] = ['err', 'Passwort stimmt nicht mit config.php überein.'];
    } elseif ($vorhanden) {
        $meldungen[] = ['err', 'Die Datenbank ist bereits eingerichtet. Zum Neuaufsetzen bitte die Tabellen manuell löschen.'];
    } else {
        $sqlDateien = ['schema.sql', 'seed.sql'];
        if (!empty($_POST['mit_buchungen'])) $sqlDateien[] = 'seed_buchungen.sql';
        foreach ($sqlDateien as $f) {
            $sql = file_get_contents(__DIR__ . '/install/' . $f);
            $sql = preg_replace('/^--.*$/m', '', $sql);
            foreach (preg_split('/;\s*\n/', $sql) as $stmt) {
                if (trim($stmt) !== '') db()->exec($stmt);
            }
        }
        // automatisch zuordnen
        $n = 0;
        foreach (rows('SELECT id FROM buchungen ORDER BY datum, id') as $b) if (auto_zuordnen((int)$b['id'])) $n++;

        // Bekannte Vorgänge aus den Unterlagen
        if (!empty($_POST['mit_buchungen'])) {
            // Verwaltungspauschalen als bereits erstattete Auslagen
            foreach (rows("SELECT id, datum, betrag FROM buchungen WHERE zweck LIKE 'Pauschale Hausverwaltung%' ORDER BY datum") as $b) {
                q("INSERT INTO auslagen (datum, kategorie, beschreibung, empfaenger, betrag, status, erstattet_am, buchung_id, notiz)
                   VALUES (?, 'Pauschale', ?, 'Tilo Jandt', ?, 'erstattet', ?, ?, 'Pauschaler Auslagenersatz (Porto, Telefon, Fahrten) – aus Kontoauszug übernommen')",
                  [$b['datum'], 'Pauschale Hausverwaltung ' . substr($b['datum'], 0, 4), abs((float)$b['betrag']), $b['datum'], $b['id']]);
            }
            // Abrechnung 2024 als Archiv (Werte aus Nebenkostenabrechnung_2024.pdf)
            $pos = [
                ['position', '1.', false, 'Grundsteuer', 317.68, 'Fläche (31 %)', '317,68 × 31 %', 98.48],
                ['gruppe', '2.', false, 'Wasserversorgung'],
                ['position', 'a)', true, 'Grundgebühr', 152.41, 'Direkt (100 %)', 'Direktzuordnung', 152.41],
                ['position', 'b)', true, 'Verbrauch', 90.27, 'Zähler (100 %)', 'Direktzuordnung', 90.27],
                ['gruppe', '3.', false, 'Entwässerung'],
                ['position', 'a)', true, 'Grundgebühr', 59.55, 'Direkt (100 %)', 'Direktzuordnung', 59.55],
                ['position', 'b)', true, 'Verbrauch', 63.31, 'Zähler (100 %)', 'Direktzuordnung', 63.31],
                ['position', '4.', false, 'Niederschlagswasser', 51.78, 'Fläche (31 %)', '51,78 × 31 %', 16.05],
                ['position', '5.', false, 'Gebäudeversicherung', 790.47, 'Fläche (31 %)', '790,47 × 31 %', 245.05],
                ['position', '6.', false, 'Haftpflichtvers.', 30.35, 'Fläche (31 %)', '30,35 × 31 %', 9.41],
                ['position', '7.', false, 'Allgemeinstrom', 241.37, 'Fläche (31 %)', '241,37 × 31 %', 74.82],
                ['position', '8.', false, 'Müllbeseitigung', 88.68, 'Direkt (100 %)', 'Direktzuordnung', 88.68],
                ['position', '9.', false, 'Schornsteinfeger', 110.52, 'Fläche (31 %)', '110,52 × 31 %', 34.26],
            ];
            $positionen = array_map(fn($p) => $p[0] === 'gruppe'
                ? ['typ' => 'gruppe', 'nr' => $p[1], 'bezeichnung' => $p[3]]
                : ['typ' => 'position', 'nr' => $p[1], 'unter' => $p[2], 'bezeichnung' => $p[3], 'gesamt' => $p[4], 'schluessel' => $p[5], 'berechnung' => $p[6], 'anteil' => $p[7]], $pos);
            $basis = berechne_abrechnung(1, 2024);
            $snap = array_merge($basis, [
                'positionen' => $positionen, 'summe' => 932.29, 'vorauszahlungen' => 782.15, 'saldo' => -150.14,
                'sonderposten' => [
                    ['bezeichnung' => 'Gartenpacht', 'erlaeuterung' => 'Verrechnung', 'betrag' => 0.0, 'art' => 'belastung'],
                    ['bezeichnung' => 'Gutschrift Hausmeister', 'erlaeuterung' => 'Vergütung', 'betrag' => 306.78, 'art' => 'gutschrift'],
                ],
                'ergebnis' => 156.64, 'flaechen_prozent' => 31, 'briefdatum' => '2025-12-01', 'belegliste' => [],
                'hinweise' => 'Archiv: übernommen aus Nebenkostenabrechnung_2024.pdf', 'archiv' => true,
            ]);
            $snap['schluss'] = standard_schluss($basis['vertrag'], 156.64);
            $ausgleich = val("SELECT id FROM buchungen WHERE zweck LIKE 'Nebenkostenr%2024%'") ?: null;
            q("INSERT INTO abrechnungen (vertrag_id, jahr, erstellt_am, daten, ergebnis, status, buchung_id) VALUES (1, 2024, '2025-12-01', ?, 156.64, ?, ?)",
              [json_encode($snap, JSON_UNESCAPED_UNICODE), $ausgleich ? 'ausgeglichen' : 'versendet', $ausgleich]);
        }
        $meldungen[] = ['ok', 'Datenbank eingerichtet. ' . (int)val('SELECT COUNT(*) FROM buchungen') . " Buchungen, davon $n automatisch zugeordnet."];
        $vorhanden = true;
    }
}

$ordner = $CFG['beleg_ordner'] ?? __DIR__ . '/belege';
if (!is_dir($ordner)) @mkdir($ordner, 0770, true);
?><!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Einrichtung · Nebenkosten</title><link rel="stylesheet" href="assets/style.css"></head>
<body class="login"><div class="card narrow">
<h1>Einrichtung</h1>
<?php foreach ($meldungen as [$t, $m]) echo '<div class="flash ' . $t . '">' . h($m) . '</div>'; ?>
<?php if ($vorhanden): ?>
  <p>Die Datenbank ist eingerichtet.</p>
  <p><a class="btn" href="index.php">Zur Anwendung</a></p>
  <p class="muted">Aus Sicherheitsgründen kannst du <code>install.php</code> und den Ordner <code>install/</code> jetzt vom Server löschen.</p>
<?php else: ?>
  <p>Legt die Tabellen in der Datenbank <b><?= h($CFG['db_name']) ?></b> an und übernimmt die Stammdaten (Objekt Geithainer Str. 3, Mietvertrag Rantzuch, Kostenarten wie in der Abrechnung 2024).</p>
  <form method="post">
    <?= csrf_field() ?>
    <label><input type="checkbox" name="mit_buchungen" value="1" checked> Kontoumsätze 10/2021 – 10/2026 aus den N26-Auszügen übernehmen (235 Buchungen, Endsaldo 1.198,98 €), Abrechnung 2024 archivieren</label>
    <label>Passwort aus config.php<input type="password" name="passwort" required autofocus></label>
    <button class="btn">Einrichten</button>
  </form>
<?php endif; ?>
</div></body></html>
