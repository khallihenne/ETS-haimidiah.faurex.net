<?php
/**
 * Classe métier Faurex Reception (Réception liée à une facture fournisseur)
 */

class FaurexReception
{
    public $db;
    public $id;
    public $ref;
    public $fk_facture_fourn;
    public $fk_soc;
    public $date_facture;
    public $date_planned;
    public $status;
    public $note;
    public $lines = array();

    const STATUS_VALIDATED = 0;
    const STATUS_PARTIAL   = 1;
    const STATUS_RECEIVED  = 2;

    public function __construct($db) { $this->db = $db; }

    public static function getStatusLabel($status)
    {
        switch ((int)$status) {
            case self::STATUS_VALIDATED: return 'Validé';
            case self::STATUS_PARTIAL:   return 'Partiel';
            case self::STATUS_RECEIVED:  return 'Reçu';
        }
        return '-';
    }

    public function fetch($id)
    {
        $sql = "SELECT rowid, ref, fk_facture_fourn, fk_soc, date_facture, date_planned, status, note FROM ".MAIN_DB_PREFIX."faurex_reception WHERE rowid = ".(int)$id;
        $res = $this->db->query($sql);
        if (!$res) return -1;
        $obj = $this->db->fetch_object($res);
        if (!$obj) return 0;
        $this->id = $obj->rowid;
        $this->ref = $obj->ref;
        $this->fk_facture_fourn = $obj->fk_facture_fourn;
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
        $sql = "SELECT rowid, fk_facture_fourn_det, fk_product, qty_invoiced, qty_received, fk_warehouse, date_reception, mode_reglement, account_credit FROM ".MAIN_DB_PREFIX."faurex_reception_line WHERE fk_reception = ".(int)$this->id." ORDER BY rowid ASC";
        $res = $this->db->query($sql);
        if (!$res) return -1;
        while ($obj = $this->db->fetch_object($res)) $this->lines[] = $obj;
        return count($this->lines);
    }

    public static function createFromInvoice($db, $facture, $user)
    {
        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."faurex_reception WHERE fk_facture_fourn = ".(int)$facture->id;
        $res = $db->query($sql);
        if ($res && $db->num_rows($res) > 0) return 0;

        $ref = 'REC'.dol_print_date(dol_now(), '%Y%m%d').'-'.((int)$facture->id);
        $sql = "INSERT INTO ".MAIN_DB_PREFIX."faurex_reception (ref, fk_facture_fourn, fk_soc, date_facture, status, date_creation, fk_user_creat) VALUES (".
            "'".$db->escape($ref)."',".
            (int)$facture->id.",".
            (int)$facture->socid.",".
            "'".$db->idate($facture->date)."',".
            self::STATUS_VALIDATED.",".
            "'".$db->idate(dol_now())."',".
            (int)$user->id.")";
        if (!$db->query($sql)) return -1;
        return $db->last_insert_id(MAIN_DB_PREFIX.'faurex_reception');
    }

    public function addLine($fk_fdet, $fk_product, $qty_invoiced, $qty_received, $date_reception, $mode_reglement, $account_credit, $user, $fk_warehouse = 0)
    {
        if ($this->status == self::STATUS_RECEIVED) return -2;
        $qty_received = (float)$qty_received;
        if ($qty_received <= 0) return -3;

        $remaining = $this->getRemainingForLine($fk_fdet, $qty_invoiced);
        if ($qty_received > $remaining) return -4;

        $sql = "INSERT INTO ".MAIN_DB_PREFIX."faurex_reception_line (fk_reception, fk_facture_fourn_det, fk_product, qty_invoiced, qty_received, fk_warehouse, date_reception, mode_reglement, account_credit, date_creation, fk_user_creat) VALUES (".
            (int)$this->id.",".
            (int)$fk_fdet.",".
            ($fk_product ? (int)$fk_product : 'NULL').",".
            (float)$qty_invoiced.",".
            $qty_received.",".
            ($fk_warehouse ? (int)$fk_warehouse : 'NULL').",".
            ($date_reception ? "'".$this->db->idate($date_reception)."'" : "NULL").",".
            "'".$this->db->escape($mode_reglement)."',".
            "'".$this->db->escape($account_credit)."',".
            "'".$this->db->idate(dol_now())."',".
            (int)$user->id.")";
        if (!$this->db->query($sql)) {
            $err = $this->db->lasterror();
// tentative auto : si la colonne fk_warehouse manque, on l'ajoute
            if (stripos($err, 'fk_warehouse') !== false) {
                $this->db->query("ALTER TABLE ".MAIN_DB_PREFIX."faurex_reception_line ADD COLUMN fk_warehouse INT DEFAULT NULL");
                if (!$this->db->query($sql)) return -1;
            } else {
                return -1;
            }
        }

        // Mouvement de stock : entrée
        if ($fk_product && $fk_warehouse) {
            $this->stockMove($user, $fk_product, $fk_warehouse, $qty_received, 0, 'Réception '.$this->ref);
        }

        $this->updateStatus();
        return 1;
    }

    protected function stockMove($user, $fk_product, $fk_warehouse, $qty, $type, $label)
    {
        require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
        $prod = new Product($this->db);
        if ($prod->fetch($fk_product) <= 0) return -1;
        return $prod->correct_stock($user, $fk_warehouse, abs((float)$qty), $type, $label, 0, dol_now());
    }

    public function getReceivedByLine()
    {
        $out = array();
        $sql = "SELECT fk_facture_fourn_det, COALESCE(SUM(qty_received),0) AS s FROM ".MAIN_DB_PREFIX."faurex_reception_line WHERE fk_reception = ".(int)$this->id." GROUP BY fk_facture_fourn_det";
        $res = $this->db->query($sql);
        if ($res) while ($obj = $this->db->fetch_object($res)) $out[(int)$obj->fk_facture_fourn_det] = (float)$obj->s;
        return $out;
    }

    public static function syncFromValidatedInvoices($db, $user)
    {
        $sql = "INSERT INTO ".MAIN_DB_PREFIX."faurex_reception (ref, fk_facture_fourn, fk_soc, date_facture, status, date_creation, fk_user_creat) ".
            "SELECT CONCAT('REC', DATE_FORMAT(NOW(),'%Y%m%d'), '-', f.rowid), f.rowid, f.fk_soc, f.datef, ".self::STATUS_VALIDATED.", NOW(), ".(int)$user->id." ".
            "FROM ".MAIN_DB_PREFIX."facture_fourn f ".
            "LEFT JOIN ".MAIN_DB_PREFIX."faurex_reception r ON r.fk_facture_fourn = f.rowid ".
            "WHERE f.fk_statut >= 1 AND r.rowid IS NULL";
        return $db->query($sql);
    }

    public function getRemainingForLine($fk_fdet, $qty_invoiced)
    {
        $sql = "SELECT COALESCE(SUM(qty_received),0) AS s FROM ".MAIN_DB_PREFIX."faurex_reception_line WHERE fk_reception = ".(int)$this->id." AND fk_facture_fourn_det = ".(int)$fk_fdet;
        $res = $this->db->query($sql);
        $obj = $this->db->fetch_object($res);
        return (float)$qty_invoiced - (float)$obj->s;
    }

    public function updateStatus()
    {
        $sql = "SELECT fd.rowid AS id, fd.qty, COALESCE(SUM(rl.qty_received),0) AS received ".
            "FROM ".MAIN_DB_PREFIX."facture_fourn_det fd ".
            "LEFT JOIN ".MAIN_DB_PREFIX."faurex_reception_line rl ON rl.fk_facture_fourn_det = fd.rowid AND rl.fk_reception = ".(int)$this->id." ".
            "WHERE fd.fk_facture_fourn = ".(int)$this->fk_facture_fourn." AND fd.fk_product IS NOT NULL AND fd.fk_product > 0 ".
            "GROUP BY fd.rowid, fd.qty";
        $res = $this->db->query($sql);
        $allDone = true; $hasAny = false; $hasLines = false;
        if ($res) while ($obj = $this->db->fetch_object($res)) {
            $hasLines = true;
            if ((float)$obj->received > 0) $hasAny = true;
            if ((float)$obj->received < (float)$obj->qty) $allDone = false;
        }
        $newStatus = self::STATUS_VALIDATED;
        if ($hasLines && $allDone && $hasAny) $newStatus = self::STATUS_RECEIVED;
        elseif ($hasAny) $newStatus = self::STATUS_PARTIAL;
        $this->db->query("UPDATE ".MAIN_DB_PREFIX."faurex_reception SET status = ".(int)$newStatus." WHERE rowid = ".(int)$this->id);
        $this->status = $newStatus;
        return $newStatus;
    }

    public function deleteLine($lineid, $user = null)
    {
        global $user;
        $sql0 = "SELECT fk_product, fk_warehouse, qty_received FROM ".MAIN_DB_PREFIX."faurex_reception_line WHERE rowid = ".(int)$lineid." AND fk_reception = ".(int)$this->id;
        $r0 = $this->db->query($sql0);
        $line = $r0 ? $this->db->fetch_object($r0) : null;

        $sql = "DELETE FROM ".MAIN_DB_PREFIX."faurex_reception_line WHERE rowid = ".(int)$lineid." AND fk_reception = ".(int)$this->id;
        if (!$this->db->query($sql)) return -1;

        // Annuler l'entrée de stock → sortie
        if ($line && $line->fk_product && $line->fk_warehouse && $line->qty_received > 0) {
            $this->stockMove($user, $line->fk_product, $line->fk_warehouse, $line->qty_received, 1, 'Annulation réception '.$this->ref);
        }

        $this->updateStatus();
        return 1;
    }

    public function updateLineQty($lineid, $qty_received, $user = null)
    {
        global $user;
        $qty_received = (float)$qty_received;
        if ($qty_received <= 0) return -3;
        $sql = "SELECT fk_facture_fourn_det, fk_product, fk_warehouse, qty_invoiced, qty_received FROM ".MAIN_DB_PREFIX."faurex_reception_line WHERE rowid = ".(int)$lineid." AND fk_reception = ".(int)$this->id;
        $res = $this->db->query($sql);
        if (!$res) return -1;
        $obj = $this->db->fetch_object($res);
        if (!$obj) return -1;
        $sql2 = "SELECT COALESCE(SUM(qty_received),0) AS s FROM ".MAIN_DB_PREFIX."faurex_reception_line WHERE fk_reception = ".(int)$this->id." AND fk_facture_fourn_det = ".(int)$obj->fk_facture_fourn_det." AND rowid <> ".(int)$lineid;
        $r2 = $this->db->query($sql2);
        $o2 = $this->db->fetch_object($r2);
        $remaining = (float)$obj->qty_invoiced - (float)$o2->s;
        if ($qty_received > $remaining) return -4;
        $sql3 = "UPDATE ".MAIN_DB_PREFIX."faurex_reception_line SET qty_received = ".$qty_received." WHERE rowid = ".(int)$lineid;
        if (!$this->db->query($sql3)) return -1;

        $delta = $qty_received - (float)$obj->qty_received;
        if ($delta != 0 && $obj->fk_product && $obj->fk_warehouse) {
            if ($delta > 0) {
                $this->stockMove($user, $obj->fk_product, $obj->fk_warehouse, $delta, 0, 'Modif réception '.$this->ref);
            } else {
                $this->stockMove($user, $obj->fk_product, $obj->fk_warehouse, -$delta, 1, 'Modif réception '.$this->ref);
            }
        }

        $this->updateStatus();
        return 1;
    }

    public function cancelAll($user = null)
    {
        global $user;
        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."faurex_reception_line WHERE fk_reception = ".(int)$this->id;
        $res = $this->db->query($sql);
        if ($res) {
            while ($obj = $this->db->fetch_object($res)) {
                $this->deleteLine($obj->rowid, $user);
            }
        }
        $sql2 = "UPDATE ".MAIN_DB_PREFIX."faurex_reception SET status = ".self::STATUS_VALIDATED." WHERE rowid = ".(int)$this->id;
        $this->db->query($sql2);
        $this->status = self::STATUS_VALIDATED;
        return 1;
    }

    public function updatePlannedDate($date_planned)
    {
        $sql = "UPDATE ".MAIN_DB_PREFIX."faurex_reception SET date_planned = ".($date_planned ? "'".$this->db->idate($date_planned)."'" : "NULL")." WHERE rowid = ".(int)$this->id;
        return $this->db->query($sql) ? 1 : -1;
    }
}
