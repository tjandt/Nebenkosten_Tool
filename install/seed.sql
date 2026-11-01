-- Startdaten: Objekt Geithainer Str. 3, Rochlitz (Nachlass Oelschlägel)
SET NAMES utf8mb4;

INSERT INTO einstellungen (schluessel, wert) VALUES
('absender_name', 'Tilo Jandt'),
('absender_zusatz', 'Hausverwaltung (treuhänderisch für den Nachlass Oelschlägel)'),
('absender_strasse', 'Kirschrain 1'),
('absender_ort', '34212 Melsungen'),
('absender_telefon', ''),
('absender_email', ''),
('verwaltung_iban', 'DE24 1001 1001 2620 8578 05'),
('verwaltung_bank', 'N26'),
('km_satz', '0.30'),
('fahrt_standard_ziel', 'Geithainer Str. 3, 09306 Rochlitz'),
('fahrt_standard_km', ''),
('aktenzeichen', 'AG Bad Dürkheim VI 354/10 · Nachlasspfleger Lechner 32/2021');

INSERT INTO objekte (id, bezeichnung, strasse, plz, ort, gesamtflaeche, anzahl_einheiten, gesamtpersonen, notiz) VALUES
(1, 'Geithainer Str. 3 (Nachlass Oelschlägel)', 'Geithainer Str. 3', '09306', 'Rochlitz', 237.14, 4, 1,
 'Doppelhaushälfte, 4 Haushalte lt. Abrechnung Pietler 2022; nur eine Wohnung vermietet. Gemarkung Poppitz Flst. 113.');

INSERT INTO vertraege (id, objekt_id, mieter_name, mieter_strasse, mieter_plz, mieter_ort, mieter_iban, mieter_bank, einheit,
  wohnflaeche, personen, anteil_prozent, beginn, ende, gesamtmiete, nk_vorauszahlung_jahr, zahler_suchtext, notiz) VALUES
(1, 1, 'Frau Runa Rantzuch', 'Geithainerstr. 3', '09306', 'Rochlitz', 'DE73 8705 2000 4200 0597 92', 'Sparkasse Mittelsachsen', 'Wohnung',
  73.46, 1, 31.000, '2010-01-01', NULL, 201.13, 782.15, 'Rantzuch',
 'Mietbeginn bitte aus dem Mietvertrag nachtragen. Flächenanteil 31 % wie in der Abrechnung 2024 (exakt 73,46/237,14 = 30,98 %). Zahlung 201,13 €/Monat inkl. NK-Vorauszahlung 782,15 €/Jahr.');

INSERT INTO vertrag_sonderposten (vertrag_id, bezeichnung, erlaeuterung, betrag, art) VALUES
(1, 'Gartenpacht', 'wird durch Pflege des Oelschlägel-Gartens erlassen (Verrechnung)', 0.00, 'belastung'),
(1, 'Gutschrift Hausmeister', 'Hausmeister-Vergütung 01–12', 306.78, 'gutschrift');

-- Umlagefähige Kostenarten (Reihenfolge wie Abrechnung 2024)
INSERT INTO kostenarten (id, bezeichnung, gruppe, schluessel, prozent, umlagefaehig, sortierung, hinweis) VALUES
(1,  'Grundsteuer',              NULL,               'flaeche',   NULL, 1, 10, 'Stadt Rochlitz, Grundsteuer B'),
(2,  'Grundgebühr',              'Wasserversorgung', 'direkt',    NULL, 1, 20, 'lt. ZWA-Jahresrechnung'),
(3,  'Verbrauch',                'Wasserversorgung', 'direkt',    NULL, 1, 21, 'lt. ZWA-Jahresrechnung (Zähler)'),
(4,  'Grundgebühr',              'Entwässerung',     'direkt',    NULL, 1, 30, 'lt. ZWA-Jahresrechnung'),
(5,  'Verbrauch',                'Entwässerung',     'direkt',    NULL, 1, 31, 'lt. ZWA-Jahresrechnung (Zähler)'),
(6,  'Niederschlagswasser',      NULL,               'flaeche',   NULL, 1, 40, 'lt. ZWA-Jahresrechnung'),
(7,  'Gebäudeversicherung',      NULL,               'flaeche',   NULL, 1, 50, 'HDI'),
(8,  'Haftpflichtvers.',         NULL,               'flaeche',   NULL, 1, 60, 'HDI V-010-406-102-7'),
(9,  'Allgemeinstrom',           NULL,               'flaeche',   NULL, 1, 70, 'envia, Hauslicht'),
(10, 'Müllbeseitigung',          NULL,               'direkt',    NULL, 1, 80, 'Landkreis Mittelsachsen'),
(11, 'Schornsteinfeger',         NULL,               'flaeche',   NULL, 1, 90, 'Michael Band'),
-- Nicht umlagefähig / sonstige Kontobewegungen
(20, 'ZWA Abschläge (Wasser/Abwasser)', NULL, 'direkt', NULL, 0, 200, 'Nur Zahlungen – die umlagefähigen Beträge kommen aus der ZWA-Jahresrechnung (unter „Kosten" erfassen)'),
(21, 'Mieteingang (Miete + NK-Vorauszahlung)', NULL, 'direkt', NULL, 0, 210, 'Einnahme'),
(22, 'NK-Erstattung / Nachzahlung Mieter', NULL, 'direkt', NULL, 0, 220, 'Ausgleich früherer Abrechnungen'),
(23, 'Verwaltung / Auslagenerstattung', NULL, 'direkt', NULL, 0, 230, 'Pauschalen, Auslagen'),
(24, 'Depot / Kapitalerträge', NULL, 'direkt', NULL, 0, 240, 'Wertpapiere, Dividenden, Gebühren'),
(25, 'Einlagen / Überträge / Rückzahlungen', NULL, 'direkt', NULL, 0, 250, 'Kontoeröffnung, Übertrag Pietler, Rückzahlungen'),
(26, 'Nachlass: Vergütung, Gerichtskosten, Darlehen', NULL, 'direkt', NULL, 0, 260, 'Nachlasslasten, nicht umlagefähig'),
(27, 'Instandhaltung / Reparaturen', NULL, 'direkt', NULL, 0, 270, 'nicht umlagefähig'),
(28, 'Sonstiges (nicht umlagefähig)', NULL, 'direkt', NULL, 0, 290, NULL);

INSERT INTO regeln (suchtext, kostenart_id, nur_vorzeichen, prioritaet) VALUES
('Grundsteuer', 1, 'ausgabe', 10),
('V-010-406-102-7', 8, 'alle', 10),
('V-083-886-867-1', 7, 'alle', 10),
('V-009-382-539-5', 7, 'alle', 10),
('HDI Versicherung', 7, 'alle', 20),
('envia', 9, 'alle', 20),
('Michael Band', 11, 'alle', 20),
('Abfall', 10, 'alle', 20),
('Landkreis Mittelsachsen', 10, 'ausgabe', 30),
('Landratsamt Mittelsachsen', 10, 'ausgabe', 30),
('Zweckverband Kommunale Wasser', 20, 'alle', 20),
('ZWA', 20, 'alle', 40),
('Nebenkostenr', 22, 'alle', 10),
('Rantzuch', 21, 'einnahme', 50),
('Pauschale Hausverwaltung', 23, 'alle', 10),
('Equities', 24, 'alle', 20),
('Investitionsgebühr', 24, 'alle', 20),
('Pietler', 25, 'alle', 30),
('Christiane Jandt', 25, 'alle', 30),
('Gesendet mit N26', 25, 'alle', 40),
('hinzugefügt hast', 25, 'alle', 40),
('N26 Cashback', 24, 'alle', 40),
('Lechner', 26, 'ausgabe', 20),
('Amtsgericht', 26, 'ausgabe', 20),
('Kreisverwaltung Bad D', 26, 'ausgabe', 20);
