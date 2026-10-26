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
if (empty($user->rights->faurex_magasin->reception->read)) accessforbidden();

llxHeader(faurex_css_head(), 'Bons de commande - Réceptions');

print faurex_page_header('Bons de commande', '📄', 'Documents générés à partir des réceptions');

$sql = "SELECT r.rowid AS rec_id, r.ref AS rec_ref, r.fk_facture_fourn, r.fk_soc, soc.nom AS socname, ".
    "rl.date_reception, COUNT(*) AS nb_lines, SUM(rl.qty_received) AS total_qty ".
    "FROM ".MAIN_DB_PREFIX."faurex_reception_line rl ".
    "INNER JOIN ".MAIN_DB_PREFIX."faurex_reception r ON r.rowid = rl.fk_reception ".
    "LEFT JOIN ".MAIN_DB_PREFIX."societe soc ON soc.rowid = r.fk_soc ".
    "WHERE rl.qty_received > 0 ".
    "GROUP BY r.rowid, r.ref, r.fk_facture_fourn, r.fk_soc, soc.nom, rl.date_reception ".
    "ORDER BY rl.date_reception DESC, r.rowid DESC";
$resq = $db->query($sql);

print '<table class="fx-table">';
print '<thead><tr>';
print '<th>Référence BC</th><th>Réf. réception</th><th>Tiers</th><th>Date</th>';
print '<th class="right">Lignes</th><th class="right">Qté totale</th><th class="center">État</th><th class="center">Action</th>';
print '</tr></thead><tbody>';
$count = 0;
if ($resq) while ($obj = $db->fetch_object($resq)) {
    $count++;
    $dateStr = $db->jdate($obj->date_reception);
    $dateParam = $obj->date_reception ? $obj->date_reception : '0000-00-00';
    $bcRef = 'BC-'.$obj->rec_ref.'-'.dol_print_date($dateStr, '%Y%m%d');
    print '<tr>';
    print '<td><strong>'.dol_escape_htmltag($bcRef).'</strong></td>';
    print '<td><a href="reception_card.php?id='.(int)$obj->rec_id.'">'.dol_escape_htmltag($obj->rec_ref).'</a></td>';
    print '<td>'.dol_escape_htmltag($obj->socname).'</td>';
    print '<td>'.dol_print_date($dateStr, 'day').'</td>';
    print '<td class="right">'.(int)$obj->nb_lines.'</td>';
    print '<td class="right"><strong>'.$obj->total_qty.'</strong></td>';
    print '<td class="center">'.faurex_status_badge('Reçu', 'done').'</td>';
    print '<td class="center"><a class="fx-action" href="bon_reception_card.php?rec_id='.(int)$obj->rec_id.'&date='.urlencode($dateParam).'" title="Générer le bon">📄</a></td>';
    print '</tr>';
}
if ($count == 0) print '<tr><td colspan="8" class="fx-empty"><div class="fx-empty-icon">📄</div>Aucun bon de commande à afficher</td></tr>';
print '</tbody></table>';

llxFooter();
$db->close();
