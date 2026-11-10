<?php
/* Copyright (C) 2026		Ets Haimidah
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    stockproprietaire/core/triggers/interface_99_modStockProprietaire_StockProprietaireTriggers.class.php
 * \ingroup stockproprietaire
 * \brief   - BILL_VALIDATE : crée l'expédition Faurex Magasin (bon de livraison) de la facture client
 *          - BILL_DELETE   : facture client IMPAYÉE supprimée -> les quantités déjà livrées reviennent
 *                            dans leur entrepôt d'origine, puis le bon de livraison est supprimé
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

class InterfaceStockProprietaireTriggers extends DolibarrTriggers
{
	public function __construct($db)
	{
		$this->db = $db;
		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = "products";
		$this->description = "StockProprietaire : bon de livraison à la validation d'une facture client, retour en stock à sa suppression";
		$this->version = '1.1';
		$this->picto = 'fa-warehouse';
	}

	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if ($action !== 'BILL_VALIDATE' && $action !== 'BILL_DELETE') {
			return 0;
		}

		// Les deux modules doivent être actifs
		if (!isModEnabled('stockproprietaire') || !isModEnabled('faurexmagasin')) {
			return 0;
		}

		// Factures standard uniquement
		if ((int) $object->type !== 0) {
			return 0;
		}

		dol_include_once('/faurex_magasin/class/faurexshipment.class.php');
		if (!class_exists('FaurexShipment')) {
			dol_syslog(__METHOD__." Classe FaurexShipment introuvable", LOG_WARNING);
			return 0;
		}

		if ($action === 'BILL_VALIDATE') {
			// La fonction vérifie elle-même si l'expédition existe déjà (pas de doublon)
			$res = FaurexShipment::createFromInvoice($this->db, $object, $user);
			if ($res < 0) {
				dol_syslog(__METHOD__." Echec création expédition pour facture ".$object->ref." : ".$this->db->lasterror(), LOG_WARNING);
				return 0;
			}
			return 1;
		}

		// BILL_DELETE
		return $this->restoreStockOnInvoiceDelete($object, $user);
	}

	/**
	 * Facture client supprimée : si elle est impayée, on remet en stock ce qui a été livré
	 * (dans l'entrepôt d'où c'est sorti) et on supprime son bon de livraison.
	 * Une facture payée (ou avec un paiement) n'est pas touchée.
	 *
	 * @param  Facture $facture
	 * @param  User    $user
	 * @return int     0 = rien à faire, 1 = OK, -1 = erreur (la suppression de la facture est annulée)
	 */
	private function restoreStockOnInvoiceDelete($facture, $user)
	{
		// Seulement les factures impayées
		if (!empty($facture->paye) || (int) $facture->status === 2) {
			return 0;
		}
		if (method_exists($facture, 'getSommePaiement') && (float) $facture->getSommePaiement() > 0) {
			return 0;
		}

		$res = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."faurex_shipment WHERE fk_facture = ".((int) $facture->id));
		if (!$res) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$ids = array();
		while ($obj = $this->db->fetch_object($res)) {
			$ids[] = (int) $obj->rowid;
		}
		if (empty($ids)) {
			return 0;
		}

		$motif = 'Facture '.$facture->ref.' supprimée : retour en stock automatique';
		foreach ($ids as $shipid) {
			$ship = new FaurexShipment($this->db);
			if ($ship->fetch($shipid) <= 0) {
				continue;
			}

			// Le n° de facture apparaît dans le libellé du mouvement de stock (recherche par "FV...")
			$ship->stockMoveNote = ' - facture '.$facture->ref.' supprimée';

			// Chaque ligne livrée revient dans son entrepôt + trace dans l'historique magasin
			$ship->cancelAll($user, $motif);

			// Puis le bon de livraison (expédition) est supprimé
			if (!$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."faurex_shipment_line WHERE fk_shipment = ".((int) $shipid))
				|| !$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."faurex_shipment WHERE rowid = ".((int) $shipid))) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			dol_syslog(__METHOD__." Facture ".$facture->ref." supprimée : expédition ".$ship->ref." annulée, stock restauré", LOG_INFO);
		}
		return 1;
	}
}