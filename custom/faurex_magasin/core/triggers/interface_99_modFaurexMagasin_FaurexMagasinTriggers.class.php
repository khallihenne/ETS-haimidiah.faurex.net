<?php
/**
 * Trigger: création automatique d'expéditions/réceptions à la validation des factures
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';
require_once dol_buildpath('/faurex_magasin/class/faurexshipment.class.php');
require_once dol_buildpath('/faurex_magasin/class/faurexreception.class.php');

class InterfaceFaurexMagasinTriggers extends DolibarrTriggers
{
    public function __construct($db)
    {
        $this->db = $db;
        $this->name = preg_replace('/^Interface/i', '', get_class($this));
        $this->family = "faurex_magasin";
        $this->description = "Triggers Faurex Magasin";
        $this->version = '1.0.0';
        $this->picto = 'shipment';
    }

    public function runTrigger($action, $object, $user, $langs, $conf)
    {
        if (empty($conf->faurexmagasin) || empty($conf->faurexmagasin->enabled)) return 0;

        if ($action === 'BILL_VALIDATE') {
            $r = FaurexShipment::createFromInvoice($this->db, $object, $user);
            return ($r < 0 ? -1 : 1);
        }
        if ($action === 'BILL_SUPPLIER_VALIDATE') {
            $r = FaurexReception::createFromInvoice($this->db, $object, $user);
            return ($r < 0 ? -1 : 1);
        }
        return 0;
    }
}
