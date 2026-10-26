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

$rec_id = GETPOST('rec_id', 'int');
$date   = GETPOST('date', 'alpha');
if (!$rec_id) accessforbidden('Paramètres manquants');

$sql = "SELECT r.ref AS rec_ref, r.fk_facture_fourn, f.ref AS fref, soc.nom AS socname, soc.address, soc.zip, soc.town, ".
    "rl.fk_product, p.ref AS pref, p.label AS plabel, SUM(rl.qty_received) AS qty ".
    "FROM ".MAIN_DB_PREFIX."faurex_reception_line rl ".
    "INNER JOIN ".MAIN_DB_PREFIX."faurex_reception r ON r.rowid = rl.fk_reception ".
    "LEFT JOIN ".MAIN_DB_PREFIX."facture_fourn f ON f.rowid = r.fk_facture_fourn ".
    "LEFT JOIN ".MAIN_DB_PREFIX."societe soc ON soc.rowid = r.fk_soc ".
    "LEFT JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = rl.fk_product ".
    "WHERE rl.fk_reception = ".(int)$rec_id." AND rl.qty_received > 0 ".
    ($date && $date !== '0000-00-00' ? " AND rl.date_reception = '".$db->escape($date)."'" : "").
    " GROUP BY r.ref, r.fk_facture_fourn, f.ref, soc.nom, soc.address, soc.zip, soc.town, rl.fk_product, p.ref, p.label".
    " ORDER BY p.ref ASC";
$resq = $db->query($sql);
if (!$resq) accessforbidden('Erreur SQL');

$header = null; $lines = array();
while ($obj = $db->fetch_object($resq)) { if ($header === null) $header = $obj; $lines[] = $obj; }
if (!$header) accessforbidden('Aucune donnée');

$bcRef = 'BC-'.$header->rec_ref.'-'.dol_print_date($db->jdate($date), '%Y%m%d');

llxHeader(faurex_css_head(), 'Bon de commande '.$bcRef);

$right = '<a class="fx-btn fx-btn-secondary" href="bon_reception_list.php">← Retour</a> '.
    '<a class="fx-btn" href="javascript:window.print();">🖨 Imprimer</a>';
print faurex_page_header($bcRef, '📄', 'Bon de commande - Réception', $right);

print '<div class="fx-card" style="max-width:900px;margin:auto;">';
print '<div class="fx-card-body">';

print '<div style="text-align:center;margin-bottom:24px;">';
print '<h1 style="margin:0;color:#4f46e5;letter-spacing:2px;">BON DE COMMANDE</h1>';
print '<div style="color:#6b7280;margin-top:6px;font-size:14px;">'.dol_escape_htmltag($bcRef).'</div>';
print '</div>';

print '<div class="fx-info-grid">';
print faurex_info_item('Référence réception', dol_escape_htmltag($header->rec_ref));
print faurex_info_item('Facture fournisseur', dol_escape_htmltag($header->fref));
print faurex_info_item('Date réception', dol_print_date($db->jdate($date), 'day'));
print '</div>';

print '<br><div class="fx-info-item" style="background:linear-gradient(135deg,#4f46e5 0%,#3730a3 100%);color:#fff;">';
print '<div class="fx-label" style="color:rgba(255,255,255,.8);">Fournisseur</div>';
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
