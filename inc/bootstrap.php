<?php
declare(strict_types=1);
mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Berlin');

$cfgFile = dirname(__DIR__) . '/config.php';
if (!is_file($cfgFile)) {
    http_response_code(500);
    exit('config.php fehlt – bitte config.sample.php nach config.php kopieren und anpassen.');
}
$CFG = require $cfgFile;

session_name('nkverw');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();

function db(): PDO
{
    static $pdo = null;
    global $CFG;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $CFG['db_host'], (int)($CFG['db_port'] ?? 3306), $CFG['db_name']);
        $pdo = new PDO($dsn, $CFG['db_user'], $CFG['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function q(string $sql, array $p = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st;
}
function rows(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function row(string $sql, array $p = []): ?array { $r = q($sql, $p)->fetch(); return $r ?: null; }
function val(string $sql, array $p = []) { return q($sql, $p)->fetchColumn(); }

function h($s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

function eur($v, bool $sign = false): string
{
    $v = (float)$v;
    $s = number_format(abs($v), 2, ',', '.');
    $pre = $v < 0 ? '-' : ($sign && $v > 0 ? '+' : '');
    return $pre . $s . ' €';
}
function num($v, int $dec = 2): string { return number_format((float)$v, $dec, ',', '.'); }

/** Deutsche Zahleneingabe ("1.234,56" oder "1234.56") nach float */
function parse_num($s): float
{
    $s = trim((string)$s);
    if ($s === '') return 0.0;
    $s = str_replace(['€', ' ', "\u{00A0}"], '', $s);
    if (preg_match('/,\d{1,2}$/', $s) || (str_contains($s, ',') && !str_contains($s, '.'))) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } else {
        $s = str_replace(',', '', $s);
    }
    return (float)$s;
}
function dt(?string $d): string { return $d ? date('d.m.Y', strtotime($d)) : ''; }

function setting(string $k, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (rows('SELECT schluessel, wert FROM einstellungen') as $r) $cache[$r['schluessel']] = (string)$r['wert'];
    }
    return $cache[$k] ?? $default;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'; }
function check_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Ungültiges Formular-Token. Bitte Seite neu laden.');
    }
}

function flash(string $msg, string $typ = 'ok'): void { $_SESSION['flash'][] = [$typ, $msg]; }
function redirect(string $url): never { header('Location: ' . $url); exit; }

function require_login(): void
{
    if (empty($_SESSION['login'])) redirect('login.php?zurueck=' . urlencode($_SERVER['REQUEST_URI'] ?? 'index.php'));
}

function check_password(string $pw): bool
{
    global $CFG;
    if (!empty($CFG['passwort_hash'])) return password_verify($pw, $CFG['passwort_hash']);
    return ($CFG['passwort'] ?? '') !== '' && hash_equals((string)$CFG['passwort'], $pw);
}

function schluessel_label(string $s): string
{
    return [
        'flaeche' => 'Fläche', 'direkt' => 'Direkt', 'personen' => 'Personen',
        'einheiten' => 'Einheiten', 'prozent' => 'fester Anteil',
    ][$s] ?? $s;
}

const AUSLAGE_KATEGORIEN = ['Porto', 'Fahrtkosten', 'Telefon / Kopien', 'Gebühren (Notar, Grundbuch, Behörden)', 'Material / Schlüssel', 'Pauschale', 'Sonstiges'];
