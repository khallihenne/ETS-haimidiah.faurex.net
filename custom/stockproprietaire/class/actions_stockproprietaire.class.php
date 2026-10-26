<?php
/* Copyright (C) 2026		Ets Haimidah
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    stockproprietaire/class/actions_stockproprietaire.class.php
 * \ingroup stockproprietaire
 * \brief   Hooks du module StockProprietaire
 */

/**
 * Classe des hooks du module StockProprietaire
 */
class ActionsStockProprietaire
{
	/**
	 * @var DoliDB Connexion à la base
	 */
	public $db;

	/**
	 * @var string Message d'erreur
	 */
	public $error = '';

	/**
	 * @var string[] Liste des erreurs
	 */
	public $errors = array();

	/**
	 * @var array Résultats renvoyés au hook manager
	 */
	public $results = array();

	/**
	 * @var string HTML à afficher dans la page
	 */
	public $resprints;

	/**
	 * Constructeur
	 *
	 * @param DoliDB $db Connexion à la base
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Ajoute le stock physique, la quantité non récupérée et le stock propriétaire
	 * sur la fiche produit.
	 *
	 * @param  array        $parameters  Paramètres du hook (contient 'context')
	 * @param  CommonObject $object      Le produit affiché
	 * @param  string       $action      Action en cours (create, edit, ...)
	 * @param  HookManager  $hookmanager Gestionnaire des hooks
	 * @return int                       0 = on ajoute au contenu standard
	 */
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user;

		// 1. Seulement sur la fiche produit
		if (!in_array('productcard', explode(':', $parameters['context']))) {
			return 0;
		}

		// 2. Seulement en mode affichage (pas en création ni en modification)
		if (in_array($action, array('create', 'edit'))) {
			return 0;
		}

		// 3. Seulement pour les produits (type 0), pas les services (type 1)
		if (empty($object->id) || (int) $object->type !== 0) {
			return 0;
		}

		// 4. Seulement si l'utilisateur a le droit de voir le stock propriétaire
		if (!$user->hasRight('stockproprietaire', 'read')) {
			return 0;
		}

		dol_include_once('/stockproprietaire/lib/stockproprietaire.lib.php');

		// Calcul
		$nonrecup  = stockproprietaire_get_non_recupere($this->db, $object->id);
		$qtyclient = isset($nonrecup[$object->id]) ? $nonrecup[$object->id] : 0;
		$physique  = (float) $object->stock_reel;
		$proprio   = $physique - $qtyclient;

		// Affichage : 3 lignes ajoutées dans le tableau de la fiche produit
		$out  = '<tr><td>Stock physique (magasin)</td>';
		$out .= '<td>'.price($physique, 0, $langs, 0, -1, -1).'</td></tr>';

		$out .= '<tr><td>Vendu, pas encore récupéré par les clients</td>';
		$out .= '<td>'.price($qtyclient, 0, $langs, 0, -1, -1).'</td></tr>';

		$out .= '<tr><td><strong>Stock propriétaire</strong></td>';
		$out .= '<td><strong>'.price($proprio, 0, $langs, 0, -1, -1).'</strong></td></tr>';

		$this->resprints = $out;

		return 0;
	}
}