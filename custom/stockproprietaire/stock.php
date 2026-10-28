<?php
/* Copyright (C) 2026		Ets Haimidah
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    stockproprietaire/stock.php
 * \ingroup stockproprietaire
 * \brief   Page "Stock" : stock physique et stock propriétaire, filtrable par client
 */

// Chargement de l'environnement Dolibarr
$res = 0;
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/ajax.lib.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
dol_include_once('/stockproprietaire/lib/stockproprietaire.lib.php');

$langs->loadLangs(array('products', 'stocks', 'bills', 'companies'));

// Sécurité
if (!isModEnabled('stockproprietaire') || !$user->hasRight('stockproprietaire', 'read')) {
	accessforbidden();
}

// Filtre client
$socid = GETPOSTINT('socid');

$productstatic = new Product($db);


/*
 * Données
 */

// Tous les produits (pas les services)
$products = array();
$sql = "SELECT p.rowid, p.ref, p.label, p.stock";
$sql .= " FROM ".MAIN_DB_PREFIX."product AS p";
$sql .= " WHERE p.fk_product_type = 0";
$sql .= " AND p.entity IN (".getEntity('product').")";
$sql .= " ORDER BY p.ref";
$resql = $db->query($sql);
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$products[] = $obj;
	}
} else {
	dol_print_error($db);
}

$nonrecupAll    = stockproprietaire_get_non_recupere($db);
$nonrecupClient = ($socid > 0) ? stockproprietaire_get_non_recupere($db, 0, $socid) : array();
$clients        = stockproprietaire_get_clients_en_attente($db);


/*
 * Affichage
 */

llxHeader('', 'Stock', '', '', 0, 0, '', '', '', 'mod-stockproprietaire page-stock');

print load_fiche_titre('Stock physique et stock propriétaire', '', 'fa-warehouse');

// Formulaire de filtre : seulement les clients qui ont de la marchandise au magasin
print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
print '<div class="divsearchfield">';
print '<span class="paddingright">Client :</span>';
print '<select name="socid" id="socid" class="minwidth400">';
print '<option value="0">Tous les clients</option>';
foreach ($clients as $c) {
	$label = $c->nom.' (n°'.$c->rowid.')';
	if (!empty($c->town)) {
		$label .= ' - '.$c->town;
	}
	$label .= ' : '.stockproprietaire_qty($c->reste).' unités au magasin';
	$selected = ((int) $c->rowid === (int) $socid) ? ' selected' : '';
	print '<option value="'.((int) $c->rowid).'"'.$selected.'>'.dol_escape_htmltag($label).'</option>';
}
print '</select>';
print ajax_combobox('socid');
print ' <input type="submit" class="button small" value="Filtrer">';
if ($socid > 0) {
	print ' <a class="paddingleft" href="'.$_SERVER['PHP_SELF'].'">Voir tous les clients</a>';
}
print '</div>';
print '<div class="opacitymedium paddingtop">'.count($clients).' client(s) ont de la marchandise au magasin.</div>';
print '</form>';
print '<br>';

// Tableau principal
print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>Produit</th>';
print '<th class="right">Stock physique (magasin)</th>';
print '<th class="right">Vendu, non récupéré (tous clients)</th>';
if ($socid > 0) {
	print '<th class="right">Dont ce client</th>';
}
print '<th class="right">Stock propriétaire</th>';
print '</tr>';

$totPhysique = 0;
$totNonRecup = 0;
$totClient   = 0;
$totProprio  = 0;
$nbLignes    = 0;

foreach ($products as $obj) {
	$physique = (float) $obj->stock;
	$nonrecup = isset($nonrecupAll[$obj->rowid]) ? $nonrecupAll[$obj->rowid] : 0;
	$client   = isset($nonrecupClient[$obj->rowid]) ? $nonrecupClient[$obj->rowid] : 0;
	$proprio  = $physique - $nonrecup;

	// Avec un filtre client : seulement les produits que ce client a encore au magasin
	if ($socid > 0 && $client <= 0) {
		continue;
	}

	$productstatic->id    = $obj->rowid;
	$productstatic->ref   = $obj->ref;
	$productstatic->label = $obj->label;
	$productstatic->type  = 0;

	print '<tr class="oddeven">';
	print '<td>'.$productstatic->getNomUrl(1).' <span class="opacitymedium">'.dol_escape_htmltag($obj->label).'</span></td>';
	print '<td class="right">'.stockproprietaire_qty($physique).'</td>';
	print '<td class="right">'.stockproprietaire_qty($nonrecup).'</td>';
	if ($socid > 0) {
		print '<td class="right"><strong>'.stockproprietaire_qty($client).'</strong></td>';
	}
	// Stock propriétaire en rouge s'il est négatif (stock enregistré incohérent)
	$class = ($proprio < 0) ? ' error' : '';
	print '<td class="right'.$class.'"><strong>'.stockproprietaire_qty($proprio).'</strong></td>';
	print '</tr>';

	$totPhysique += $physique;
	$totNonRecup += $nonrecup;
	$totClient   += $client;
	$totProprio  += $proprio;
	$nbLignes++;
}

if ($nbLignes == 0) {
	$colspan = ($socid > 0) ? 5 : 4;
	print '<tr><td colspan="'.$colspan.'"><span class="opacitymedium">Aucun produit en attente pour ce client.</span></td></tr>';
} else {
	print '<tr class="liste_total">';
	print '<td>Total</td>';
	print '<td class="right">'.stockproprietaire_qty($totPhysique).'</td>';
	print '<td class="right">'.stockproprietaire_qty($totNonRecup).'</td>';
	if ($socid > 0) {
		print '<td class="right">'.stockproprietaire_qty($totClient).'</td>';
	}
	print '<td class="right">'.stockproprietaire_qty($totProprio).'</td>';
	print '</tr>';
}

print '</table>';
print '</div>';

// Détail par facture pour le client choisi
if ($socid > 0) {
	$rows = stockproprietaire_get_detail_client($db, $socid);

	print '<br>';
	print load_fiche_titre('Détail par facture : marchandise de ce client encore au magasin', '', 'bill');

	print '<div class="div-table-responsive">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<th>Facture</th>';
	print '<th class="center">Date</th>';
	print '<th>Produit</th>';
	print '<th class="right">Facturé</th>';
	print '<th class="right">Déjà livré</th>';
	print '<th class="right">Reste au magasin</th>';
	print '</tr>';

	if (empty($rows)) {
		print '<tr><td colspan="6"><span class="opacitymedium">Aucune facture en attente.</span></td></tr>';
	}
	foreach ($rows as $row) {
		print '<tr class="oddeven">';
		print '<td><a href="'.DOL_URL_ROOT.'/compta/facture/card.php?facid='.((int) $row->facid).'">'.img_picto('', 'bill', 'class="pictofixedwidth"').dol_escape_htmltag($row->facref).'</a></td>';
		print '<td class="center">'.dol_print_date($db->jdate($row->datef), 'day').'</td>';
		print '<td>'.dol_escape_htmltag($row->pref).' <span class="opacitymedium">'.dol_escape_htmltag($row->plabel).'</span></td>';
		print '<td class="right">'.stockproprietaire_qty($row->qty).'</td>';
		print '<td class="right">'.stockproprietaire_qty($row->livre).'</td>';
		print '<td class="right"><strong>'.stockproprietaire_qty($row->reste).'</strong></td>';
		print '</tr>';
	}
	print '</table>';
	print '</div>';
}

llxFooter();
$db->close();