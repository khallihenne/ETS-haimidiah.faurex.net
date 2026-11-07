<?php
/**
 * Clients non livrés (reste à livrer par client / facture / produit)
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

// --- Filtres ---
$search_client = trim(GETPOST('search_client', 'alphanohtml'));
$search_date   = GETPOST('search_date', 'alpha'); // YYYY-MM-DD
if (GETPOST('button_removefilter', 'alpha')) { $search_client = ''; $search_date = ''; }

// Clients de test à exclure
$exclus = array('Client test', 'khallihenne', 'Mehasen');
$excl_sql = "'".implode("','", array_map(array($db, 'escape'), $exclus))."'";

$where = "";
if ($search_client !== '') {
    $where .= " AND soc.nom LIKE '%".$db->escape($search_client)."%'";
}
if ($search_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $search_date)) {
    $where .= " AND f.datef >= '".$db->escape($search_date)."'";
}

// --- Requête : reste à livrer par facture et produit ---
$sql = "SELECT soc.rowid AS socid, soc.nom AS client, f.rowid AS fid, f.ref AS facture, f.datef,"
    ." p.rowid AS pid, p.ref AS produit,"
    ." d.qty AS commande, COALESCE(l.livre,0) AS livre, d.qty - COALESCE(l.livre,0) AS reste"
    ." FROM (SELECT fk_facture, fk_product, SUM(qty) AS qty FROM ".MAIN_DB_PREFIX."facturedet"
    ."       WHERE fk_product > 0 GROUP BY fk_facture, fk_product) d"
    ." JOIN ".MAIN_DB_PREFIX."facture f ON f.rowid = d.fk_facture AND f.fk_statut > 0 AND f.type <> 2"
    ." JOIN ".MAIN_DB_PREFIX."societe soc ON soc.rowid = f.fk_soc"
    ." JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = d.fk_product AND p.fk_product_type = 0"
    ." LEFT JOIN (SELECT s.fk_facture, sl.fk_product, SUM(sl.qty_delivered) AS livre"
    ."            FROM ".MAIN_DB_PREFIX."faurex_shipment s"
    ."            JOIN ".MAIN_DB_PREFIX."faurex_shipment_line sl ON sl.fk_shipment = s.rowid"
    ."            WHERE s.status IN (1,2)"
    ."            GROUP BY s.fk_facture, sl.fk_product) l"
    ."   ON l.fk_facture = f.rowid AND l.fk_product = d.fk_product"
    ." WHERE d.qty - COALESCE(l.livre,0) > 0"
    ." AND soc.nom NOT IN (".$excl_sql.")".$where
    ." ORDER BY soc.nom, f.datef, f.ref";

$rows = array();
$parClient = array();
$parProduit = array();
$totalGeneral = 0;

$resql = $db->query($sql);
if ($resql) {
    while ($o = $db->fetch_object($resql)) {
        $rows[] = $o;
        $parClient[$o->socid] = (isset($parClient[$o->socid]) ? $parClient[$o->socid] : 0) + $o->reste;
        if (!isset($parProduit[$o->pid])) $parProduit[$o->pid] = array('ref' => $o->produit, 'reste' => 0);
        $parProduit[$o->pid]['reste'] += $o->reste;
        $totalGeneral += $o->reste;
    }
} else {
    setEventMessages($db->lasterror(), null, 'errors');
}

// --- Stock total par produit (tous entrepôts) ---
$stocks = array();
$rs = $db->query("SELECT fk_product, SUM(reel) AS s FROM ".MAIN_DB_PREFIX."product_stock GROUP BY fk_product");
if ($rs) {
    while ($o = $db->fetch_object($rs)) {
        $stocks[$o->fk_product] = (float) $o->s;
    }
}

// --- Affichage ---
llxHeader(faurex_css_head(), 'Clients non livrés');

print faurex_page_header('Clients non livrés', '📦', 'Quantités facturées restant à livrer, par client');

print '<div class="fx-stats">';
print faurex_stat_card('Total à livrer', price2num($totalGeneral, 'MS'), 'warning');
print faurex_stat_card('Clients', count($parClient), 'info');
print faurex_stat_card('Lignes', count($rows), '');
print '</div>';

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
print '<table class="fx-table"><thead>';
print '<tr><th>Client</th><th>Facture</th><th>Date</th><th>Produit</th>'
    .'<th class="center">Commandé</th><th class="center">Livré</th><th class="center">Reste</th></tr>';

print '<tr class="liste_titre_filter">';
print '<td><input type="text" class="fx-input" name="search_client" value="'.dol_escape_htmltag($search_client).'" placeholder="Nom client..."></td>';
print '<td></td>';
print '<td><input type="date" class="fx-input" name="search_date" value="'.dol_escape_htmltag($search_date).'" title="Factures à partir du"></td>';
print '<td></td><td></td><td></td>';
print '<td class="center">';
print '<button type="submit" class="fx-btn fx-btn-primary" title="Filtrer">🔍</button> ';
print '<button type="submit" class="fx-btn fx-btn-secondary" name="button_removefilter" value="1" title="Réinitialiser">🧹</button>';
print '</td>';
print '</tr></thead><tbody>';

if (empty($rows)) {
    print '<tr><td colspan="7" class="fx-empty"><div class="fx-empty-icon">✅</div>Rien à livrer</td></tr>';
}

$cur = null;
foreach ($rows as $i => $o) {
    $url = DOL_URL_ROOT.'/compta/facture/card.php?facid='.(int) $o->fid;
    print '<tr>';
    print '<td>'.($cur !== $o->socid ? '<strong>'.dol_escape_htmltag($o->client).'</strong>' : '').'</td>';
    print '<td><a href="'.$url.'">'.dol_escape_htmltag($o->facture).'</a></td>';
    print '<td>'.dol_print_date($db->jdate($o->datef), 'day').'</td>';
    print '<td>'.dol_escape_htmltag($o->produit).'</td>';
    print '<td class="center">'.price2num($o->commande, 'MS').'</td>';
    print '<td class="center">'.price2num($o->livre, 'MS').'</td>';
    print '<td class="center"><strong>'.price2num($o->reste, 'MS').'</strong></td>';
    print '</tr>';

    $cur = $o->socid;
    $next = isset($rows[$i + 1]) ? $rows[$i + 1]->socid : null;
    if ($next !== $cur) {
        print '<tr style="background:#f3f4f6"><td colspan="6" style="text-align:right">Total '.dol_escape_htmltag($o->client).'</td>';
        print '<td class="center"><strong>'.price2num($parClient[$o->socid], 'MS').'</strong></td></tr>';
    }
}

if (!empty($rows)) {
    print '<tr style="background:#e5e7eb"><td colspan="6" style="text-align:right"><strong>TOTAL GÉNÉRAL</strong></td>';
    print '<td class="center"><strong>'.price2num($totalGeneral, 'MS').'</strong></td></tr>';
}
print '</tbody></table></form><br>';

// --- Total par produit vs stock ---
print '<h3>Total à livrer par produit</h3>';
print '<table class="fx-table"><thead><tr><th>Produit</th><th class="center">Reste à livrer</th>'
    .'<th class="center">Stock total</th><th class="center">Écart</th></tr></thead><tbody>';

uasort($parProduit, function ($a, $b) { return $b['reste'] <=> $a['reste']; });
foreach ($parProduit as $pid => $pp) {
    $stk = isset($stocks[$pid]) ? $stocks[$pid] : 0;
    $ecart = $stk - $pp['reste'];
    print '<tr><td>'.dol_escape_htmltag($pp['ref']).'</td>';
    print '<td class="center">'.price2num($pp['reste'], 'MS').'</td>';
    print '<td class="center">'.price2num($stk, 'MS').'</td>';
    print '<td class="center"'.($ecart < 0 ? ' style="color:#c00;font-weight:bold"' : '').'>'.price2num($ecart, 'MS').'</td></tr>';
}
print '</tbody></table>';

llxFooter();
$db->close();