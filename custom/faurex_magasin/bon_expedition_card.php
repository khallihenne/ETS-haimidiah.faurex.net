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

$ship_id = GETPOST('ship_id', 'int');
$date    = GETPOST('date', 'alpha');
if (!$ship_id) accessforbidden('Paramètres manquants');

$sql = "SELECT s.ref AS ship_ref, s.fk_facture, f.ref AS fref, soc.nom AS socname, soc.address, soc.zip, soc.town, ".
    "sl.fk_product, p.ref AS pref, p.label AS plabel, SUM(sl.qty_delivered) AS qty ".
    "FROM ".MAIN_DB_PREFIX."faurex_shipment_line sl ".
    "INNER JOIN ".MAIN_DB_PREFIX."faurex_shipment s ON s.rowid = sl.fk_shipment ".
    "LEFT JOIN ".MAIN_DB_PREFIX."facture f ON f.rowid = s.fk_facture ".
    "LEFT JOIN ".MAIN_DB_PREFIX."societe soc ON soc.rowid = s.fk_soc ".
    "LEFT JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = sl.fk_product ".
    "WHERE sl.fk_shipment = ".(int)$ship_id." AND sl.qty_delivered > 0 ".
    ($date && $date !== '0000-00-00' ? " AND sl.date_shipment = '".$db->escape($date)."'" : "").
    " GROUP BY s.ref, s.fk_facture, f.ref, soc.nom, soc.address, soc.zip, soc.town, sl.fk_product, p.ref, p.label".
    " ORDER BY p.ref ASC";
$resq = $db->query($sql);
if (!$resq) accessforbidden('Erreur SQL');

$header = null; $lines = array();
while ($obj = $db->fetch_object($resq)) { if ($header === null) $header = $obj; $lines[] = $obj; }
if (!$header) accessforbidden('Aucune donnée');

$bcRef = 'BC-'.$header->ship_ref.'-'.dol_print_date($db->jdate($date), '%Y%m%d');

llxHeader(faurex_css_head(), 'Bon de commande '.$bcRef);

$right = '<a class="fx-btn fx-btn-secondary" href="bon_expedition_list.php">← Retour</a> '.
    '<a class="fx-btn" href="javascript:window.print();">🖨 Imprimer</a>';
print faurex_page_header($bcRef, '📄', 'Bon de commande - Expédition', $right);

print '<div class="fx-card" style="max-width:900px;margin:auto;">';
print '<div class="fx-card-body">';

print '<div style="text-align:center;margin-bottom:24px;">';
print '<h1 style="margin:0;color:#4f46e5;letter-spacing:2px;">BON DE COMMANDE</h1>';
print '<div style="color:#6b7280;margin-top:6px;font-size:14px;">'.dol_escape_htmltag($bcRef).'</div>';
print '</div>';

print '<div class="fx-info-grid">';
print faurex_info_item('Référence expédition', dol_escape_htmltag($header->ship_ref));
print faurex_info_item('Facture client', dol_escape_htmltag($header->fref));
print faurex_info_item('Date expédition', dol_print_date($db->jdate($date), 'day'));
print '</div>';

print '<br><div class="fx-info-item" style="background:linear-gradient(135deg,#4f46e5 0%,#3730a3 100%);color:#fff;">';
print '<div class="fx-label" style="color:rgba(255,255,255,.8);">Destinataire</div>';
print '<div class="fx-value" style="color:#fff;font-size:16px;">'.dol_escape_htmltag($header->socname).'</div>';
if ($header->address || $header->zip || $header->town) {
    print '<div style="color:rgba(255,255,255,.9);font-size:13px;margin-top:4px;">'.dol_escape_htmltag($header->address).' '.dol_escape_htmltag($header->zip).' '.dol_escape_htmltag($header->town).'</div>';
}
print '</div>';

print '<br><table class="fx-table">';
print '<thead><tr><th>Référence</th><th>Désignation</th><th class="right">Quantité</th></tr></thead><tbody>';
$total = 0;
foreach ($lines as $ln) {
    print '<tr>';
    print '<td><strong>'.dol_escape_htmltag($ln->pref).'</strong></td>';
    print '<td>'.dol_escape_htmltag($ln->plabel).'</td>';
    print '<td class="right"><strong>'.$ln->qty.'</strong></td>';
    print '</tr>';
    $total += (float)$ln->qty;
}
print '</tbody><tfoot><tr><td colspan="2" class="right">Total</td><td class="right">'.$total.'</td></tr></tfoot></table>';

print '</div></div>';

llxFooter();
$db->close();
