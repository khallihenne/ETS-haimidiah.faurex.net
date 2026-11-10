<?php
/**
 * Liste des bons de livraison
 *  - un bon par facture client validée contenant des produits
 *  - deux états : En attente (il reste à livrer, même en partie) / Livré (tout est livré)
 */

$res = 0;
$sep = DIRECTORY_SEPARATOR;
for ($i = 0; $i < 5; $i++) {
    $dir = str_repeat('..'.$sep, $i);
    if (@include_once $dir.'main.inc.php') { $res = 1; break; }
}
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/custom/faurex_magasin/class/faurexshipment.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/faurex_magasin/lib/faurex_ui.lib.php';

$langs->loadLangs(array("faurex_magasin@faurex_magasin", "bills", "companies"));
if (empty($user->rights->faurex_magasin->shipment->read)) accessforbidden();

// Les nouvelles factures validées apparaissent tout de suite (expédition créée si elle manque)
FaurexShipment::syncFromValidatedInvoices($db, $user);

// --- Filtres ---
$search_client = trim(GETPOST('search_client', 'alphanohtml'));
$search_ref    = trim(GETPOST('search_ref', 'alphanohtml'));
$search_etat   = GETPOST('search_etat', 'aZ09');   // '', attente, livre
if (GETPOST('button_removefilter', 'alpha')) { $search_client = ''; $search_ref = ''; $search_etat = ''; }

// --- Requête : un bon par expédition (= par facture client) ---
$sql = "SELECT s.rowid AS ship_id, s.ref AS ship_ref, f.rowid AS fid, f.ref AS fref, f.datef,"
    ." soc.rowid AS socid, soc.nom AS socname, soc.town,"
    ." fq.qty_facture, COALESCE(dl.qty_livre, 0) AS qty_livre, dl.last_date"
    ." FROM ".MAIN_DB_PREFIX."faurex_shipment s"
    ." INNER JOIN ".MAIN_DB_PREFIX."facture f ON f.rowid = s.fk_facture AND f.fk_statut IN (1, 2) AND f.type = 0"
    ." LEFT JOIN ".MAIN_DB_PREFIX."societe soc ON soc.rowid = f.fk_soc"
    // Quantité facturée (produits uniquement)
    ." INNER JOIN (SELECT fk_facture, SUM(qty) AS qty_facture FROM ".MAIN_DB_PREFIX."facturedet"
    ."             WHERE fk_product > 0 AND product_type = 0 GROUP BY fk_facture) fq ON fq.fk_facture = f.rowid"
    // Quantité déjà livrée + date de la dernière livraison
    ." LEFT JOIN (SELECT fk_shipment, SUM(qty_delivered) AS qty_livre, MAX(date_shipment) AS last_date"
    ."            FROM ".MAIN_DB_PREFIX."faurex_shipment_line GROUP BY fk_shipment) dl ON dl.fk_shipment = s.rowid"
    ." WHERE f.entity IN (".getEntity('invoice').")";
if ($search_client !== '') {
    $sql .= " AND soc.nom LIKE '%".$db->escape($search_client)."%'";
}
if ($search_ref !== '') {
    $sql .= " AND (s.ref LIKE '%".$db->escape($search_ref)."%' OR f.ref LIKE '%".$db->escape($search_ref)."%')";
}
if ($search_etat === 'attente') {
    $sql .= " AND COALESCE(dl.qty_livre, 0) < fq.qty_facture";
} elseif ($search_etat === 'livre') {
    $sql .= " AND COALESCE(dl.qty_livre, 0) >= fq.qty_facture";
}
$sql .= " ORDER BY s.rowid DESC";
$sql .= $db->plimit(500);

$rows = array();
$nb = array('attente' => 0, 'livre' => 0);
$resq = $db->query($sql);
if ($resq) {
    while ($obj = $db->fetch_object($resq)) {
        $livre = (float) $obj->qty_livre;
        $fact  = (float) $obj->qty_facture;
        if ($livre < $fact) {
            $obj->etat = 'attente';   // rien livré, ou livré en partie
        } else {
            $obj->etat = 'livre';
        }
        $nb[$obj->etat]++;
        $rows[] = $obj;
    }
} else {
    setEventMessages($db->lasterror(), null, 'errors');
}

// Libellé et couleur de chaque état
$etats = array(
    'attente' => array('En attente', 'validated'),
    'livre'   => array('Livré', 'done'),
);

llxHeader(faurex_css_head(), 'Bons de livraison');

print faurex_page_header('Bons de livraison', '📄', 'Un bon par facture client : en attente ou livré');

print '<div class="fx-stats">';
print faurex_stat_card('Total', count($rows), 'info');
print faurex_stat_card('En attente', $nb['attente'], '');
print faurex_stat_card('Livrés', $nb['livre'], 'success');
print '</div>';

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
print '<table class="fx-table"><thead>';
print '<tr>';
print '<th>Référence BL</th><th>Facture</th><th>Client</th><th>Date facture</th>';
print '<th class="right">Qté facturée</th><th class="right">Qté livrée</th><th class="center">État</th><th class="center">Action</th>';
print '</tr>';

// Ligne de filtres
print '<tr class="liste_titre_filter">';
print '<td colspan="2"><input type="text" class="fx-input" name="search_ref" value="'.dol_escape_htmltag($search_ref).'" placeholder="Réf. / facture..."></td>';
print '<td><input type="text" class="fx-input" name="search_client" value="'.dol_escape_htmltag($search_client).'" placeholder="Nom client..."></td>';
print '<td></td><td></td><td></td>';
print '<td class="center"><select class="fx-input" name="search_etat">';
print '<option value="">-- Tous --</option>';
foreach ($etats as $code => $e) {
    print '<option value="'.$code.'"'.($search_etat === $code ? ' selected' : '').'>'.$e[0].'</option>';
}
print '</select></td>';
print '<td class="center">';
print '<button type="submit" class="fx-btn fx-btn-primary" title="Filtrer">🔍</button> ';
print '<button type="submit" class="fx-btn fx-btn-secondary" name="button_removefilter" value="1" title="Réinitialiser">🧹</button>';
print '</td>';
print '</tr></thead><tbody>';

if (empty($rows)) {
    print '<tr><td colspan="8" class="fx-empty"><div class="fx-empty-icon">📄</div>Aucun bon de livraison à afficher</td></tr>';
}

foreach ($rows as $obj) {
    $blRef = 'BL-'.$obj->ship_ref;
    $e = $etats[$obj->etat];
    print '<tr>';
    print '<td><strong>'.dol_escape_htmltag($blRef).'</strong></td>';
    print '<td><a href="'.DOL_URL_ROOT.'/compta/facture/card.php?facid='.((int) $obj->fid).'">'.dol_escape_htmltag($obj->fref).'</a></td>';
    print '<td>'.dol_escape_htmltag($obj->socname).($obj->town ? ' <span style="color:#6b7280;font-size:12px;">'.dol_escape_htmltag($obj->town).'</span>' : '').'</td>';
    print '<td>'.dol_print_date($db->jdate($obj->datef), 'day').'</td>';
    print '<td class="right">'.price2num($obj->qty_facture, 'MS').'</td>';
    print '<td class="right"><strong>'.price2num($obj->qty_livre, 'MS').'</strong></td>';
    print '<td class="center">'.faurex_status_badge($e[0], $e[1]).'</td>';
    print '<td class="center"><a class="fx-action" href="bon_expedition_card.php?ship_id='.((int) $obj->ship_id).'" title="Ouvrir le bon de livraison">📄</a></td>';
    print '</tr>';
}
print '</tbody></table></form>';

llxFooter();
$db->close();