<?php
/**
 * Historique des suppressions, annulations et modifications (expéditions et réceptions)
 */

$res = 0;
$sep = DIRECTORY_SEPARATOR;
for ($i = 0; $i < 5; $i++) {
    $dir = str_repeat('..'.$sep, $i);
    if (@include_once $dir.'main.inc.php') { $res = 1; break; }
}
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/custom/faurex_magasin/lib/faurex_ui.lib.php';
require_once DOL_DOCUMENT_ROOT.'/custom/faurex_magasin/lib/faurex_magasin.lib.php';

$langs->loadLangs(array("faurex_magasin@faurex_magasin", "products", "stocks"));

$canShip = !empty($user->rights->faurex_magasin->shipment->read);
$canRec  = !empty($user->rights->faurex_magasin->reception->read);
if (!$canShip && !$canRec) accessforbidden();

faurex_history_ensure_table($db);

// --- Filtres ---
$search_element = GETPOST('search_element', 'aZ09');   // '', shipment, reception
$search_action  = GETPOST('search_action', 'aZ09');    // '', DELETE_LINE, CANCEL_ALL, UPDATE_LINE
$search_ref     = trim(GETPOST('search_ref', 'alphanohtml'));
$search_from    = GETPOST('search_from', 'alpha');     // YYYY-MM-DD
$search_to      = GETPOST('search_to', 'alpha');       // YYYY-MM-DD
if (GETPOST('button_removefilter', 'alpha')) {
    $search_element = ''; $search_action = ''; $search_ref = ''; $search_from = ''; $search_to = '';
}

$sql = "SELECT h.rowid, h.element, h.fk_element, h.element_ref, h.action, h.qty_before, h.qty_after, h.motif, h.date_action,";
$sql .= " p.ref AS pref, p.label AS plabel, e.ref AS wref, u.login, u.firstname, u.lastname";
$sql .= " FROM ".MAIN_DB_PREFIX."faurex_history h";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = h.fk_product";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."entrepot e ON e.rowid = h.fk_warehouse";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user u ON u.rowid = h.fk_user";
$sql .= " WHERE 1 = 1";
// Seulement ce que l'utilisateur a le droit de voir
if (!$canShip) $sql .= " AND h.element <> 'shipment'";
if (!$canRec)  $sql .= " AND h.element <> 'reception'";
if (in_array($search_element, array('shipment', 'reception'))) {
    $sql .= " AND h.element = '".$db->escape($search_element)."'";
}
if (in_array($search_action, array('DELETE_LINE', 'CANCEL_ALL', 'UPDATE_LINE'))) {
    $sql .= " AND h.action = '".$db->escape($search_action)."'";
}
if ($search_ref !== '') {
    $sql .= " AND h.element_ref LIKE '%".$db->escape($search_ref)."%'";
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $search_from)) {
    $sql .= " AND h.date_action >= '".$db->escape($search_from)." 00:00:00'";
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $search_to)) {
    $sql .= " AND h.date_action <= '".$db->escape($search_to)." 23:59:59'";
}
$sql .= " ORDER BY h.date_action DESC, h.rowid DESC";
$sql .= $db->plimit(500);

$rows = array();
$resql = $db->query($sql);
if ($resql) {
    while ($o = $db->fetch_object($resql)) $rows[] = $o;
} else {
    setEventMessages($db->lasterror(), null, 'errors');
}

llxHeader(faurex_css_head(), 'Historique des suppressions');

print faurex_page_header('Historique des suppressions', '🗂', 'Suppressions, annulations et modifications sur les expéditions et réceptions (500 dernières)');

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
print '<table class="fx-table"><thead>';
print '<tr><th>Date</th><th>Utilisateur</th><th>Type</th><th>Référence</th><th>Action</th><th>Produit</th><th>Entrepôt</th>';
print '<th class="right">Qté avant</th><th class="right">Qté après</th><th>Motif</th></tr>';

print '<tr class="liste_titre_filter">';
print '<td><input type="date" class="fx-input" name="search_from" value="'.dol_escape_htmltag($search_from).'" title="Du"><br>';
print '<input type="date" class="fx-input" name="search_to" value="'.dol_escape_htmltag($search_to).'" title="Au"></td>';
print '<td></td>';
print '<td><select class="fx-input" name="search_element">';
print '<option value="">-- Tous --</option>';
print '<option value="shipment"'.($search_element == 'shipment' ? ' selected' : '').'>Expédition</option>';
print '<option value="reception"'.($search_element == 'reception' ? ' selected' : '').'>Réception</option>';
print '</select></td>';
print '<td><input type="text" class="fx-input" name="search_ref" value="'.dol_escape_htmltag($search_ref).'" placeholder="EXP... / REC..."></td>';
print '<td><select class="fx-input" name="search_action">';
print '<option value="">-- Toutes --</option>';
foreach (array('DELETE_LINE', 'CANCEL_ALL', 'UPDATE_LINE') as $a) {
    print '<option value="'.$a.'"'.($search_action == $a ? ' selected' : '').'>'.faurex_history_action_label($a).'</option>';
}
print '</select></td>';
print '<td></td><td></td><td></td><td></td>';
print '<td class="center">';
print '<button type="submit" class="fx-btn fx-btn-primary" title="Filtrer">🔍</button> ';
print '<button type="submit" class="fx-btn fx-btn-secondary" name="button_removefilter" value="1" title="Réinitialiser">🧹</button>';
print '</td>';
print '</tr></thead><tbody>';

if (empty($rows)) {
    print '<tr><td colspan="10" class="fx-empty"><div class="fx-empty-icon">📭</div>Aucune opération enregistrée</td></tr>';
}

foreach ($rows as $o) {
    $isShip = ($o->element === 'shipment');
    $card = $isShip ? 'shipment_card.php' : 'reception_card.php';
    $userName = trim($o->firstname.' '.$o->lastname);
    if ($userName === '') $userName = $o->login;
    $badgeType = ($o->action === 'UPDATE_LINE') ? 'partial' : 'cancelled';

    print '<tr>';
    print '<td>'.dol_print_date($db->jdate($o->date_action), 'dayhour').'</td>';
    print '<td>'.dol_escape_htmltag($userName).'</td>';
    print '<td>'.($isShip ? '🚚 Expédition' : '📦 Réception').'</td>';
    print '<td><a href="'.$card.'?id='.(int)$o->fk_element.'">'.dol_escape_htmltag($o->element_ref).'</a></td>';
    print '<td>'.faurex_status_badge(faurex_history_action_label($o->action), $badgeType).'</td>';
    print '<td><strong>'.dol_escape_htmltag($o->pref).'</strong> <span style="color:#6b7280;font-size:12px;">'.dol_escape_htmltag($o->plabel).'</span></td>';
    print '<td>'.dol_escape_htmltag($o->wref).'</td>';
    print '<td class="right">'.($o->qty_before !== null ? price2num($o->qty_before, 'MS') : '').'</td>';
    print '<td class="right">'.($o->qty_after !== null ? price2num($o->qty_after, 'MS') : '').'</td>';
    print '<td>'.dol_escape_htmltag($o->motif).'</td>';
    print '</tr>';
}
print '</tbody></table></form>';

llxFooter();
$db->close();