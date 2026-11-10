<?php
/**
 * Clients non livrés : quantités facturées restant à livrer
 *  - vue "Résumé" : un client par ligne (voir tous les clients non livrés)
 *  - vue "Détail" : par client / facture / produit
 *
 * Calcul : reste = quantité facturée - quantité déjà livrée (lignes d'expédition),
 * sur les factures clients standard validées ou payées, produits uniquement.
 * C'est le même calcul que la page "Stock propriétaire".
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

// Crée les expéditions manquantes (anciennes factures) pour que le lien "Livrer" fonctionne
FaurexShipment::syncFromValidatedInvoices($db, $user);

// --- Paramètres / filtres ---
$mode          = GETPOST('mode', 'aZ09') === 'detail' ? 'detail' : 'resume';
$search_client = trim(GETPOST('search_client', 'alphanohtml'));
$search_socid  = GETPOSTINT('socid');
$search_date   = GETPOST('search_date', 'alpha'); // YYYY-MM-DD
if (GETPOST('button_removefilter', 'alpha')) { $search_client = ''; $search_date = ''; $search_socid = 0; }

// Clients exclus (clients de test). Modifiable dans Configuration > Divers :
// constante FAUREX_MAGASIN_CLIENTS_EXCLUS = noms séparés par des virgules (vide = aucun exclu)
$exclus = array_filter(array_map('trim', explode(',', getDolGlobalString('FAUREX_MAGASIN_CLIENTS_EXCLUS', 'Client test,khallihenne,Mehasen'))));

$where = "";
if (!empty($exclus)) {
    $where .= " AND soc.nom NOT IN ('".implode("','", array_map(array($db, 'escape'), $exclus))."')";
}
if ($search_socid > 0) {
    $where .= " AND soc.rowid = ".((int) $search_socid);
}
if ($search_client !== '') {
    $where .= " AND soc.nom LIKE '%".$db->escape($search_client)."%'";
}
if ($search_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $search_date)) {
    $where .= " AND f.datef >= '".$db->escape($search_date)."'";
}

// --- Requête : reste à livrer par facture et produit ---
$sql = "SELECT soc.rowid AS socid, soc.nom AS client, soc.town,"
    ." f.rowid AS fid, f.ref AS facture, f.datef, sh.shipid,"
    ." p.rowid AS pid, p.ref AS produit, p.label AS plabel,"
    ." SUM(fd.qty) AS commande, SUM(COALESCE(d.livre, 0)) AS livre,"
    ." SUM(fd.qty - COALESCE(d.livre, 0)) AS reste"
    ." FROM ".MAIN_DB_PREFIX."facture f"
    ." INNER JOIN ".MAIN_DB_PREFIX."facturedet fd ON fd.fk_facture = f.rowid AND fd.product_type = 0 AND fd.fk_product > 0"
    ." INNER JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = fd.fk_product"
    ." INNER JOIN ".MAIN_DB_PREFIX."societe soc ON soc.rowid = f.fk_soc"
    ." LEFT JOIN (SELECT fk_facturedet, SUM(qty_delivered) AS livre"
    ."            FROM ".MAIN_DB_PREFIX."faurex_shipment_line GROUP BY fk_facturedet) d ON d.fk_facturedet = fd.rowid"
    ." LEFT JOIN (SELECT fk_facture, MIN(rowid) AS shipid"
    ."            FROM ".MAIN_DB_PREFIX."faurex_shipment GROUP BY fk_facture) sh ON sh.fk_facture = f.rowid"
    ." WHERE f.fk_statut IN (1, 2)"   // Factures validées ou payées (pas brouillon, pas abandonnées)
    ." AND f.type = 0"                // Factures standard (pas les avoirs ni les acomptes)
    ." AND f.entity IN (".getEntity('invoice').")"
    ." AND fd.qty - COALESCE(d.livre, 0) > 0"
    .$where
    ." GROUP BY soc.rowid, soc.nom, soc.town, f.rowid, f.ref, f.datef, sh.shipid, p.rowid, p.ref, p.label"
    ." ORDER BY soc.nom, soc.rowid, f.datef, f.ref, p.ref";

$rows = array();
$parClient = array();   // socid => [client, town, reste, factures[]]
$parProduit = array();
$totalGeneral = 0;

$resql = $db->query($sql);
if ($resql) {
    while ($o = $db->fetch_object($resql)) {
        $o->reste = (float) $o->reste;
        $rows[] = $o;
        if (!isset($parClient[$o->socid])) {
            $parClient[$o->socid] = array('client' => $o->client, 'town' => $o->town, 'reste' => 0, 'factures' => array());
        }
        $parClient[$o->socid]['reste'] += $o->reste;
        $parClient[$o->socid]['factures'][$o->fid] = 1;
        if (!isset($parProduit[$o->pid])) $parProduit[$o->pid] = array('ref' => $o->produit, 'label' => $o->plabel, 'reste' => 0);
        $parProduit[$o->pid]['reste'] += $o->reste;
        $totalGeneral += $o->reste;
    }
} else {
    setEventMessages($db->lasterror(), null, 'errors');
}

$nbFactures = 0;
foreach ($parClient as $c) $nbFactures += count($c['factures']);

// --- Stock total par produit (tous entrepôts) ---
$stocks = array();
$rs = $db->query("SELECT fk_product, SUM(reel) AS s FROM ".MAIN_DB_PREFIX."product_stock GROUP BY fk_product");
if ($rs) {
    while ($o = $db->fetch_object($rs)) {
        $stocks[$o->fk_product] = (float) $o->s;
    }
}

$hasStockProprio = isModEnabled('stockproprietaire') && $user->hasRight('stockproprietaire', 'read');

// Paramètres à conserver dans les liens
$keep = '&search_client='.urlencode($search_client).'&search_date='.urlencode($search_date);

// --- Affichage ---
llxHeader(faurex_css_head(), 'Clients non livrés');

print faurex_page_header('Clients non livrés', '📦', 'Quantités facturées restant à livrer, par client');

print '<div class="fx-stats">';
print faurex_stat_card('Total à livrer', price2num($totalGeneral, 'MS'), 'warning');
print faurex_stat_card('Clients', count($parClient), 'info');
print faurex_stat_card('Factures', $nbFactures, '');
print faurex_stat_card('Lignes', count($rows), '');
print '</div>';

// Onglets Résumé / Détail
print '<div style="margin:10px 0;">';
print '<a class="fx-btn'.($mode == 'resume' ? '' : ' fx-btn-secondary').'" href="'.$_SERVER['PHP_SELF'].'?mode=resume'.$keep.'">👥 Voir tous les clients (résumé)</a> ';
print '<a class="fx-btn'.($mode == 'detail' ? '' : ' fx-btn-secondary').'" href="'.$_SERVER['PHP_SELF'].'?mode=detail'.$keep.($search_socid > 0 ? '&socid='.$search_socid : '').'">📋 Détail par facture</a>';
if ($search_socid > 0) {
    print ' <a class="paddingleft" href="'.$_SERVER['PHP_SELF'].'?mode='.$mode.$keep.'">Voir tous les clients</a>';
}
print '</div>';

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="mode" value="'.$mode.'">';
if ($search_socid > 0) print '<input type="hidden" name="socid" value="'.((int) $search_socid).'">';

if ($mode == 'resume') {
    // ===== Vue résumé : un client par ligne =====
    print '<table class="fx-table"><thead>';
    print '<tr><th>Client</th><th>Ville</th><th class="center">Factures</th><th class="center">Reste à livrer</th><th class="center">Action</th></tr>';
    print '<tr class="liste_titre_filter">';
    print '<td><input type="text" class="fx-input" name="search_client" value="'.dol_escape_htmltag($search_client).'" placeholder="Nom client..."></td>';
    print '<td><input type="date" class="fx-input" name="search_date" value="'.dol_escape_htmltag($search_date).'" title="Factures à partir du"></td>';
    print '<td></td><td></td>';
    print '<td class="center">';
    print '<button type="submit" class="fx-btn fx-btn-primary" title="Filtrer">🔍</button> ';
    print '<button type="submit" class="fx-btn fx-btn-secondary" name="button_removefilter" value="1" title="Réinitialiser">🧹</button>';
    print '</td></tr></thead><tbody>';

    if (empty($parClient)) {
        print '<tr><td colspan="5" class="fx-empty"><div class="fx-empty-icon">✅</div>Rien à livrer</td></tr>';
    }
    foreach ($parClient as $socid => $c) {
        print '<tr>';
        print '<td><strong>'.dol_escape_htmltag($c['client']).'</strong></td>';
        print '<td>'.dol_escape_htmltag($c['town']).'</td>';
        print '<td class="center">'.count($c['factures']).'</td>';
        print '<td class="center"><strong>'.price2num($c['reste'], 'MS').'</strong></td>';
        print '<td class="center nowraponall">';
        print '<a class="fx-action" href="'.$_SERVER['PHP_SELF'].'?mode=detail&socid='.((int) $socid).$keep.'" title="Détail">📋</a>';
        if ($hasStockProprio) {
            print '<a class="fx-action" href="'.dol_buildpath('/stockproprietaire/stock.php', 1).'?socid='.((int) $socid).'" title="Livrer depuis la page Stock">🚚</a>';
        }
        print '</td>';
        print '</tr>';
    }
    if (!empty($parClient)) {
        print '<tr style="background:#e5e7eb"><td colspan="3" style="text-align:right"><strong>TOTAL GÉNÉRAL</strong></td>';
        print '<td class="center"><strong>'.price2num($totalGeneral, 'MS').'</strong></td><td></td></tr>';
    }
    print '</tbody></table>';
} else {
    // ===== Vue détail : client / facture / produit =====
    print '<table class="fx-table"><thead>';
    print '<tr><th>Client</th><th>Facture</th><th>Date</th><th>Produit</th>'
        .'<th class="center">Commandé</th><th class="center">Livré</th><th class="center">Reste</th><th class="center">Action</th></tr>';
    print '<tr class="liste_titre_filter">';
    print '<td><input type="text" class="fx-input" name="search_client" value="'.dol_escape_htmltag($search_client).'" placeholder="Nom client..."></td>';
    print '<td></td>';
    print '<td><input type="date" class="fx-input" name="search_date" value="'.dol_escape_htmltag($search_date).'" title="Factures à partir du"></td>';
    print '<td></td><td></td><td></td><td></td>';
    print '<td class="center">';
    print '<button type="submit" class="fx-btn fx-btn-primary" title="Filtrer">🔍</button> ';
    print '<button type="submit" class="fx-btn fx-btn-secondary" name="button_removefilter" value="1" title="Réinitialiser">🧹</button>';
    print '</td></tr></thead><tbody>';

    if (empty($rows)) {
        print '<tr><td colspan="8" class="fx-empty"><div class="fx-empty-icon">✅</div>Rien à livrer</td></tr>';
    }

    $cur = null;
    foreach ($rows as $i => $o) {
        $url = DOL_URL_ROOT.'/compta/facture/card.php?facid='.(int) $o->fid;
        print '<tr>';
        print '<td>'.($cur !== $o->socid ? '<strong>'.dol_escape_htmltag($o->client).'</strong>' : '').'</td>';
        print '<td><a href="'.$url.'">'.dol_escape_htmltag($o->facture).'</a></td>';
        print '<td>'.dol_print_date($db->jdate($o->datef), 'day').'</td>';
        print '<td><strong>'.dol_escape_htmltag($o->produit).'</strong> <span style="color:#6b7280;font-size:12px;">'.dol_escape_htmltag($o->plabel).'</span></td>';
        print '<td class="center">'.price2num($o->commande, 'MS').'</td>';
        print '<td class="center">'.price2num($o->livre, 'MS').'</td>';
        print '<td class="center"><strong>'.price2num($o->reste, 'MS').'</strong></td>';
        print '<td class="center">';
        if ($o->shipid) print '<a class="fx-action" href="shipment_card.php?id='.((int) $o->shipid).'" title="Ouvrir l\'expédition">🚚</a>';
        print '</td>';
        print '</tr>';

        $cur = $o->socid;
        $next = isset($rows[$i + 1]) ? $rows[$i + 1]->socid : null;
        if ($next !== $cur) {
            print '<tr style="background:#f3f4f6"><td colspan="6" style="text-align:right">Total '.dol_escape_htmltag($o->client).'</td>';
            print '<td class="center"><strong>'.price2num($parClient[$o->socid]['reste'], 'MS').'</strong></td><td></td></tr>';
        }
    }

    if (!empty($rows)) {
        print '<tr style="background:#e5e7eb"><td colspan="6" style="text-align:right"><strong>TOTAL GÉNÉRAL</strong></td>';
        print '<td class="center"><strong>'.price2num($totalGeneral, 'MS').'</strong></td><td></td></tr>';
    }
    print '</tbody></table>';
}
print '</form><br>';

// --- Total par produit vs stock ---
print '<h3>Total à livrer par produit</h3>';
print '<table class="fx-table"><thead><tr><th>Produit</th><th class="center">Reste à livrer</th>'
    .'<th class="center">Stock total</th><th class="center">Écart (stock propriétaire)</th></tr></thead><tbody>';

uasort($parProduit, function ($a, $b) {
    return $b['reste'] <=> $a['reste'];
});
foreach ($parProduit as $pid => $pp) {
    $stk = isset($stocks[$pid]) ? $stocks[$pid] : 0;
    $ecart = $stk - $pp['reste'];
    print '<tr><td><strong>'.dol_escape_htmltag($pp['ref']).'</strong> <span style="color:#6b7280;font-size:12px;">'.dol_escape_htmltag($pp['label']).'</span></td>';
    print '<td class="center">'.price2num($pp['reste'], 'MS').'</td>';
    print '<td class="center">'.price2num($stk, 'MS').'</td>';
    print '<td class="center"'.($ecart < 0 ? ' style="color:#c00;font-weight:bold"' : '').'>'.price2num($ecart, 'MS').'</td></tr>';
}
if (empty($parProduit)) {
    print '<tr><td colspan="4" class="fx-empty">—</td></tr>';
}
print '</tbody></table>';

llxFooter();
$db->close();