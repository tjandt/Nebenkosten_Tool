<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();

if (($_GET['typ'] ?? '') === 'versorger') {
    $a = row('SELECT datei beleg_datei, datei_name beleg_name FROM versorger_abrechnungen WHERE id=?', [(int)($_GET['id'] ?? 0)]);
} else {
    $a = row('SELECT beleg_datei, beleg_name FROM auslagen WHERE id=?', [(int)($_GET['id'] ?? 0)]);
}
$ordner = $CFG['beleg_ordner'] ?? __DIR__ . '/belege';
$pfad = $a && $a['beleg_datei'] ? $ordner . '/' . basename($a['beleg_datei']) : '';
if (!$pfad || !is_file($pfad)) { http_response_code(404); exit('Beleg nicht gefunden.'); }

$typen = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'heic' => 'image/heic'];
$ext = strtolower(pathinfo($pfad, PATHINFO_EXTENSION));
header('Content-Type: ' . ($typen[$ext] ?? 'application/octet-stream'));
header('Content-Disposition: inline; filename="' . rawurlencode($a['beleg_name'] ?: basename($pfad)) . '"');
header('X-Content-Type-Options: nosniff');
readfile($pfad);
