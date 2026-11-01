-- Nebenkosten-Verwaltung – Datenbankschema (MySQL 5.7+/MariaDB 10.3+)
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS einstellungen (
  schluessel VARCHAR(64) PRIMARY KEY,
  wert TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS objekte (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bezeichnung VARCHAR(190) NOT NULL,
  strasse VARCHAR(190), plz VARCHAR(10), ort VARCHAR(100),
  gesamtflaeche DECIMAL(10,2) NOT NULL DEFAULT 0,
  anzahl_einheiten INT NOT NULL DEFAULT 1,
  gesamtpersonen INT NOT NULL DEFAULT 1,
  notiz TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vertraege (
  id INT AUTO_INCREMENT PRIMARY KEY,
  objekt_id INT NOT NULL,
  mieter_name VARCHAR(190) NOT NULL,
  mieter_strasse VARCHAR(190), mieter_plz VARCHAR(10), mieter_ort VARCHAR(100),
  mieter_iban VARCHAR(40), mieter_bank VARCHAR(100),
  einheit VARCHAR(100),
  wohnflaeche DECIMAL(10,2) NOT NULL DEFAULT 0,
  personen INT NOT NULL DEFAULT 1,
  anteil_prozent DECIMAL(6,3) NULL COMMENT 'manueller Flächenanteil in %, leer = Wohnfläche/Gesamtfläche',
  beginn DATE NOT NULL,
  ende DATE NULL,
  gesamtmiete DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'monatliche Zahlung inkl. NK',
  nk_vorauszahlung_jahr DECIMAL(10,2) NOT NULL DEFAULT 0,
  zahler_suchtext VARCHAR(190) COMMENT 'zur Erkennung der Mieteingänge',
  notiz TEXT,
  FOREIGN KEY (objekt_id) REFERENCES objekte(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vertrag_sonderposten (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vertrag_id INT NOT NULL,
  bezeichnung VARCHAR(190) NOT NULL,
  erlaeuterung VARCHAR(255),
  betrag DECIMAL(10,2) NOT NULL DEFAULT 0,
  art ENUM('gutschrift','belastung') NOT NULL DEFAULT 'gutschrift',
  FOREIGN KEY (vertrag_id) REFERENCES vertraege(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS kostenarten (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bezeichnung VARCHAR(190) NOT NULL,
  gruppe VARCHAR(100) NULL COMMENT 'z. B. Wasserversorgung -> Unterpositionen a), b)',
  schluessel ENUM('flaeche','direkt','personen','einheiten','prozent') NOT NULL DEFAULT 'flaeche',
  prozent DECIMAL(6,3) NULL,
  umlagefaehig TINYINT(1) NOT NULL DEFAULT 1,
  sortierung INT NOT NULL DEFAULT 100,
  hinweis VARCHAR(255)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS regeln (
  id INT AUTO_INCREMENT PRIMARY KEY,
  suchtext VARCHAR(190) NOT NULL,
  kostenart_id INT NOT NULL,
  nur_vorzeichen ENUM('alle','ausgabe','einnahme') NOT NULL DEFAULT 'alle',
  prioritaet INT NOT NULL DEFAULT 100,
  FOREIGN KEY (kostenart_id) REFERENCES kostenarten(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS buchungen (
  id INT AUTO_INCREMENT PRIMARY KEY,
  datum DATE NOT NULL,
  betrag DECIMAL(12,2) NOT NULL,
  name VARCHAR(190),
  typ VARCHAR(100),
  iban VARCHAR(40),
  zweck TEXT,
  notiz TEXT,
  import_hash CHAR(40) NULL UNIQUE,
  erstellt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (datum)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Zuordnung einer Buchung (oder eines Belegs ohne Buchung) zu Kostenart + Abrechnungsjahr.
-- betrag: Kosten positiv (Ausgabe), Erstattungen negativ. Einnahmen bei nicht umlagefähigen Arten negativ.
CREATE TABLE IF NOT EXISTS zuordnungen (
  id INT AUTO_INCREMENT PRIMARY KEY,
  buchung_id INT NULL,
  kostenart_id INT NOT NULL,
  jahr SMALLINT NOT NULL,
  betrag DECIMAL(12,2) NOT NULL,
  datum DATE NULL,
  beschreibung VARCHAR(255),
  INDEX (jahr, kostenart_id),
  FOREIGN KEY (buchung_id) REFERENCES buchungen(id) ON DELETE CASCADE,
  FOREIGN KEY (kostenart_id) REFERENCES kostenarten(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS abrechnungen (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vertrag_id INT NOT NULL,
  jahr SMALLINT NOT NULL,
  erstellt_am DATE NOT NULL,
  daten LONGTEXT NOT NULL COMMENT 'JSON-Schnappschuss',
  ergebnis DECIMAL(12,2) NOT NULL COMMENT '>0 Guthaben Mieter, <0 Nachzahlung',
  status ENUM('entwurf','versendet','ausgeglichen') NOT NULL DEFAULT 'entwurf',
  buchung_id INT NULL,
  FOREIGN KEY (vertrag_id) REFERENCES vertraege(id),
  FOREIGN KEY (buchung_id) REFERENCES buchungen(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS auslagen (
  id INT AUTO_INCREMENT PRIMARY KEY,
  datum DATE NOT NULL,
  kategorie VARCHAR(60) NOT NULL,
  beschreibung VARCHAR(255) NOT NULL,
  empfaenger VARCHAR(190),
  km DECIMAL(8,1) NULL,
  hin_rueck TINYINT(1) NOT NULL DEFAULT 0,
  km_satz DECIMAL(5,2) NULL,
  betrag DECIMAL(10,2) NOT NULL,
  beleg_datei VARCHAR(190), beleg_name VARCHAR(190),
  status ENUM('offen','eingereicht','erstattet') NOT NULL DEFAULT 'offen',
  erstattet_am DATE NULL,
  buchung_id INT NULL,
  notiz TEXT,
  erstellt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (buchung_id) REFERENCES buchungen(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
