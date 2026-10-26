<?php
/* Copyright (C) 2025 Your Company
 *
 * This program is free software
 */

/**
 * \file    class/payrun.class.php
 * \ingroup payroll
 * \brief   PayRun class file
 */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';

/**
 * Class for PayRun
 */
class PayRun extends CommonObject
{
    /**
     * @var string ID to identify managed object
     */
    public $element = 'payrun';

    /**
     * @var string Name of table without prefix where object is stored
     */
    public $table_element = 'payroll_payrun';

    /**
     * @var int Does object support multicompany module? 0=No test on entity, 1=Test with field entity
     */
    public $ismultientitymanaged = 1;

    /**
     * @var int Does object support extrafields ? 0=No, 1=Yes
     */
    public $isextrafieldmanaged = 0;

    /**
     * @var string String with name of icon for payrun
     */
    public $picto = 'generic';

    const STATUS_DRAFT = 0;
    const STATUS_VALIDATED = 1;
    const STATUS_PAID = 2;

    /**
     * @var array Array of fields (key => array of field info)
     */
    public $fields = array(
        'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'visible' => -2, 'position' => 1),
        'ref' => array('type' => 'varchar(128)', 'label' => 'Ref', 'enabled' => 1, 'visible' => 1, 'position' => 10, 'notnull' => 1, 'showoncombobox' => 1),
        'label' => array('type' => 'varchar(255)', 'label' => 'Label', 'enabled' => 1, 'visible' => 1, 'position' => 20),
        'period_month' => array('type' => 'integer', 'label' => 'Month', 'enabled' => 1, 'visible' => 1, 'position' => 30, 'notnull' => 1),
        'period_year' => array('type' => 'integer', 'label' => 'Year', 'enabled' => 1, 'visible' => 1, 'position' => 40, 'notnull' => 1),
        'status' => array('type' => 'integer', 'label' => 'Status', 'enabled' => 1, 'visible' => 1, 'position' => 500, 'notnull' => 1, 'default' => '0'),
        'total_gross' => array('type' => 'double(24,8)', 'label' => 'TotalGross', 'enabled' => 1, 'visible' => 1, 'position' => 50),
        'total_advances' => array('type' => 'double(24,8)', 'label' => 'TotalAdvances', 'enabled' => 1, 'visible' => 0, 'position' => 60),
        'total_deductions' => array('type' => 'double(24,8)', 'label' => 'TotalDeductions', 'enabled' => 1, 'visible' => 1, 'position' => 70),
        'total_bonuses' => array('type' => 'double(24,8)', 'label' => 'TotalBonuses', 'enabled' => 1, 'visible' => 1, 'position' => 80),
        'total_net' => array('type' => 'double(24,8)', 'label' => 'TotalNet', 'enabled' => 1, 'visible' => 1, 'position' => 90),
        'note_public' => array('type' => 'html', 'label' => 'NotePublic', 'enabled' => 1, 'visible' => 0, 'position' => 61),
        'note_private' => array('type' => 'html', 'label' => 'NotePrivate', 'enabled' => 1, 'visible' => 0, 'position' => 62),
        'date_creation' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => 1, 'visible' => -2, 'position' => 500),
        'tms' => array('type' => 'timestamp', 'label' => 'DateModification', 'enabled' => 1, 'visible' => -2, 'position' => 501),
        'fk_user_creat' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserAuthor', 'enabled' => 1, 'visible' => -2, 'position' => 510),
        'fk_user_modif' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserModif', 'enabled' => 1, 'visible' => -2, 'position' => 511),
        'import_key' => array('type' => 'varchar(14)', 'label' => 'ImportId', 'enabled' => 1, 'visible' => -2, 'position' => 1000),
        'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => 1, 'visible' => 0, 'position' => 30, 'default' => 1)
    );

    public $rowid;
    public $ref;
    public $label;
    public $period_month;
    public $period_year;
    public $status;
    public $total_gross;
    public $total_advances;
    public $total_deductions;
    public $total_bonuses;
    public $total_net;
    public $note_public;
    public $note_private;
    public $date_creation;
    public $tms;
    public $fk_user_creat;
    public $fk_user_modif;
    public $import_key;
    public $entity;

    /**
     * Constructor
     *
     * @param DoliDb $db Database handler
     */
    public function __construct(DoliDB $db)
    {
        global $conf, $langs;

        $this->db = $db;

        if (empty($conf->global->MAIN_SHOW_TECHNICAL_ID) && isset($this->fields['rowid'])) {
            $this->fields['rowid']['visible'] = 0;
        }
        if (empty($conf->multicompany->enabled) && isset($this->fields['entity'])) {
            $this->fields['entity']['enabled'] = 0;
        }
    }

    /**
     * Create object into database
     *
     * @param User $user User that creates
     * @param bool $notrigger false=launch triggers after, true=disable triggers
     * @return int <0 if KO, ID of created object if OK
     */
    public function create(User $user, $notrigger = false)
    {
        global $conf;

        $this->entity = ((isset($this->entity) && is_numeric($this->entity)) ? $this->entity : $conf->entity);

        // Generate ref if not set
        if (empty($this->ref)) {
            $this->ref = $this->getNextNumRef();
        }

        return $this->createCommon($user, $notrigger);
    }

    /**
     * Load object in memory from the database
     *
     * @param int $id Id object
     * @param string $ref Ref
     * @return int <0 if KO, 0 if not found, >0 if OK
     */
    public function fetch($id, $ref = null)
    {
        $result = $this->fetchCommon($id, $ref);
        return $result;
    }

    /**
     * Update object into database
     *
     * @param User $user User that modifies
     * @param bool $notrigger false=launch triggers after, true=disable triggers
     * @return int <0 if KO, >0 if OK
     */
    public function update(User $user, $notrigger = false)
    {
        return $this->updateCommon($user, $notrigger);
    }

    /**
     * Delete object in database
     *
     * @param User $user User that deletes
     * @param bool $notrigger false=launch triggers after, true=disable triggers
     * @return int <0 if KO, >0 if OK
     */
    public function delete(User $user, $notrigger = false)
    {
        require_once DOL_DOCUMENT_ROOT . '/custom/payroll/class/payslip.class.php';

        $this->db->begin();

        $error = 0;

        // First, delete all payslips associated with this payrun
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "payroll_payslip";
        $sql .= " WHERE fk_payrun = " . (int) $this->id;

        $resql = $this->db->query($sql);
        if ($resql) {
            $num = $this->db->num_rows($resql);
            $i = 0;

            while ($i < $num && !$error) {
                $obj = $this->db->fetch_object($resql);

                $payslip = new Payslip($this->db);
                $result = $payslip->fetch($obj->rowid);

                if ($result > 0) {
                    $result = $payslip->delete($user, $notrigger);
                    if ($result < 0) {
                        $error++;
                        $this->error = $payslip->error;
                        $this->errors = $payslip->errors;
                    }
                }

                $i++;
            }
        } else {
            $error++;
            $this->error = $this->db->lasterror();
        }

        // Then delete the payrun itself
        if (!$error) {
            $result = $this->deleteCommon($user, $notrigger);
            if ($result < 0) {
                $error++;
            }
        }

        if (!$error) {
            $this->db->commit();
            return 1;
        } else {
            $this->db->rollback();
            return -1;
        }
    }

    /**
     * Generate next reference
     *
     * @return string Next reference
     */
    public function getNextNumRef()
    {
        global $conf;

        $prefix = 'PR';
        $year = date('Y');

        $sql = "SELECT MAX(CAST(SUBSTRING(ref FROM " . (strlen($prefix . $year) + 2) . ") AS SIGNED)) as max";
        $sql .= " FROM " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " WHERE ref LIKE '" . $this->db->escape($prefix . $year) . "-%'";
        $sql .= " AND entity = " . $conf->entity;

        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            $max = $obj->max;
            $num = $max + 1;
        } else {
            $num = 1;
        }

        return $prefix . $year . '-' . str_pad($num, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Validate payrun
     *
     * @param User $user User validating
     * @param int $notrigger 1=Does not execute triggers
     * @return int <0 if KO, 0 if nothing done, >0 if OK
     */
    public function setValidated($user, $notrigger = 0)
    {
        global $conf, $langs;

        $error = 0;

        // Protection
        if ($this->status == self::STATUS_VALIDATED) {
            dol_syslog(get_class($this) . "::validate action abandoned: already validated", LOG_WARNING);
            return 0;
        }

        $this->db->begin();

        // Validate all payslips in this payrun
        require_once DOL_DOCUMENT_ROOT . '/custom/payroll/class/payslip.class.php';
        $payslip = new Payslip($this->db);

        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "payroll_payslip";
        $sql .= " WHERE fk_payrun = " . (int) $this->id;
        $sql .= " AND status = 0";

        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $payslip->fetch($obj->rowid);
                $result = $payslip->setValidated($user, $notrigger);
                if ($result < 0) {
                    $error++;
                    $this->error = $payslip->error;
                    $this->errors = $payslip->errors;
                    break;
                }
            }
        } else {
            $error++;
            $this->error = $this->db->lasterror();
        }

        if (!$error) {
            $this->status = self::STATUS_VALIDATED;
            $result = $this->update($user, $notrigger);
            if ($result < 0) {
                $error++;
            }
        }

        if (!$error) {
            $this->db->commit();
            return 1;
        } else {
            $this->db->rollback();
            return -1;
        }
    }

    /**
     * Set payrun to draft status
     *
     * @param User $user User de-validating
     * @param int $notrigger 1=Does not execute triggers
     * @return int <0 if KO, 0 if nothing done, >0 if OK
     */
    public function setDraft($user, $notrigger = 0)
    {
        if ($this->status == self::STATUS_DRAFT) {
            return 0;
        }
        if ($this->status == self::STATUS_PAID) {
            $this->error = "Cannot revert to draft a paid payrun";
            return -1;
        }

        $this->db->begin();

        $error = 0;

        // Revert all payslips to draft
        require_once DOL_DOCUMENT_ROOT . '/custom/payroll/class/payslip.class.php';
        $payslip = new Payslip($this->db);

        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "payroll_payslip";
        $sql .= " WHERE fk_payrun = " . (int) $this->id;

        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $payslip->fetch($obj->rowid);
                $result = $payslip->setDraft($user, $notrigger);
                if ($result < 0) {
                    $error++;
                    $this->error = $payslip->error;
                    break;
                }
            }
        } else {
            $error++;
            $this->error = $this->db->lasterror();
        }

        if (!$error) {
            $this->status = self::STATUS_DRAFT;
            $result = $this->update($user, $notrigger);
            if ($result < 0) {
                $error++;
            }
        }

        if (!$error) {
            $this->db->commit();
            return 1;
        } else {
            $this->db->rollback();
            return -1;
        }
    }

    /**
     * Record payment for the payrun
     *
     * @param User $user User recording payment
     * @return int <0 if KO, 0 if nothing done, >0 if OK (number of payments recorded)
     */
    public function recordPayment($user)
    {
        global $conf, $langs;

        if ($this->status != self::STATUS_VALIDATED) {
            $this->error = "PayRun must be validated before recording payment";
            return -1;
        }

        require_once DOL_DOCUMENT_ROOT . '/custom/payroll/class/payslip.class.php';
        require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';

        $this->db->begin();

        $error = 0;
        $count = 0;

        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "payroll_payslip";
        $sql .= " WHERE fk_payrun = " . (int) $this->id;
        $sql .= " AND status = " . Payslip::STATUS_VALIDATED;

        $resql = $this->db->query($sql);
        if ($resql) {
            $ids = array();
            while ($obj = $this->db->fetch_object($resql)) {
                $ids[] = $obj->rowid;
            }
            $this->db->free($resql);

            if (empty($ids)) {
                $this->error = "No validated payslips found for this payrun. Status=" . $this->status;
                $this->db->rollback();
                return 0;
            }

            foreach ($ids as $payslip_id) {
                $payslip = new Payslip($this->db);
                $payslip->fetch($payslip_id);

                // Create bank/cash transaction
                if ($payslip->net_salary > 0) {
                    if ($payslip->fk_account <= 0) {
                        $error++;
                        $this->error = "Missing bank account for employee " . $payslip->fk_user . " (Ref: " . $payslip->ref . ")";
                        break;
                    }
                    if ($payslip->fk_mode_reglement <= 0) {
                        $error++;
                        $this->error = "Missing payment method for employee " . $payslip->fk_user . " (Ref: " . $payslip->ref . ")";
                        break;
                    }

                    // Get payment mode code (like 'VIR', 'CHQ', etc.)
                    $pay_mode_code = 'VIR';
                    $sql_mode = "SELECT code FROM " . MAIN_DB_PREFIX . "c_paiement WHERE id = " . (int) $payslip->fk_mode_reglement;
                    $resql_mode = $this->db->query($sql_mode);
                    if ($resql_mode) {
                        $obj_mode = $this->db->fetch_object($resql_mode);
                        if ($obj_mode) {
                            $pay_mode_code = $obj_mode->code;
                        }
                    }

                    $acc = new Account($this->db);
                    $acc->fetch($payslip->fk_account);

                    $payment_date = dol_now();
                    $label = $langs->trans("PayrollPayment") . " " . $this->ref . " - " . $payslip->ref;

                    // Add line in llx_bank
                    $bank_line_id = $acc->addline(
                        $payment_date,
                        $pay_mode_code,
                        $label,
                        -1 * $payslip->net_salary,
                        '',
                        0,
                        $user
                    );

                    if ($bank_line_id > 0) {
                        // Link bank line to payslip
                        $url = 'custom/payroll/payslip_card.php?id=' . $payslip->id;
                        $acc->add_url_line($bank_line_id, $payslip->id, $url, $payslip->ref, $payslip->element);

                        // Generate accounting entries for payment
                        if (!empty($conf->accounting->enabled)) {
                            $res_acc = $payslip->generatePaymentAccountingEntries($user, $payslip->fk_account, $payment_date, $bank_line_id);
                            if ($res_acc < 0) {
                                $error++;
                                $this->error = $payslip->error;
                                break;
                            }
                        }

                        // Mark payslip as paid
                        $result = $payslip->setPaid($user, $payment_date);
                        if ($result < 0) {
                            $error++;
                            $this->error = $payslip->error;
                            break;
                        }
                        $count++;
                    } else {
                        $error++;
                        $this->error = $acc->error;
                        if (empty($this->error))
                            $this->error = "Failed to create bank entry for slip " . $payslip->ref;
                        break;
                    }
                } elseif ($payslip->net_salary == 0) {
                    // Net zero, just mark as paid if no error
                    $payslip->setPaid($user);
                    $count++;
                }
            }
        } else {
            $error++;
            $this->error = $this->db->lasterror();
        }

        if (!$error && $count > 0) {
            $this->status = self::STATUS_PAID;
            $res = $this->update($user);
            if ($res < 0) {
                $error++;
            }
        }

        if (!$error) {
            $this->db->commit();
            return $count;
        } else {
            $this->db->rollback();
            return -1;
        }
    }

    /**
     * Generate payslips for all employees
     *
     * @param User $user User generating
     * @return int <0 if KO, number of payslips created if OK
     */
    public function generatePayslips($user)
    {
        global $conf;

        require_once DOL_DOCUMENT_ROOT . '/custom/payroll/class/payslip.class.php';
        require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';

        $this->db->begin();

        $count = 0;
        $error = 0;

        // 1. Bulk fetch all advances for the given period for ALL employees to avoid N+1 query problem
        $advances_by_user = array();
        $sql_adv = "SELECT s.fk_user, SUM(ps.amount) as total";
        $sql_adv .= " FROM " . MAIN_DB_PREFIX . "payment_salary as ps";
        $sql_adv .= " JOIN " . MAIN_DB_PREFIX . "salary as s ON ps.fk_salary = s.rowid";
        $sql_adv .= " WHERE s.paye = 1";
        $sql_adv .= " AND MONTH(ps.datep) = " . (int) $this->period_month;
        $sql_adv .= " AND YEAR(ps.datep) = " . (int) $this->period_year;
        $sql_adv .= " GROUP BY s.fk_user";
        
        $resql_adv = $this->db->query($sql_adv);
        if ($resql_adv) {
            while ($obj_adv = $this->db->fetch_object($resql_adv)) {
                $advances_by_user[$obj_adv->fk_user] = (float) $obj_adv->total;
            }
        }

        // Get all active employees with salary > 0
        $sql = "SELECT u.rowid, u.salary";
        $sql .= " FROM " . MAIN_DB_PREFIX . "user as u";
        $sql .= " WHERE u.entity IN (" . getEntity('user') . ")";
        $sql .= " AND u.statut = 1";
        $sql .= " AND u.employee = 1";
        $sql .= " AND u.salary > 0";

        $resql = $this->db->query($sql);
        if ($resql) {
            $num = $this->db->num_rows($resql);
            $i = 0;
            while ($i < $num && !$error) {
                $obj = $this->db->fetch_object($resql);

                // Check if payslip already exists for this user
                $sql_check = "SELECT rowid FROM " . MAIN_DB_PREFIX . "payroll_payslip";
                $sql_check .= " WHERE fk_payrun = " . (int) $this->id;
                $sql_check .= " AND fk_user = " . (int) $obj->rowid;

                $resql_check = $this->db->query($sql_check);
                if ($resql_check) {
                    $obj_check = $this->db->fetch_object($resql_check);
                    if ($obj_check) {
                        // Payslip already exists
                        $payslip = new Payslip($this->db);
                        $payslip->fetch($obj_check->rowid);

                        // Only refresh if still in draft
                        if ($payslip->status == Payslip::STATUS_DRAFT) {
                            $payslip->advances = isset($advances_by_user[$obj->rowid]) ? $advances_by_user[$obj->rowid] : 0;

                            // Always update bank account from configuration when refreshing
                            if (!empty($conf->global->PAYROLL_DEFAULT_BANK_ACCOUNT)) {
                                $payslip->fk_account = $conf->global->PAYROLL_DEFAULT_BANK_ACCOUNT;
                            }

                            // Always update payment method from configuration when refreshing
                            if (!empty($conf->global->PAYROLL_DEFAULT_PAYMENT_METHOD)) {
                                $payslip->fk_mode_reglement = $conf->global->PAYROLL_DEFAULT_PAYMENT_METHOD;
                            }

                            $payslip->calculateNet();
                            $payslip->update($user);
                            $count++;
                        }
                    } else {
                        // Create new payslip
                        $payslip = new Payslip($this->db);
                        $payslip->fk_payrun = $this->id;
                        $payslip->fk_user = $obj->rowid;
                        $payslip->base_salary = $obj->salary ? $obj->salary : 0;
                        $payslip->advances = isset($advances_by_user[$obj->rowid]) ? $advances_by_user[$obj->rowid] : 0;
                        $payslip->deductions = 0;
                        $payslip->bonuses = 0;

                        // Set default bank account (from configuration)
                        if (!empty($conf->global->PAYROLL_DEFAULT_BANK_ACCOUNT)) {
                            $payslip->fk_account = $conf->global->PAYROLL_DEFAULT_BANK_ACCOUNT;
                        }

                        // Set default payment method (from configuration)
                        if (!empty($conf->global->PAYROLL_DEFAULT_PAYMENT_METHOD)) {
                            $payslip->fk_mode_reglement = $conf->global->PAYROLL_DEFAULT_PAYMENT_METHOD;
                        }

                        $payslip->calculateNet();

                        $result = $payslip->create($user);
                        if ($result > 0) {
                            $count++;
                        } else {
                            $error++;
                            $this->error = $payslip->error;
                            $this->errors = $payslip->errors;
                        }
                    }
                }

                $i++;
            }
        } else {
            $error++;
            $this->error = $this->db->lasterror();
        }

        if (!$error) {
            // Recalculate totals
            $this->calculateTotals();
            $this->update($user);

            $this->db->commit();
            return $count;
        } else {
            $this->db->rollback();
            return -1;
        }
    }

    /**
     * Calculate totals from payslips
     *
     * @return void
     */
    public function calculateTotals()
    {
        $sql = "SELECT";
        $sql .= " SUM(base_salary) as total_gross,";
        $sql .= " SUM(advances) as total_advances,";
        $sql .= " SUM(bonuses) as total_bonuses,";
        $sql .= " SUM(net_salary) as total_net";
        $sql .= " FROM " . MAIN_DB_PREFIX . "payroll_payslip";
        $sql .= " WHERE fk_payrun = " . (int) $this->id;

        $resql = $this->db->query($sql);
        if ($resql) {
            if ($obj = $this->db->fetch_object($resql)) {
                $this->total_gross = $obj->total_gross ? $obj->total_gross : 0;
                $this->total_advances = $obj->total_advances ? $obj->total_advances : 0;
                $this->total_bonuses = $obj->total_bonuses ? $obj->total_bonuses : 0;
                $this->total_net = $obj->total_net ? $obj->total_net : 0;
            }
        }
    }

    /**
     * Return the label of the status
     *
     * @param int $mode 0=long label, 1=short label, 2=Picto + short label
     * @return string Label of status
     */
    public function getLibStatut($mode = 0)
    {
        return $this->LibStatut($this->status, $mode);
    }

    /**
     * Return the label of a given status
     *
     * @param int $status Id status
     * @param int $mode 0=long label, 1=short label, 2=Picto + short label
     * @return string Label of status
     */
    public function LibStatut($status, $mode = 0)
    {
        global $langs;

        $statusType = 'status' . $status;
        if ($status == self::STATUS_VALIDATED) {
            $statusType = 'status4';
        }
        if ($status == self::STATUS_PAID) {
            $statusType = 'status6';
        }

        $statusLabel = array(
            self::STATUS_DRAFT => $langs->trans('Draft'),
            self::STATUS_VALIDATED => $langs->trans('Validated'),
            self::STATUS_PAID => $langs->trans('Paid')
        );

        return dolGetStatus($statusLabel[$status], '', '', $statusType, $mode);
    }

    /**
     * Return URL to object
     *
     * @param int $withpicto Include picto in link (0=No picto, 1=Include picto into link, 2=Only picto)
     * @param string $option On what the link point to ('nolink', ...)
     * @param int $notooltip 1=Disable tooltip
     * @return string String with URL
     */
    /**
     * Generate PDF document for payroll summary
     *
     * @param string $outputlangs Language
     * @return int <0 if KO, >0 if OK
     */
    public function generateDocument($outputlangs = '')
    {
        global $conf, $langs, $db, $user;

        if (empty($outputlangs)) {
            $outputlangs = $langs;
        }

        require_once DOL_DOCUMENT_ROOT . '/core/lib/pdf.lib.php';
        require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';

        $dir = $conf->payroll->dir_output . '/payrun/' . dol_sanitizeFileName($this->ref);
        if (!is_dir($dir)) {
            dol_mkdir($dir);
        }

        $file = $dir . '/' . dol_sanitizeFileName($this->ref) . '_summary.pdf';

        $outputlangs->load("payroll@payroll");
        $outputlangs->load("main");
        $outputlangs->load("dict");

        // Create PDF
        $pdf = pdf_getInstance('A4', 'mm', 'L');
        if (!$pdf) {
            return -1;
        }
        $pdf->SetCreator('Dolibarr ' . DOL_VERSION);
        $pdf->SetAuthor(dol_html_entity_decode($user->getFullName($outputlangs), ENT_QUOTES));
        $pdf->SetTitle(dol_html_entity_decode($outputlangs->trans("PayrollSummary"), ENT_QUOTES) . ' ' . $this->ref);
        $pdf->SetSubject(dol_html_entity_decode($outputlangs->trans("PayrollSummary"), ENT_QUOTES));
        if (method_exists($pdf, 'setPrintHeader')) {
            $pdf->setPrintHeader(false);
        }
        if (method_exists($pdf, 'setPrintFooter')) {
            $pdf->setPrintFooter(false);
        }
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(TRUE, 15);
        $pdf->AddPage();

        $table_width = 65 + 40 + 40 + 40 + 45; // 230mm
        $page_width = 297; // A4 Landscape
        $start_x = ($page_width - $table_width) / 2;

        // Logo and Header
        if (!empty($conf->mycompany->logo)) {
            $logofile = $conf->mycompany->dir_output . '/logos/' . $conf->mycompany->logo;
            if (file_exists($logofile)) {
                $pdf->Image($logofile, 10, 10, 0, 15);
            }
        }

        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetXY(10, 30);
        $pdf->Cell(0, 12, dol_html_entity_decode($outputlangs->trans("PayrollSummary"), ENT_QUOTES) . ' - ' . dol_print_date(dol_mktime(0, 0, 0, $this->period_month, 1, $this->period_year), '%B %Y'), 0, 1, 'C');

        // Draw a separator line
        $pdf->SetDrawColor(200, 200, 200);
        $pdf->Line(20, $pdf->GetY(), 277, $pdf->GetY());

        $pdf->SetFont('helvetica', 'I', 9);
        $pdf->Ln(2);
        $pdf->Cell(0, 5, dol_html_entity_decode($outputlangs->trans("GeneratedOn"), ENT_QUOTES) . ': ' . dol_print_date(dol_now(), 'dayhour'), 0, 1, 'C');

        $pdf->Ln(8);

        // Table Header
        $pdf->SetX($start_x);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->SetDrawColor(180, 180, 180);
        $h = 8;
        $pdf->Cell(65, $h, dol_html_entity_decode($outputlangs->trans("Employee"), ENT_QUOTES), 1, 0, 'L', 1);
        $pdf->Cell(40, $h, dol_html_entity_decode($outputlangs->trans("BaseSalary"), ENT_QUOTES), 1, 0, 'R', 1);
        $pdf->Cell(40, $h, dol_html_entity_decode($outputlangs->trans("Bonuses"), ENT_QUOTES), 1, 0, 'R', 1);
        $pdf->Cell(40, $h, dol_html_entity_decode($outputlangs->trans("Advances"), ENT_QUOTES), 1, 0, 'R', 1);
        $pdf->Cell(45, $h, dol_html_entity_decode($outputlangs->trans("NetSalary"), ENT_QUOTES), 1, 1, 'R', 1);

        $pdf->SetFont('helvetica', '', 9);

        // Fetch Payslips
        $sql = "SELECT t.base_salary, t.bonuses, t.advances, t.net_salary, u.firstname, u.lastname";
        $sql .= " FROM " . MAIN_DB_PREFIX . "payroll_payslip as t";
        $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "user as u ON t.fk_user = u.rowid";
        $sql .= " WHERE t.fk_payrun = " . (int) $this->id;
        $sql .= " ORDER BY u.lastname ASC";

        $resql = $this->db->query($sql);
        if ($resql) {
            $filler = 0;
            while ($obj = $this->db->fetch_object($resql)) {
                $empname = $obj->lastname . ' ' . $obj->firstname;
                $pdf->SetX($start_x);
                $pdf->SetFillColor(250, 250, 250);
                
                $pdf->Cell(65, $h, dol_html_entity_decode($empname, ENT_QUOTES), 1, 0, 'L', $filler);
                $pdf->Cell(40, $h, price($obj->base_salary, 0, $outputlangs, 1, -1, -1, $conf->currency), 1, 0, 'R', $filler);
                $pdf->Cell(40, $h, price($obj->bonuses, 0, $outputlangs, 1, -1, -1, $conf->currency), 1, 0, 'R', $filler);
                $pdf->Cell(40, $h, price(-1 * $obj->advances, 0, $outputlangs, 1, -1, -1, $conf->currency), 1, 0, 'R', $filler);
                $pdf->Cell(45, $h, price($obj->net_salary, 0, $outputlangs, 1, -1, -1, $conf->currency), 1, 1, 'R', $filler);
                
                $filler = !$filler;
            }
        }

        // Totals Row
        $pdf->SetX($start_x);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(220, 220, 220);
        $row_h = $h + 1;
        $pdf->Cell(65, $row_h, "TOTAL", 1, 0, 'L', 1);
        $pdf->Cell(40, $row_h, price($this->total_gross, 0, $outputlangs, 1, -1, -1, $conf->currency), 1, 0, 'R', 1);
        $pdf->Cell(40, $row_h, price($this->total_bonuses, 0, $outputlangs, 1, -1, -1, $conf->currency), 1, 0, 'R', 1);
        $pdf->Cell(40, $row_h, price(-1 * $this->total_advances, 0, $outputlangs, 1, -1, -1, $conf->currency), 1, 0, 'R', 1);
        $pdf->Cell(45, $row_h, price($this->total_net, 0, $outputlangs, 1, -1, -1, $conf->currency), 1, 1, 'R', 1);

        $pdf->SetY(-15);
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->Cell(0, 5, dol_html_entity_decode(!empty($conf->global->MAIN_INFO_SOCIETE_NOM) ? $conf->global->MAIN_INFO_SOCIETE_NOM : '', ENT_QUOTES), 0, 0, 'C');

        $pdf->Output($file, 'F');
        $pdf->Close();

        return 1;
    }

    /**
     * Return URL to object
     *
     * @param int $withpicto Include picto in link (0=No picto, 1=Include picto into link, 2=Only picto)
     * @param string $option On what the link point to ('nolink', ...)
     * @param int $notooltip 1=Disable tooltip
     * @return string String with URL
     */
    public function getNomUrl($withpicto = 0, $option = '', $notooltip = 0)
    {
        global $conf, $langs;

        if (!empty($conf->dol_no_mouse_hover)) {
            $notooltip = 1;
        }

        $result = '';

        $label = '<u>' . $langs->trans("PayRun") . '</u>';
        $label .= '<br><b>' . $langs->trans('Ref') . ':</b> ' . $this->ref;
        $label .= '<br><b>' . $langs->trans('Period') . ':</b> ' . dol_print_date(dol_mktime(0, 0, 0, $this->period_month, 1, $this->period_year), '%B %Y');

        $url = DOL_URL_ROOT . '/custom/payroll/payrun_card.php?id=' . $this->id;

        if ($option != 'nolink') {
            $linkstart = '<a href="' . $url . '"';
            $linkstart .= ($notooltip ? '' : ' title="' . dol_escape_htmltag($label, 1) . '" class="classfortooltip"');
            $linkstart .= '>';
            $linkend = '</a>';

            $result .= $linkstart;
            if ($withpicto) {
                $result .= img_object(($notooltip ? '' : $label), ($this->picto ? $this->picto : 'generic'), ($notooltip ? (($withpicto != 2) ? 'class="paddingright"' : '') : 'class="' . (($withpicto != 2) ? 'paddingright ' : '') . 'classfortooltip"'), 0, 0, $notooltip ? 0 : 1);
            }
            if ($withpicto != 2) {
                $result .= $this->ref;
            }
            $result .= $linkend;
        } else {
            if ($withpicto) {
                $result .= img_object(($notooltip ? '' : $label), ($this->picto ? $this->picto : 'generic'), 'class="paddingright"');
            }
            if ($withpicto != 2) {
                $result .= $this->ref;
            }
        }

        return $result;
    }
}