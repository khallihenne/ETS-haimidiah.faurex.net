<?php
/* Copyright (C) 2025 Your Company
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    core/modules/modPayroll.class.php
 * \ingroup payroll
 * \brief   Module Payroll descriptor
 */

include_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for module Payroll
 */
class modPayroll extends DolibarrModules
{
    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        global $langs, $conf;
        $this->db = $db;

        // Module info
        $this->numero = 900000; // Unique ID
        $this->rights_class = 'payroll';
        $this->family = "hr";
        $this->module_position = '90';
        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->description = "Module de gestion de paie";
        $this->descriptionlong = "Gestion mensuelle de la paie avec fiches de paie, écritures comptables et paiements bancaires";

        $this->editor_name = 'Your Company';
        $this->editor_url = 'https://www.example.com';

        $this->version = '1.0.0';
        $this->const_name = 'MAIN_MODULE_' . strtoupper($this->name);
        $this->picto = 'object_payroll@payroll';

        // Dependencies
        $this->depends = array('modUser', 'modHRM');
        $this->requiredby = array();
        $this->conflictwith = array('modPaiReel');

        // Config pages
        $this->config_page_url = array("setup.php@payroll");

        // Constants
        $this->const = array(
            0 => array(
                'PAYROLL_ACCOUNT_SALARY_EXPENSE',
                'chaine',
                '65',
                'Accounting account for basic salary expenses',
                0,
                'current',
                1
            ),
            1 => array(
                'PAYROLL_ACCOUNT_BONUSES',
                'chaine',
                '6506',
                'Accounting account for bonuses and gratifications',
                0,
                'current',
                1
            ),
            2 => array(
                'PAYROLL_ACCOUNT_NET_PAYABLE',
                'chaine',
                '42',
                'Accounting account for net salary payable',
                0,
                'current',
                1
            ),
            3 => array(
                'PAYROLL_ACCOUNT_ADVANCES',
                'chaine',
                '425',
                'Accounting account for salary advances',
                0,
                'current',
                1
            )
        );

        // Boxes
        $this->boxes = array();

        // Permissions
        $this->rights = array();
        $r = 0;

        // Payrun permissions
        $r++;
        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Permission900001';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'payrun';
        $this->rights[$r][5] = 'read';

        $r++;
        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Permission900002';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'payrun';
        $this->rights[$r][5] = 'write';

        $r++;
        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Permission900003';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'payrun';
        $this->rights[$r][5] = 'validate';

        $r++;
        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Permission900004';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'payrun';
        $this->rights[$r][5] = 'delete';

        // Payslip permissions
        $r++;
        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Permission900005';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'payslip';
        $this->rights[$r][5] = 'read';

        $r++;
        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Permission900006';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'payslip';
        $this->rights[$r][5] = 'write';

        $r++;
        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Permission900007';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'payslip';
        $this->rights[$r][5] = 'readown';

        // Accounting permissions
        $r++;
        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Permission900008';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'accounting';
        $this->rights[$r][5] = 'write';

        // Payment permissions
        $r++;
        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Permission900009';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'payment';
        $this->rights[$r][5] = 'write';

        // Menu entries
        $this->menu = array();
        $r = 0;

        // Top menu
        $r++;
        $this->menu[$r] = array(
            'fk_menu' => 'fk_mainmenu=hrm',
            'type' => 'left',
            'titre' => 'Payroll',
            'prefix' => '',
            'mainmenu' => 'hrm',
            'leftmenu' => 'payroll',
            'url' => '/custom/payroll/payrun_list.php',
            'langs' => 'payroll@payroll',
            'position' => 100,
            'enabled' => '$conf->payroll->enabled',
            'perms' => '$user->rights->payroll->payrun->read',
            'target' => '',
            'user' => 2
        );

        // Pay runs submenu
        $r++;
        $this->menu[$r] = array(
            'fk_menu' => 'fk_mainmenu=hrm,fk_leftmenu=payroll',
            'type' => 'left',
            'titre' => 'PayRuns',
            'mainmenu' => 'hrm',
            'leftmenu' => 'payroll_payrun',
            'url' => '/custom/payroll/payrun_list.php',
            'langs' => 'payroll@payroll',
            'position' => 101,
            'enabled' => '$conf->payroll->enabled',
            'perms' => '$user->rights->payroll->payrun->read',
            'target' => '',
            'user' => 2
        );

        $r++;
        $this->menu[$r] = array(
            'fk_menu' => 'fk_mainmenu=hrm,fk_leftmenu=payroll_payrun',
            'type' => 'left',
            'titre' => 'NewPayRun',
            'mainmenu' => 'hrm',
            'leftmenu' => '',
            'url' => '/custom/payroll/payrun_card.php?action=create',
            'langs' => 'payroll@payroll',
            'position' => 102,
            'enabled' => '$conf->payroll->enabled',
            'perms' => '$user->rights->payroll->payrun->write',
            'target' => '',
            'user' => 2
        );

        // Payslips submenu
        $r++;
        $this->menu[$r] = array(
            'fk_menu' => 'fk_mainmenu=hrm,fk_leftmenu=payroll',
            'type' => 'left',
            'titre' => 'Payslips',
            'mainmenu' => 'hrm',
            'leftmenu' => '',
            'url' => '/custom/payroll/payslip_list.php',
            'langs' => 'payroll@payroll',
            'position' => 103,
            'enabled' => '$conf->payroll->enabled',
            'perms' => '$user->rights->payroll->payslip->read',
            'target' => '',
            'user' => 2
        );

        // Advances submenu
        $r++;
        $this->menu[$r] = array(
            'fk_menu' => 'fk_mainmenu=hrm,fk_leftmenu=payroll',
            'type' => 'left',
            'titre' => 'Advances',
            'mainmenu' => 'hrm',
            'leftmenu' => '',
            'url' => '/custom/payroll/advance_list.php',
            'langs' => 'payroll@payroll',
            'position' => 104,
            'enabled' => '$conf->payroll->enabled',
            'perms' => '$user->rights->payroll->payslip->read',
            'target' => '',
            'user' => 2
        );

        // Payment Settings submenu
        $r++;
        $this->menu[$r] = array(
            'fk_menu' => 'fk_mainmenu=hrm,fk_leftmenu=payroll',
            'type' => 'left',
            'titre' => 'PaymentSettings',
            'mainmenu' => 'hrm',
            'leftmenu' => '',
            'url' => '/custom/payroll/payment_settings.php',
            'langs' => 'payroll@payroll',
            'position' => 105,
            'enabled' => '$conf->payroll->enabled',
            'perms' => '$user->admin',
            'target' => '',
            'user' => 2
        );
    }

    /**
     * Function called when module is enabled.
     *
     * @param string $options Options when enabling module
     * @return int 1 if OK, 0 if KO
     */
    // public function init($options = '')
    // {
    //     global $conf, $langs;

    //     $sql = array();
    //     return $this->_init($sql, $options);
    // }

    /**
     * Function called when module is disabled.
     *
     * @param string $options Options when disabling module
     * @return int 1 if OK, 0 if KO
     */
    // public function remove($options = '')
    // {
    //     // Requêtes SQL à exécuter à la désactivation si tu veux DROP des tables (ici rien)
    //     $sql = array();
    //     return $this->_remove($sql, $options);
    // }

    //**********************************
    public function init($options = '')
    {
        global $conf, $langs;

        // Auto-deactivate conflicting module PaiReel
        if (!empty($conf->global->MAIN_MODULE_PAIREEL)) {
            require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
            unActivateModule('PaiReel');
            // Force reload of configuration for the rest of the script
            $conf->global->MAIN_MODULE_PAIREEL = 0;
            if (isset($conf->pai_reel)) $conf->pai_reel->enabled = 0;
        }

        $sql = array();
        return $this->_init($sql, $options);
    }

    /**
     * Function called when module is disabled.
     *
     * @param string $options Options when disabling module
     * @return int 1 if OK, 0 if KO
     */
    public function remove($options = '')
    {
        $sql = array();
        return $this->_remove($sql, $options);
    }
}