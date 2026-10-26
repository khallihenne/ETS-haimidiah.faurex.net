<?php
/* Copyright (C) 2026		SuperAdmin
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    stockproprietaire/lib/stockproprietaire.lib.php
 * \ingroup stockproprietaire
 * \brief   Fonctions communes du module StockProprietaire
 */

/**
 * Onglets de la page de réglages du module
 *
 * @return array<array{string,string,string}>
 */
function stockproprietaireAdminPrepareHead()
{
	global $langs, $conf;

	$langs->load("stockproprietaire@stockproprietaire");

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/stockproprietaire/admin/setup.php", 1);
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath("/stockproprietaire/admin/about.php", 1);
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'stockproprietaire@stockproprietaire');
	complete_head_from_modules($conf, $langs, null, $head, $h, 'stockproprietaire@stockproprietaire', 'remove');

	return $head;
}

/**
 * Partie commune des requêtes : expéditions Faurex non terminées
 * et quantités déjà livrées par ligne de facture.
 *
 * @return string  Morceau de SQL (FROM ... WHERE ...)
 */
function stockproprietaire_sql_base()
{
	$sql = " FROM ".MAIN_DB_PREFIX."faurex_shipment AS s";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."facture AS f ON f.rowid = s.fk_facture";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."facturedet AS fd ON fd.fk_facture = f.rowid";
	$sql .= " LEFT JOIN (SELECT fk_facturedet, SUM(qty_delivered) AS livre";
	$sql .= "   FROM ".MAIN_DB_PREFIX."faurex_shipment_line GROUP BY fk_facturedet) AS d";
	$sql .= "   ON d.fk_facturedet = fd.rowid";
	$sql .= " WHERE s.status IN (0, 1)";          // Expéditions non terminées (Validé, Partiel)
	$sql .= " AND f.fk_statut IN (1, 2)";         // Factures validées ou payées
	$sql .= " AND f.type = 0";                    // Factures standard (pas les avoirs)
	$sql .= " AND fd.product_type = 0";           // Produits uniquement (pas les services)
	$sql .= " AND f.entity IN (".getEntity('invoice').")";
	return $sql;
}

/**
 * Quantité vendue mais pas encore récupérée, par produit.
 *
 * @param  DoliDB $db          Connexion à la base
 * @param  int    $fk_product  Id du produit (0 = tous)
 * @param  int    $fk_soc      Id du client (0 = tous les clients)
 * @return array<int,float>    [id_produit => quantité non récupérée]
 */
function stockproprietaire_get_non_recupere($db, $fk_product = 0, $fk_soc = 0)
{
	$sql = "SELECT fd.fk_product, SUM(GREATEST(fd.qty - COALESCE(d.livre, 0), 0)) AS non_recup";
	$sql .= stockproprietaire_sql_base();
	if ($fk_product > 0) {
		$sql .= " AND fd.fk_product = ".((int) $fk_product);
	}
	if ($fk_soc > 0) {
		$sql .= " AND f.fk_soc = ".((int) $fk_soc);
	}
	$sql .= " GROUP BY fd.fk_product";

	$result = array();
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$result[(int) $obj->fk_product] = (float) $obj->non_recup;
		}
	} else {
		dol_syslog(__FUNCTION__." Erreur SQL : ".$db->lasterror(), LOG_ERR);
	}
	return $result;
}

/**
 * Détail par facture de ce qu'un client a encore au magasin.
 *
 * @param  DoliDB $db      Connexion à la base
 * @param  int    $fk_soc  Id du client
 * @return array<int,object>  Lignes : facid, facref, datef, pid, pref, plabel, qty, livre, reste
 */
function stockproprietaire_get_detail_client($db, $fk_soc)
{
	$sql = "SELECT f.rowid AS facid, f.ref AS facref, f.datef,";
	$sql .= " p.rowid AS pid, p.ref AS pref, p.label AS plabel,";
	$sql .= " fd.qty, COALESCE(d.livre, 0) AS livre,";
	$sql .= " GREATEST(fd.qty - COALESCE(d.livre, 0), 0) AS reste";
	$sql .= stockproprietaire_sql_base();
	$sql .= " AND f.fk_soc = ".((int) $fk_soc);
	$sql .= " AND fd.qty - COALESCE(d.livre, 0) > 0";
	// On ajoute la jointure avec la table des produits pour avoir la référence et le libellé
	$sql = str_replace(
		"INNER JOIN ".MAIN_DB_PREFIX."facturedet AS fd ON fd.fk_facture = f.rowid",
		"INNER JOIN ".MAIN_DB_PREFIX."facturedet AS fd ON fd.fk_facture = f.rowid INNER JOIN ".MAIN_DB_PREFIX."product AS p ON p.rowid = fd.fk_product",
		$sql
	);
	$sql .= " ORDER BY f.datef DESC, f.ref, p.ref";

	$rows = array();
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$rows[] = $obj;
		}
	} else {
		dol_syslog(__FUNCTION__." Erreur SQL : ".$db->lasterror(), LOG_ERR);
	}
	return $rows;
}

/**
 * Affiche une quantité : sans décimales si c'est un nombre entier.
 * Exemple : 6427 -> "6 427" ; 2.5 -> "2,50"
 *
 * @param  float  $qty  Quantité
 * @return string
 */
function stockproprietaire_qty($qty)
{
	$qty = (float) $qty;
	$decimals = (floor($qty) == $qty) ? 0 : 2;
	return number_format($qty, $decimals, ',', ' ');
}