<?php
/* ---------- Versorgungsverträge: Erkennung in Kontobuchungen ---------- */

/** Nummern vergleichbar machen: Kleinbuchstaben, nur Buchstaben/Ziffern ("V-010 406/1" → "v0104061") */
function norm_nummer(string $s): string
{
    return preg_replace('/[^a-z0-9]/', '', mb_strtolower($s));
}

/** Passt ein Buchungstext (Name + Zweck) zu den Nummern bzw. dem Suchtext des Versorgungsvertrags? */
function vertrag_passt_text(array $v, string $text): bool
{
    $norm = norm_nummer($text);
    foreach (['vertragsnummer', 'kundennummer'] as $f) {
        $n = norm_nummer((string)($v[$f] ?? ''));
        if (strlen($n) >= 5 && str_contains($norm, $n)) return true;
    }
    $s = mb_strtolower(trim((string)($v['suchtext'] ?? '')));
    return mb_strlen($s) >= 3 && str_contains(mb_strtolower($text), $s);
}

/* ---------- PDF-Upload ---------- */

/** Speichert ein hochgeladenes PDF (oder Foto/Scan) im Belegordner. Rückgabe [Dateiname, Originalname] */
function versorger_datei_speichern(array $f): array
{
    global $CFG;
    $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) throw new RuntimeException('Datei ist größer als vom Server erlaubt (upload_max_filesize ' . ini_get('upload_max_filesize') . ').');
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new RuntimeException('Datei konnte nicht hochgeladen werden.');
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true)) throw new RuntimeException('Bitte ein PDF hochladen (oder jpg/png bei Scans).');
    if ($f['size'] > 20 * 1024 * 1024) throw new RuntimeException('Datei zu groß (max. 20 MB).');
    if ($ext === 'pdf' && file_get_contents($f['tmp_name'], false, null, 0, 5) !== '%PDF-') throw new RuntimeException('Die Datei ist kein gültiges PDF.');
    $ordner = $CFG['beleg_ordner'] ?? dirname(__DIR__) . '/belege';
    if (!is_dir($ordner) && !mkdir($ordner, 0770, true)) throw new RuntimeException('Belegordner kann nicht angelegt werden.');
    $datei = 'va_' . date('Ymd') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $ordner . '/' . $datei)) throw new RuntimeException('Datei konnte nicht gespeichert werden (Ordner belege/ beschreibbar?).');
    return [$datei, mb_substr(basename($f['name']), 0, 190)];
}

/** Text aus einem PDF lesen. Braucht `pdftotext` (poppler-utils) auf dem Server; sonst leer – dann Text manuell einfügen. */
function pdf_text(string $pfad): string
{
    if (strtolower(pathinfo($pfad, PATHINFO_EXTENSION)) !== 'pdf' || !is_file($pfad)) return '';
    $gesperrt = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (!function_exists('shell_exec') || in_array('shell_exec', $gesperrt, true)) return '';
    $out = @shell_exec('pdftotext -layout -enc UTF-8 ' . escapeshellarg($pfad) . ' - 2>/dev/null');
    return is_string($out) ? mb_substr($out, 0, 300000) : '';
}

/**
 * Aus dem PDF-Text Vertrag, Zeitraum, Rechnungsdatum und Beträge erkennen (Vorschläge – werden vom Nutzer geprüft).
 * Rückgabe: vertrag_id, von, bis, jahr, rechnungsdatum, rechnungsnummer, betraege[ ['label','betrag'] ]
 */
function versorger_erkennen(string $text): array
{
    $r = ['vertrag_id' => null, 'von' => null, 'bis' => null, 'jahr' => null, 'rechnungsdatum' => null, 'rechnungsnummer' => null, 'betraege' => []];
    if (trim($text) === '') return $r;

    $norm = norm_nummer($text);
    foreach (rows('SELECT * FROM versorgungsvertraege ORDER BY id') as $v) {
        foreach (['vertragsnummer', 'kundennummer'] as $f) {
            $n = norm_nummer((string)$v[$f]);
            if (strlen($n) >= 5 && str_contains($norm, $n)) { $r['vertrag_id'] = (int)$v['id']; break 2; }
        }
    }

    $datum = fn(string $d, string $m, string $y) => checkdate((int)$m, (int)$d, (int)$y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
    if (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})\s*(?:-|–|—|bis)\s*(\d{1,2})\.(\d{1,2})\.(\d{4})/u', $text, $m)) {
        $von = $datum($m[1], $m[2], $m[3]); $bis = $datum($m[4], $m[5], $m[6]);
        if ($von && $bis && $von < $bis) {
            $r['von'] = $von; $r['bis'] = $bis;
            // Jahr, in das der größere Teil des Zeitraums fällt
            $r['jahr'] = (int)date('Y', (int)((strtotime($von) + strtotime($bis)) / 2));
        }
    }
    if (preg_match('/(?:Rechnungsdatum|Rechnung vom|Datum der Rechnung|Abrechnungsdatum|Erstellt am)\D{0,15}(\d{1,2})\.(\d{1,2})\.(\d{4})/iu', $text, $m)) {
        $r['rechnungsdatum'] = $datum($m[1], $m[2], $m[3]);
    }
    if (preg_match('/Rechnungs?(?:nummer|-?nr\.?)\s*:?\s*([A-Z0-9][A-Z0-9\/\-]{3,})/iu', $text, $m)) {
        $r['rechnungsnummer'] = $m[1];
    }
    foreach (preg_split('/\R/u', $text) as $l) {
        if (count($r['betraege']) >= 20) break;
        if (!preg_match('/gesamt|rechnungsbetrag|summe|nachzahlung|guthaben|abschl[aä]g|bereits gezahlt|zu zahlen|erstattung/iu', $l)) continue;
        if (!preg_match_all('/-?\d{1,3}(?:\.\d{3})*,\d{2}/', $l, $mm)) continue;
        $label = trim(preg_replace('/\s+/u', ' ', preg_replace('/\s-?\d{1,3}(?:\.\d{3})*,\d{2}.*$/u', '', trim($l))));
        $r['betraege'][] = ['label' => mb_substr($label, 0, 80), 'betrag' => parse_num(end($mm[0]))];
    }
    return $r;
}

/* ---------- Auswertung ---------- */

function versorger_laden(int $id): ?array
{
    $d = row('SELECT * FROM versorger_abrechnungen WHERE id=?', [$id]);
    if (!$d) return null;
    $d['vertrag'] = $d['versorgungsvertrag_id'] ? row('SELECT * FROM versorgungsvertraege WHERE id=?', [$d['versorgungsvertrag_id']]) : null;
    $d['positionen'] = rows('SELECT * FROM versorger_positionen WHERE abrechnung_id=? ORDER BY id', [$id]);
    $d['gesamt'] = round(array_sum(array_map(fn($p) => (float)$p['betrag'], $d['positionen'])), 2);
    $d['uebernommen'] = (int)val('SELECT COUNT(*) FROM zuordnungen WHERE dokument_id=?', [$id]) > 0;
    return $d;
}

/** Zeitraum der Abrechnung (Fallback: Kalenderjahr) */
function versorger_zeitraum(array $d): array
{
    return [$d['zeitraum_von'] ?: $d['jahr'] . '-01-01', $d['zeitraum_bis'] ?: $d['jahr'] . '-12-31'];
}

/** Erwarteter Saldo: lt. Abrechnung angegeben, sonst Gesamtkosten − gezahlte Abschläge. >0 Nachzahlung, <0 Guthaben. */
function versorger_saldo(array $d): ?float
{
    if ($d['saldo'] !== null && $d['saldo'] !== '') return round((float)$d['saldo'], 2);
    if ($d['abschlaege'] !== null && $d['abschlaege'] !== '') return round($d['gesamt'] - (float)$d['abschlaege'], 2);
    return null;
}

/**
 * Vergleicht eine Versorger-Jahresabrechnung mit den Kontobuchungen.
 * Zugeordnet werden Buchungen, deren Text die Vertrags-/Kundennummer bzw. den Suchtext des Versorgungsvertrags enthält
 * oder die einer Kostenart der Abrechnung zugeordnet sind.
 *   Abschläge  = Zahlungen im Abrechnungszeitraum (vor dem Rechnungsdatum)
 *   Ausgleich  = Nachzahlung/Guthaben danach (Buchung mit dem Saldo-Betrag bis 12 Monate nach Zeitraumende, mit Textbezug bevorzugt)
 */
function versorger_abgleich(array $d): array
{
    [$von, $bis] = versorger_zeitraum($d);
    $ende = date('Y-m-d', strtotime($bis . ' +12 months'));
    $ka = array_filter(array_map(fn($p) => $p['kostenart_id'] ? (int)$p['kostenart_id'] : null, $d['positionen']));
    if ($d['vertrag'] && $d['vertrag']['kostenart_id']) $ka[] = (int)$d['vertrag']['kostenart_id'];
    $ka = array_values(array_unique($ka));

    $zuKa = [];
    foreach (rows('SELECT buchung_id, kostenart_id FROM zuordnungen WHERE buchung_id IS NOT NULL') as $z) $zuKa[$z['buchung_id']][] = (int)$z['kostenart_id'];

    $saldo = versorger_saldo($d);
    $abschl = $kandidaten = [];
    foreach (rows('SELECT * FROM buchungen WHERE datum BETWEEN ? AND ? ORDER BY datum, id', [$von, $ende]) as $b) {
        $text = ($b['name'] ?? '') . ' ' . ($b['zweck'] ?? '');
        $relevant = ($d['vertrag'] && vertrag_passt_text($d['vertrag'], $text)) || ($ka && array_intersect($zuKa[$b['id']] ?? [], $ka));
        $nachRechnung = $b['datum'] > $bis || ($d['rechnungsdatum'] && $b['datum'] >= $d['rechnungsdatum']);
        if ($relevant && !$nachRechnung && (float)$b['betrag'] < 0) {
            $abschl[] = $b;
        } elseif ($nachRechnung && $saldo !== null && abs($saldo) > 0.005 && abs(-(float)$b['betrag'] - $saldo) < 0.015) {
            $b['textbezug'] = (bool)$relevant;
            $kandidaten[] = $b;
        }
    }
    // Nachzahlung/Guthaben: Buchung mit passendem Betrag nach der Abrechnung; mit Textbezug zum Vertrag bevorzugt.
    // (Folge-Abschläge nach Zeitraumende werden nicht als Ausgleich gewertet, nur der Saldo-Betrag.)
    $mitBezug = array_values(array_filter($kandidaten, fn($b) => $b['textbezug']));
    $ausgl = array_slice($mitBezug ?: $kandidaten, 0, 1);
    $sumAbschl = round(-array_sum(array_map(fn($b) => (float)$b['betrag'], $abschl)), 2);
    $sumAusgl = round(-array_sum(array_map(fn($b) => (float)$b['betrag'], $ausgl)), 2);

    $checks = [];
    $hatDaten = $d['positionen'] !== [];
    if (!$hatDaten) $checks[] = ['info', 'Noch keine Kostenpositionen erfasst – bitte die Beträge aus dem PDF eintragen.'];

    if ($hatDaten && $d['abschlaege'] !== null && $d['abschlaege'] !== '' && $d['saldo'] !== null && $d['saldo'] !== '') {
        $diff = round($d['gesamt'] - (float)$d['abschlaege'] - (float)$d['saldo'], 2);
        $checks[] = abs($diff) < 0.015
            ? ['ok', 'Positionen − Abschläge = Saldo lt. Abrechnung (' . eur($d['gesamt']) . ' − ' . eur($d['abschlaege']) . ' = ' . eur($d['saldo']) . ').']
            : ['warn', 'Positionen stimmen nicht mit der Abrechnung überein: ' . eur($d['gesamt']) . ' − ' . eur($d['abschlaege']) . ' ergibt ' . eur($d['gesamt'] - (float)$d['abschlaege']) . ', lt. Abrechnung ' . eur($d['saldo']) . ' (Differenz ' . eur($diff, true) . '). Positionen im PDF nochmal prüfen.'];
    }

    if (!$abschl && !$ausgl && !$d['vertrag'] && !$ka) {
        $checks[] = ['warn', 'Kein Versorgungsvertrag gewählt und keine Kostenart in den Positionen – ohne einen von beiden können keine Buchungen gefunden werden.'];
    } elseif (!$abschl && $hatDaten) {
        $checks[] = ['warn', 'Im Kontoauszug wurden keine Abschlagszahlungen im Zeitraum ' . dt($von) . ' – ' . dt($bis) . ' gefunden. Vertragsnummer/Suchtext im Versorgungsvertrag (Stammdaten) hinterlegen oder Buchungen einer Kostenart zuordnen.'];
    }

    if ($abschl && $d['abschlaege'] !== null && $d['abschlaege'] !== '') {
        $diff = round($sumAbschl - (float)$d['abschlaege'], 2);
        $checks[] = abs($diff) < 0.015
            ? ['ok', 'Abschläge laut Kontoauszug (' . count($abschl) . ' Zahlungen, ' . eur($sumAbschl) . ') stimmen mit der Abrechnung überein.']
            : ['warn', 'Abschläge laut Kontoauszug ' . eur($sumAbschl) . ' (' . count($abschl) . ' Zahlungen) ≠ lt. Abrechnung ' . eur($d['abschlaege']) . ' (Differenz ' . eur($diff, true) . '). Fehlende oder zusätzliche Zahlung?'];
    } elseif ($abschl) {
        $checks[] = ['info', 'Abschläge laut Kontoauszug: ' . eur($sumAbschl) . ' (' . count($abschl) . ' Zahlungen). Tipp: „Bereits gezahlte Abschläge“ aus dem PDF eintragen, dann wird verglichen.'];
    }

    if ($saldo !== null && abs($saldo) > 0.005) {
        $art = $saldo > 0 ? 'Nachzahlung' : 'Guthaben';
        if (!$ausgl) {
            $checks[] = ['info', $art . ' von ' . eur(abs($saldo)) . ' laut Abrechnung – im Kontoauszug (noch) nicht gefunden.'];
        } elseif (abs($sumAusgl - $saldo) < 0.015) {
            $checks[] = ['ok', $art . ' von ' . eur(abs($saldo)) . ' ist im Kontoauszug verbucht.'];
        } else {
            $checks[] = ['warn', $art . ' laut Abrechnung ' . eur(abs($saldo)) . ', im Kontoauszug ' . eur(abs($sumAusgl)) . ' ' . ($sumAusgl >= 0 ? '(Zahlung)' : '(Gutschrift)') . '.'];
        }
    }

    if ($hatDaten && $abschl && ($ausgl || $saldo === null || abs($saldo) < 0.005)) {
        $ist = round($sumAbschl + $sumAusgl, 2);
        $diff = round($ist - $d['gesamt'], 2);
        $checks[] = abs($diff) < 0.015
            ? ['ok', 'Gezahlte Beträge laut Kontoauszug (' . eur($ist) . ') entsprechen den Gesamtkosten der Abrechnung.']
            : ['warn', 'Gezahlt laut Kontoauszug ' . eur($ist) . ' (Abschläge + Ausgleich) ≠ Gesamtkosten ' . eur($d['gesamt']) . ' (Differenz ' . eur($diff, true) . ').'];
    }

    $status = 'ok';
    foreach ($checks as [$s]) { if ($s === 'warn') { $status = 'warn'; break; } if ($s === 'info') $status = 'info'; }
    if (!$hatDaten) $status = 'info';

    return ['von' => $von, 'bis' => $bis, 'abschlaege' => $abschl, 'ausgleich' => $ausgl, 'summe_abschlaege' => $sumAbschl,
            'summe_ausgleich' => $sumAusgl, 'saldo' => $saldo, 'checks' => $checks, 'status' => $status];
}

/** Positionen als Kosten (Zuordnungen ohne Buchung) übernehmen. Rückgabe: Anzahl der übernommenen Positionen */
function versorger_uebernehmen(int $id): int
{
    $d = versorger_laden($id);
    if (!$d) return 0;
    [$von, $bis] = versorger_zeitraum($d);
    $datum = $d['rechnungsdatum'] ?: $bis;
    $anbieter = $d['vertrag']['anbieter'] ?? '';
    $kopf = trim('Jahresabrechnung ' . ($anbieter ?: ($d['vertrag']['bezeichnung'] ?? '')) . ' ' . dt($von) . '–' . dt($bis));
    db()->beginTransaction();
    q('DELETE FROM zuordnungen WHERE dokument_id=?', [$id]);
    $n = 0;
    foreach ($d['positionen'] as $p) {
        if (!$p['kostenart_id']) continue;
        q('INSERT INTO zuordnungen (buchung_id, kostenart_id, jahr, betrag, datum, beschreibung, dokument_id) VALUES (NULL,?,?,?,?,?,?)',
          [(int)$p['kostenart_id'], (int)$d['jahr'], (float)$p['betrag'], $datum, mb_substr($kopf . ': ' . $p['bezeichnung'], 0, 255), $id]);
        $n++;
    }
    db()->commit();
    return $n;
}

function versorger_datei_loeschen(?string $datei): void
{
    global $CFG;
    if ($datei) @unlink(($CFG['beleg_ordner'] ?? dirname(__DIR__) . '/belege') . '/' . basename($datei));
}

/** Versorgungsverträge, die im Jahr laufen, zu denen aber noch keine Jahresabrechnung erfasst ist */
function versorger_fehlende(int $jahr): array
{
    return rows('SELECT v.* FROM versorgungsvertraege v
        WHERE (v.beginn IS NULL OR v.beginn <= ?) AND (v.ende IS NULL OR v.ende >= ?)
          AND NOT EXISTS (SELECT 1 FROM versorger_abrechnungen a WHERE a.versorgungsvertrag_id=v.id AND a.jahr=?)
        ORDER BY v.bezeichnung', ["$jahr-12-31", "$jahr-01-01", $jahr]);
}

function versorger_label(array $v): string
{
    return $v['bezeichnung'] . ($v['anbieter'] ? ' · ' . $v['anbieter'] : '') . ($v['vertragsnummer'] ? ' (' . $v['vertragsnummer'] . ')' : '');
}
