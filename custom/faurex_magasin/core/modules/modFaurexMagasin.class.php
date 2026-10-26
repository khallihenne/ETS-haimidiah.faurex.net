<?php
/**
 * \file    core/modules/modFaurexMagasin.class.php
 * \ingroup faurex_magasin
 * \brief   Module Faurex Magasin descriptor (Expéditions & Réceptions)
 */

include_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

class modFaurexMagasin extends DolibarrModules
{
    public function __construct($db)
    {
        global $langs, $conf;
        $this->db = $db;

        $this->numero = 500100;
        $this->rights_class = 'faurex_magasin';
        $this->family = "products";
        $this->module_position = '90';
        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->description = "Faurex Magasin - Expéditions & Réceptions liées aux factures";
        $this->descriptionlong = "Gestion des expéditions (factures clients) et réceptions (factures fournisseurs) avec suivi des quantités livrées/reçues.";

        $this->editor_name = 'Faurex';
        $this->editor_url = 'https://www.faurex.mr';

        $this->version = '1.0.0';
        $this->const_name = 'MAIN_MODULE_' . strtoupper($this->name);
        $this->picto = 'shipment';

        $this->depends = array('modFacture', 'modFournisseur', 'modProduct');
        $this->requiredby = array();
        $this->conflictwith = array();

        $this->langfiles = array("faurex_magasin@faurex_magasin");

        $this->config_page_url = array();
        $this->const = array();
        $this->boxes = array();

        // Permissions
        $this->rights = array();
        $r = 0;

        $r++;
        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Lire les expéditions';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'shipment';
        $this->rights[$r][5] = 'read';

        $r++;
        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Créer/modifier les expéditions';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'shipment';
        $this->rights[$r][5] = 'write';

        $r++;
        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Lire les réceptions';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'reception';
        $this->rights[$r][5] = 'read';

        $r++;
        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Créer/modifier les réceptions';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'reception';
        $this->rights[$r][5] = 'write';

        // Menus
        $this->menu = array();
        $r = 0;

        // Top: Expédition
        $r++;
        $this->menu[$r] = array(
            'fk_menu' => 'fk_mainmenu=products',
            'type' => 'left',
            'titre' => '<span class="fas fa-truck paddingright"></span> Expédition',
            'mainmenu' => 'products',
            'leftmenu' => 'faurex_shipment',
            'url' => '/custom/faurex_magasin/shipment_list.php',
            'langs' => 'faurex_magasin@faurex_magasin',
            'position' => 200,
            'enabled' => '$conf->faurexmagasin->enabled',
            'perms' => '$user->rights->faurex_magasin->shipment->read',
            'target' => '',
            'user' => 2
        );

        $r++;
        $this->menu[$r] = array(
            'fk_menu' => 'fk_mainmenu=products,fk_leftmenu=faurex_shipment',
            'type' => 'left',
            'titre' => 'List',
            'mainmenu' => 'products',
            'url' => '/custom/faurex_magasin/shipment_list.php',
            'langs' => 'faurex_magasin@faurex_magasin',
            'position' => 201,
            'enabled' => '$conf->faurexmagasin->enabled',
            'perms' => '$user->rights->faurex_magasin->shipment->read',
            'target' => '',
            'user' => 2
        );

        $r++;
        $this->menu[$r] = array(
            'fk_menu' => 'fk_mainmenu=products,fk_leftmenu=faurex_shipment',
            'type' => 'left',
            'titre' => 'Bon de livraison',
            'mainmenu' => 'products',
            'url' => '/custom/faurex_magasin/bon_expedition_list.php',
            'langs' => 'faurex_magasin@faurex_magasin',
            'position' => 202,
            'enabled' => '$conf->faurexmagasin->enabled',
            'perms' => '$user->rights->faurex_magasin->shipment->read',
            'target' => '',
            'user' => 2
        );

        // Top: Réception
        $r++;
        $this->menu[$r] = array(
            'fk_menu' => 'fk_mainmenu=products',
            'type' => 'left',
            'titre' => '<span class="fas fa-box-open paddingright"></span> Réception',
            'mainmenu' => 'products',
            'leftmenu' => 'faurex_reception',
            'url' => '/custom/faurex_magasin/reception_list.php',
            'langs' => 'faurex_magasin@faurex_magasin',
            'position' => 210,
            'enabled' => '$conf->faurexmagasin->enabled',
            'perms' => '$user->rights->faurex_magasin->reception->read',
            'target' => '',
            'user' => 2
        );

        $r++;
        $this->menu[$r] = array(
            'fk_menu' => 'fk_mainmenu=products,fk_leftmenu=faurex_reception',
            'type' => 'left',
            'titre' => 'List',
            'mainmenu' => 'products',
            'url' => '/custom/faurex_magasin/reception_list.php',
            'langs' => 'faurex_magasin@faurex_magasin',
            'position' => 211,
            'enabled' => '$conf->faurexmagasin->enabled',
            'perms' => '$user->rights->faurex_magasin->reception->read',
            'target' => '',
            'user' => 2
        );

        $r++;
        $this->menu[$r] = array(
            'fk_menu' => 'fk_mainmenu=products,fk_leftmenu=faurex_reception',
            'type' => 'left',
            'titre' => 'Bon de reception',
            'mainmenu' => 'products',
            'url' => '/custom/faurex_magasin/bon_reception_list.php',
            'langs' => 'faurex_magasin@faurex_magasin',
            'position' => 212,
            'enabled' => '$conf->faurexmagasin->enabled',
            'perms' => '$user->rights->faurex_magasin->reception->read',
            'target' => '',
            'user' => 2
        );
    }

    public function init($options = '')
    {
        $result = $this->_load_tables('/faurex_magasin/sql/');
        if ($result < 0) {
            return -1;
        }
        $sql = array();
        return $this->_init($sql, $options);
    }

    public function remove($options = '')
    {
        $sql = array();
        return $this->_remove($sql, $options);
    }
}
