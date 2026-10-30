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
 * \brief   Crée l'expédition Faurex Magasin à la validation d'une facture client.
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

class InterfaceStockProprietaireTriggers extends DolibarrTriggers
{
	public function __construct($db)
	{
		$this->db = $db;
		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = "products";
		$this->description = "StockProprietaire : crée l'expédition Faurex Magasin à la validation d'une facture client";
		$this->version = '1.0';
		$this->picto = 'fa-warehouse';
	}

	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		// Seulement à la validation d'une facture client
		if ($action !== 'BILL_VALIDATE') {
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

		// La fonction vérifie elle-même si l'expédition existe déjà (pas de doublon)
		$res = FaurexShipment::createFromInvoice($this->db, $object, $user);
		if ($res < 0) {
			dol_syslog(__METHOD__." Echec création expédition pour facture ".$object->ref." : ".$this->db->lasterror(), LOG_WARNING);
			return 0;
		}

		return 1;
	}
}
