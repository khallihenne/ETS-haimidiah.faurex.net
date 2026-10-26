<?php
/**
 * Classe métier Faurex Shipment (Expédition liée à une facture client)
 */

class FaurexShipment
{
    public $db;
    public $id;
    public $ref;
    public $fk_facture;
    public $fk_soc;
    public $date_facture;
    public $date_planned;
    public $status; // 0=Validé, 1=Partiel, 2=Livré
    public $note;
    public $lines = array();

    const STATUS_VALIDATED = 0;
    const STATUS_PARTIAL   = 1;
    const STATUS_DELIVERED = 2;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public static function getStatusLabel($status)
    {
        switch ((int)$status) {
            case self::STATUS_VALIDATED: return 'Validé';
            case self::STATUS_PARTIAL:   return 'Partiel';
            case self::STATUS_DELIVERED: return 'Livré';
        }
        return '-';
    }

    public function fetch($id)
    {
        $sql = "SELECT rowid, ref, fk_facture, fk_soc, date_facture, date_planned, status, note FROM ".MAIN_DB_PREFIX."faurex_shipment WHERE rowid = ".(int)$id;
        $res = $this->db->query($sql);
        if (!$res) return -1;
        $obj = $this->db->fetch_object($res);
        if (!$obj) return 0;
        $this->id = $obj->rowid;
        $this->ref = $obj->ref;
        $this->fk_facture = $obj->fk_facture;
        $this->fk_soc = $obj->fk_soc;
        $this->date_facture = $obj->date_facture;
        $this->date_planned = $obj->date_planned;
        $this->status = $obj->status;
        $this->note = $obj->note;
        $this->fetchLines();
        return 1;
    }

    public function fetchLines()
    {
        $this->lines = array();
        $sql = "SELECT rowid, fk_facturedet, fk_product, qty_invoiced, qty_delivered, fk_warehouse, date_shipment, mode_reglement, account_credit FROM ".MAIN_DB_PREFIX."faurex_shipment_line WHERE fk_shipment = ".(int)$this->id." ORDER BY rowid ASC";
        $res = $this->db->query($sql);
        if (!$res) return -1;
        while ($obj = $this->db->fetch_object($res)) {
            $this->lines[] = $obj;
        }
        return count($this->lines);
    }

    /**
     * Crée automatiquement une expédition à la validation d'une facture client
     */
    public static function createFromInvoice($db, $facture, $user)
    {
        // Existe déjà ?
        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."faurex_shipment WHERE fk_facture = ".(int)$facture->id;
        $res = $db->query($sql);
        if ($res && $db->num_rows($res) > 0) {
            return 0;
        }
        $ref = 'EXP'.dol_print_date(dol_now(), '%Y%m%d').'-'.((int)$facture->id);
        $sql = "INSERT INTO ".MAIN_DB_PREFIX."faurex_shipment (ref, fk_facture, fk_soc, date_facture, status, date_creation, fk_user_creat) VALUES (".
            "'".$db->escape($ref)."',".
            (int)$facture->id.",".
            (int)$facture->socid.",".
            "'".$db->idate($facture->date)."',".
            self::STATUS_VALIDATED.",".
            "'".$db->idate(dol_now())."',".
            (int)$user->id.")";
        if (!$db->query($sql)) return -1;
        return $db->last_insert_id(MAIN_DB_PREFIX.'faurex_shipment');
    }

    /**
     * Ajoute une ligne d'expédition
     */
    public function addLine($fk_facturedet, $fk_product, $qty_invoiced, $qty_delivered, $date_shipment, $mode_reglement, $account_credit, $user, $fk_warehouse = 0)
    {
        if ($this->status == self::STATUS_DELIVERED) return -2;

        $qty_delivered = (float)$qty_delivered;
        if ($qty_delivered <= 0) return -3;

        $remaining = $this->getRemainingForLine($fk_facturedet, $qty_invoiced);
        if ($qty_delivered > $remaining) return -4;

        $sql = "INSERT INTO ".MAIN_DB_PREFIX."faurex_shipment_line (fk_shipment, fk_facturedet, fk_product, qty_invoiced, qty_delivered, fk_warehouse, date_shipment, mode_reglement, account_credit, date_creation, fk_user_creat) VALUES (".
            (int)$this->id.",".
            (int)$fk_facturedet.",".
            ($fk_product ? (int)$fk_product : 'NULL').",".
            (float)$qty_invoiced.",".
            $qty_delivered.",".
            ($fk_warehouse ? (int)$fk_warehouse : 'NULL').",".
            ($date_shipment ? "'".$this->db->idate($date_shipment)."'" : "NULL").",".
            "'".$this->db->escape($mode_reglement)."',".
            "'".$this->db->escape($account_credit)."',".
            "'".$this->db->idate(dol_now())."',".
            (int)$user->id.")";
        if (!$this->db->query($sql)) {
            $err = $this->db->lasterror();
if (stripos($err, 'fk_warehouse') !== false) {
                $this->db->query("ALTER TABLE ".MAIN_DB_PREFIX."faurex_shipment_line ADD COLUMN fk_warehouse INT DEFAULT NULL");
                if (!$this->db->query($sql)) return -1;
            } else {
                return -1;
            }
        }

        // Mouvement de stock : sortie
        if ($fk_product && $fk_warehouse) {
            $this->stockMove($user, $fk_product, $fk_warehouse, $qty_delivered, 1, 'Expédition '.$this->ref);
        }

        $this->updateStatus();
        return 1;
    }

    /**
     * Effectue un mouvement de stock
     * @param int $type 0 = entrée (+), 1 = sortie (-)
     */
    protected function stockMove($user, $fk_product, $fk_warehouse, $qty, $type, $label)
    {
        require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
        $prod = new Product($this->db);
        if ($prod->fetch($fk_product) <= 0) return -1;
        return $prod->correct_stock($user, $fk_warehouse, abs((float)$qty), $type, $label, 0, dol_now());
    }

    /**
     * Renvoie un tableau [fk_facturedet => qty_delivered_total] pour cette expédition.
     * Permet d'éviter N requêtes dans la boucle d'affichage.
     */
    public function getDeliveredByLine()
    {
        $out = array();
        $sql = "SELECT fk_facturedet, COALESCE(SUM(qty_delivered),0) AS s FROM ".MAIN_DB_PREFIX."faurex_shipment_line WHERE fk_shipment = ".(int)$this->id." GROUP BY fk_facturedet";
        $res = $this->db->query($sql);
        if ($res) while ($obj = $this->db->fetch_object($res)) $out[(int)$obj->fk_facturedet] = (float)$obj->s;
        return $out;
    }

    /**
     * Synchronisation par lots : crée toutes les expéditions manquantes pour les factures clients validées en une seule requête.
     */
    public static function syncFromValidatedInvoices($db, $user)
    {
        $sql = "INSERT INTO ".MAIN_DB_PREFIX."faurex_shipment (ref, fk_facture, fk_soc, date_facture, status, date_creation, fk_user_creat) ".
            "SELECT CONCAT('EXP', DATE_FORMAT(NOW(),'%Y%m%d'), '-', f.rowid), f.rowid, f.fk_soc, f.datef, ".self::STATUS_VALIDATED.", NOW(), ".(int)$user->id." ".
            "FROM ".MAIN_DB_PREFIX."facture f ".
            "LEFT JOIN ".MAIN_DB_PREFIX."faurex_shipment s ON s.fk_facture = f.rowid ".
            "WHERE f.fk_statut >= 1 AND s.rowid IS NULL";
        return $db->query($sql);
    }

    public function getRemainingForLine($fk_facturedet, $qty_invoiced)
    {
        $sql = "SELECT COALESCE(SUM(qty_delivered),0) AS s FROM ".MAIN_DB_PREFIX."faurex_shipment_line WHERE fk_shipment = ".(int)$this->id." AND fk_facturedet = ".(int)$fk_facturedet;
        $res = $this->db->query($sql);
        $obj = $this->db->fetch_object($res);
        return (float)$qty_invoiced - (float)$obj->s;
    }

    /**
     * Recalcule l'état: si totale qty_delivered par ligne == qty_invoiced pour toutes les lignes -> Livré
     */
    /**
     * Recalcule l'état en 2 requêtes (lignes facture + agrégat livraisons), sans charger l'objet Facture.
     */
    public function updateStatus()
    {
        $sql = "SELECT fd.rowid AS id, fd.qty, COALESCE(SUM(sl.qty_delivered),0) AS delivered ".
            "FROM ".MAIN_DB_PREFIX."facturedet fd ".
            "LEFT JOIN ".MAIN_DB_PREFIX."faurex_shipment_line sl ON sl.fk_facturedet = fd.rowid AND sl.fk_shipment = ".(int)$this->id." ".
            "WHERE fd.fk_facture = ".(int)$this->fk_facture." AND fd.fk_product IS NOT NULL AND fd.fk_product > 0 ".
            "GROUP BY fd.rowid, fd.qty";
        $res = $this->db->query($sql);
        $allDone = true; $hasAny = false; $hasLines = false;
        if ($res) while ($obj = $this->db->fetch_object($res)) {
            $hasLines = true;
            if ((float)$obj->delivered > 0) $hasAny = true;
            if ((float)$obj->delivered < (float)$obj->qty) $allDone = false;
        }
        $newStatus = self::STATUS_VALIDATED;
        if ($hasLines && $allDone && $hasAny) $newStatus = self::STATUS_DELIVERED;
        elseif ($hasAny) $newStatus = self::STATUS_PARTIAL;

        $this->db->query("UPDATE ".MAIN_DB_PREFIX."faurex_shipment SET status = ".(int)$newStatus." WHERE rowid = ".(int)$this->id);
        $this->status = $newStatus;
        return $newStatus;
    }

    public function deleteLine($lineid, $user = null)
    {
        global $user;
        // Récupérer ligne pour annuler le mouvement de stock
        $sql0 = "SELECT fk_product, fk_warehouse, qty_delivered FROM ".MAIN_DB_PREFIX."faurex_shipment_line WHERE rowid = ".(int)$lineid." AND fk_shipment = ".(int)$this->id;
        $r0 = $this->db->query($sql0);
        $line = $r0 ? $this->db->fetch_object($r0) : null;

        $sql = "DELETE FROM ".MAIN_DB_PREFIX."faurex_shipment_line WHERE rowid = ".(int)$lineid." AND fk_shipment = ".(int)$this->id;
        if (!$this->db->query($sql)) return -1;

        // Réintégrer la qté en stock (entrée)
        if ($line && $line->fk_product && $line->fk_warehouse && $line->qty_delivered > 0) {
            $this->stockMove($user, $line->fk_product, $line->fk_warehouse, $line->qty_delivered, 0, 'Annulation expédition '.$this->ref);
        }

        $this->updateStatus();
        return 1;
    }

    public function updateLineQty($lineid, $qty_delivered, $user = null)
    {
        global $user;
        $qty_delivered = (float)$qty_delivered;
        if ($qty_delivered <= 0) return -3;
        $sql = "SELECT fk_facturedet, fk_product, fk_warehouse, qty_invoiced, qty_delivered FROM ".MAIN_DB_PREFIX."faurex_shipment_line WHERE rowid = ".(int)$lineid." AND fk_shipment = ".(int)$this->id;
        $res = $this->db->query($sql);
        if (!$res) return -1;
        $obj = $this->db->fetch_object($res);
        if (!$obj) return -1;
        $sql2 = "SELECT COALESCE(SUM(qty_delivered),0) AS s FROM ".MAIN_DB_PREFIX."faurex_shipment_line WHERE fk_shipment = ".(int)$this->id." AND fk_facturedet = ".(int)$obj->fk_facturedet." AND rowid <> ".(int)$lineid;
        $r2 = $this->db->query($sql2);
        $o2 = $this->db->fetch_object($r2);
        $remaining = (float)$obj->qty_invoiced - (float)$o2->s;
        if ($qty_delivered > $remaining) return -4;
        $sql3 = "UPDATE ".MAIN_DB_PREFIX."faurex_shipment_line SET qty_delivered = ".$qty_delivered." WHERE rowid = ".(int)$lineid;
        if (!$this->db->query($sql3)) return -1;

        // Ajuster le stock : delta = nouvelle - ancienne ; si > 0 → sortie supplémentaire ; si < 0 → réintégration
        $delta = $qty_delivered - (float)$obj->qty_delivered;
        if ($delta != 0 && $obj->fk_product && $obj->fk_warehouse) {
            if ($delta > 0) {
                $this->stockMove($user, $obj->fk_product, $obj->fk_warehouse, $delta, 1, 'Modif expédition '.$this->ref);
            } else {
                $this->stockMove($user, $obj->fk_product, $obj->fk_warehouse, -$delta, 0, 'Modif expédition '.$this->ref);
            }
        }

        $this->updateStatus();
        return 1;
    }

    /**
     * Annule toute l'expédition : supprime toutes les lignes (et restaure le stock).
     * Statut repasse à "Validé".
     */
    public function cancelAll($user = null)
    {
        global $user;
        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."faurex_shipment_line WHERE fk_shipment = ".(int)$this->id;
        $res = $this->db->query($sql);
        if ($res) {
            while ($obj = $this->db->fetch_object($res)) {
                $this->deleteLine($obj->rowid, $user);
            }
        }
        $sql2 = "UPDATE ".MAIN_DB_PREFIX."faurex_shipment SET status = ".self::STATUS_VALIDATED." WHERE rowid = ".(int)$this->id;
        $this->db->query($sql2);
        $this->status = self::STATUS_VALIDATED;
        return 1;
    }

    public function updatePlannedDate($date_planned)
    {
        $sql = "UPDATE ".MAIN_DB_PREFIX."faurex_shipment SET date_planned = ".($date_planned ? "'".$this->db->idate($date_planned)."'" : "NULL")." WHERE rowid = ".(int)$this->id;
        return $this->db->query($sql) ? 1 : -1;
    }
}
