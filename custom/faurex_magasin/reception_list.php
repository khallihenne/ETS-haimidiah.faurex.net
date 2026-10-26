<?php
/**
 * Liste des réceptions (factures fournisseurs validées)
 */

$res = 0;
$sep = DIRECTORY_SEPARATOR;
for ($i = 0; $i < 5; $i++) {
    $dir = str_repeat('..'.$sep, $i);
    if (@include_once $dir.'main.inc.php') { $res = 1; break; }
}
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/custom/faurex_magasin/class/faurexreception.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/faurex_magasin/lib/faurex_ui.lib.php';

$langs->loadLangs(array("faurex_magasin@faurex_magasin", "bills", "companies"));
if (empty($user->rights->faurex_magasin->reception->read)) accessforbidden();

FaurexReception::syncFromValidatedInvoices($db, $user);

llxHeader(faurex_css_head(), 'Réceptions');

$stats = array('total' => 0, 'validated' => 0, 'partial' => 0, 'received' => 0);
$rs = $db->query("SELECT r.status, COUNT(*) c FROM ".MAIN_DB_PREFIX."faurex_reception r INNER JOIN ".MAIN_DB_PREFIX."facture_fourn f ON f.rowid = r.fk_facture_fourn AND f.fk_statut >= 1 GROUP BY r.status");
if ($rs) while ($o = $db->fetch_object($rs)) {
    $stats['total'] += $o->c;
    if ($o->status == 0) $stats['validated'] = $o->c;
    elseif ($o->status == 1) $stats['partial'] = $o->c;
    elseif ($o->status == 2) $stats['received'] = $o->c;
}

print faurex_page_header('Réceptions', '📦', 'Suivi logistique des factures fournisseurs validées');

print '<div class="fx-stats">';
print faurex_stat_card('Total', $stats['total'], 'info');
print faurex_stat_card('À traiter', $stats['validated'], '');
print faurex_stat_card('Partielles', $stats['partial'], 'warning');
print faurex_stat_card('Reçues', $stats['received'], 'success');
print '</div>';

$sql = "SELECT r.rowid, r.ref, r.fk_facture_fourn, r.fk_soc, r.date_facture, r.status, f.ref AS fref, soc.nom AS socname".
    " FROM ".MAIN_DB_PREFIX."faurex_reception r".
    " INNER JOIN ".MAIN_DB_PREFIX."facture_fourn f ON f.rowid = r.fk_facture_fourn AND f.fk_statut >= 1".
    " LEFT JOIN ".MAIN_DB_PREFIX."societe soc ON soc.rowid = r.fk_soc".
    " ORDER BY r.rowid DESC";
$res = $db->query($sql);

print '<table class="fx-table">';
print '<thead><tr>';
print '<th>Référence</th><th>Facture</th><th>Fournisseur</th><th>Date facturation</th><th class="center">État</th><th class="center">Action</th>';
print '</tr></thead><tbody>';

$count = 0;
if ($res) while ($obj = $db->fetch_object($res)) {
    $count++;
    $type = $obj->status == 2 ? 'done' : ($obj->status == 1 ? 'partial' : 'validated');
    $label = FaurexReception::getStatusLabel($obj->status);
    print '<tr>';
    print '<td><strong>'.dol_escape_htmltag($obj->ref).'</strong></td>';
    print '<td>'.dol_escape_htmltag($obj->fref).'</td>';
    print '<td>'.dol_escape_htmltag($obj->socname).'</td>';
    print '<td>'.dol_print_date($db->jdate($obj->date_facture), 'day').'</td>';
    print '<td class="center">'.faurex_status_badge($label, $type).'</td>';
    print '<td class="center"><a class="fx-action" href="reception_card.php?id='.(int)$obj->rowid.'" title="Détails">👁</a></td>';
    print '</tr>';
}
if ($count == 0) print '<tr><td colspan="6" class="fx-empty"><div class="fx-empty-icon">📥</div>Aucune réception pour le moment</td></tr>';
print '</tbody></table>';

llxFooter();
$db->close();
