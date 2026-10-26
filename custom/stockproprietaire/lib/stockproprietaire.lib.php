<?php
/* Copyright (C) 2026		SuperAdmin
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    stockproprietaire/lib/stockproprietaire.lib.php
 * \ingroup stockproprietaire
 * \brief   Library files with common functions for StockProprietaire
 */

/**
 * Prepare admin pages header
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
 * Quantité vendue mais pas encore récupérée par les clients, par produit.
 *
 * Calcul basé sur les expéditions du module Faurex Magasin :
 * quantité facturée (une seule fois, depuis la ligne de facture)
 * moins la somme des quantités déjà livrées.
 *
 * @param  DoliDB $db          Connexion à la base
 * @param  int    $fk_product  Id du produit (0 = tous les produits)
 * @return array<int,float>    [id_produit => quantité non récupérée]
 */
function stockproprietaire_get_non_recupere($db, $fk_product = 0)
{
	$sql = "SELECT fd.fk_product, SUM(GREATEST(fd.qty - COALESCE(d.livre, 0), 0)) AS non_recup";
	$sql .= " FROM ".MAIN_DB_PREFIX."faurex_shipment AS s";
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
	if ($fk_product > 0) {
		$sql .= " AND fd.fk_product = ".((int) $fk_product);
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