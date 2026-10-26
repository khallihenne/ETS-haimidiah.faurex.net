<?php
/**
 * Fiche expédition
 */

$res = 0;
$sep = DIRECTORY_SEPARATOR;
for ($i = 0; $i < 5; $i++) {
    $dir = str_repeat('..'.$sep, $i);
    if (@include_once $dir.'main.inc.php') { $res = 1; break; }
}
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/custom/faurex_magasin/class/faurexshipment.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

$langs->loadLangs(array("faurex_magasin@faurex_magasin", "bills", "products", "companies"));

if (empty($user->rights->faurex_magasin->shipment->read)) accessforbidden();

$id = GETPOST('id', 'int');
$action = GETPOST('action', 'aZ09');
$lineid = GETPOST('lineid', 'int');
$editid = GETPOST('editid', 'int');

$ship = new FaurexShipment($db);
if ($ship->fetch($id) <= 0) accessforbidden('Expédition introuvable');

$facture = new Facture($db);
$facture->fetch($ship->fk_facture);
$soc = new Societe($db);
$soc->fetch($ship->fk_soc);

if ($action === 'updateplanned' && !empty($user->rights->faurex_magasin->shipment->write)) {
    $dp = dol_mktime(0, 0, 0, GETPOST('plannedmonth', 'int'), GETPOST('plannedday', 'int'), GETPOST('plannedyear', 'int'));
    $ship->updatePlannedDate($dp);
    setEventMessages('Date prévue mise à jour', null, 'mesgs');
    header("Location: ".$_SERVER['PHP_SELF'].'?id='.$id);
    exit;
}

if ($action === 'savelines' && !empty($user->rights->faurex_magasin->shipment->write)) {
    if ($ship->status == FaurexShipment::STATUS_DELIVERED) {
        setEventMessages('Expédition déjà livrée intégralement', null, 'errors');
    } else {
        $dship = dol_mktime(0, 0, 0, GETPOST('shipmonth', 'int'), GETPOST('shipday', 'int'), GETPOST('shipyear', 'int'));
        $fk_warehouse = GETPOST('fk_warehouse', 'int');
        if (empty($fk_warehouse)) {
            setEventMessages('Vous devez sélectionner un entrepôt', null, 'errors');
            header("Location: ".$_SERVER['PHP_SELF'].'?id='.$id);
            exit;
        }
        $qtys = array();
        foreach ($_POST as $k => $v) {
            if (strpos($k, 'qty_') === 0) {
                $qtys[(int)substr($k, 4)] = $v;
            }
        }
        if (isset($_POST['qty']) && is_array($_POST['qty'])) {
            foreach ($_POST['qty'] as $kk => $vv) $qtys[(int)$kk] = $vv;
        }
        $nbAdded = 0; $errors = array();
        if (is_array($qtys)) {
            foreach ($qtys as $fk_fdet => $q) {
                $q = (float)str_replace(',', '.', $q);
                if ($q <= 0) continue;
                $fl = null;
                foreach ($facture->lines as $line) { if ($line->id == (int)$fk_fdet) { $fl = $line; break; } }
                if (!$fl) continue;
                $r = $ship->addLine($fl->id, $fl->fk_product, $fl->qty, $q, $dship, '', '', $user, $fk_warehouse);
                if ($r > 0) $nbAdded++;
                elseif ($r == -4) $errors[] = 'Quantité supérieure au reste pour le produit '.$fl->fk_product;
                elseif ($r == -3) $errors[] = 'Quantité invalide pour produit '.$fl->fk_product;
            }
        }
        if ($nbAdded > 0) setEventMessages($nbAdded.' ligne(s) ajoutée(s)', null, 'mesgs');
        if (!empty($errors)) setEventMessages(null, $errors, 'errors');
        if ($nbAdded == 0 && empty($errors)) setEventMessages('Aucune quantité saisie', null, 'warnings');
    }
    header("Location: ".$_SERVER['PHP_SELF'].'?id='.$id);
    exit;
}

if ($action === 'cancelall' && !empty($user->rights->faurex_magasin->shipment->write)) {
    $ship->cancelAll($user);
    setEventMessages('Expédition annulée, stock restauré', null, 'mesgs');
    header("Location: ".$_SERVER['PHP_SELF'].'?id='.$id);
    exit;
}

if ($action === 'deleteline' && $lineid > 0 && !empty($user->rights->faurex_magasin->shipment->write)) {
    $ship->deleteLine($lineid, $user);
    setEventMessages('Ligne supprimée', null, 'mesgs');
    header("Location: ".$_SERVER['PHP_SELF'].'?id='.$id);
    exit;
}

if ($action === 'updateline' && $lineid > 0 && !empty($user->rights->faurex_magasin->shipment->write)) {
    $newq = (float)str_replace(',', '.', GETPOST('newqty', 'alpha'));
    $r = $ship->updateLineQty($lineid, $newq, $user);
    if ($r > 0) setEventMessages('Ligne modifiée', null, 'mesgs');
    elseif ($r == -4) setEventMessages('Quantité supérieure au reste autorisé', null, 'errors');
    elseif ($r == -3) setEventMessages('Quantité invalide', null, 'errors');
    else setEventMessages('Erreur', null, 'errors');
    header("Location: ".$_SERVER['PHP_SELF'].'?id='.$id);
    exit;
}

require_once DOL_DOCUMENT_ROOT.'/custom/faurex_magasin/lib/faurex_ui.lib.php';
llxHeader(faurex_css_head(), 'Expédition '.$ship->ref);

$form = new Form($db);

$statusType = $ship->status == 2 ? 'done' : ($ship->status == 1 ? 'partial' : 'validated');
$statusLbl = FaurexShipment::getStatusLabel($ship->status);
$headerRight = '<a class="fx-btn fx-btn-secondary" href="shipment_list.php">← Retour</a>';
if ($ship->status != FaurexShipment::STATUS_VALIDATED && !empty($user->rights->faurex_magasin->shipment->write)) {
    $headerRight .= ' <a class="fx-btn fx-btn-danger" href="?id='.$id.'&action=cancelall&token='.newToken().'" onclick="return confirm(\'Annuler toute l\\\'expédition ? Les quantités livrées seront supprimées et le stock restauré.\');">Annuler l\'expédition</a>';
}
print faurex_page_header('Expédition '.$ship->ref, '🚚', 'Facture '.$facture->ref.' — '.$soc->nom, $headerRight);

print '<div class="fx-card">';
print '<div class="fx-card-header">Informations générales</div>';
print '<div class="fx-card-body">';
print '<div class="fx-info-grid">';
print faurex_info_item('Référence', dol_escape_htmltag($ship->ref));
print faurex_info_item('Facture client', '<a href="'.DOL_URL_ROOT.'/compta/facture/card.php?facid='.(int)$facture->id.'">'.dol_escape_htmltag($facture->ref).'</a>');
print faurex_info_item('Client', dol_escape_htmltag($soc->nom));
print faurex_info_item('Date facturation', dol_print_date($db->jdate($ship->date_facture), 'day'));
print faurex_info_item('État', faurex_status_badge($statusLbl, $statusType));
print '</div>';
print '<br><form method="POST" style="display:flex;gap:10px;align-items:center;">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="updateplanned">';
print '<label style="font-weight:600;color:#374151;">Date prévue d\'expédition :</label>';
print $form->selectDate($ship->date_planned ? $db->jdate($ship->date_planned) : '', 'planned', 0, 0, 0, '', 1, 0);
print '<button class="fx-btn" type="submit">Enregistrer</button>';
print '</form>';
print '</div></div>';

$canAdd = ($ship->status != FaurexShipment::STATUS_DELIVERED) && !empty($user->rights->faurex_magasin->shipment->write);

// Pré-charger remaining + produits en 2 requêtes au lieu de N
$deliveredByLine = $ship->getDeliveredByLine();
$prodCache = array();
$prodIds = array();
foreach ($facture->lines as $fl) if ($fl->fk_product) $prodIds[] = (int)$fl->fk_product;
foreach ($ship->lines as $ll) if ($ll->fk_product) $prodIds[] = (int)$ll->fk_product;
$prodIds = array_unique($prodIds);
if (!empty($prodIds)) {
    $sqlp = "SELECT rowid, ref, label FROM ".MAIN_DB_PREFIX."product WHERE rowid IN (".implode(',', $prodIds).")";
    $rp = $db->query($sqlp);
    if ($rp) while ($op = $db->fetch_object($rp)) $prodCache[(int)$op->rowid] = $op;
}

print '<div class="fx-card">';
print '<div class="fx-card-header">📦 Produits de la facture</div>';
print '<div class="fx-card-body">';

if ($canAdd) {
    print '<form method="POST" id="savelinesform" action="'.$_SERVER['PHP_SELF'].'?id='.$id.'">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="savelines">';
    print '</form>';
}

print '<table class="fx-table">';
print '<thead><tr>';
print '<th>Produit</th><th class="right">Qté facturée</th><th class="right">Qté livrée</th><th class="right">Reste</th>';
if ($canAdd) print '<th class="right">Qté à livrer</th>';
print '</tr></thead><tbody>';

foreach ($facture->lines as $fl) {
    if (empty($fl->fk_product)) continue;
    $prod = isset($prodCache[(int)$fl->fk_product]) ? $prodCache[(int)$fl->fk_product] : (object)array('ref'=>'?', 'label'=>'');
    $delivered = isset($deliveredByLine[(int)$fl->id]) ? $deliveredByLine[(int)$fl->id] : 0;
    $remaining = (float)$fl->qty - $delivered;
    print '<tr>';
    print '<td><strong>'.dol_escape_htmltag($prod->ref).'</strong><br><span style="color:#6b7280;font-size:13px;">'.dol_escape_htmltag($prod->label).'</span></td>';
    print '<td class="right">'.$fl->qty.'</td>';
    print '<td class="right">'.$delivered.'</td>';
    print '<td class="right"><strong style="color:'.($remaining > 0 ? '#f59e0b' : '#10b981').';">'.$remaining.'</strong></td>';
    if ($canAdd) {
        print '<td class="right">';
        if ($remaining > 0) print '<input class="fx-input" form="savelinesform" type="text" name="qty_'.(int)$fl->id.'" style="width:80px;text-align:right;" placeholder="0">';
        else print '<span style="color:#10b981;font-weight:600;">✓ Complet</span>';
        print '</td>';
    }
    print '</tr>';
}
print '</tbody></table>';

if ($canAdd) {
    require_once DOL_DOCUMENT_ROOT.'/product/class/html.formproduct.class.php';
    $formproduct = new FormProduct($db);
    print '<br><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;">';

    print '<div class="fx-info-item"><div class="fx-label">Date d\'expédition</div><div class="fx-value">';
    ob_start(); $ret = $form->selectDate('', 'ship', 0, 0, 0, '', 1, 0); $echoed = ob_get_clean();
    $dateHtml = $ret ? $ret : $echoed;
    $dateHtml = preg_replace('/<input([^>]*name="ship[a-z]+")/i', '<input form="savelinesform"$1', $dateHtml);
    $dateHtml = preg_replace('/<select([^>]*name="ship[a-z]+")/i', '<select form="savelinesform"$1', $dateHtml);
    print $dateHtml;
    print '</div></div>';

    print '<div class="fx-info-item"><div class="fx-label">Entrepôt (sortie de stock) <span style="color:#ef4444;">*</span></div><div class="fx-value">';
    $whHtml = $formproduct->selectWarehouses('', 'fk_warehouse', '', 1);
    $whHtml = preg_replace('/<select/i', '<select form="savelinesform" required', $whHtml);
    print $whHtml;
    print '</div></div>';
    print '</div>';
    print '<div style="font-size:12px;color:#6b7280;margin-top:8px;"><span style="color:#ef4444;">*</span> Entrepôt obligatoire pour le mouvement de stock</div>';

    print '<br><div style="text-align:center;"><button form="savelinesform" class="fx-btn fx-btn-success" type="submit" onclick="return faurexValidate(this)">➕ Ajouter les lignes</button></div>';
    print '<script>
function faurexValidate(btn){
    var f = document.getElementById("savelinesform");
    var wh = document.querySelector("[name=fk_warehouse]");
    if (!wh || !wh.value || wh.value == "-1" || wh.value == "0") {
        alert("⚠ Vous devez sélectionner un entrepôt avant d\'ajouter des lignes.");
        return false;
    }
    var hasQty = false;
    document.querySelectorAll("input[name^=qty_]").forEach(function(i){
        if (parseFloat(i.value) > 0) hasQty = true;
    });
    if (!hasQty) {
        alert("⚠ Vous devez saisir au moins une quantité à livrer.");
        return false;
    }
    return true;
}
</script>';
}
print '</div></div>';

print '<div class="fx-card">';
print '<div class="fx-card-header">📋 Lignes d\'expédition enregistrées</div>';
print '<div class="fx-card-body">';
print '<table class="fx-table">';
print '<thead><tr><th>Produit</th><th class="right">Qté facturée</th><th class="right">Qté livrée</th><th>Date</th><th class="center">Action</th></tr></thead><tbody>';
$nbLines = 0;
foreach ($ship->lines as $line) {
    $nbLines++;
    $prod = isset($prodCache[(int)$line->fk_product]) ? $prodCache[(int)$line->fk_product] : (object)array('ref'=>'?', 'label'=>'');
    $isEditing = ($editid == $line->rowid);
    print '<tr>';
    print '<td><strong>'.dol_escape_htmltag($prod->ref).'</strong><br><span style="color:#6b7280;font-size:13px;">'.dol_escape_htmltag($prod->label).'</span></td>';
    print '<td class="right">'.$line->qty_invoiced.'</td>';
    if ($isEditing && $canAdd) {
        print '<td class="right">';
        print '<form method="POST" style="display:inline-flex;gap:6px;">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="action" value="updateline">';
        print '<input type="hidden" name="lineid" value="'.(int)$line->rowid.'">';
        print '<input class="fx-input" type="text" name="newqty" style="width:70px;text-align:right;" value="'.$line->qty_delivered.'">';
        print '<button class="fx-action success" type="submit" title="Enregistrer">✓</button>';
        print '<a class="fx-action" href="?id='.$id.'">✕</a>';
        print '</form></td>';
        print '<td>'.dol_print_date($db->jdate($line->date_shipment), 'day').'</td>';
        print '<td class="center">—</td>';
    } else {
        print '<td class="right"><strong>'.$line->qty_delivered.'</strong></td>';
        print '<td>'.dol_print_date($db->jdate($line->date_shipment), 'day').'</td>';
        print '<td class="center">';
        if ($canAdd) {
            print '<a class="fx-action" href="?id='.$id.'&editid='.(int)$line->rowid.'" title="Modifier">✏️</a>';
            print '<a class="fx-action danger" href="?id='.$id.'&action=deleteline&lineid='.(int)$line->rowid.'&token='.newToken().'" onclick="return confirm(\'Supprimer cette ligne ?\');" title="Supprimer">🗑</a>';
        } else print '—';
        print '</td>';
    }
    print '</tr>';
}
if ($nbLines == 0) print '<tr><td colspan="5" class="fx-empty"><div class="fx-empty-icon">📭</div>Aucune ligne enregistrée</td></tr>';
print '</tbody></table>';
print '</div></div>';

llxFooter();
$db->close();
