<?php
/**
 * Liste des expéditions (factures clients validées)
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

FaurexShipment::syncFromValidatedInvoices($db, $user);

// --- GESTION ET MÉMORISATION DES FILTRES (POINT 4) ---
$search_ref    = GETPOST('search_ref', 'alpha');
$search_client = GETPOST('search_client', 'alpha');
$search_status = GETPOST('search_status', 'int');

if (GETPOST('button_search', 'alpha') || GETPOST('button_search.x', 'alpha')) {
    $_SESSION['faurex_ship_search_ref']    = $search_ref;
    $_SESSION['faurex_ship_search_client'] = $search_client;
    $_SESSION['faurex_ship_search_status'] = $search_status;
} elseif (GETPOST('button_removefilter', 'alpha') || GETPOST('button_removefilter.x', 'alpha')) {
    $_SESSION['faurex_ship_search_ref']    = '';
    $_SESSION['faurex_ship_search_client'] = '';
    $_SESSION['faurex_ship_search_status'] = -1;
    $search_ref = ''; $search_client = ''; $search_status = -1;
} else {
    $search_ref    = isset($_SESSION['faurex_ship_search_ref']) ? $_SESSION['faurex_ship_search_ref'] : '';
    $search_client = isset($_SESSION['faurex_ship_search_client']) ? $_SESSION['faurex_ship_search_client'] : '';
    $search_status = isset($_SESSION['faurex_ship_search_status']) ? (int)$_SESSION['faurex_ship_search_status'] : -1;
}

llxHeader(faurex_css_head(), 'Expéditions');

// Stats
$stats = array('total' => 0, 'validated' => 0, 'partial' => 0, 'delivered' => 0);
$rs = $db->query("SELECT s.status, COUNT(*) c FROM ".MAIN_DB_PREFIX."faurex_shipment s INNER JOIN ".MAIN_DB_PREFIX."facture f ON f.rowid = s.fk_facture AND f.fk_statut >= 1 GROUP BY s.status");
if ($rs) while ($o = $db->fetch_object($rs)) {
    $stats['total'] += $o->c;
    if ($o->status == 0) $stats['validated'] = $o->c;
    elseif ($o->status == 1) $stats['partial'] = $o->c;
    elseif ($o->status == 2) $stats['delivered'] = $o->c;
}

print faurex_page_header('Expéditions', '🚚', 'Suivi logistique des factures clients validées');

print '<div class="fx-stats">';
print faurex_stat_card('Total', $stats['total'], 'info');
print faurex_stat_card('À traiter', $stats['validated'], '');
print faurex_stat_card('Partielles', $stats['partial'], 'warning');
print faurex_stat_card('Livrées', $stats['delivered'], 'success');
print '</div>';

// Construction de la requête SQL avec clauses WHERE dynamiques
$sql = "SELECT s.rowid, s.ref, s.fk_facture, s.fk_soc, s.date_facture, s.status, f.ref AS fref, soc.nom AS socname".
    " FROM ".MAIN_DB_PREFIX."faurex_shipment s".
    " INNER JOIN ".MAIN_DB_PREFIX."facture f ON f.rowid = s.fk_facture AND f.fk_statut >= 1".
    " LEFT JOIN ".MAIN_DB_PREFIX."societe soc ON soc.rowid = s.fk_soc".
    " WHERE 1=1";

if ($search_ref != '') {
    $sql .= " AND (s.ref LIKE '%".$db->escape($search_ref)."%' OR f.ref LIKE '%".$db->escape($search_ref)."%')";
}
if ($search_client != '') {
    $sql .= " AND soc.nom LIKE '%".$db->escape($search_client)."%'";
}
if ($search_status >= 0) {
    $sql .= " AND s.status = ".(int)$search_status;
}

$sql .= " ORDER BY s.rowid DESC";
$res = $db->query($sql);

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';

print '<table class="fx-table">';
print '<thead>';
print '<tr>';
print '<th>Référence / Facture</th><th>Client</th><th>Date facturation</th><th class="center">État</th><th class="center">Action</th>';
print '</tr>';

// Ligne d'inputs de filtrage (Point 4)
print '<tr class="liste_titre_filter">';
print '<td><input type="text" class="fx-input" name="search_ref" value="'.dol_escape_htmltag($search_ref).'" placeholder="Ref / Facture..."></td>';
print '<td><input type="text" class="fx-input" name="search_client" value="'.dol_escape_htmltag($search_client).'" placeholder="Nom client..."></td>';
print '<td></td>';
print '<td class="center">';
print '<select class="fx-input" name="search_status">';
print '<option value="-1"'.($search_status == -1 ? ' selected' : '').'>-- Tous --</option>';
print '<option value="0"'.($search_status == 0 ? ' selected' : '').'>À traiter</option>';
print '<option value="1"'.($search_status == 1 ? ' selected' : '').'>Partiellement livrée</option>';
print '<option value="2"'.($search_status == 2 ? ' selected' : '').'>Livrée</option>';
print '</select>';
print '</td>';
print '<td class="center">';
print '<button type="submit" class="fx-btn fx-btn-primary" name="button_search" value="1" title="Filtrer">🔍</button> ';
print '<button type="submit" class="fx-btn fx-btn-secondary" name="button_removefilter" value="1" title="Réinitialiser">🧹</button>';
print '</td>';
print '</tr>';
print '</thead><tbody>';

$count = 0;
if ($res) while ($obj = $db->fetch_object($res)) {
    $count++;
    $type = $obj->status == 2 ? 'done' : ($obj->status == 1 ? 'partial' : 'validated');
    $label = FaurexShipment::getStatusLabel($obj->status);
    print '<tr>';
    print '<td><strong>'.dol_escape_htmltag($obj->ref).'</strong><br><span style="color:#6b7280;font-size:12px;">'.$obj->fref.'</span></td>';
    print '<td>'.dol_escape_htmltag($obj->socname).'</td>';
    print '<td>'.dol_print_date($db->jdate($obj->date_facture), 'day').'</td>';
    print '<td class="center">'.faurex_status_badge($label, $type).'</td>';
    print '<td class="center"><a class="fx-action" href="shipment_card.php?id='.(int)$obj->rowid.'" title="Détails">👁</a></td>';
    print '</tr>';
}
if ($count == 0) print '<tr><td colspan="5" class="fx-empty"><div class="fx-empty-icon">📭</div>Aucune expédition ne correspond aux critères</td></tr>';
print '</tbody></table>';
print '</form>';

llxFooter();
$db->close();