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
 * Partie commune des requêtes : lignes de factures clients et quantités déjà livrées.
 *
 * Le calcul ne dépend PAS du statut de l'expédition : on compare simplement
 * la quantité facturée et la quantité livrée (lignes d'expédition Faurex).
 * Ainsi un statut d'expédition faux ne peut plus cacher un client.
 *
 * @param  string $extrajoin  Jointures supplémentaires (produit, client...)
 * @return string             Morceau de SQL (FROM ... WHERE ...)
 */
function stockproprietaire_sql_base($extrajoin = '')
{
	$sql = " FROM ".MAIN_DB_PREFIX."facture AS f";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."facturedet AS fd ON fd.fk_facture = f.rowid";
	$sql .= " LEFT JOIN (SELECT fk_facturedet, SUM(qty_delivered) AS livre";
	$sql .= "   FROM ".MAIN_DB_PREFIX."faurex_shipment_line GROUP BY fk_facturedet) AS d";
	$sql .= "   ON d.fk_facturedet = fd.rowid";
	$sql .= $extrajoin;
	$sql .= " WHERE f.fk_statut IN (1, 2)";       // Factures validées ou payées
	$sql .= " AND f.type = 0";                    // Factures standard (pas les avoirs)
	$sql .= " AND fd.product_type = 0";           // Produits uniquement (pas les services)
	$sql .= " AND fd.fk_product > 0";             // Lignes liées à un produit (le texte libre n'a pas de stock)
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
 * Liste des clients qui ont encore de la marchandise au magasin.
 *
 * @param  DoliDB $db  Connexion à la base
 * @return array<int,object>  Lignes : rowid, nom, town, reste
 */
function stockproprietaire_get_clients_en_attente($db)
{
	$sql = "SELECT so.rowid, so.nom, so.town,";
	$sql .= " SUM(GREATEST(fd.qty - COALESCE(d.livre, 0), 0)) AS reste";
	$sql .= stockproprietaire_sql_base(" INNER JOIN ".MAIN_DB_PREFIX."societe AS so ON so.rowid = f.fk_soc");
	$sql .= " GROUP BY so.rowid, so.nom, so.town";
	$sql .= " HAVING reste > 0";
	$sql .= " ORDER BY so.nom, so.rowid";

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
 * Détail par facture de ce qu'un client a encore au magasin.
 *
 * @param  DoliDB $db      Connexion à la base
 * @param  int    $fk_soc  Id du client
 * @return array<int,object>  Lignes : shipid, shipstatus, fdid, facid, facref, datef, pid, pref, plabel, qty, livre, reste
 */
function stockproprietaire_get_detail_client($db, $fk_soc)
{
	$sql = "SELECT s.rowid AS shipid, s.status AS shipstatus, fd.rowid AS fdid,";
	$sql .= " f.rowid AS facid, f.ref AS facref, f.datef,";
	$sql .= " p.rowid AS pid, p.ref AS pref, p.label AS plabel,";
	$sql .= " fd.qty, COALESCE(d.livre, 0) AS livre,";
	$sql .= " GREATEST(fd.qty - COALESCE(d.livre, 0), 0) AS reste";
	$extrajoin = " INNER JOIN ".MAIN_DB_PREFIX."product AS p ON p.rowid = fd.fk_product";
	// Expédition Faurex de la facture (la première si jamais il y en a plusieurs)
	$extrajoin .= " LEFT JOIN (SELECT fk_facture, MIN(rowid) AS shipid FROM ".MAIN_DB_PREFIX."faurex_shipment GROUP BY fk_facture) AS sm";
	$extrajoin .= "   ON sm.fk_facture = f.rowid";
	$extrajoin .= " LEFT JOIN ".MAIN_DB_PREFIX."faurex_shipment AS s ON s.rowid = sm.shipid";
	$sql .= stockproprietaire_sql_base($extrajoin);
	$sql .= " AND f.fk_soc = ".((int) $fk_soc);
	$sql .= " AND fd.qty - COALESCE(d.livre, 0) > 0";
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