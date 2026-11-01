<?php
// Kopieren nach config.php und anpassen.
return [
    'db_host' => 'localhost',
    'db_name' => 'nebenkosten',
    'db_user' => 'nebenkosten',
    'db_pass' => 'BITTE-AENDERN',
    'db_port' => 3306,

    // Zugangspasswort für die Anwendung (ein Benutzer).
    // Empfohlen: Hash eintragen, erzeugen mit:  php -r "echo password_hash('meinPasswort', PASSWORD_DEFAULT);"
    'passwort_hash' => '',
    // Alternativ (nur lokal!): Klartext-Passwort. Wird ignoriert, wenn passwort_hash gesetzt ist.
    'passwort' => 'bitte-aendern',

    // Ordner für Belege (Auslagen). Muss beschreibbar sein. Liegt per Standard in der App, ist per .htaccess gesperrt.
    'beleg_ordner' => __DIR__ . '/belege',
];
