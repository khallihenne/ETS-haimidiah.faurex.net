<?php
/* Copyright (C) 2025 Your Company
 *
 * This program is free software
 */

/**
 * \file    class/payslip.class.php
 * \ingroup payroll
 * \brief   Payslip class file
 */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';

/**
 * Class for Payslip
 */
class Payslip extends CommonObject
{
    /**
     * @var string ID to identify managed object
     */
    public $element = 'payslip';

    /**
     * @var string Name of table without prefix where object is stored
     */
    public $table_element = 'payroll_payslip';

    /**
     * @var int Does object support multicompany module
     */
    public $ismultientitymanaged = 1;

    /**
     * @var int Does object support extrafields
     */
    public $isextrafieldmanaged = 0;

    /**
     * @var string String with name of icon for payslip
     */
    public $picto = 'bill';

    const STATUS_DRAFT = 0;
    const STATUS_VALIDATED = 1;
    const STATUS_PAID = 2;
    const STATUS_CANCELLED = 3;

    /**
     * @var array Array of fields
     */
    public $fields = array(
        'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'visible' => -2, 'position' => 1),
        'ref' => array('type' => 'varchar(128)', 'label' => 'Ref', 'enabled' => 1, 'visible' => 1, 'position' => 10, 'notnull' => 1, 'showoncombobox' => 1),
        'fk_payrun' => array('type' => 'integer:PayRun:payroll/class/payrun.class.php', 'label' => 'PayRun', 'enabled' => 1, 'visible' => 1, 'position' => 20, 'notnull' => 1),
        'fk_user' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'Employee', 'enabled' => 1, 'visible' => 1, 'position' => 30, 'notnull' => 1),
        'base_salary' => array('type' => 'double(24,8)', 'label' => 'BaseSalary', 'enabled' => 1, 'visible' => 1, 'position' => 40, 'notnull' => 1),
        'advances' => array('type' => 'double(24,8)', 'label' => 'Advances', 'enabled' => 1, 'visible' => 1, 'position' => 50),
        'bonuses' => array('type' => 'double(24,8)', 'label' => 'Bonuses', 'enabled' => 1, 'visible' => 1, 'position' => 70),
        'net_salary' => array('type' => 'double(24,8)', 'label' => 'NetSalary', 'enabled' => 1, 'visible' => 1, 'position' => 80),
        'fk_account' => array('type' => 'integer', 'label' => 'BankAccount', 'enabled' => 1, 'visible' => 1, 'position' => 85),
        'fk_mode_reglement' => array('type' => 'integer', 'label' => 'PaymentMethod', 'enabled' => 1, 'visible' => 1, 'position' => 86),
        'date_payment' => array('type' => 'datetime', 'label' => 'PaymentDate', 'enabled' => 1, 'visible' => 1, 'position' => 87),
        'status' => array('type' => 'integer', 'label' => 'Status', 'enabled' => 1, 'visible' => 1, 'position' => 500, 'notnull' => 1, 'default' => '0'),
        'note_public' => array('type' => 'html', 'label' => 'NotePublic', 'enabled' => 1, 'visible' => 0, 'position' => 61),
        'note_private' => array('type' => 'html', 'label' => 'NotePrivate', 'enabled' => 1, 'visible' => 0, 'position' => 62),
        'date_creation' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => 1, 'visible' => -2, 'position' => 500),
        'tms' => array('type' => 'timestamp', 'label' => 'DateModification', 'enabled' => 1, 'visible' => -2, 'position' => 501),
        'fk_user_creat' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserAuthor', 'enabled' => 1, 'visible' => -2, 'position' => 510),
        'fk_user_modif' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserModif', 'enabled' => 1, 'visible' => -2, 'position' => 511),
        'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => 1, 'visible' => 0, 'position' => 30, 'default' => 1)
    );

    public $rowid;
    public $ref;
    public $fk_payrun;
    public $fk_user;
    public $base_salary;
    public $advances;
    public $deductions;
    public $bonuses;
    public $net_salary;
    public $fk_account;
    public $fk_mode_reglement;
    public $date_payment;
    public $status;
    public $note_public;
    public $note_private;
    public $date_creation;
    public $tms;
    public $fk_user_creat;
    public $fk_user_modif;
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

        // Calculate net salary
        $this->calculateNet();

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
     * Fetch advances (payments) from core Salaries module for the given period
     *
     * @param int $month Month
     * @param int $year Year
     * @return float Total advances
     */
    public function fetchAdvancesFromSalaryModule($month, $year)
    {
        $sql = "SELECT SUM(ps.amount) as total";
        $sql .= " FROM " . MAIN_DB_PREFIX . "payment_salary as ps";
        $sql .= " JOIN " . MAIN_DB_PREFIX . "salary as s ON ps.fk_salary = s.rowid";
        $sql .= " WHERE s.fk_user = " . (int) $this->fk_user;
        $sql .= " AND s.paye = 1";
        $sql .= " AND MONTH(ps.datep) = " . (int) $month;
        $sql .= " AND YEAR(ps.datep) = " . (int) $year;

        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            return $obj->total ? (float) $obj->total : 0;
        }
        return 0;
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
        // Recalculate net salary
        $this->calculateNet();

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
        $this->db->begin();

        $error = 0;
        $linked_bank_ids = array();

        // Delete associated bank lines
        if (!$error) {
            require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
            $sql = "SELECT fk_bank FROM " . MAIN_DB_PREFIX . "bank_url";
            $sql .= " WHERE url_id = " . (int) $this->id;
            $sql .= " AND type = '" . $this->db->escape($this->element) . "'";

            $resql = $this->db->query($sql);
            if ($resql) {
                while ($obj = $this->db->fetch_object($resql)) {
                    $bank_id = (int) $obj->fk_bank;
                    $linked_bank_ids[] = $bank_id;

                    // IMPORTANT: We must delete the bookkeeping entries BEFORE deleting the bank line
                    // because Dolibarr core prevents deleting a bank line that is already in accounting.
                    $sql_acc = "DELETE FROM " . MAIN_DB_PREFIX . "accounting_bookkeeping WHERE fk_doc = " . $bank_id . " AND doc_type = 'bank'";
                    if (!$this->db->query($sql_acc)) {
                        $error++;
                        $this->error = $this->db->lasterror();
                        break;
                    }

                    $bankline = new AccountLine($this->db);
                    $result = $bankline->fetch($bank_id);
                    if ($result > 0) {
                        $result = $bankline->delete($user);
                        if ($result < 0) {
                            $error++;
                            $this->error = $bankline->error;
                            break;
                        }
                    }
                }
            } else {
                $error++;
                $this->error = $this->db->lasterror();
            }
        }

        if (!$error) {
            $result = $this->deleteCommon($user, $notrigger);
            if ($result < 0) {
                $error++;
            }
        }

        // Delete associated accounting entries from bookkeeping for the payslip itself
        if (!$error) {
            $sql = "DELETE FROM " . MAIN_DB_PREFIX . "accounting_bookkeeping";
            $sql .= " WHERE (fk_doc = " . (int) $this->id . " AND doc_type IN ('payslip', 'payslip_payment'))";
            
            if (!$this->db->query($sql)) {
                $error++;
                $this->error = $this->db->lasterror();
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

        $prefix = 'PS';
        $year = date('Y');
        $month = date('m');

        $sql = "SELECT MAX(CAST(SUBSTRING(ref FROM " . (strlen($prefix . $year . $month) + 2) . ") AS SIGNED)) as max";
        $sql .= " FROM " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " WHERE ref LIKE '" . $this->db->escape($prefix . $year . $month) . "-%'";
        $sql .= " AND entity = " . $conf->entity;

        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            $max = $obj->max;
            $num = $max + 1;
        } else {
            $num = 1;
        }

        return $prefix . $year . $month . '-' . str_pad($num, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Calculate net salary
     *
     * @return void
     */
    public function calculateNet()
    {
        $this->net_salary = $this->base_salary - $this->advances + $this->bonuses;
    }

    /**
     * Validate payslip and generate accounting entries
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

        if ($this->fk_account <= 0 || $this->fk_mode_reglement <= 0) {
            $this->error = $langs->trans("ErrorMissingBankOrPaymentMode");
            return -1;
        }

        $this->db->begin();

        // Generate accounting entries if accounting module is enabled
        if (!empty($conf->accounting->enabled)) {
            $result = $this->generateAccountingEntries($user);
            if ($result < 0) {
                $error++;
            }
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
     * Set payslip to draft status
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

        $this->db->begin();

        // Delete accounting entries
        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "acc_bookkeeping";
        $sql .= " WHERE doc_type = 'payslip' AND fk_doc = " . (int) $this->id;

        if (!$this->db->query($sql)) {
            $this->error = $this->db->lasterror();
            $this->db->rollback();
            return -1;
        }

        $this->status = self::STATUS_DRAFT;
        $res = $this->update($user, $notrigger);
        if ($res < 0) {
            $this->db->rollback();
            return -1;
        }

        $this->db->commit();
        return 1;
    }

    /**
     * Generate accounting entries for this payslip
     *
     * @param User $user User generating entries
     * @return int <0 if KO, >0 if OK
     */
    public function generateAccountingEntries($user)
    {
        global $conf, $langs;

        require_once DOL_DOCUMENT_ROOT . '/accountancy/class/bookkeeping.class.php';

        $error = 0;
        $now = dol_now();

        static $accounting_accounts_cache = array();
        static $global_conf_cache = array();

        if (empty($global_conf_cache)) {
            $global_conf_cache['account_salary'] = getDolGlobalString('PAYROLL_ACCOUNT_SALARY_EXPENSE', '65');
            $global_conf_cache['account_bonuses'] = getDolGlobalString('PAYROLL_ACCOUNT_BONUSES', '6506');
            $global_conf_cache['account_net'] = getDolGlobalString('PAYROLL_ACCOUNT_NET_PAYABLE', '42');
            $global_conf_cache['account_advances'] = getDolGlobalString('PAYROLL_ACCOUNT_ADVANCES', '425');
            $global_conf_cache['journal_code'] = getDolGlobalString('PAYROLL_JOURNAL', 'PAY');
        }

        $account_salary = $global_conf_cache['account_salary'];
        $account_bonuses = $global_conf_cache['account_bonuses'];
        $account_net = $global_conf_cache['account_net'];
        $account_advances = $global_conf_cache['account_advances'];
        $journal_code = $global_conf_cache['journal_code'];

        require_once DOL_DOCUMENT_ROOT . '/accountancy/class/accountingaccount.class.php';
        $acc_obj = new AccountingAccount($this->db);

        // Fetch payrun for period info
        require_once DOL_DOCUMENT_ROOT . '/custom/payroll/class/payrun.class.php';
        $payrun = new PayRun($this->db);
        $payrun->fetch($this->fk_payrun);

        // Fetch user info
        require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
        $employee = new User($this->db);
        $employee->fetch($this->fk_user);

        $date_entry = dol_mktime(0, 0, 0, $payrun->period_month, 1, $payrun->period_year);
        $piece_num = $this->ref;
        $doc_ref = $payrun->ref; // Use PayRun Ref for easier searching
        $payrun_label = $payrun->label ? $payrun->label : $payrun->ref;

        // Entry 1: Debit salary expense (Base Salary) -> 61000
        if ($this->base_salary > 0) {
            $bookkeeping = new BookKeeping($this->db);
            $bookkeeping->doc_date = $date_entry;
            $bookkeeping->date_lim_reglement = 0;
            $bookkeeping->doc_ref = $doc_ref;
            $bookkeeping->date_creation = $now;
            $bookkeeping->doc_type = 'payslip';
            $bookkeeping->fk_doc = $this->id;
            $bookkeeping->fk_docdet = 0;
            $bookkeeping->thirdparty_code = $employee->login;
            $bookkeeping->subledger_account = '';
            $bookkeeping->subledger_label = $employee->getFullName($langs);
            if (!isset($accounting_accounts_cache[$account_salary])) {
                $label_salary = $langs->trans('SalaryExpense');
                if ($acc_obj->fetch(0, $account_salary, 1) > 0)
                    $label_salary = $acc_obj->label;
                $accounting_accounts_cache[$account_salary] = $label_salary;
            }

            $bookkeeping->numero_compte = $account_salary;
            $bookkeeping->label_compte = $accounting_accounts_cache[$account_salary];
            $bookkeeping->label_operation = $payrun_label . ' - ' . $employee->getFullName($langs);
            $bookkeeping->montant = $this->base_salary;
            $bookkeeping->sens = 'D';
            $bookkeeping->debit = $this->base_salary;
            $bookkeeping->credit = 0;
            $bookkeeping->code_journal = $journal_code;
            $bookkeeping->journal_label = $langs->trans('PayrollJournal');
            $bookkeeping->piece_num = $piece_num;

            $result = $bookkeeping->create($user);
            if ($result < 0) {
                $this->error = $bookkeeping->error;
                $this->errors = $bookkeeping->errors;
                $error++;
            }
        }

        // Entry 2: Debit bonuses (Gratifications) -> 610600
        if ($this->bonuses > 0 && !$error) {
            $bookkeeping = new BookKeeping($this->db);
            $bookkeeping->doc_date = $date_entry;
            $bookkeeping->date_lim_reglement = 0;
            $bookkeeping->doc_ref = $doc_ref;
            $bookkeeping->date_creation = $now;
            $bookkeeping->doc_type = 'payslip';
            $bookkeeping->fk_doc = $this->id;
            $bookkeeping->fk_docdet = 0;
            $bookkeeping->thirdparty_code = $employee->login;
            $bookkeeping->subledger_account = '';
            $bookkeeping->subledger_label = $employee->getFullName($langs);
            if (!isset($accounting_accounts_cache[$account_bonuses])) {
                $label_bonuses = $langs->trans('Bonuses');
                if ($acc_obj->fetch(0, $account_bonuses, 1) > 0)
                    $label_bonuses = $acc_obj->label;
                $accounting_accounts_cache[$account_bonuses] = $label_bonuses;
            }

            $bookkeeping->numero_compte = $account_bonuses;
            $bookkeeping->label_compte = $accounting_accounts_cache[$account_bonuses];
            $bookkeeping->label_operation = $payrun_label . ' - ' . $employee->getFullName($langs);
            $bookkeeping->montant = $this->bonuses;
            $bookkeeping->sens = 'D';
            $bookkeeping->debit = $this->bonuses;
            $bookkeeping->credit = 0;
            $bookkeeping->code_journal = $journal_code;
            $bookkeeping->journal_label = $langs->trans('PayrollJournal');
            $bookkeeping->piece_num = $piece_num;

            $result = $bookkeeping->create($user);
            if ($result < 0) {
                $this->error = $bookkeeping->error;
                $this->errors = $bookkeeping->errors;
                $error++;
            }
        }

        // Entry 3: Credit advances if any (Recovery) -> 425000
        if ($this->advances > 0 && !$error) {
            $bookkeeping = new BookKeeping($this->db);
            $bookkeeping->doc_date = $date_entry;
            $bookkeeping->date_lim_reglement = 0;
            $bookkeeping->doc_ref = $doc_ref;
            $bookkeeping->date_creation = $now;
            $bookkeeping->doc_type = 'payslip';
            $bookkeeping->fk_doc = $this->id;
            $bookkeeping->fk_docdet = 0;
            $bookkeeping->thirdparty_code = $employee->login;
            $bookkeeping->subledger_account = '';
            $bookkeeping->subledger_label = $employee->getFullName($langs);
            if (!isset($accounting_accounts_cache[$account_advances])) {
                $label_advances = $langs->trans('AdvancesAccount');
                if ($acc_obj->fetch(0, $account_advances, 1) > 0)
                    $label_advances = $acc_obj->label;
                $accounting_accounts_cache[$account_advances] = $label_advances;
            }

            $bookkeeping->numero_compte = $account_advances;
            $bookkeeping->label_compte = $accounting_accounts_cache[$account_advances];
            $bookkeeping->label_operation = $payrun_label . ' - ' . $employee->getFullName($langs);
            $bookkeeping->montant = $this->advances;
            $bookkeeping->sens = 'C';
            $bookkeeping->debit = 0;
            $bookkeeping->credit = $this->advances;
            $bookkeeping->code_journal = $journal_code;
            $bookkeeping->journal_label = $langs->trans('PayrollJournal');
            $bookkeeping->piece_num = $piece_num;

            $result = $bookkeeping->create($user);
            if ($result < 0) {
                $this->error = $bookkeeping->error;
                $this->errors = $bookkeeping->errors;
                $error++;
            }
        }

        // Entry 4: Credit net payable -> 420000
        if ($this->net_salary > 0 && !$error) {
            $bookkeeping = new BookKeeping($this->db);
            $bookkeeping->doc_date = $date_entry;
            $bookkeeping->date_lim_reglement = 0;
            $bookkeeping->doc_ref = $doc_ref;
            $bookkeeping->date_creation = $now;
            $bookkeeping->doc_type = 'payslip';
            $bookkeeping->fk_doc = $this->id;
            $bookkeeping->fk_docdet = 0;
            $bookkeeping->thirdparty_code = $employee->login;
            $bookkeeping->subledger_account = '';
            $bookkeeping->subledger_label = $employee->getFullName($langs);
            if (!isset($accounting_accounts_cache[$account_net])) {
                $label_net = $langs->trans('NetPayable');
                if ($acc_obj->fetch(0, $account_net, 1) > 0)
                    $label_net = $acc_obj->label;
                $accounting_accounts_cache[$account_net] = $label_net;
            }

            $bookkeeping->numero_compte = $account_net;
            $bookkeeping->label_compte = $accounting_accounts_cache[$account_net];
            $bookkeeping->label_operation = $payrun_label . ' - ' . $employee->getFullName($langs);
            $bookkeeping->montant = $this->net_salary;
            $bookkeeping->sens = 'C';
            $bookkeeping->debit = 0;
            $bookkeeping->credit = $this->net_salary;
            $bookkeeping->code_journal = $journal_code;
            $bookkeeping->journal_label = $langs->trans('PayrollJournal');
            $bookkeeping->piece_num = $piece_num;

            $result = $bookkeeping->create($user);
            if ($result < 0) {
                $this->error = $bookkeeping->error;
                $this->errors = $bookkeeping->errors;
                $error++;
            }
        }

        if ($error)
            return -1;
        return 1;
    }

    /**
     * Generate payment accounting entries for this payslip
     *
     * @param User $user User generating entries
     * @param int $fk_bank Bank account ID
     * @param string $payment_date Payment date
     * @param int $bank_line_id ID of the line in llx_bank
     * @return int <0 if KO, >0 if OK
     */
    public function generatePaymentAccountingEntries($user, $fk_bank, $payment_date = '', $bank_line_id = 0)
    {
        global $conf, $langs;

        if (empty($conf->accounting->enabled))
            return 0;

        require_once DOL_DOCUMENT_ROOT . '/accountancy/class/bookkeeping.class.php';
        require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';

        $now = dol_now();
        if (empty($payment_date))
            $payment_date = $now;

        $account_net = getDolGlobalString('PAYROLL_ACCOUNT_NET_PAYABLE', '42');

        require_once DOL_DOCUMENT_ROOT . '/accountancy/class/accountingaccount.class.php';
        $acc_obj = new AccountingAccount($this->db);

        $bank_acc = new Account($this->db);
        $bank_acc->fetch($fk_bank);
        $account_bank = $bank_acc->account_number; // The general accounting account linked to the bank/cash account

        // Fetch payrun info
        require_once DOL_DOCUMENT_ROOT . '/custom/payroll/class/payrun.class.php';
        $payrun = new PayRun($this->db);
        $payrun->fetch($this->fk_payrun);
        $doc_ref = $payrun->ref;
        $payrun_label = $payrun->label ? $payrun->label : $payrun->ref;

        // Fetch user info
        require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
        $employee = new User($this->db);
        $employee->fetch($this->fk_user);

        $error = 0;
        $piece_num = 'PAY-' . $this->ref;

        // Entry 1: Debit net payable (420000) -> Cancelling the debt
        $bookkeeping = new BookKeeping($this->db);
        $bookkeeping->doc_date = $payment_date;
        $bookkeeping->doc_ref = $doc_ref;
        $bookkeeping->date_creation = $now;
        $bookkeeping->doc_type = 'payslip_payment';
        $bookkeeping->fk_doc = $this->id;
        $bookkeeping->fk_docdet = 0;
        $bookkeeping->thirdparty_code = $employee->login;
        $bookkeeping->subledger_account = '';
        $bookkeeping->subledger_label = $employee->getFullName($langs);
        $bookkeeping->numero_compte = $account_net;
        $label_net = $langs->trans('NetPayable');
        if ($acc_obj->fetch(0, $account_net, 1) > 0) {
            $label_net = $acc_obj->label;
        }
        $bookkeeping->label_compte = $label_net;
        $bookkeeping->label_operation = $langs->trans('SalaryPayment') . ' ' . $payrun_label . ' - ' . $employee->getFullName($langs);
        $bookkeeping->montant = $this->net_salary;
        $bookkeeping->sens = 'D';
        $bookkeeping->debit = $this->net_salary;
        $bookkeeping->credit = 0;
        $bookkeeping->code_journal = getDolGlobalString('PAYROLL_BANK_JOURNAL', 'BQ');
        $bookkeeping->journal_label = $langs->trans('TreasuryJournal');
        $bookkeeping->piece_num = $piece_num;

        if ($bookkeeping->create($user) < 0) {
            $this->error = $bookkeeping->error;
            $error++;
        }

        // Entry 2: Credit bank/cash account -> Cash outflow
        // Use doc_type = 'bank' and fk_doc = $bank_line_id to prevent redundant transfer entries
        if (!$error && $this->net_salary > 0) {
            $bookkeeping = new BookKeeping($this->db);
            $bookkeeping->doc_date = $payment_date;
            $bookkeeping->doc_ref = $doc_ref;
            $bookkeeping->date_creation = $now;
            if ($bank_line_id > 0) {
                $bookkeeping->doc_type = 'bank';
                $bookkeeping->fk_doc = $bank_line_id;
            } else {
                $bookkeeping->doc_type = 'payslip_payment';
                $bookkeeping->fk_doc = $this->id;
            }
            $bookkeeping->fk_docdet = 0;
            $bookkeeping->thirdparty_code = $employee->login;
            $bookkeeping->subledger_account = '';
            $bookkeeping->subledger_label = $employee->getFullName($langs);
            $bookkeeping->numero_compte = $account_bank;
            $bookkeeping->label_compte = $bank_acc->label;
            $bookkeeping->label_operation = $langs->trans('SalaryPayment') . ' ' . $payrun_label . ' - ' . $employee->getFullName($langs);
            $bookkeeping->montant = $this->net_salary;
            $bookkeeping->sens = 'C';
            $bookkeeping->debit = 0;
            $bookkeeping->credit = $this->net_salary;
            $bookkeeping->code_journal = getDolGlobalString('PAYROLL_BANK_JOURNAL', 'BQ');
            $bookkeeping->journal_label = $langs->trans('TreasuryJournal');
            $bookkeeping->piece_num = $piece_num;

            if ($bookkeeping->create($user) < 0) {
                $this->error = $bookkeeping->error;
                $error++;
            }
        }

        return ($error ? -1 : 1);
    }

    public function setPaid($user, $payment_date = '')
    {
        if ($this->status == self::STATUS_PAID)
            return 0;
        $this->status = self::STATUS_PAID;
        if (!empty($payment_date)) {
            $this->date_payment = $payment_date;
        }
        return $this->update($user);
    }

    /**
     * Record payment for this payslip
     *
     * @param User $user User recording payment
     * @param int $fk_bank Bank account ID
     * @param string $payment_date Payment date
     * @return int <0 if KO, >0 if OK
     */
    public function recordPayment($user, $fk_bank, $payment_date = '')
    {
        global $conf, $langs;

        if (empty($payment_date)) {
            $payment_date = dol_now();
        }

        if ($this->status != self::STATUS_VALIDATED) {
            $this->error = "Payslip must be validated before recording payment";
            return -1;
        }

        $this->db->begin();

        $error = 0;

        // Get payment mode code (like 'VIR', 'CHQ', etc.)
        $pay_mode_code = 'VIR';
        $sql_mode = "SELECT code FROM " . MAIN_DB_PREFIX . "c_paiement WHERE id = " . (int) $this->fk_mode_reglement;
        $resql_mode = $this->db->query($sql_mode);
        if ($resql_mode) {
            $obj_mode = $this->db->fetch_object($resql_mode);
            if ($obj_mode) {
                $pay_mode_code = $obj_mode->code;
            }
        }

        // Create bank line
        require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
        $account = new Account($this->db);
        $result = $account->fetch($fk_bank);

        if ($result > 0) {
            // Get employee info
            require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
            $employee = new User($this->db);
            $employee->fetch($this->fk_user);

            // Fetch payrun ref for label
            require_once DOL_DOCUMENT_ROOT . '/custom/payroll/class/payrun.class.php';
            $payrun = new PayRun($this->db);
            $payrun->fetch($this->fk_payrun);

            $label = $langs->trans("PayrollPayment") . " " . $payrun->ref . " - " . $this->ref;

            $bank_line_id = $account->addline(
                $payment_date,
                $pay_mode_code,
                $label,
                -1 * $this->net_salary, // Negative for payment
                '',
                0,
                $user
            );

            if ($bank_line_id > 0) {
                // Link bank line to payslip
                $url = 'custom/payroll/payslip_card.php?id=' . $this->id;
                $account->add_url_line($bank_line_id, $this->id, $url, $this->ref, $this->element);

                // Generate accounting entries for payment
                if (!empty($conf->accounting->enabled)) {
                    $res_acc = $this->generatePaymentAccountingEntries($user, $fk_bank, $payment_date, $bank_line_id);
                    if ($res_acc < 0) {
                        $error++;
                    }
                }

                // Mark as paid
                $res = $this->setPaid($user, $payment_date);
                if ($res < 0) {
                    $error++;
                }
            } else {
                $error++;
                $this->error = $account->error;
            }
        } else {
            $error++;
            $this->error = $account->error;
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
        if ($status == self::STATUS_CANCELLED) {
            $statusType = 'status5';
        }

        $statusLabel = array(
            self::STATUS_DRAFT => $langs->trans('Draft'),
            self::STATUS_VALIDATED => $langs->trans('Validated'),
            self::STATUS_PAID => $langs->trans('Paid'),
            self::STATUS_CANCELLED => $langs->trans('Cancelled')
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
    public function getNomUrl($withpicto = 0, $option = '', $notooltip = 0)
    {
        global $langs, $conf;

        $result = '';
        $label = '<u>' . $langs->trans("Payslip") . '</u>';
        $label .= '<br>';
        $label .= '<b>' . $langs->trans('Ref') . ':</b> ' . $this->ref;

        $url = DOL_URL_ROOT . '/custom/payroll/payslip_card.php?id=' . $this->id;

        $linkclose = '';
        if (empty($notooltip)) {
            $linkclose .= ' title="' . dol_escape_htmltag($label, 1) . '" class="classfortooltip"';
        }

        $linkstart = '<a href="' . $url . '"';
        $linkstart .= $linkclose . '>';
        $linkend = '</a>';

        $result .= $linkstart;
        if ($withpicto) {
            $result .= img_object(($notooltip ? '' : $label), ($this->picto ? $this->picto : 'generic'), ($notooltip ? (($withpicto != 2) ? 'class="paddingright"' : '') : 'class="' . (($withpicto != 2) ? 'paddingright ' : '') . 'classfortooltip"'), 0, 0, $notooltip ? 0 : 1);
        }
        if ($withpicto != 2) {
            $result .= $this->ref;
        }
        $result .= $linkend;

        return $result;
    }

    /**
     * Generate PDF document
     *
     * @param string $outputlangs Lang object for output language
     * @param string $hidedetails Hide details
     * @param string $hidedesc Hide description
     * @param string $hideref Hide ref
     * @return int <0 if KO, >0 if OK
     */ 
    public function generateDocument($outputlangs = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
    {
        global $conf, $langs, $user, $mysoc;

        if (empty($outputlangs)) {
            $outputlangs = $langs;
        }

        $dir = $conf->payroll->dir_output . '/payslip';
        $file = $dir . '/' . $this->ref . '.pdf';

        if (!dol_is_dir($dir)) {
            dol_mkdir($dir);
        }

        require_once DOL_DOCUMENT_ROOT . '/core/lib/pdf.lib.php';
        require_once DOL_DOCUMENT_ROOT . '/core/lib/company.lib.php';

        // Create PDF
        $pdf = pdf_getInstance();

        if ($pdf) {
            $pdf->Open();
            $pagenb = 0;
            $pdf->SetTitle($outputlangs->convToOutputCharset($this->ref));
            $pdf->SetSubject($outputlangs->trans("Payslip"));
            $pdf->SetCreator("Dolibarr " . DOL_VERSION);
            $pdf->SetAuthor($outputlangs->convToOutputCharset($user->getFullName($outputlangs)));

            $pdf->SetMargins(10, 10, 10);
            $pdf->SetAutoPageBreak(1, 10);

            $pagenb++;
            $pdf->AddPage();

            // Get data
            require_once DOL_DOCUMENT_ROOT . '/custom/payroll/class/payrun.class.php';
            $payrun = new PayRun($this->db);
            $payrun->fetch($this->fk_payrun);

            require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
            $employee = new User($this->db);
            $employee->fetch($this->fk_user);

            // --- HEADER SECTION ---

            $logofile = '';
            if (!empty($mysoc->logo)) {
                $logofile = $conf->mycompany->dir_output . '/logos/' . $mysoc->logo;
            }
            if (empty($logofile) || !file_exists($logofile)) {
                $logofile = $conf->mycompany->dir_output . '/logos/logo2.png';
            }

            if (file_exists($logofile)) {
                $pdf->Image($logofile, 10, 10, 0, 25);
            }

            // Title (Top Right)
            $pdf->SetFont('helvetica', 'B', 18);
            $pdf->SetXY(110, 10);
            $pdf->Cell(90, 10, "BULLETIN DE PAIE", 0, 1, 'R');

            // Payment Mode Label
            $label_payment_mode = '';
            if ($this->fk_mode_reglement > 0) {
                $sql = "SELECT code, libelle FROM " . MAIN_DB_PREFIX . "c_paiement WHERE id = " . (int) $this->fk_mode_reglement;
                $resql = $this->db->query($sql);
                if ($resql && $obj = $this->db->fetch_object($resql)) {
                    $label_payment_mode = ($langs->trans("PaymentType" . $obj->code) != "PaymentType" . $obj->code) ? $langs->trans("PaymentType" . $obj->code) : $obj->libelle;
                }
            }
            // Decode HTML entities so Esp&egrave;ce becomes Espèce
            $label_payment_mode = dol_html_entity_decode($label_payment_mode, ENT_QUOTES);

            // Payment Date (Real payment date if paid, otherwise empty)
            if (!empty($this->date_payment)) {
                $display_date_val = dol_print_date($this->date_payment, '%d/%m/%Y');
            } else {
                $display_date_val = '';
            }

            // Period, Payment Date and Mode Box (Top Right)
            $pdf->SetFont('helvetica', '', 9);
            $pdf->SetXY(130, 20);
            $pdf->SetFillColor(255, 255, 255);

            // Row 1: Period
            $pdf->Cell(35, 6, "Période du:", 1, 0, 'L');
            $pdf->Cell(35, 6, dol_print_date(dol_mktime(0, 0, 0, $payrun->period_month, 1, $payrun->period_year), '%m/%Y'), 1, 1, 'C');

            // Row 2: Payment Date
            $pdf->SetX(130);
            $pdf->Cell(35, 6, "Date de paiement:", 1, 0, 'L');
            $pdf->Cell(35, 6, $display_date_val, 1, 1, 'C');

            // Row 3: Paid By
            $pdf->SetX(130);
            $pdf->Cell(35, 6, "Paie par:", 1, 0, 'L');
            $pdf->Cell(35, 6, $label_payment_mode, 1, 1, 'C');

            // Company Info (Left under logo) - Aligned with Employee Box
            $pdf->SetXY(10, 45); // Moved from 30 to 45
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->MultiCell(80, 5, dol_html_entity_decode($conf->global->MAIN_INFO_SOCIETE_NOM, ENT_QUOTES), 0, 'L');
            $pdf->SetFont('helvetica', '', 9); // Slightly larger font
            $company_address = $conf->global->MAIN_INFO_SOCIETE_ADDRESS;
            $company_town = (!empty($conf->global->MAIN_INFO_SOCIETE_ZIP) ? $conf->global->MAIN_INFO_SOCIETE_ZIP . " " : "") . (!empty($conf->global->MAIN_INFO_SOCIETE_TOWN) ? $conf->global->MAIN_INFO_SOCIETE_TOWN : "");
            $pdf->MultiCell(80, 4, dol_html_entity_decode($company_address . "\n" . $company_town, ENT_QUOTES), 0, 'L');

            // --- MIDDLE SECTION ---

            // Employee Box (Right)
            $pdf->SetXY(110, 45);
            $pdf->SetFont('helvetica', '', 9);
            $pdf->SetFillColor(255, 255, 255);
            $box_width = 90;
            $box_height = 35; // You might want to increase this if adding more lines
            $pdf->Rect(110, 45, $box_width, $box_height);

            $pdf->SetXY(112, 47);

            // Name
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->Cell($box_width - 4, 5, "Nom : " . $employee->getFullName($outputlangs), 0, 1);


            // Email
            if (!empty($employee->email)) {
                $pdf->SetX(112);
                $pdf->Cell($box_width - 4, 5, "Email : " . $employee->email, 0, 1);
            }

            // Phone
            $phone = !empty($employee->user_mobile) ? $employee->user_mobile : $employee->office_phone;
            if (!empty($phone)) {
                $pdf->SetX(112);
                $pdf->Cell($box_width - 4, 5, "Tél : " . $phone, 0, 1);
            }

            // Address
            $address = $employee->address;
            $ville = $employee->zip . " " . $employee->town;
            if (trim($address)) {
                $pdf->SetX(112);
                $pdf->MultiCell($box_width - 4, 5, "Adresse :" . $address . "\n" . "ville : " . $ville, 0, 'L');
            }

            // Seniority / Extra info (Left - if needed) - Moved down slightly if company info takes more space
            $pdf->SetXY(10, 70); // Moved from 55 to 70 to clear the company info which is now lower
            $pdf->SetFont('helvetica', '', 8);
            if (!empty($employee->job)) {
                $pdf->Cell(40, 5, "Emploi: " . $employee->job, 0, 1);
            }
            if (!empty($employee->dateemployment)) {
                $pdf->Cell(40, 5, "Date d'entrée: " . dol_print_date($employee->dateemployment, 'day'), 0, 1);
            }

            // --- TABLE SECTION ---
            $pdf->SetY(85);
            $pdf->SetFont('helvetica', 'B', 8);
            $pdf->SetFillColor(230, 230, 230);

            // Header columns
            $cols = array(
                'N°' => 15,
                'Désignation' => 110,
                'Gain' => 32,
                'Retenue' => 33
            );

            foreach ($cols as $label => $width) {
                $pdf->Cell($width, 7, $label, 1, 0, 'C', 1);
            }
            $pdf->Ln();

            $pdf->SetFont('helvetica', '', 8);

            // 1. Base Salary
            $pdf->Cell($cols['N°'], 7, '100', 1, 0, 'C');
            $pdf->Cell($cols['Désignation'], 7, "Salaire de base", 1, 0, 'L');
            $pdf->Cell($cols['Gain'], 7, price($this->base_salary, 0, $outputlangs, 0, 1), 1, 0, 'R');
            $pdf->Cell($cols['Retenue'], 7, '', 1, 1, 'R');

            // 2. Bonuses
            if ($this->bonuses > 0) {
                $pdf->Cell($cols['N°'], 7, '200', 1, 0, 'C');
                $pdf->Cell($cols['Désignation'], 7, "Primes / Indemnités", 1, 0, 'L');
                $pdf->Cell($cols['Gain'], 7, price($this->bonuses, 0, $outputlangs, 0, 1), 1, 0, 'R');
                $pdf->Cell($cols['Retenue'], 7, '', 1, 1, 'R');
            }

            // 3. Advances / Deductions
            if ($this->advances > 0) {
                $pdf->Cell($cols['N°'], 7, '900', 1, 0, 'C');
                $pdf->Cell($cols['Désignation'], 7, "Avances / Acomptes", 1, 0, 'L');
                $pdf->Cell($cols['Gain'], 7, '', 1, 0, 'R');
                $pdf->Cell($cols['Retenue'], 7, price($this->advances, 0, $outputlangs, 0, 1), 1, 1, 'R');
            }

            // --- BOTTOM SECTION ---

            // Totals row (optional in table)
            $pdf->SetFont('helvetica', 'B', 8);
            $total_gain = $this->base_salary + $this->bonuses;
            $total_retenue = $this->advances;

            $pdf->Cell($cols['N°'] + $cols['Désignation'], 7, "TOTAUX", 1, 0, 'R', 1);
            $pdf->Cell($cols['Gain'], 7, price($total_gain, 0, $outputlangs, 0, 1), 1, 0, 'R', 1);
            $pdf->Cell($cols['Retenue'], 7, price($total_retenue, 0, $outputlangs, 0, 1), 1, 1, 'R', 1);

            $pdf->Ln(10);

            // Summary Table (Cumuls)
            $y_cumuls = $pdf->GetY();
            $pdf->SetX(10);
            $pdf->SetFont('helvetica', 'B', 8);
            $pdf->Cell(30, 6, "Salaire Brut", 1, 0, 'C', 1);
            $pdf->Cell(30, 6, "Charges Sal.", 1, 0, 'C', 1);
            $pdf->Cell(30, 6, "Net Imposable", 1, 1, 'C', 1);
            $pdf->SetFont('helvetica', '', 8);
            $pdf->Cell(30, 8, price($total_gain, 0, $outputlangs, 0, 1), 1, 0, 'C');
            $pdf->Cell(30, 8, '0,00', 1, 0, 'C'); // No charge module yet
            $pdf->Cell(30, 8, price($this->net_salary, 0, $outputlangs, 0, 1), 1, 0, 'C');

            // Net à payer box (Prominent) - Aligned with Summary Table
            // Table width approx: 3x30 = 90. Starts at X=10. Ends at X=100.
            // Page width 210. Margins 10. Max X = 200.
            // Net box width 50. Should end at 200. Start X = 150.

            $pdf->SetXY(150, $y_cumuls);
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->SetFillColor(230, 230, 230);
            $pdf->Cell(50, 6, "NET A PAYER", 1, 1, 'C', 1); // Height 6 matches header height

            $pdf->SetXY(150, $y_cumuls + 6);
            $pdf->SetFont('helvetica', 'B', 14);
            $pdf->Cell(50, 8, price($this->net_salary, 0, $outputlangs, 0, 1), 1, 1, 'C'); // Height 8 matches data height

            // Footer
            $pdf->SetY(-20);
            $pdf->SetFont('helvetica', 'I', 7);
            $pdf->MultiCell(0, 4, "Pour vous aider à faire valoir vos droits, conservez ce bulletin de paie sans limitation de durée.", 0, 'C');
            $pdf->Cell(0, 5, "Page " . $pagenb . " - " . $conf->global->MAIN_INFO_SOCIETE_NOM, 0, 0, 'R');

            $pdf->Output($file, 'F');
            $pdf->Close();

            return 1;
        }

        return -1;
    }


}