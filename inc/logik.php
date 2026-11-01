<?php
require_once __DIR__ . '/versorger.php';

/* ---------- Zuordnungsregeln ---------- */

function regel_kostenart(array $b): ?int
{
    static $regeln = null;
    $regeln ??= rows('SELECT * FROM regeln ORDER BY prioritaet, id');
    $text = mb_strtolower(($b['name'] ?? '') . ' ' . ($b['zweck'] ?? '') . ' ' . ($b['typ'] ?? ''));
    $betrag = (float)$b['betrag'];
    // Versorgungsverträge (Vertrags-/Kundennummer, Anbieter-Suchtext) sind genauer als allgemeine Textregeln
    static $vertraege = null;
    $vertraege ??= rows('SELECT * FROM versorgungsvertraege WHERE kostenart_id IS NOT NULL ORDER BY id');
    foreach ($vertraege as $v) {
        if (vertrag_passt_text($v, ($b['name'] ?? '') . ' ' . ($b['zweck'] ?? ''))) return (int)$v['kostenart_id'];
    }
    foreach ($regeln as $r) {
        if ($r['nur_vorzeichen'] === 'ausgabe' && $betrag >= 0) continue;
        if ($r['nur_vorzeichen'] === 'einnahme' && $betrag <= 0) continue;
        if (str_contains($text, mb_strtolower($r['suchtext']))) return (int)$r['kostenart_id'];
    }
    return null;
}

/**
 * Abrechnungsjahr vorschlagen. Enthält der Verwendungszweck einen Zeitraum
 * (z. B. "01.12.2024 - 01.12.2025"), zählt das Jahr, in das der größere Teil fällt.
 */
function vorschlag_jahr(array $b): int
{
    if (preg_match('/(\d{2})\.(\d{2})\.(\d{4})\s*-\s*\d{2}\.\d{2}\.(\d{4})/', (string)($b['zweck'] ?? ''), $m)) {
        $monat = (int)$m[2]; $jahr = (int)$m[3];
        return $monat >= 7 ? $jahr + 1 : $jahr;
    }
    return (int)substr((string)$b['datum'], 0, 4);
}

/** Buchung automatisch zuordnen, falls noch nicht zugeordnet. Gibt true zurück, wenn zugeordnet wurde. */
function auto_zuordnen(int $buchungId): bool
{
    $b = row('SELECT * FROM buchungen WHERE id=?', [$buchungId]);
    if (!$b) return false;
    if ((int)val('SELECT COUNT(*) FROM zuordnungen WHERE buchung_id=?', [$buchungId]) > 0) return false;
    $ka = regel_kostenart($b);
    if (!$ka) return false;
    q('INSERT INTO zuordnungen (buchung_id, kostenart_id, jahr, betrag, datum, beschreibung) VALUES (?,?,?,?,?,NULL)',
      [$buchungId, $ka, vorschlag_jahr($b), -(float)$b['betrag'], $b['datum']]);
    return true;
}

/* ---------- Import ---------- */

function import_hash(string $datum, float $betrag, string $name, string $zweck, array &$zaehler): string
{
    $key = $datum . '|' . number_format($betrag, 2, '.', '') . '|' . $name . '|' . $zweck;
    $zaehler[$key] = ($zaehler[$key] ?? 0) + 1;
    return sha1($key . '#' . $zaehler[$key]);
}

/** Speichert Buchungen, überspringt bereits importierte. Rückgabe [neu, doppelt, zugeordnet] */
function buchungen_speichern(array $liste): array
{
    $neu = $doppelt = $zug = 0; $zaehler = [];
    foreach ($liste as $b) {
        $hash = import_hash($b['datum'], (float)$b['betrag'], $b['name'], $b['zweck'], $zaehler);
        if (val('SELECT id FROM buchungen WHERE import_hash=?', [$hash])) { $doppelt++; continue; }
        q('INSERT INTO buchungen (datum, betrag, name, typ, iban, zweck, import_hash) VALUES (?,?,?,?,?,?,?)',
          [$b['datum'], $b['betrag'], $b['name'], $b['typ'] ?? '', $b['iban'] ?? '', $b['zweck'], $hash]);
        $neu++;
        if (auto_zuordnen((int)db()->lastInsertId())) $zug++;
    }
    return [$neu, $doppelt, $zug];
}

/** Text, der aus einem N26-Kontoauszug (PDF) kopiert wurde. */
function parse_n26_text(string $text): array
{
    $typen = ['Gutschriften', 'Belastungen', 'Lastschriften', 'Business Mastercard', 'N26 Prämie', 'Investitionsgebühr', 'Mastercard', 'Gebühren'];
    $istTyp = fn(string $l) => (bool)array_filter($typen, fn($t) => str_starts_with($l, $t));
    $out = []; $buf = [];
    foreach (preg_split('/\R/u', $text) as $l) {
        $l = trim($l);
        if ($l === '' || in_array($l, ['TILO JANDT', 'Erstellt am', 'Beschreibung Verbuchungsdatum Betrag', 'Kontoaktivität'], true)) continue;
        if (preg_match('/^\d+ \/ \d+$/', $l) || preg_match('/^\d\d\.\d\d\.\d{4}( bis \d\d\.\d\d\.\d{4})?$/', $l)) continue;
        if (preg_match('/^IBAN: DE24 ?1001/', $l) || str_starts_with($l, 'Kirschrain 1')) continue;
        if (preg_match('/^(\d\d)\.(\d\d)\.(\d{4})\s+([+-][\d.]*\d,\d\d)\s?€$/u', $l, $m)) {
            $lines = array_values(array_filter($buf, fn($x) => !str_starts_with($x, 'Wertstellung')));
            $k = 1;
            while ($k < count($lines) - 1 && !$istTyp($lines[$k]) && !str_starts_with($lines[$k], 'IBAN:')) {
                $hatTypDanach = false;
                for ($j = $k + 1; $j < min($k + 3, count($lines)); $j++) if ($istTyp($lines[$j])) $hatTypDanach = true;
                if (!$hatTypDanach) break;
                $k++;
            }
            $name = trim(str_replace('- /', '-/', implode(' ', array_slice($lines, 0, $k))));
            $typ = $lines[$k] ?? '';
            $rest = array_slice($lines, $k + 1);
            $iban = '';
            if ($rest && str_starts_with($rest[0], 'IBAN:')) {
                $iban = trim(str_replace('IBAN:', '', explode('•', $rest[0])[0]));
                array_shift($rest);
            }
            $out[] = ['datum' => "$m[3]-$m[2]-$m[1]", 'betrag' => parse_num($m[4]), 'name' => $name, 'typ' => $typ, 'iban' => $iban, 'zweck' => implode(' ', $rest)];
            $buf = [];
        } else {
            $buf[] = $l;
        }
    }
    return $out;
}

/** CSV-Export einer Bank (N26 neu/alt, Sparkasse, generisch). Spalten werden über die Kopfzeile erkannt. */
function parse_csv(string $inhalt): array
{
    $inhalt = preg_replace('/^\xEF\xBB\xBF/', '', $inhalt);
    if (!mb_check_encoding($inhalt, 'UTF-8')) $inhalt = mb_convert_encoding($inhalt, 'UTF-8', 'Windows-1252');
    $zeilen = preg_split('/\R/u', trim($inhalt));
    $erste = $zeilen[0] ?? '';
    $sep = substr_count($erste, ';') > substr_count($erste, ',') ? ';' : (substr_count($erste, "\t") > substr_count($erste, ',') ? "\t" : ',');
    $kopf = array_map(fn($x) => mb_strtolower(trim($x)), str_getcsv(array_shift($zeilen), $sep, '"', '\\'));
    $finde = function (array $kandidaten) use ($kopf): ?int {
        foreach ($kandidaten as $k) foreach ($kopf as $i => $h) if (str_contains($h, $k)) return $i;
        return null;
    };
    $iDatum = $finde(['booking date', 'buchungstag', 'buchungsdatum', 'datum', 'date']);
    $iBetrag = $finde(['amount (eur)', 'betrag (eur)', 'betrag', 'amount', 'umsatz']);
    $iName = $finde(['partner name', 'empfänger', 'beguenstigter', 'begünstigter', 'zahlungspflichtiger', 'name', 'payee']);
    $iIban = $finde(['partner iban', 'kontonummer', 'iban']);
    $iZweck = $finde(['payment reference', 'verwendungszweck', 'reference', 'zweck', 'buchungstext']);
    $iTyp = $finde(['type', 'transaktionstyp', 'umsatzart', 'buchungsart']);
    if ($iDatum === null || $iBetrag === null) throw new RuntimeException('CSV: Spalten für Datum und Betrag nicht erkannt. Kopfzeile: ' . implode(' | ', $kopf));
    $out = [];
    foreach ($zeilen as $z) {
        if (trim($z) === '') continue;
        $c = str_getcsv($z, $sep, '"', '\\');
        $d = trim($c[$iDatum] ?? '');
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2,4})$/', $d, $m)) {
            $y = strlen($m[3]) === 2 ? '20' . $m[3] : $m[3];
            $d = sprintf('%s-%02d-%02d', $y, $m[2], $m[1]);
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}/', $d)) continue;
        $d = substr($d, 0, 10);
        $roh = trim($c[$iBetrag] ?? '');
        // N26-CSV nutzt Punkt als Dezimaltrenner ohne Tausenderpunkt
        $betrag = preg_match('/^-?\d+\.\d{1,2}$/', $roh) ? (float)$roh : parse_num($roh);
        $out[] = [
            'datum' => $d, 'betrag' => $betrag,
            'name' => trim($c[$iName] ?? ''), 'iban' => trim($c[$iIban] ?? ''),
            'zweck' => trim($c[$iZweck] ?? ''), 'typ' => trim($c[$iTyp] ?? ''),
        ];
    }
    return $out;
}

/* ---------- Abrechnung ---------- */

/** Anzahl Monate des Mietverhältnisses im Jahr */
function monate_im_jahr(array $v, int $jahr): int
{
    $start = max(strtotime($v['beginn']), strtotime("$jahr-01-01"));
    $ende = min($v['ende'] ? strtotime($v['ende']) : PHP_INT_MAX, strtotime("$jahr-12-31"));
    if ($ende < $start) return 0;
    return ((int)date('Y', $ende) - (int)date('Y', $start)) * 12 + (int)date('n', $ende) - (int)date('n', $start) + 1;
}

/**
 * Berechnet die Abrechnung für Vertrag + Jahr. Liefert ein Array, das 1:1 als Schnappschuss gespeichert wird.
 * $eingaben: vorauszahlungen, sonderposten[], briefdatum, einleitung, hinweise, schluss, belegliste
 */
function berechne_abrechnung(int $vertragId, int $jahr, array $eingaben = []): array
{
    $v = row('SELECT * FROM vertraege WHERE id=?', [$vertragId]);
    $o = row('SELECT * FROM objekte WHERE id=?', [$v['objekt_id']]);
    $monate = monate_im_jahr($v, $jahr);
    $zeitfaktor = $monate / 12;

    $flaechenProzent = $v['anteil_prozent'] !== null && $v['anteil_prozent'] !== ''
        ? (float)$v['anteil_prozent']
        : ((float)$o['gesamtflaeche'] > 0 ? round((float)$v['wohnflaeche'] / (float)$o['gesamtflaeche'] * 100, 2) : 0);

    $kostenarten = rows('SELECT * FROM kostenarten WHERE umlagefaehig=1 ORDER BY sortierung, id');
    $summen = [];
    foreach (rows('SELECT z.kostenart_id, SUM(z.betrag) s FROM zuordnungen z WHERE z.jahr=? AND ' . zaehlt_sql() . ' GROUP BY z.kostenart_id', [$jahr]) as $r) {
        $summen[(int)$r['kostenart_id']] = round((float)$r['s'], 2);
    }
    // Kostenarten, deren Jahreskosten aus einer Versorger-Jahresabrechnung (PDF) stammen
    $ausDokument = array_flip(array_map('intval', array_column(
        rows('SELECT DISTINCT kostenart_id FROM zuordnungen WHERE jahr=? AND dokument_id IS NOT NULL', [$jahr]), 'kostenart_id')));

    $positionen = []; $nr = 0; $letzteGruppe = null; $unter = 0; $summe = 0.0;
    foreach ($kostenarten as $k) {
        $gesamt = $summen[(int)$k['id']] ?? 0.0;
        if (abs($gesamt) < 0.005) continue;

        switch ($k['schluessel']) {
            case 'flaeche':
                $f = $flaechenProzent / 100;
                $sText = 'Fläche (' . num($flaechenProzent, fmod($flaechenProzent, 1) ? 2 : 0) . ' %)';
                $bText = num($gesamt) . ' × ' . num($flaechenProzent, fmod($flaechenProzent, 1) ? 2 : 0) . ' %';
                break;
            case 'personen':
                $f = (int)$o['gesamtpersonen'] > 0 ? (int)$v['personen'] / (int)$o['gesamtpersonen'] : 0;
                $sText = 'Personen (' . $v['personen'] . '/' . $o['gesamtpersonen'] . ')';
                $bText = num($gesamt) . ' / ' . $o['gesamtpersonen'] . ' × ' . $v['personen'];
                break;
            case 'einheiten':
                $f = (int)$o['anzahl_einheiten'] > 0 ? 1 / (int)$o['anzahl_einheiten'] : 0;
                $sText = 'Einheiten (1/' . $o['anzahl_einheiten'] . ')';
                $bText = num($gesamt) . ' / ' . $o['anzahl_einheiten'];
                break;
            case 'prozent':
                $p = (float)$k['prozent'];
                $f = $p / 100;
                $sText = 'Anteil (' . num($p, fmod($p, 1) ? 2 : 0) . ' %)';
                $bText = num($gesamt) . ' × ' . num($p, fmod($p, 1) ? 2 : 0) . ' %';
                break;
            default: // direkt
                $p = $k['prozent'] !== null ? (float)$k['prozent'] : 100.0;
                $f = $p / 100;
                $sText = (str_contains(mb_strtolower((string)$k['hinweis']), 'zähler') ? 'Zähler' : 'Direkt') . ' (' . num($p, 0) . ' %)';
                $bText = $p == 100 ? 'Direktzuordnung' : num($gesamt) . ' × ' . num($p, 0) . ' %';
        }
        if ($monate < 12) $bText .= ' × ' . $monate . '/12';
        $anteil = round($gesamt * $f * $zeitfaktor, 2);
        $summe += $anteil;

        if ($k['gruppe']) {
            if ($k['gruppe'] !== $letzteGruppe) {
                $nr++; $unter = 0; $letzteGruppe = $k['gruppe'];
                $positionen[] = ['typ' => 'gruppe', 'nr' => $nr . '.', 'bezeichnung' => $k['gruppe']];
            }
            $label = chr(ord('a') + $unter++) . ')';
        } else {
            $nr++; $letzteGruppe = null; $label = $nr . '.';
        }
        $positionen[] = [
            'typ' => 'position', 'nr' => $label, 'unter' => (bool)$k['gruppe'], 'kostenart_id' => (int)$k['id'],
            'bezeichnung' => $k['bezeichnung'], 'gesamt' => $gesamt, 'schluessel' => $sText,
            'berechnung' => $bText, 'anteil' => $anteil,
            'quelle' => isset($ausDokument[(int)$k['id']]) ? 'Jahresabrechnung' : 'Kontobuchungen',
        ];
    }
    $summe = round($summe, 2);

    $vzVorschlag = round((float)$v['nk_vorauszahlung_jahr'] * $zeitfaktor, 2);
    $vz = isset($eingaben['vorauszahlungen']) && $eingaben['vorauszahlungen'] !== '' ? parse_num($eingaben['vorauszahlungen']) : $vzVorschlag;
    $saldo = round($vz - $summe, 2);

    if (isset($eingaben['sonderposten'])) {
        $sp = [];
        foreach ($eingaben['sonderposten'] as $s) {
            if (trim((string)($s['bezeichnung'] ?? '')) === '') continue;
            $sp[] = ['bezeichnung' => trim($s['bezeichnung']), 'erlaeuterung' => trim((string)($s['erlaeuterung'] ?? '')),
                     'betrag' => round(abs(parse_num($s['betrag'] ?? '0')), 2), 'art' => ($s['art'] ?? '') === 'belastung' ? 'belastung' : 'gutschrift'];
        }
    } else {
        $sp = array_map(fn($s) => ['bezeichnung' => $s['bezeichnung'], 'erlaeuterung' => $s['erlaeuterung'],
            'betrag' => round((float)$s['betrag'] * $zeitfaktor, 2), 'art' => $s['art']],
            rows('SELECT * FROM vertrag_sonderposten WHERE vertrag_id=? ORDER BY id', [$vertragId]));
    }
    $ergebnis = $saldo;
    foreach ($sp as $s) $ergebnis += $s['art'] === 'gutschrift' ? $s['betrag'] : -$s['betrag'];
    $ergebnis = round($ergebnis, 2);

    $belege = [];
    if (!empty($eingaben['belegliste'])) {
        $belege = rows('SELECT z.kostenart_id, z.betrag, COALESCE(z.datum, b.datum) datum,
                COALESCE(NULLIF(z.beschreibung,""), CONCAT_WS(" – ", b.name, b.zweck)) text
            FROM zuordnungen z JOIN kostenarten k ON k.id=z.kostenart_id LEFT JOIN buchungen b ON b.id=z.buchung_id
            WHERE z.jahr=? AND k.umlagefaehig=1 AND ' . zaehlt_sql() . ' ORDER BY k.sortierung, datum', [$jahr]);
    }

    $vonDatum = max($v['beginn'], "$jahr-01-01");
    $bisDatum = min($v['ende'] ?: "$jahr-12-31", "$jahr-12-31");

    return [
        'version' => 1,
        'jahr' => $jahr, 'monate' => $monate,
        'zeitraum_von' => $vonDatum, 'zeitraum_bis' => $bisDatum,
        'vertrag' => array_intersect_key($v, array_flip(['id', 'mieter_name', 'mieter_strasse', 'mieter_plz', 'mieter_ort', 'mieter_iban', 'mieter_bank', 'einheit', 'wohnflaeche', 'personen'])),
        'objekt' => array_intersect_key($o, array_flip(['bezeichnung', 'strasse', 'plz', 'ort', 'gesamtflaeche'])),
        'absender' => [
            'name' => setting('absender_name'), 'zusatz' => setting('absender_zusatz'),
            'strasse' => setting('absender_strasse'), 'ort' => setting('absender_ort'),
            'telefon' => setting('absender_telefon'), 'email' => setting('absender_email'),
            'iban' => setting('verwaltung_iban'), 'bank' => setting('verwaltung_bank'),
        ],
        'flaechen_prozent' => $flaechenProzent,
        'positionen' => $positionen,
        'summe' => $summe,
        'vorauszahlungen' => $vz, 'vorauszahlungen_vorschlag' => $vzVorschlag,
        'saldo' => $saldo,
        'sonderposten' => $sp,
        'ergebnis' => $ergebnis,
        'briefdatum' => $eingaben['briefdatum'] ?? date('Y-m-d'),
        'einleitung' => $eingaben['einleitung'] ?? standard_einleitung($v, $jahr),
        'hinweise' => $eingaben['hinweise'] ?? '',
        'schluss' => $eingaben['schluss'] ?? standard_schluss($v, $ergebnis),
        'belegliste' => $belege,
    ];
}

function anrede(array $v): string
{
    $n = trim($v['mieter_name']);
    if (preg_match('/^Frau\s+(.+)$/u', $n, $m)) return 'Sehr geehrte Frau ' . preg_replace('/^.*\s/u', '', $m[1]) . ',';
    if (preg_match('/^Herr[n]?\s+(.+)$/u', $n, $m)) return 'Sehr geehrter Herr ' . preg_replace('/^.*\s/u', '', $m[1]) . ',';
    return 'Sehr geehrte Damen und Herren,';
}

function standard_einleitung(array $v, int $jahr): string
{
    return anrede($v) . "\n\nin der Folge erhalten Sie die Betriebskostenabrechnung Ihrer Wohneinheit für den Abrechnungszeitraum $jahr.";
}

function standard_schluss(array $v, float $ergebnis): string
{
    if ($ergebnis > 0) {
        return 'Dieser Betrag wird Ihnen in den nächsten Tagen auf Ihr Konto IBAN ' . ($v['mieter_iban'] ?: '…')
            . ($v['mieter_bank'] ? ' bei der ' . $v['mieter_bank'] : '') . ' überwiesen.';
    }
    if ($ergebnis < 0) {
        return 'Bitte überweisen Sie den Nachzahlungsbetrag innerhalb von 30 Tagen auf das Verwaltungskonto IBAN '
            . setting('verwaltung_iban') . (setting('verwaltung_bank') ? ' (' . setting('verwaltung_bank') . ')' : '')
            . ', Verwendungszweck „Nebenkosten ' . ($v['mieter_name'] ?? '') . '“.';
    }
    return 'Es ergibt sich weder ein Guthaben noch eine Nachzahlung.';
}
