<?php
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

llxHeader(faurex_css_head(), 'Bons de commande - Expéditions');

print faurex_page_header('Bons de commande', '📄', 'Documents générés à partir des expéditions livrées');

$sql = "SELECT s.rowid AS ship_id, s.ref AS ship_ref, s.fk_facture, s.fk_soc, soc.nom AS socname, ".
    "sl.date_shipment, COUNT(*) AS nb_lines, SUM(sl.qty_delivered) AS total_qty ".
    "FROM ".MAIN_DB_PREFIX."faurex_shipment_line sl ".
    "INNER JOIN ".MAIN_DB_PREFIX."faurex_shipment s ON s.rowid = sl.fk_shipment ".
    "LEFT JOIN ".MAIN_DB_PREFIX."societe soc ON soc.rowid = s.fk_soc ".
    "WHERE sl.qty_delivered > 0 ".
    "GROUP BY s.rowid, s.ref, s.fk_facture, s.fk_soc, soc.nom, sl.date_shipment ".
    "ORDER BY sl.date_shipment DESC, s.rowid DESC";
$resq = $db->query($sql);

print '<table class="fx-table">';
print '<thead><tr>';
print '<th>Référence BC</th><th>Réf. expédition</th><th>Tiers</th><th>Date</th>';
print '<th class="right">Lignes</th><th class="right">Qté totale</th><th class="center">État</th><th class="center">Action</th>';
print '</tr></thead><tbody>';
$count = 0;
if ($resq) while ($obj = $db->fetch_object($resq)) {
    $count++;
    $dateStr = $db->jdate($obj->date_shipment);
    $dateParam = $obj->date_shipment ? $obj->date_shipment : '0000-00-00';
    $bcRef = 'BC-'.$obj->ship_ref.'-'.dol_print_date($dateStr, '%Y%m%d');
    print '<tr>';
    print '<td><strong>'.dol_escape_htmltag($bcRef).'</strong></td>';
    print '<td><a href="shipment_card.php?id='.(int)$obj->ship_id.'">'.dol_escape_htmltag($obj->ship_ref).'</a></td>';
    print '<td>'.dol_escape_htmltag($obj->socname).'</td>';
    print '<td>'.dol_print_date($dateStr, 'day').'</td>';
    print '<td class="right">'.(int)$obj->nb_lines.'</td>';
    print '<td class="right"><strong>'.$obj->total_qty.'</strong></td>';
    print '<td class="center">'.faurex_status_badge('Livré', 'done').'</td>';
    print '<td class="center"><a class="fx-action" href="bon_expedition_card.php?ship_id='.(int)$obj->ship_id.'&date='.urlencode($dateParam).'" title="Générer le bon">📄</a></td>';
    print '</tr>';
}
if ($count == 0) print '<tr><td colspan="8" class="fx-empty"><div class="fx-empty-icon">📄</div>Aucun bon de commande à afficher</td></tr>';
print '</tbody></table>';

llxFooter();
$db->close();
