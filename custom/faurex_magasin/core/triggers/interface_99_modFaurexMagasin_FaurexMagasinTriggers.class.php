<?php
/**
 * Triggers Faurex Magasin
 *  - BILL_VALIDATE          : crée l'expédition (bon de livraison) de la facture client
 *  - BILL_SUPPLIER_VALIDATE : crée la réception de la facture fournisseur
 *  - BILL_DELETE            : facture client IMPAYÉE supprimée -> les quantités déjà livrées
 *                             reviennent dans leur entrepôt d'origine, puis le bon de livraison est supprimé
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
        $this->version = '1.1.0';
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
        if ($action === 'BILL_DELETE') {
            return $this->restoreStockOnInvoiceDelete($object, $user);
        }
        return 0;
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
        if (!empty($facture->paye) || (int) $facture->status === 2) return 0;
        if (method_exists($facture, 'getSommePaiement') && (float) $facture->getSommePaiement() > 0) return 0;

        $res = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."faurex_shipment WHERE fk_facture = ".((int) $facture->id));
        if (!$res) {
            $this->error = $this->db->lasterror();
            return -1;
        }
        $ids = array();
        while ($obj = $this->db->fetch_object($res)) $ids[] = (int) $obj->rowid;
        if (empty($ids)) return 0;

        $motif = 'Facture '.$facture->ref.' supprimée : retour en stock automatique';
        foreach ($ids as $shipid) {
            $ship = new FaurexShipment($this->db);
            if ($ship->fetch($shipid) <= 0) continue;

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