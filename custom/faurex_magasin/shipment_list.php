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

$sql = "SELECT s.rowid, s.ref, s.fk_facture, s.fk_soc, s.date_facture, s.status, f.ref AS fref, soc.nom AS socname".
    " FROM ".MAIN_DB_PREFIX."faurex_shipment s".
    " INNER JOIN ".MAIN_DB_PREFIX."facture f ON f.rowid = s.fk_facture AND f.fk_statut >= 1".
    " LEFT JOIN ".MAIN_DB_PREFIX."societe soc ON soc.rowid = s.fk_soc".
    " ORDER BY s.rowid DESC";
$res = $db->query($sql);

print '<table class="fx-table">';
print '<thead><tr>';
print '<th>Référence</th><th>Facture</th><th>Client</th><th>Date facturation</th><th class="center">État</th><th class="center">Action</th>';
print '</tr></thead><tbody>';

$count = 0;
if ($res) while ($obj = $db->fetch_object($res)) {
    $count++;
    $type = $obj->status == 2 ? 'done' : ($obj->status == 1 ? 'partial' : 'validated');
    $label = FaurexShipment::getStatusLabel($obj->status);
    print '<tr>';
    print '<td><strong>'.dol_escape_htmltag($obj->ref).'</strong></td>';
    print '<td>'.dol_escape_htmltag($obj->fref).'</td>';
    print '<td>'.dol_escape_htmltag($obj->socname).'</td>';
    print '<td>'.dol_print_date($db->jdate($obj->date_facture), 'day').'</td>';
    print '<td class="center">'.faurex_status_badge($label, $type).'</td>';
    print '<td class="center"><a class="fx-action" href="shipment_card.php?id='.(int)$obj->rowid.'" title="Détails">👁</a></td>';
    print '</tr>';
}
if ($count == 0) print '<tr><td colspan="6" class="fx-empty"><div class="fx-empty-icon">📭</div>Aucune expédition pour le moment</td></tr>';
print '</tbody></table>';

llxFooter();
$db->close();
