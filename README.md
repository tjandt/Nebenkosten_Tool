# Nebenkosten-Verwaltung Geithainer Str. 3

Kleine PHP/MySQL-Anwendung für Mietverträge, Kontobuchungen, Nebenkostenabrechnungen auf Klick und die eigene Auslagenverwaltung.

## Voraussetzungen
- PHP 8.1 oder neuer mit PDO-MySQL (bei jedem gängigen Webhoster vorhanden)
- MySQL 5.7+ oder MariaDB 10.3+
- Apache (die `.htaccess`-Dateien sperren `config.php`, `inc/`, `install/` und `belege/`). Bei nginx diese Ordner in der Serverkonfiguration sperren.

## Installation
1. Beim Webhoster eine leere Datenbank mit Benutzer anlegen.
2. Den Ordner `nebenkosten/` per FTP hochladen (am besten in ein Unterverzeichnis, möglichst mit HTTPS).
3. `config.sample.php` nach `config.php` kopieren und Datenbankzugang + Passwort eintragen.
   Passwort-Hash erzeugen: `php -r "echo password_hash('meinPasswort', PASSWORD_DEFAULT);"` → bei `passwort_hash` eintragen.
4. `install.php` im Browser aufrufen, Passwort eingeben, „Einrichten“.
   Optional werden dabei alle 235 Kontoumsätze 10/2021–10/2026 aus den N26-Auszügen übernommen (Endsaldo 1.198,98 €) und die Abrechnung 2024 als Archiv angelegt.
5. Danach `install.php` und den Ordner `install/` löschen.
6. Der Ordner `belege/` muss für PHP beschreibbar sein (Belege der Auslagen).

Lokal testen: `php -S localhost:8000` im Ordner starten.

## Ablauf Jahresabrechnung
1. **Import** – Kontoauszug als CSV (N26: Kontoauszüge → CSV) oder den Text aus dem PDF-Auszug einfügen. Doppelte Buchungen werden erkannt, Kostenarten über Regeln automatisch zugeordnet.
2. **Kosten je Jahr** prüfen. Beträge aus Bescheiden ohne passende Einzelbuchung (v. a. ZWA-Jahresrechnung: Grundgebühr, Verbrauch, Niederschlagswasser) dort als „Kostenposition ohne Kontobuchung“ erfassen. Die monatlichen ZWA-Abschläge selbst sind bewusst *nicht* umlagefähig.
   Das Abrechnungsjahr einer Buchung lässt sich ändern (z. B. Versicherung im Dezember für das Folgejahr – bei Zeitraumangabe im Verwendungszweck geschieht das automatisch).
3. **Abrechnung** – Vertrag + Jahr wählen. Vorschau mit Vorjahresvergleich, Vorauszahlungen und Sonderposten (Gartenpacht, Hausmeister-Gutschrift) anpassbar → „Speichern & Druckansicht“ → im Browser als PDF speichern. Gespeicherte Abrechnungen sind eingefroren und ändern sich nicht mehr, wenn später Buchungen geändert werden.
4. Nach der Überweisung die Ausgleichsbuchung verknüpfen → Status „ausgeglichen“.

## Verteilerschlüssel
Fläche (Anteil % im Vertrag, sonst Wohnfläche/Gesamtfläche), Direkt (100 % oder fester %), Personen, Einheiten, fester Prozentsatz. Zieht ein Mieter unterjährig ein oder aus, wird monatsgenau anteilig gerechnet.

## Auslagen
Porto, Fahrten (km × Satz, Hin-/Rückfahrt), Gebühren usw. mit Beleg (PDF/Foto). Status offen → eingereicht → erstattet, optional mit der Erstattungsbuchung verknüpft. „Aufstellung drucken“ erzeugt eine unterschriftsfähige Liste (z. B. für den Nachlasspfleger). Die drei Verwaltungspauschalen 2023–2025 sind als bereits erstattet eingetragen.

## Datensicherung
Regelmäßig die Datenbank (phpMyAdmin → Exportieren) und den Ordner `belege/` sichern.
