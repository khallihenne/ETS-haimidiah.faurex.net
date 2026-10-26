<?php
/* Copyright (C) 2004-2018	Laurent Destailleur			<eldy@users.sourceforge.net>
 * Copyright (C) 2018-2019	Nicolas ZABOURI				<info@inovea-conseil.com>
 * Copyright (C) 2019-2024	Frédéric France				<frederic.france@free.fr>
 * Copyright (C) 2026		SuperAdmin
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * 	\defgroup   stockproprietaire     Module StockProprietaire
 *  \brief      StockProprietaire module descriptor.
 *
 *  \file       htdocs/stockproprietaire/core/modules/modStockProprietaire.class.php
 *  \ingroup    stockproprietaire
 *  \brief      Description and activation file for module StockProprietaire
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';


/**
 *  Description and activation class for module StockProprietaire
 */
class modStockProprietaire extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf, $langs;

		$this->db = $db;

		// Id unique du module (500100 = Faurex Magasin, ne pas réutiliser)
		$this->numero = 500200;

		// Clé utilisée pour les droits et les menus
		$this->rights_class = 'stockproprietaire';

		// Famille : le module apparaît dans la rubrique "Produits" de la liste des modules
		$this->family = "products";
		$this->module_position = '90';

		$this->name = preg_replace('/^mod/i', '', get_class($this));

		$this->description = "Stock propriétaire : distingue la marchandise payée mais laissée au magasin par les clients";
		$this->descriptionlong = "Affiche sur chaque fiche produit le stock physique, la quantité vendue mais pas encore récupérée par les clients (d'après les expéditions Faurex Magasin), et le stock qui appartient réellement au propriétaire.";

		// Auteur
		$this->editor_name = 'Ets Haimidah';
		$this->editor_url = '';
		$this->editor_squarred_logo = '';

		$this->version = '1.0';

		// Constante enregistrée dans llx_const quand le module est activé
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);

		$this->picto = 'fa-warehouse';

		// Fonctionnalités du module
		$this->module_parts = array(
			'triggers' => 0,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 0,
			'printing' => 0,
			'theme' => 0,
			'css' => array(),
			'js' => array(),
			/* BEGIN MODULEBUILDER HOOKSCONTEXTS */
			'hooks' => array(
				'data' => array(
					'productcard',      // Fiche produit
				),
				'entity' => '0',
			),
			/* END MODULEBUILDER HOOKSCONTEXTS */
			'moduleforexternal' => 0,
			'websitetemplates' => 0,
			'captcha' => 0
		);

		// Dossiers de données créés à l'activation
		$this->dirs = array("/stockproprietaire/temp");

		// Page de réglages
		$this->config_page_url = array("setup.php@stockproprietaire");

		// Dépendances
		$this->hidden = getDolGlobalInt('MODULE_STOCKPROPRIETAIRE_DISABLED');
		// Ce module lit les tables de Faurex Magasin et utilise les stocks
		$this->depends = array('modFaurexMagasin', 'modStock');
		$this->requiredby = array();
		$this->conflictwith = array();

		$this->langfiles = array("stockproprietaire@stockproprietaire");

		// Prérequis
		$this->phpmin = array(7, 1);
		$this->need_dolibarr_version = array(19, -3);
		$this->need_javascript_ajax = 0;

		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		// Constantes
		$this->const = array();

		if (!isModEnabled("stockproprietaire")) {
			$conf->stockproprietaire = new stdClass();
			$conf->stockproprietaire->enabled = 0;
		}

		// Onglets
		/* BEGIN MODULEBUILDER TABS */
		$this->tabs = array();
		/* END MODULEBUILDER TABS */

		// Dictionnaires
		/* BEGIN MODULEBUILDER DICTIONARIES */
		$this->dictionaries = array();
		/* END MODULEBUILDER DICTIONARIES */

		// Widgets
		/* BEGIN MODULEBUILDER WIDGETS */
		$this->boxes = array();
		/* END MODULEBUILDER WIDGETS */

		// Tâches planifiées
		/* BEGIN MODULEBUILDER CRON */
		$this->cronjobs = array();
		/* END MODULEBUILDER CRON */

		// Droits
		$this->rights = array();
		$r = 0;
		/* BEGIN MODULEBUILDER PERMISSIONS */
		$this->rights[$r][0] = $this->numero.sprintf("%02d", 1);   // Id du droit : 50020001
		$this->rights[$r][1] = 'Voir le stock propriétaire';
		$this->rights[$r][4] = 'read';                             // Vérifié par $user->hasRight('stockproprietaire', 'read')
		$r++;
		/* END MODULEBUILDER PERMISSIONS */

		// Menus : une entrée dans le menu de gauche de "Produits"
		$this->menu = array();
		$r = 0;
		/* BEGIN MODULEBUILDER LEFTMENU */
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=products',
			'type' => 'left',
			'titre' => 'Stock propriétaire',
			'prefix' => img_picto('', $this->picto, 'class="pictofixedwidth valignmiddle paddingright"'),
			'mainmenu' => 'products',
			'leftmenu' => 'stockproprietaire',
			'url' => '/stockproprietaire/stockproprietaireindex.php',
			'langs' => 'stockproprietaire@stockproprietaire',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("stockproprietaire")',
			'perms' => '$user->hasRight("stockproprietaire", "read")',
			'target' => '',
			'user' => 0,    // 0 = utilisateurs internes uniquement
		);
		/* END MODULEBUILDER LEFTMENU */
	}

	/**
	 *  Function called when module is enabled.
	 *
	 *  @param      string  $options    Options when enabling module ('', 'noboxes')
	 *  @return     int<-1,1>          	1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		// Ce module ne crée aucune table : il lit celles de Dolibarr et de Faurex Magasin
		$this->remove($options);

		$sql = array();

		return $this->_init($sql, $options);
	}

	/**
	 *	Function called when module is disabled.
	 *
	 *	@param	string		$options	Options when enabling module ('', 'noboxes')
	 *	@return	int<-1,1>				1 if OK, <=0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}