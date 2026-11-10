<?php
/**
 * Bon de livraison d'une expédition (facture client)
 *  - client destinataire (nom, adresse, téléphone)
 *  - pour chaque produit : dépôt d'où il est sorti (si livré)
 *    et dépôts où il est disponible (s'il reste à livrer)
 *  - paramètre optionnel date=AAAA-MM-JJ : seulement ce qui a été livré ce jour-là
 */

$res = 0;
$sep = DIRECTORY_SEPARATOR;
for ($i = 0; $i < 5; $i++) {
    $dir = str_repeat('..'.$sep, $i);
    if (@include_once $dir.'main.inc.php') { $res = 1; break; }
}
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/custom/faurex_magasin/lib/faurex_ui.lib.php';

$langs->loadLangs(array("faurex_magasin@faurex_magasin", "bills", "companies"));
if (empty($user->rights->faurex_magasin->shipment->read)) accessforbidden();

$ship_id = GETPOSTINT('ship_id');
$date    = GETPOST('date', 'alpha');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) $date = '';
if ($ship_id <= 0) accessforbidden('Paramètres manquants');

// --- En-tête : expédition, facture, client ---
$sql = "SELECT s.rowid, s.ref AS ship_ref, f.rowid AS fid, f.ref AS fref, f.datef,"
    ." soc.nom AS socname, soc.address, soc.zip, soc.town, soc.phone, soc.phone_mobile, soc.email"
    ." FROM ".MAIN_DB_PREFIX."faurex_shipment s"
    ." INNER JOIN ".MAIN_DB_PREFIX."facture f ON f.rowid = s.fk_facture"
    ." LEFT JOIN ".MAIN_DB_PREFIX."societe soc ON soc.rowid = f.fk_soc"
    ." WHERE s.rowid = ".((int) $ship_id);
$resh = $db->query($sql);
$header = $resh ? $db->fetch_object($resh) : null;
if (!$header) accessforbidden('Bon de livraison introuvable');

// --- Lignes : produits de la facture + quantités livrées + dépôts utilisés ---
$dateFilter = ($date !== '') ? " AND sl.date_shipment = '".$db->escape($date)."'" : "";
$sql = "SELECT fd.rowid AS fdid, p.rowid AS pid, p.ref AS pref, p.label AS plabel, fd.qty,"
    ." COALESCE(SUM(sl.qty_delivered), 0) AS livre,"
    ." GROUP_CONCAT(DISTINCT e.ref ORDER BY e.ref SEPARATOR ', ') AS depots_livres"
    ." FROM ".MAIN_DB_PREFIX."facturedet fd"
    ." INNER JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = fd.fk_product"
    ." LEFT JOIN ".MAIN_DB_PREFIX."faurex_shipment_line sl ON sl.fk_facturedet = fd.rowid AND sl.fk_shipment = ".((int) $ship_id).$dateFilter
    ." LEFT JOIN ".MAIN_DB_PREFIX."entrepot e ON e.rowid = sl.fk_warehouse"
    ." WHERE fd.fk_facture = ".((int) $header->fid)." AND fd.product_type = 0 AND fd.fk_product > 0"
    ." GROUP BY fd.rowid, fd.rang, p.rowid, p.ref, p.label, fd.qty"
    ." ORDER BY fd.rang, fd.rowid";
$resl = $db->query($sql);
if (!$resl) accessforbidden('Erreur SQL : '.$db->lasterror());

$lines = array();
$pids = array();
while ($obj = $db->fetch_object($resl)) {
    $lines[] = $obj;
    $pids[(int) $obj->pid] = 1;
}

// Quantité totale déjà livrée par ligne (toutes dates) pour calculer le reste
$totalLivre = array();
if ($date !== '') {
    $rt = $db->query("SELECT fk_facturedet, SUM(qty_delivered) AS s FROM ".MAIN_DB_PREFIX."faurex_shipment_line WHERE fk_shipment = ".((int) $ship_id)." GROUP BY fk_facturedet");
    if ($rt) while ($o = $db->fetch_object($rt)) $totalLivre[(int) $o->fk_facturedet] = (float) $o->s;
}

// Dépôts où chaque produit est disponible (stock > 0)
$dispo = array();
if (!empty($pids)) {
    $sql = "SELECT ps.fk_product, e.ref, ps.reel"
        ." FROM ".MAIN_DB_PREFIX."product_stock ps"
        ." INNER JOIN ".MAIN_DB_PREFIX."entrepot e ON e.rowid = ps.fk_entrepot"
        ." WHERE ps.fk_product IN (".implode(',', array_keys($pids)).") AND ps.reel > 0"
        ." ORDER BY ps.reel DESC";
    $rd = $db->query($sql);
    if ($rd) while ($o = $db->fetch_object($rd)) {
        $dispo[(int) $o->fk_product][] = dol_escape_htmltag($o->ref).' ('.price2num($o->reel, 'MS').')';
    }
}

// État global du bon
$totFact = 0; $totLivre = 0;
foreach ($lines as $ln) {
    $totFact += (float) $ln->qty;
    $totLivre += ($date !== '') ? (isset($totalLivre[(int) $ln->fdid]) ? $totalLivre[(int) $ln->fdid] : 0) : (float) $ln->livre;
}
if ($totLivre < $totFact) {
    $etat = array('En attente', 'validated');   // rien livré, ou livré en partie
} else {
    $etat = array('Livré', 'done');
}

$blRef = 'BL-'.$header->ship_ref.($date !== '' ? '-'.str_replace('-', '', $date) : '');

// --- Affichage ---
llxHeader(faurex_css_head(), 'Bon de livraison '.$blRef);

$right = '<a class="fx-btn fx-btn-secondary" href="bon_expedition_list.php">← Retour</a> '
    .'<a class="fx-btn fx-btn-secondary" href="shipment_card.php?id='.((int) $ship_id).'">🚚 Expédition</a> '
    .'<a class="fx-btn" href="javascript:window.print();">🖨 Imprimer</a>';
print faurex_page_header($blRef, '📄', 'Bon de livraison', $right);

print '<div class="fx-card" style="max-width:1000px;margin:auto;">';
print '<div class="fx-card-body">';

print '<div style="text-align:center;margin-bottom:24px;">';
print '<h1 style="margin:0;color:#4f46e5;letter-spacing:2px;">BON DE LIVRAISON</h1>';
print '<div style="color:#6b7280;margin-top:6px;font-size:14px;">'.dol_escape_htmltag($blRef).' &nbsp; '.faurex_status_badge($etat[0], $etat[1]).'</div>';
print '</div>';

print '<div class="fx-info-grid">';
print faurex_info_item('Référence expédition', dol_escape_htmltag($header->ship_ref));
print faurex_info_item('Facture client', '<a href="'.DOL_URL_ROOT.'/compta/facture/card.php?facid='.((int) $header->fid).'">'.dol_escape_htmltag($header->fref).'</a>');
print faurex_info_item('Date facture', dol_print_date($db->jdate($header->datef), 'day'));
if ($date !== '') {
    print faurex_info_item('Date de livraison', dol_print_date($db->jdate($date), 'day'));
}
print '</div>';

// Client destinataire (fond clair + texte foncé : lisible aussi à l'impression)
print '<br><div class="fx-info-item" style="background:#eef2ff;border:2px solid #4f46e5;">';
print '<div class="fx-label" style="color:#3730a3;">Client (destinataire)</div>';
print '<div class="fx-value" style="color:#111827;font-size:18px;">'.dol_escape_htmltag($header->socname).'</div>';
$adresse = trim($header->address.' '.$header->zip.' '.$header->town);
if ($adresse !== '') {
    print '<div style="color:#374151;font-size:13px;margin-top:4px;">📍 '.dol_escape_htmltag($adresse).'</div>';
}
$tel = trim($header->phone ? $header->phone : $header->phone_mobile);
if ($tel !== '') {
    print '<div style="color:#374151;font-size:13px;margin-top:2px;">📞 '.dol_escape_htmltag($tel).'</div>';
}
if (!empty($header->email)) {
    print '<div style="color:#374151;font-size:13px;margin-top:2px;">✉ '.dol_escape_htmltag($header->email).'</div>';
}
print '</div>';

// Tableau des produits
print '<br><table class="fx-table">';
print '<thead><tr><th>Référence</th><th>Désignation</th><th>Dépôt</th>';
print '<th class="right">Qté facturée</th><th class="right">'.($date !== '' ? 'Livré ce jour' : 'Qté livrée').'</th><th class="right">Reste</th></tr></thead><tbody>';

if (empty($lines)) {
    print '<tr><td colspan="6" class="fx-empty">Aucun produit sur cette facture</td></tr>';
}

$sumFact = 0; $sumLivre = 0; $sumReste = 0;
foreach ($lines as $ln) {
    $livreTotal = ($date !== '') ? (isset($totalLivre[(int) $ln->fdid]) ? $totalLivre[(int) $ln->fdid] : 0) : (float) $ln->livre;
    $reste = max(0, (float) $ln->qty - $livreTotal);

    // Colonne Dépôt : d'où le produit est sorti, et où il est disponible pour le reste
    $depot = array();
    if (!empty($ln->depots_livres)) {
        $depot[] = '<span style="color:#10b981;">Sorti de : <strong>'.dol_escape_htmltag($ln->depots_livres).'</strong></span>';
    }
    if ($reste > 0) {
        if (!empty($dispo[(int) $ln->pid])) {
            $depot[] = '<span style="color:#6b7280;">Disponible : '.implode(', ', $dispo[(int) $ln->pid]).'</span>';
        } else {
            $depot[] = '<span style="color:#ef4444;">Aucun stock disponible</span>';
        }
    }

    print '<tr>';
    print '<td><strong>'.dol_escape_htmltag($ln->pref).'</strong></td>';
    print '<td>'.dol_escape_htmltag($ln->plabel).'</td>';
    print '<td>'.implode('<br>', $depot).'</td>';
    print '<td class="right">'.price2num($ln->qty, 'MS').'</td>';
    print '<td class="right"><strong>'.price2num($ln->livre, 'MS').'</strong></td>';
    print '<td class="right">'.($reste > 0 ? '<strong style="color:#f59e0b;">'.price2num($reste, 'MS').'</strong>' : '<span style="color:#10b981;">✓</span>').'</td>';
    print '</tr>';

    $sumFact += (float) $ln->qty;
    $sumLivre += (float) $ln->livre;
    $sumReste += $reste;
}
print '</tbody><tfoot><tr><td colspan="3" class="right"><strong>Total</strong></td>';
print '<td class="right">'.price2num($sumFact, 'MS').'</td>';
print '<td class="right"><strong>'.price2num($sumLivre, 'MS').'</strong></td>';
print '<td class="right">'.price2num($sumReste, 'MS').'</td></tr></tfoot></table>';

// Signatures (pour le bon imprimé)
print '<br><div style="display:flex;justify-content:space-between;gap:40px;margin-top:30px;">';
print '<div style="flex:1;border-top:1px solid #d1d5db;padding-top:8px;color:#6b7280;">Signature magasinier</div>';
print '<div style="flex:1;border-top:1px solid #d1d5db;padding-top:8px;color:#6b7280;">Signature client</div>';
print '</div>';

print '</div></div>';

llxFooter();
$db->close();