<?php
/**
 * Fiche réception
 */

$res = 0;
$sep = DIRECTORY_SEPARATOR;
for ($i = 0; $i < 5; $i++) {
    $dir = str_repeat('..'.$sep, $i);
    if (@include_once $dir.'main.inc.php') { $res = 1; break; }
}
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/custom/faurex_magasin/class/faurexreception.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

$langs->loadLangs(array("faurex_magasin@faurex_magasin", "bills", "products", "companies"));

if (empty($user->rights->faurex_magasin->reception->read)) accessforbidden();

$id = GETPOST('id', 'int');
$action = GETPOST('action', 'aZ09');
$lineid = GETPOST('lineid', 'int');
$editid = GETPOST('editid', 'int');

$rec = new FaurexReception($db);
if ($rec->fetch($id) <= 0) accessforbidden('Réception introuvable');

$facture = new FactureFournisseur($db);
$facture->fetch($rec->fk_facture_fourn);
$soc = new Societe($db);
$soc->fetch($rec->fk_soc);

if ($action === 'updateplanned' && !empty($user->rights->faurex_magasin->reception->write)) {
    $dp = dol_mktime(0, 0, 0, GETPOST('plannedmonth', 'int'), GETPOST('plannedday', 'int'), GETPOST('plannedyear', 'int'));
    $rec->updatePlannedDate($dp);
    setEventMessages('Date prévue mise à jour', null, 'mesgs');
    header("Location: ".$_SERVER['PHP_SELF'].'?id='.$id);
    exit;
}

if ($action === 'savelines' && !empty($user->rights->faurex_magasin->reception->write)) {
    if ($rec->status == FaurexReception::STATUS_RECEIVED) {
        setEventMessages('Réception déjà complète', null, 'errors');
    } else {
        $drec = dol_mktime(0, 0, 0, GETPOST('recmonth', 'int'), GETPOST('recday', 'int'), GETPOST('recyear', 'int'));
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
                $r = $rec->addLine($fl->id, $fl->fk_product, $fl->qty, $q, $drec, '', '', $user, $fk_warehouse);
                if ($r > 0) $nbAdded++;
                elseif ($r == -4) $errors[] = 'Quantité supérieure au reste pour produit '.$fl->fk_product;
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

if ($action === 'cancelall' && !empty($user->rights->faurex_magasin->reception->write)) {
    $rec->cancelAll($user);
    setEventMessages('Réception annulée, stock restauré', null, 'mesgs');
    header("Location: ".$_SERVER['PHP_SELF'].'?id='.$id);
    exit;
}

if ($action === 'deleteline' && $lineid > 0 && !empty($user->rights->faurex_magasin->reception->write)) {
    $rec->deleteLine($lineid, $user);
    setEventMessages('Ligne supprimée', null, 'mesgs');
    header("Location: ".$_SERVER['PHP_SELF'].'?id='.$id);
    exit;
}

if ($action === 'updateline' && $lineid > 0 && !empty($user->rights->faurex_magasin->reception->write)) {
    $newq = (float)str_replace(',', '.', GETPOST('newqty', 'alpha'));
    $r = $rec->updateLineQty($lineid, $newq, $user);
    if ($r > 0) setEventMessages('Ligne modifiée', null, 'mesgs');
    elseif ($r == -4) setEventMessages('Quantité supérieure au reste autorisé', null, 'errors');
    elseif ($r == -3) setEventMessages('Quantité invalide', null, 'errors');
    else setEventMessages('Erreur', null, 'errors');
    header("Location: ".$_SERVER['PHP_SELF'].'?id='.$id);
    exit;
}

require_once DOL_DOCUMENT_ROOT.'/custom/faurex_magasin/lib/faurex_ui.lib.php';
llxHeader(faurex_css_head(), 'Réception '.$rec->ref);

$form = new Form($db);

$statusType = $rec->status == 2 ? 'done' : ($rec->status == 1 ? 'partial' : 'validated');
$statusLbl = FaurexReception::getStatusLabel($rec->status);
$headerRight = '<a class="fx-btn fx-btn-secondary" href="reception_list.php">← Retour</a>';
if ($rec->status != FaurexReception::STATUS_VALIDATED && !empty($user->rights->faurex_magasin->reception->write)) {
    $headerRight .= ' <a class="fx-btn fx-btn-danger" href="?id='.$id.'&action=cancelall&token='.newToken().'" onclick="return confirm(\'Annuler toute la réception ? Les quantités reçues seront supprimées et le stock corrigé.\');">Annuler la réception</a>';
}
print faurex_page_header('Réception '.$rec->ref, '📦', 'Facture '.$facture->ref.' — '.$soc->nom, $headerRight);

print '<div class="fx-card">';
print '<div class="fx-card-header">Informations générales</div>';
print '<div class="fx-card-body">';
print '<div class="fx-info-grid">';
print faurex_info_item('Référence', dol_escape_htmltag($rec->ref));
print faurex_info_item('Facture fournisseur', '<a href="'.DOL_URL_ROOT.'/fourn/facture/card.php?id='.(int)$facture->id.'">'.dol_escape_htmltag($facture->ref).'</a>');
print faurex_info_item('Fournisseur', dol_escape_htmltag($soc->nom));
print faurex_info_item('Date facturation', dol_print_date($db->jdate($rec->date_facture), 'day'));
print faurex_info_item('État', faurex_status_badge($statusLbl, $statusType));
print '</div>';
print '<br><form method="POST" style="display:flex;gap:10px;align-items:center;">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="updateplanned">';
print '<label style="font-weight:600;color:#374151;">Date prévue de réception :</label>';
print $form->selectDate($rec->date_planned ? $db->jdate($rec->date_planned) : '', 'planned', 0, 0, 0, '', 1, 0);
print '<button class="fx-btn" type="submit">Enregistrer</button>';
print '</form>';
print '</div></div>';

$canAdd = ($rec->status != FaurexReception::STATUS_RECEIVED) && !empty($user->rights->faurex_magasin->reception->write);

$receivedByLine = $rec->getReceivedByLine();
$prodCache = array();
$prodIds = array();
foreach ($facture->lines as $fl) if ($fl->fk_product) $prodIds[] = (int)$fl->fk_product;
foreach ($rec->lines as $ll) if ($ll->fk_product) $prodIds[] = (int)$ll->fk_product;
$prodIds = array_unique($prodIds);
if (!empty($prodIds)) {
    $sqlp = "SELECT rowid, ref, label FROM ".MAIN_DB_PREFIX."product WHERE rowid IN (".implode(',', $prodIds).")";
    $rp = $db->query($sqlp);
    if ($rp) while ($op = $db->fetch_object($rp)) $prodCache[(int)$op->rowid] = $op;
}

print '<div class="fx-card">';
print '<div class="fx-card-header">📦 Produits de la facture fournisseur</div>';
print '<div class="fx-card-body">';

if ($canAdd) {
    print '<form method="POST" id="savelinesform" action="'.$_SERVER['PHP_SELF'].'?id='.$id.'">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="savelines">';
    print '</form>';
}

print '<table class="fx-table">';
print '<thead><tr>';
print '<th>Produit</th><th class="right">Qté facturée</th><th class="right">Qté reçue</th><th class="right">Reste</th>';
if ($canAdd) print '<th class="right">Qté à recevoir</th>';
print '</tr></thead><tbody>';

foreach ($facture->lines as $fl) {
    if (empty($fl->fk_product)) continue;
    $prod = isset($prodCache[(int)$fl->fk_product]) ? $prodCache[(int)$fl->fk_product] : (object)array('ref'=>'?', 'label'=>'');
    $received = isset($receivedByLine[(int)$fl->id]) ? $receivedByLine[(int)$fl->id] : 0;
    $remaining = (float)$fl->qty - $received;
    print '<tr>';
    print '<td><strong>'.dol_escape_htmltag($prod->ref).'</strong><br><span style="color:#6b7280;font-size:13px;">'.dol_escape_htmltag($prod->label).'</span></td>';
    print '<td class="right">'.$fl->qty.'</td>';
    print '<td class="right">'.$received.'</td>';
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
    print '<div class="fx-info-item"><div class="fx-label">Date de réception</div><div class="fx-value">';
    ob_start(); $ret = $form->selectDate('', 'rec', 0, 0, 0, '', 1, 0); $echoed = ob_get_clean();
    $dateHtml = $ret ? $ret : $echoed;
    $dateHtml = preg_replace('/<input([^>]*name="rec[a-z]+")/i', '<input form="savelinesform"$1', $dateHtml);
    $dateHtml = preg_replace('/<select([^>]*name="rec[a-z]+")/i', '<select form="savelinesform"$1', $dateHtml);
    print $dateHtml;
    print '</div></div>';
    print '<div class="fx-info-item"><div class="fx-label">Entrepôt (entrée de stock) <span style="color:#ef4444;">*</span></div><div class="fx-value">';
    $whHtml = $formproduct->selectWarehouses('', 'fk_warehouse', '', 1);
    $whHtml = preg_replace('/<select/i', '<select form="savelinesform" required', $whHtml);
    print $whHtml;
    print '</div></div>';
    print '</div>';
    print '<div style="font-size:12px;color:#6b7280;margin-top:8px;"><span style="color:#ef4444;">*</span> Entrepôt obligatoire pour le mouvement de stock</div>';
    print '<br><div style="text-align:center;"><button form="savelinesform" class="fx-btn fx-btn-success" type="submit" onclick="return faurexValidate(this)">➕ Ajouter les lignes</button></div>';
    print '<script>
function faurexValidate(btn){
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
        alert("⚠ Vous devez saisir au moins une quantité à recevoir.");
        return false;
    }
    return true;
}
</script>';
}
print '</div></div>';

print '<div class="fx-card">';
print '<div class="fx-card-header">📋 Lignes de réception enregistrées</div>';
print '<div class="fx-card-body">';
print '<table class="fx-table">';
print '<thead><tr><th>Produit</th><th class="right">Qté facturée</th><th class="right">Qté reçue</th><th>Date</th><th class="center">Action</th></tr></thead><tbody>';
$nbLines = 0;
foreach ($rec->lines as $line) {
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
        print '<input class="fx-input" type="text" name="newqty" style="width:70px;text-align:right;" value="'.$line->qty_received.'">';
        print '<button class="fx-action success" type="submit" title="Enregistrer">✓</button>';
        print '<a class="fx-action" href="?id='.$id.'">✕</a>';
        print '</form></td>';
        print '<td>'.dol_print_date($db->jdate($line->date_reception), 'day').'</td>';
        print '<td class="center">—</td>';
    } else {
        print '<td class="right"><strong>'.$line->qty_received.'</strong></td>';
        print '<td>'.dol_print_date($db->jdate($line->date_reception), 'day').'</td>';
        print '<td class="center">';
        if ($canAdd) {
            print '<a class="fx-action" href="?id='.$id.'&editid='.(int)$line->rowid.'" title="Modifier">✏️</a>';
            print '<a class="fx-action danger" href="?id='.$id.'&action=deleteline&lineid='.(int)$line->rowid.'&token='.newToken().'" onclick="return confirm(\'Supprimer cette ligne ?\');" title="Supprimer">🗑</a>';
        } else print '—';
        print '</td>';
    }
    print '</tr>';
}
if ($nbLines == 0) print '<tr><td colspan="5" class="fx-empty"><div class="fx-empty-icon">📥</div>Aucune ligne enregistrée</td></tr>';
print '</tbody></table>';
print '</div></div>';

llxFooter();
$db->close();
