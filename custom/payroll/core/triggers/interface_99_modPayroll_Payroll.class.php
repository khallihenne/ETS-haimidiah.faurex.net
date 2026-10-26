<?php
/* Copyright (C) 2025 Your Company
 *
 * This program is free software
 */

/**
 * \file    custom/payroll/core/triggers/interface_99_modPayroll_Payroll.class.php
 * \ingroup payroll
 * \brief   Trigger file for payroll module
 */

require_once DOL_DOCUMENT_ROOT . '/core/triggers/dolibarrtriggers.class.php';

/**
 * Class of triggers for Payroll module
 */
class InterfacePayroll extends DolibarrTriggers
{
    /**
     * @var string  Label
     */
    public $family = "payroll";

    /**
     * @var string  Description
     */
    public $description = "Triggers of Payroll module to handle automated accounting for advances.";

    /**
     * @var string  Version
     */
    public $version = '1.0.1';

    /**
     * Constructor
     * @param   DoliDB  $db Database handler
     */
    public function __construct(DoliDB $db)
    {
        parent::__construct($db);
        $this->name = 'InterfacePayroll';
    }

    /**
     * Function called when a Dolibarr event is done.
     *
     * @param   string      $action     Event action code
     * @param   object      $object     Object
     * @param   User        $user       Object user
     * @param   Translate   $langs      Object langs
     * @param   Conf        $conf       Object conf
     * @return  int                     if KO: <0, if no trigger ran: 0, if OK: >0
     */
    public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
    {
        if (empty($conf->payroll->enabled))
            return 0;

        if ($action == 'PAYMENTSALARY_CREATE') {
            dol_syslog("PayrollTrigger: Action PAYMENTSALARY_CREATE detected for payment ID " . $object->id, LOG_DEBUG);
            return $this->generateAdvanceAccountingEntries($object, $user, $langs, $conf);
        }

        return 0;
    }

    /**
     * Generate accounting entries for salary advances (payments)
     *
     * @param   object      $object     PaymentSalary object
     * @param   User        $user       User generating entries
     * @param   Translate   $langs      Langs object
     * @param   Conf        $conf       Conf object
     * @return  int                     <0 if KO, >0 if OK
     */
    private function generateAdvanceAccountingEntries($object, $user, $langs, $conf)
    {
        if (empty($conf->accounting->enabled))
            return 0;

        require_once DOL_DOCUMENT_ROOT . '/accountancy/class/bookkeeping.class.php';
        require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
        require_once DOL_DOCUMENT_ROOT . '/salaries/class/salary.class.php';
        require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';

        $now = dol_now();
        $error = 0;

        // Get accounting accounts from module setup
        $account_advances = getDolGlobalString('PAYROLL_ACCOUNT_ADVANCES', '425');

        // Fetch Salary info to get the employee and label
        $salary = new Salary($this->db);
        if ($salary->fetch($object->fk_salary) <= 0) {
            dol_syslog("PayrollTrigger: Failed to fetch salary " . $object->fk_salary, LOG_ERR);
            return 0;
        }

        // Fetch Employee info
        $employee = new User($this->db);
        if ($employee->fetch($salary->fk_user) <= 0) {
            dol_syslog("PayrollTrigger: Failed to fetch employee " . $salary->fk_user, LOG_ERR);
            return 0;
        }

        // Identify Bank Account
        $fk_bank_acc = GETPOSTINT('accountid');
        if ($fk_bank_acc <= 0) {
            // Fallback: search in bank lines
            $sql = "SELECT b.fk_account FROM " . MAIN_DB_PREFIX . "bank as b WHERE b.rowid = " . (int) $object->fk_bank;
            $resql = $this->db->query($sql);
            if ($resql && ($obj = $this->db->fetch_object($resql))) {
                $fk_bank_acc = $obj->fk_account;
            }
        }

        if ($fk_bank_acc <= 0) {
            dol_syslog("PayrollTrigger: No bank account found for payment, skipping bookkeeping", LOG_WARNING);
            return 0;
        }

        $bank_acc = new Account($this->db);
        $bank_acc->fetch($fk_bank_acc);
        $account_bank = $bank_acc->account_number;
        if (empty($account_bank)) {
            $account_bank = '512';
        }

        $piece_num = 'ADV-' . $object->id;
        $label_operation = "Avance sur salaire - " . $employee->getFullName($langs);
        if (!empty($salary->label))
            $label_operation .= " (" . $salary->label . ")";

        $date_entry = $object->datep;

        // Entry 1: Debit Advance account (425) -> DEBIT
        $bookkeeping = new BookKeeping($this->db);
        $bookkeeping->doc_date = $date_entry;
        $bookkeeping->doc_ref = 'ADV' . $object->id;
        $bookkeeping->date_creation = $now;
        $bookkeeping->doc_type = 'salary_advance';
        $bookkeeping->fk_doc = $object->id;
        $bookkeeping->fk_docdet = 0;
        $bookkeeping->thirdparty_code = $employee->login;
        $bookkeeping->subledger_account = '';
        $bookkeeping->subledger_label = $employee->getFullName($langs);
        $bookkeeping->numero_compte = $account_advances;
        $bookkeeping->label_compte = "Avances et acomptes";
        $bookkeeping->label_operation = $label_operation;
        $bookkeeping->montant = $object->amount;
        $bookkeeping->sens = 'D';
        $bookkeeping->debit = $object->amount;
        $bookkeeping->credit = 0;
        $bookkeeping->code_journal = getDolGlobalString('PAYROLL_BANK_JOURNAL', 'BQ');
        $bookkeeping->journal_label = 'Journal de Trésorerie';
        $bookkeeping->piece_num = $piece_num;

        if ($bookkeeping->create($user) < 0) {
            $this->error = $bookkeeping->error;
            dol_syslog("PayrollTrigger: Error creating Debit entry: " . $this->error, LOG_ERR);
            $error++;
        }

        // Entry 2: Credit Bank account -> CREDIT
        if (!$error) {
            $bookkeeping = new BookKeeping($this->db);
            $bookkeeping->doc_date = $date_entry;
            $bookkeeping->doc_ref = 'ADV' . $object->id;
            $bookkeeping->date_creation = $now;
            $bookkeeping->doc_type = 'salary_advance';
            $bookkeeping->fk_doc = $object->id;
            $bookkeeping->fk_docdet = 0;
            $bookkeeping->thirdparty_code = $employee->login;
            $bookkeeping->subledger_account = '';
            $bookkeeping->subledger_label = $employee->getFullName($langs);
            $bookkeeping->numero_compte = $account_bank;
            $bookkeeping->label_compte = $bank_acc->label;
            $bookkeeping->label_operation = $label_operation;
            $bookkeeping->montant = $object->amount;
            $bookkeeping->sens = 'C';
            $bookkeeping->debit = 0;
            $bookkeeping->credit = $object->amount;
            $bookkeeping->code_journal = getDolGlobalString('PAYROLL_BANK_JOURNAL', 'BQ');
            $bookkeeping->journal_label = 'Journal de Trésorerie';
            $bookkeeping->piece_num = $piece_num;

            if ($bookkeeping->create($user) < 0) {
                $this->error = $bookkeeping->error;
                dol_syslog("PayrollTrigger: Error creating Credit entry: " . $this->error, LOG_ERR);
                $error++;
            }
        }

        if (!$error) {
            dol_syslog("PayrollTrigger: Accounting entries generated successfully for Advance payment " . $object->id, LOG_DEBUG);
        }

        return ($error ? -1 : 1);
    }
}
