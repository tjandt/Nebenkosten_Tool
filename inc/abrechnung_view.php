<?php
/** Schlüssel je Position (Gruppe|Bezeichnung) → Anteil, für Vorjahresvergleich */
function abrechnung_keys(array $positionen): array
{
    $out = []; $g = '';
    foreach ($positionen as $p) {
        if ($p['typ'] === 'gruppe') { $g = $p['bezeichnung']; continue; }
        if (empty($p['unter'])) $g = '';
        $out[$g . '|' . $p['bezeichnung']] = $p['anteil'];
    }
    return $out;
}

/** Tabelle im Stil der Abrechnung 2024 */
function abrechnung_tabelle(array $d, bool $bildschirm = false, array $vorjahr = []): void
{
    $vj = $vorjahr ? $vorjahr : [];
    $g = '';
    ?>
    <table class="abr">
      <thead><tr><th>Position</th><th class="r">Gesamtkosten (€)</th><th>Verteilerschlüssel</th><th>Berechnung</th><th class="r">Ihr Anteil (€)</th></tr></thead>
      <tbody>
      <?php foreach ($d['positionen'] as $p):
          if ($p['typ'] === 'gruppe'): $g = $p['bezeichnung']; ?>
          <tr class="grp"><td colspan="5"><?= h($p['nr'] . ' ' . $p['bezeichnung']) ?></td></tr>
        <?php else:
          if (empty($p['unter'])) $g = '';
          $key = $g . '|' . $p['bezeichnung']; ?>
          <tr class="<?= !empty($p['unter']) ? 'sub' : 'main' ?>">
            <td><?= h($p['nr'] . ' ' . $p['bezeichnung']) ?><?php if ($bildschirm && !empty($p['quelle'])): ?> <span class="tag <?= $p['quelle'] === 'Jahresabrechnung' ? 'ausgeglichen' : '' ?>" title="Herkunft der Gesamtkosten"><?= h($p['quelle']) ?></span><?php endif; ?></td>
            <td class="r"><?= eur($p['gesamt']) ?></td>
            <td><?= h($p['schluessel']) ?></td>
            <td><?= h($p['berechnung']) ?></td>
            <td class="r"><?= eur($p['anteil']) ?><?php if ($bildschirm && isset($vj[$key])): ?><div class="vj"><?= eur($vj[$key]) ?></div><?php endif; ?></td>
          </tr>
        <?php endif; endforeach; ?>
        <?php if (!$d['positionen']): ?><tr><td colspan="5" class="muted">Keine umlagefähigen Kosten für dieses Jahr erfasst.</td></tr><?php endif; ?>
        <tr class="sp"><td colspan="5"></td></tr>
        <tr class="sum"><td colspan="4">Zwischensumme</td><td class="r"><?= eur($d['summe']) ?></td></tr>
        <tr class="sum"><td colspan="4">./. Vorauszahlungen<?= $d['monate'] < 12 ? ' (' . $d['monate'] . ' Monate)' : '' ?></td><td class="r"><?= eur($d['vorauszahlungen']) ?></td></tr>
        <tr class="tot"><td colspan="4" class="r"><?= $d['saldo'] < 0 ? 'Abrechnungsfehlbetrag' : 'Abrechnungsüberschuss' ?></td><td class="r"><?= eur($d['saldo']) ?></td></tr>
        <?php if ($d['sonderposten']): ?>
          <tr class="sp"><td colspan="5"></td></tr>
          <tr class="grp"><td colspan="5">Sonderposten</td></tr>
          <?php foreach ($d['sonderposten'] as $s): ?>
            <tr class="sub"><td colspan="2"><?= h($s['bezeichnung']) ?></td><td colspan="2"><?= h($s['erlaeuterung']) ?></td>
              <td class="r"><?= eur($s['art'] === 'belastung' ? -$s['betrag'] : $s['betrag']) ?></td></tr>
          <?php endforeach; ?>
        <?php endif; ?>
        <tr class="sp"><td colspan="5"></td></tr>
        <tr class="tot final"><td colspan="4" class="r"><?= $d['ergebnis'] >= 0 ? 'Abrechnungsguthaben ' . $d['jahr'] : 'Nachzahlungsbetrag ' . $d['jahr'] ?></td><td class="r"><?= eur(abs($d['ergebnis'])) ?></td></tr>
      </tbody>
    </table>
    <?php
}
