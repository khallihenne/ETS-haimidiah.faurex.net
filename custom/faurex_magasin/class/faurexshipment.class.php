<?php
/**
 * Classe métier Faurex Shipment (Expédition liée à une facture client)
 */

require_once __DIR__.'/../lib/faurex_magasin.lib.php';

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
    public $stockMoveNote = ''; // texte ajouté au libellé des mouvements de stock (ex : " - facture FV1588 supprimée")

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
     * Ajoute une ligne d'expédition (avec contrôle de stock disponible)
     */
    public function addLine($fk_facturedet, $fk_product, $qty_invoiced, $qty_delivered, $date_shipment, $mode_reglement, $account_credit, $user, $fk_warehouse = 0)
    {
        // Recalcule l'état avant de vérifier (corrige un statut "Livré" resté faux)
        $this->updateStatus();
        if ($this->status == self::STATUS_DELIVERED) return -2;

        $qty_delivered = (float)$qty_delivered;
        if ($qty_delivered <= 0) return -3;

        $remaining = $this->getRemainingForLine($fk_facturedet, $qty_invoiced);
        if ($qty_delivered > $remaining) return -4;

        // --- DÉBUT BLOCAGE STOCK NÉGATIF (POINTS 1 & 3) ---
        if ($fk_product && $fk_warehouse) {
            $sql_stock = "SELECT reel FROM ".MAIN_DB_PREFIX."product_stock WHERE fk_product = ".(int)$fk_product." AND fk_entrepot = ".(int)$fk_warehouse;
            $res_stock = $this->db->query($sql_stock);
            $stock_reel = 0;
            if ($res_stock && $obj_stock = $this->db->fetch_object($res_stock)) {
                $stock_reel = (float)$obj_stock->reel;
            }
            if ($qty_delivered > $stock_reel) {
                return -5; // Stock insuffisant dans l'entrepôt sélectionné
            }
        }
        // --- FIN BLOCAGE STOCK NÉGATIF ---

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
     * Synchronisation par lots
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
        $delivered = $obj ? (float)$obj->s : 0;
        return max(0, (float)$qty_invoiced - $delivered);
    }

    /**
     * Recalcule l'état de l'expédition
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

    /**
     * Supprime une ligne d'expédition, remet la quantité en stock et l'enregistre dans l'historique.
     *
     * @param  int    $lineid
     * @param  User   $user
     * @param  string $motif   Motif de la suppression (historique)
     * @param  string $action  Code historique (DELETE_LINE ou CANCEL_ALL)
     * @return int    1 OK, -1 erreur
     */
    public function deleteLine($lineid, $user = null, $motif = '', $action = 'DELETE_LINE')
    {
        if (!is_object($user)) $user = $GLOBALS['user'];

        $sql0 = "SELECT fk_product, fk_warehouse, qty_delivered FROM ".MAIN_DB_PREFIX."faurex_shipment_line WHERE rowid = ".(int)$lineid." AND fk_shipment = ".(int)$this->id;
        $r0 = $this->db->query($sql0);
        $line = $r0 ? $this->db->fetch_object($r0) : null;
        if (!$line) return -1;

        $sql = "DELETE FROM ".MAIN_DB_PREFIX."faurex_shipment_line WHERE rowid = ".(int)$lineid." AND fk_shipment = ".(int)$this->id;
        if (!$this->db->query($sql)) return -1;

        // Réintégrer la quantité en stock (entrée)
        if ($line->fk_product && $line->fk_warehouse && $line->qty_delivered > 0) {
            $this->stockMove($user, $line->fk_product, $line->fk_warehouse, $line->qty_delivered, 0, 'Annulation expédition '.$this->ref.$this->stockMoveNote);
        }

        faurex_history_log($this->db, $user, 'shipment', $this->id, $this->ref, $action, $line->fk_product, $line->fk_warehouse, (float)$line->qty_delivered, 0, $motif);

        $this->updateStatus();
        return 1;
    }

    /**
     * Modifie la quantité livrée d'une ligne (avec contrôle du stock de l'entrepôt).
     *
     * @return int 1 OK, -1 erreur, -3 qté invalide, -4 dépasse le reste, -5 stock insuffisant
     */
    public function updateLineQty($lineid, $qty_delivered, $user = null)
    {
        if (!is_object($user)) $user = $GLOBALS['user'];
        $qty_delivered = (float)$qty_delivered;
        if ($qty_delivered <= 0) return -3;

        $sql = "SELECT fk_facturedet, fk_product, fk_warehouse, qty_invoiced, qty_delivered FROM ".MAIN_DB_PREFIX."faurex_shipment_line WHERE rowid = ".(int)$lineid." AND fk_shipment = ".(int)$this->id;
        $res = $this->db->query($sql);
        if (!$res) return -1;
        $obj = $this->db->fetch_object($res);
        if (!$obj) return -1;

        $sql2 = "SELECT COALESCE(SUM(qty_delivered),0) AS s FROM ".MAIN_DB_PREFIX."faurex_shipment_line WHERE fk_shipment = ".(int)$this->id." AND fk_facturedet = ".(int)$obj->fk_facturedet." AND rowid <> ".(int)$lineid;
        $r2 = $this->db->query($sql2);
        $o2 = $r2 ? $this->db->fetch_object($r2) : null;
        $other_delivered = $o2 ? (float)$o2->s : 0;
        $remaining = (float)$obj->qty_invoiced - $other_delivered;
        if ($qty_delivered > $remaining) return -4;

        $delta = $qty_delivered - (float)$obj->qty_delivered;
        if ($delta == 0) return 1;

        // Sortie supplémentaire : le stock de l'entrepôt doit suffire
        if ($delta > 0 && $obj->fk_product && $obj->fk_warehouse) {
            if ($delta > faurex_get_warehouse_stock($this->db, $obj->fk_product, $obj->fk_warehouse)) {
                return -5;
            }
        }

        $sql3 = "UPDATE ".MAIN_DB_PREFIX."faurex_shipment_line SET qty_delivered = ".$qty_delivered." WHERE rowid = ".(int)$lineid;
        if (!$this->db->query($sql3)) return -1;

        if ($obj->fk_product && $obj->fk_warehouse) {
            if ($delta > 0) {
                $this->stockMove($user, $obj->fk_product, $obj->fk_warehouse, $delta, 1, 'Modif expédition '.$this->ref);
            } else {
                $this->stockMove($user, $obj->fk_product, $obj->fk_warehouse, -$delta, 0, 'Modif expédition '.$this->ref);
            }
        }

        faurex_history_log($this->db, $user, 'shipment', $this->id, $this->ref, 'UPDATE_LINE', $obj->fk_product, $obj->fk_warehouse, (float)$obj->qty_delivered, $qty_delivered, '');

        $this->updateStatus();
        return 1;
    }

    /**
     * Annule toute l'expédition : supprime toutes les lignes (et restaure le stock).
     * Statut repasse à "Validé".
     *
     * @param  User   $user
     * @param  string $motif  Motif de l'annulation (historique)
     * @return int    1 OK
     */
    public function cancelAll($user = null, $motif = '')
    {
        if (!is_object($user)) $user = $GLOBALS['user'];

        $ids = array();
        $res = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."faurex_shipment_line WHERE fk_shipment = ".(int)$this->id);
        if ($res) {
            while ($obj = $this->db->fetch_object($res)) $ids[] = (int)$obj->rowid;
        }
        foreach ($ids as $lid) {
            $this->deleteLine($lid, $user, $motif, 'CANCEL_ALL');
        }
        $this->db->query("UPDATE ".MAIN_DB_PREFIX."faurex_shipment SET status = ".self::STATUS_VALIDATED." WHERE rowid = ".(int)$this->id);
        $this->status = self::STATUS_VALIDATED;
        return 1;
    }

    /**
     * Corrige les expéditions marquées "Livré" alors qu'il reste à livrer.
     * Une requête rapide trouve les expéditions concernées, puis chacune est recalculée.
     * (Les autres statuts sont déjà recalculés à chaque ouverture de fiche et à chaque livraison.)
     *
     * @param  DoliDB $db
     * @return int    Nombre d'expéditions corrigées
     */
    public static function recalcAllStatuses($db)
    {
        $sql = "SELECT DISTINCT s.rowid".
            " FROM ".MAIN_DB_PREFIX."faurex_shipment s".
            " INNER JOIN ".MAIN_DB_PREFIX."facturedet fd ON fd.fk_facture = s.fk_facture AND fd.fk_product > 0".
            " LEFT JOIN (SELECT fk_facturedet, SUM(qty_delivered) AS livre".
            "            FROM ".MAIN_DB_PREFIX."faurex_shipment_line GROUP BY fk_facturedet) d ON d.fk_facturedet = fd.rowid".
            " WHERE s.status = ".self::STATUS_DELIVERED.
            " AND fd.qty - COALESCE(d.livre, 0) > 0";
        $res = $db->query($sql);
        if (!$res) return 0;
        $ids = array();
        while ($obj = $db->fetch_object($res)) $ids[] = (int) $obj->rowid;

        $nb = 0;
        foreach ($ids as $id) {
            $ship = new self($db);
            if ($ship->fetch($id) > 0) {
                $ship->updateStatus();
                $nb++;
            }
        }
        return $nb;
    }

    public function updatePlannedDate($date_planned)
    {
        $sql = "UPDATE ".MAIN_DB_PREFIX."faurex_shipment SET date_planned = ".($date_planned ? "'".$this->db->idate($date_planned)."'" : "NULL")." WHERE rowid = ".(int)$this->id;
        return $this->db->query($sql) ? 1 : -1;
    }
}