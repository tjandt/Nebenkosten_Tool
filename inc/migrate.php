<?php
/**
 * Schema-Erweiterungen für bestehende Installationen. Läuft automatisch nach dem Login (einmalig je Version)
 * und am Ende von install.php. Alle Schritte sind idempotent.
 *
 * Version 2: Versorgungsverträge (Vertragsnummern), Jahresabrechnungen der Versorger (PDF) mit Positionen,
 *            zuordnungen.dokument_id (Kosten, die aus einer Jahresabrechnung stammen).
 */
const SCHEMA_VERSION = 2;

function spalte_vorhanden(string $tabelle, string $spalte): bool
{
    return (int)val('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$tabelle, $spalte]) > 0;
}

function schema_migrieren(): void
{
    $stand = (int)(val("SELECT wert FROM einstellungen WHERE schluessel='schema_version'") ?: 1);
    if ($stand >= SCHEMA_VERSION) return;

    if ($stand < 2) {
        db()->exec("CREATE TABLE IF NOT EXISTS versorgungsvertraege (
          id INT AUTO_INCREMENT PRIMARY KEY,
          bezeichnung VARCHAR(190) NOT NULL,
          anbieter VARCHAR(190) NOT NULL DEFAULT '',
          kostenart_id INT NULL COMMENT 'Kostenart der laufenden Zahlungen (Abschläge) – dient der automatischen Zuordnung',
          vertragsnummer VARCHAR(100) NULL,
          kundennummer VARCHAR(100) NULL,
          zaehlernummer VARCHAR(100) NULL,
          suchtext VARCHAR(190) NULL COMMENT 'Text im Kontoauszug (Empfängername), zusätzlich zu den Nummern',
          abschlag_monat DECIMAL(10,2) NULL,
          beginn DATE NULL,
          ende DATE NULL,
          notiz TEXT NULL,
          FOREIGN KEY (kostenart_id) REFERENCES kostenarten(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        db()->exec("CREATE TABLE IF NOT EXISTS versorger_abrechnungen (
          id INT AUTO_INCREMENT PRIMARY KEY,
          versorgungsvertrag_id INT NULL,
          jahr SMALLINT NOT NULL,
          zeitraum_von DATE NULL,
          zeitraum_bis DATE NULL,
          rechnungsdatum DATE NULL,
          rechnungsnummer VARCHAR(100) NULL,
          abschlaege DECIMAL(12,2) NULL COMMENT 'lt. Abrechnung bereits gezahlte Abschläge',
          saldo DECIMAL(12,2) NULL COMMENT 'lt. Abrechnung: >0 Nachzahlung an den Versorger, <0 Guthaben',
          datei VARCHAR(190) NULL,
          datei_name VARCHAR(190) NULL,
          text_auszug MEDIUMTEXT NULL,
          notiz TEXT NULL,
          erstellt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX (jahr),
          FOREIGN KEY (versorgungsvertrag_id) REFERENCES versorgungsvertraege(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        db()->exec("CREATE TABLE IF NOT EXISTS versorger_positionen (
          id INT AUTO_INCREMENT PRIMARY KEY,
          abrechnung_id INT NOT NULL,
          kostenart_id INT NULL,
          bezeichnung VARCHAR(190) NOT NULL,
          betrag DECIMAL(12,2) NOT NULL COMMENT 'Kosten positiv, Gutschriften negativ',
          FOREIGN KEY (abrechnung_id) REFERENCES versorger_abrechnungen(id) ON DELETE CASCADE,
          FOREIGN KEY (kostenart_id) REFERENCES kostenarten(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        if (!spalte_vorhanden('zuordnungen', 'dokument_id')) {
            db()->exec('ALTER TABLE zuordnungen ADD COLUMN dokument_id INT NULL,
                ADD CONSTRAINT fk_zuordnungen_dokument FOREIGN KEY (dokument_id) REFERENCES versorger_abrechnungen(id) ON DELETE CASCADE');
        }
    }

    q('INSERT INTO einstellungen (schluessel, wert) VALUES (?,?) ON DUPLICATE KEY UPDATE wert=VALUES(wert)', ['schema_version', (string)SCHEMA_VERSION]);
}
