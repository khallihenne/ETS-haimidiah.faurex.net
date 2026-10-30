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
 * \brief   Page "Stock" : stock physique et stock propriétaire, filtre par client,
 *          et saisie des livraisons (le client récupère une partie de sa marchandise).
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

$socid        = GETPOSTINT('socid');
$action       = GETPOST('action', 'aZ09');
$candeliver   = !empty($user->rights->faurex_magasin->shipment->write);
$fk_warehouse = GETPOSTINT('fk_warehouse');

$productstatic = new Product($db);


/*
 * Actions : enregistrer une livraison
 */

if ($action == 'deliver' && $socid > 0) {
	if (!$candeliver) {
		setEventMessages("Vous n'avez pas le droit d'enregistrer une livraison.", null, 'errors');
	} else {
		$fdid = GETPOSTINT('deliver_line');
		$qtys = GETPOST('qty', 'array');
		$qty  = isset($qtys[$fdid]) ? (float) price2num($qtys[$fdid]) : 0;

		// On retrouve la ligne parmi celles du client (vérifie qu'elle lui appartient bien)
		$line = null;
		foreach (stockproprietaire_get_detail_client($db, $socid) as $row) {
			if ((int) $row->fdid === $fdid) {
				$line = $row;
				break;
			}
		}

		if (!$line) {
			setEventMessages("Ligne introuvable pour ce client.", null, 'errors');
		} elseif ($fk_warehouse <= 0) {
			setEventMessages("Choisissez l'entrepôt de sortie.", null, 'errors');
		} else {
			dol_include_once('/faurex_magasin/class/faurexshipment.class.php');
			$ship = new FaurexShipment($db);
			if ($ship->fetch($line->shipid) <= 0) {
				setEventMessages("Expédition introuvable.", null, 'errors');
			} else {
				// Fonction de Faurex Magasin : ligne de livraison + sortie de stock + statut
				$r = $ship->addLine($line->fdid, $line->pid, $line->qty, $qty, dol_now(), '', '', $user, $fk_warehouse);
				if ($r > 0) {
					setEventMessages("Livraison enregistrée : ".stockproprietaire_qty($qty)." x ".$line->pref." (".$line->facref.").", null, 'mesgs');
				} elseif ($r == -3) {
					setEventMessages("La quantité doit être supérieure à 0.", null, 'errors');
				} elseif ($r == -4) {
					setEventMessages("La quantité dépasse le reste au magasin (".stockproprietaire_qty($line->reste).").", null, 'errors');
				} elseif ($r == -2) {
					setEventMessages("Cette expédition est déjà entièrement livrée.", null, 'errors');
				} else {
					setEventMessages("Erreur lors de l'enregistrement de la livraison.", null, 'errors');
				}
			}
		}
	}

	// On recharge la page pour afficher les chiffres à jour
	header('Location: '.$_SERVER['PHP_SELF'].'?socid='.$socid.'&fk_warehouse='.$fk_warehouse);
	exit;
}


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

// Entrepôts (pour la sortie de stock)
$warehouses = array();
$sql = "SELECT e.rowid, e.ref, e.lieu";
$sql .= " FROM ".MAIN_DB_PREFIX."entrepot AS e";
$sql .= " WHERE e.entity IN (".getEntity('stock').")";
$sql .= " ORDER BY e.rowid";
$resql = $db->query($sql);
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$warehouses[] = $obj;
	}
}
if ($fk_warehouse <= 0 && !empty($warehouses)) {
	$fk_warehouse = (int) $warehouses[0]->rowid;   // Premier entrepôt par défaut (Magasin-Capital)
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

// Détail par facture pour le client choisi, avec saisie des livraisons
if ($socid > 0) {
	$rows = stockproprietaire_get_detail_client($db, $socid);

	print '<br>';
	print load_fiche_titre('Détail par facture : marchandise de ce client encore au magasin', '', 'bill');

	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="deliver">';
	print '<input type="hidden" name="socid" value="'.((int) $socid).'">';

	if ($candeliver && !empty($rows)) {
		print '<div class="paddingbottom">';
		print '<span class="paddingright">Entrepôt de sortie :</span>';
		print '<select name="fk_warehouse" class="minwidth200">';
		foreach ($warehouses as $w) {
			$selected = ((int) $w->rowid === (int) $fk_warehouse) ? ' selected' : '';
			print '<option value="'.((int) $w->rowid).'"'.$selected.'>'.dol_escape_htmltag($w->ref.($w->lieu ? ' - '.$w->lieu : '')).'</option>';
		}
		print '</select>';
		print '</div>';
	}

	print '<div class="div-table-responsive">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<th>Facture</th>';
	print '<th class="center">Date</th>';
	print '<th>Produit</th>';
	print '<th class="center">État</th>';
	print '<th class="right">Facturé</th>';
	print '<th class="right">Déjà livré</th>';
	print '<th class="right">Reste au magasin</th>';
	if ($candeliver) {
		print '<th class="center">Le client récupère</th>';
	}
	print '</tr>';

	$colspan = $candeliver ? 8 : 7;
	if (empty($rows)) {
		print '<tr><td colspan="'.$colspan.'"><span class="opacitymedium">Aucune facture en attente.</span></td></tr>';
	}
	foreach ($rows as $row) {
		$statusLabel = ((int) $row->shipstatus === 1) ? 'Partiel' : 'Validé';
		$statusClass = ((int) $row->shipstatus === 1) ? 'badge-status1' : 'badge-status4';

		print '<tr class="oddeven">';
		print '<td><a href="'.DOL_URL_ROOT.'/compta/facture/card.php?facid='.((int) $row->facid).'">'.img_picto('', 'bill', 'class="pictofixedwidth"').dol_escape_htmltag($row->facref).'</a></td>';
		print '<td class="center">'.dol_print_date($db->jdate($row->datef), 'day').'</td>';
		print '<td>'.dol_escape_htmltag($row->pref).' <span class="opacitymedium">'.dol_escape_htmltag($row->plabel).'</span></td>';
		print '<td class="center"><span class="badge '.$statusClass.'">'.$statusLabel.'</span></td>';
		print '<td class="right">'.stockproprietaire_qty($row->qty).'</td>';
		print '<td class="right">'.stockproprietaire_qty($row->livre).'</td>';
		print '<td class="right"><strong>'.stockproprietaire_qty($row->reste).'</strong></td>';
		if ($candeliver) {
			print '<td class="center nowraponall">';
			print '<input type="number" name="qty['.((int) $row->fdid).']" value="'.((float) $row->reste).'" min="0" max="'.((float) $row->reste).'" step="any" class="width75 right"> ';
			print '<button type="submit" name="deliver_line" value="'.((int) $row->fdid).'" class="button smallpaddingimp"';
			print ' onclick="return confirm(\'Enregistrer cette livraison ?\');">Livrer</button>';
			print '</td>';
		}
		print '</tr>';
	}
	print '</table>';
	print '</div>';
	print '</form>';
}

llxFooter();
$db->close();