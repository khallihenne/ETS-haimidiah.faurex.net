<?php
/**
 * Fonctions communes Faurex Magasin :
 *  - stock disponible d'un produit dans un entrepôt
 *  - historique des suppressions / annulations / modifications
 */

/**
 * Stock réel d'un produit dans un entrepôt donné.
 *
 * @param  DoliDB $db
 * @param  int    $fk_product
 * @param  int    $fk_warehouse
 * @return float
 */
function faurex_get_warehouse_stock($db, $fk_product, $fk_warehouse)
{
    $sql = "SELECT reel FROM ".MAIN_DB_PREFIX."product_stock";
    $sql .= " WHERE fk_product = ".((int) $fk_product)." AND fk_entrepot = ".((int) $fk_warehouse);
    $res = $db->query($sql);
    if ($res && ($obj = $db->fetch_object($res))) {
        return (float) $obj->reel;
    }
    return 0;
}

/**
 * Crée la table d'historique si elle n'existe pas encore
 * (évite de devoir réactiver le module après la mise à jour).
 *
 * @param  DoliDB $db
 * @return void
 */
function faurex_history_ensure_table($db)
{
    static $done = false;
    if ($done) {
        return;
    }
    $sql = "CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."faurex_history (";
    $sql .= " rowid INT AUTO_INCREMENT PRIMARY KEY,";
    $sql .= " element VARCHAR(16) NOT NULL,";
    $sql .= " fk_element INT NOT NULL,";
    $sql .= " element_ref VARCHAR(64),";
    $sql .= " action VARCHAR(32) NOT NULL,";
    $sql .= " fk_product INT DEFAULT NULL,";
    $sql .= " fk_warehouse INT DEFAULT NULL,";
    $sql .= " qty_before DOUBLE DEFAULT NULL,";
    $sql .= " qty_after DOUBLE DEFAULT NULL,";
    $sql .= " motif TEXT,";
    $sql .= " date_action DATETIME NOT NULL,";
    $sql .= " fk_user INT DEFAULT NULL,";
    $sql .= " KEY idx_faurex_history_el (element, fk_element),";
    $sql .= " KEY idx_faurex_history_date (date_action)";
    $sql .= ") ENGINE=InnoDB";
    $db->query($sql);
    $done = true;
}

/**
 * Enregistre une opération dans l'historique.
 *
 * @param DoliDB $db
 * @param User   $user
 * @param string $element      'shipment' ou 'reception'
 * @param int    $fk_element   Id de l'expédition / réception
 * @param string $element_ref  Référence (EXP..., REC...)
 * @param string $action       DELETE_LINE, CANCEL_ALL, UPDATE_LINE
 * @param int    $fk_product
 * @param int    $fk_warehouse
 * @param float  $qty_before
 * @param float  $qty_after
 * @param string $motif
 * @return int   1 OK, -1 erreur
 */
function faurex_history_log($db, $user, $element, $fk_element, $element_ref, $action, $fk_product, $fk_warehouse, $qty_before, $qty_after, $motif = '')
{
    faurex_history_ensure_table($db);

    $sql = "INSERT INTO ".MAIN_DB_PREFIX."faurex_history";
    $sql .= " (element, fk_element, element_ref, action, fk_product, fk_warehouse, qty_before, qty_after, motif, date_action, fk_user)";
    $sql .= " VALUES (";
    $sql .= "'".$db->escape($element)."',";
    $sql .= ((int) $fk_element).",";
    $sql .= "'".$db->escape($element_ref)."',";
    $sql .= "'".$db->escape($action)."',";
    $sql .= ($fk_product ? (int) $fk_product : "NULL").",";
    $sql .= ($fk_warehouse ? (int) $fk_warehouse : "NULL").",";
    $sql .= ($qty_before === null ? "NULL" : (float) $qty_before).",";
    $sql .= ($qty_after === null ? "NULL" : (float) $qty_after).",";
    $sql .= "'".$db->escape($motif)."',";
    $sql .= "'".$db->idate(dol_now())."',";
    $sql .= (is_object($user) ? (int) $user->id : "NULL");
    $sql .= ")";

    if (!$db->query($sql)) {
        dol_syslog(__FUNCTION__." ".$db->lasterror(), LOG_ERR);
        return -1;
    }
    return 1;
}

/**
 * Libellé lisible d'une action d'historique.
 *
 * @param  string $action
 * @return string
 */
function faurex_history_action_label($action)
{
    switch ($action) {
        case 'DELETE_LINE':
            return 'Suppression de ligne';
        case 'CANCEL_ALL':
            return 'Annulation complète';
        case 'UPDATE_LINE':
            return 'Modification de quantité';
    }
    return $action;
}

/**
 * Récupère et valide le motif saisi (obligatoire pour supprimer / annuler).
 *
 * @return string  Motif nettoyé, ou '' s'il est absent / trop court
 */
function faurex_get_motif()
{
    $motif = trim(GETPOST('motif', 'alphanohtml'));
    if (dol_strlen($motif) < 3) {
        return '';
    }
    return dol_trunc($motif, 250, 'right', 'UTF-8', 1);
}