<?php
/* Copyright (C) 2025 Your Company
 *
 * This program is free software
 */

/**
 * \file    payslip_card.php
 * \ingroup payroll
 * \brief   Card page for payslip
 */

// Load Dolibarr environment
$res = 0;
$sep = DIRECTORY_SEPARATOR;
for ($i = 0; $i < 5; $i++) {
    $dir = str_repeat('..' . $sep, $i);
    if (@include_once $dir . 'main.inc.php') {
        $res = 1;
        break;
    }
    if (@include_once $dir . 'master.inc.php') {
        $res = 1;
        break;
    }
}
if (!$res) {
    die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT . '/core/class/html.formcompany.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.formfile.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/payroll/class/payslip.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/payroll/class/payrun.class.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';

// Load translation files
$langs->loadLangs(array("payroll@payroll", "other", "bills", "banks"));

// Get parameters
$id = GETPOST('id', 'int');
$ref = GETPOST('ref', 'alpha');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$cancel = GETPOST('cancel', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

// Initialize technical objects
$object = new Payslip($db);

// Load object
include DOL_DOCUMENT_ROOT . '/core/actions_fetchobject.inc.php';

// Security check
$user_can_read = !empty($user->rights->payroll->payslip->read);
$user_can_write = !empty($user->rights->payroll->payslip->write);
$user_can_readown = !empty($user->rights->payroll->payslip->readown);

// Check if user can only read own payslips
if ($id && !$user_can_read) {
    if ($user_can_readown && $object->fk_user != $user->id) {
        accessforbidden();
    } elseif (!$user_can_readown) {
        accessforbidden();
    }
}

/*
 * Actions
 */

$parameters = array();
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action);
if ($reshook < 0) {
    setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (empty($reshook)) {
    $error = 0;

    $backurlforlist = DOL_URL_ROOT . '/custom/payroll/payslip_list.php';

    if (empty($backtopage) || ($cancel && empty($id))) {
        if (empty($backtopage) || ($cancel && strpos($backtopage, '__ID__'))) {
            if (empty($id) && (($action != 'add' && $action != 'create') || $cancel)) {
                $backtopage = $backurlforlist;
            } else {
                $backtopage = DOL_URL_ROOT . '/custom/payroll/payslip_card.php?id=' . ((!empty($id) && $id > 0) ? $id : '__ID__');
            }
        }
    }

    if ($cancel) {
        if (!empty($backtopage)) {
            header("Location: " . $backtopage);
            exit;
        }
        $action = '';
    }

    // Action to update
    if ($action == 'update' && $user_can_write && $object->status == Payslip::STATUS_DRAFT) {
        $object->base_salary = price2num(GETPOST('base_salary', 'alpha'));
        $object->advances = price2num(GETPOST('advances', 'alpha'));
        $object->bonuses = price2num(GETPOST('bonuses', 'alpha'));
        $object->fk_account = GETPOST('fk_account', 'int');
        $object->fk_mode_reglement = GETPOST('fk_mode_reglement', 'int');

        $result = $object->update($user);
        if ($result > 0) {
            // Update payrun totals
            $payrun = new PayRun($db);
            $payrun->fetch($object->fk_payrun);
            $payrun->calculateTotals();
            $payrun->update($user);

            setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
            header("Location: " . $_SERVER["PHP_SELF"] . '?id=' . $object->id);
            exit;
        } else {
            setEventMessages($object->error, $object->errors, 'errors');
            $action = 'edit';
        }
    }

    // Action to validate
    if ($action == 'confirm_validate' && $confirm == 'yes' && $user_can_write) {
        $result = $object->setValidated($user);
        if ($result > 0) {
            setEventMessages($langs->trans("PayslipValidated"), null, 'mesgs');
            header("Location: " . $_SERVER["PHP_SELF"] . '?id=' . $object->id);
            exit;
        } else {
            setEventMessages($object->error, $object->errors, 'errors');
        }
        $action = '';
    }

    // Action to generate PDF
    if ($action == 'builddoc' && $user_can_read) {
        $result = $object->generateDocument($langs);
        if ($result > 0) {
            setEventMessages($langs->trans("PDFGenerated"), null, 'mesgs');
        } else {
            setEventMessages($object->error, $object->errors, 'errors');
        }
        $action = '';
    }

    // Action to record payment
    if ($action == 'confirm_payment' && $confirm == 'yes' && !empty($user->rights->payroll->payment->write)) {
        $fk_bank = GETPOST('fk_bank', 'int');
        if ($fk_bank <= 0 && $object->fk_account > 0) {
            $fk_bank = $object->fk_account;
        }

        $payment_date = dol_mktime(12, 0, 0, GETPOST('payment_date_month', 'int'), GETPOST('payment_date_day', 'int'), GETPOST('payment_date_year', 'int'));
        if (empty($payment_date))
            $payment_date = dol_now();

        if ($fk_bank <= 0) {
            setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("BankAccount")), null, 'errors');
            $action = 'payment';
        } else {
            $result = $object->recordPayment($user, $fk_bank, $payment_date);
            if ($result > 0) {
                setEventMessages($langs->trans("PaymentRecorded"), null, 'mesgs');
                header("Location: " . $_SERVER["PHP_SELF"] . '?id=' . $object->id);
                exit;
            } else {
                setEventMessages($object->error ? $object->error : "Failed to record payment", $object->errors, 'errors');
            }
            $action = '';
        }
    }

    // Action to delete
    if ($action == 'confirm_delete' && $confirm == 'yes' && $user_can_write) {
        $result = $object->delete($user);
        if ($result > 0) {
            setEventMessages($langs->trans("RecordDeleted"), null, 'mesgs');
            header('Location: ' . DOL_URL_ROOT . '/custom/payroll/payslip_list.php');
            exit;
        } else {
            setEventMessages($object->error, $object->errors, 'errors');
        }
    }
}

/*
 * View
 */

$form = new Form($db);
$formfile = new FormFile($db);

$title = $langs->trans("Payslip");
$help_url = '';

llxHeader('', $title, $help_url);

// Part to edit record
if (($id || $ref) && $action == 'edit' && $user_can_write && $object->status == Payslip::STATUS_DRAFT) {
    // Refresh advances from salaries module
    $payrun = new PayRun($db);
    $payrun->fetch($object->fk_payrun);
    $object->advances = $object->fetchAdvancesFromSalaryModule($payrun->period_month, $payrun->period_year);
    $object->calculateNet();

    print load_fiche_titre($langs->trans("Payslip"), '', 'object_payroll');

    print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    print '<input type="hidden" name="action" value="update">';
    print '<input type="hidden" name="id" value="' . $object->id . '">';
    if ($backtopage) {
        print '<input type="hidden" name="backtopage" value="' . $backtopage . '">';
    }

    print dol_get_fiche_head();

    print '<table class="border centpercent tableforfieldedit">' . "\n";

    // Ref
    print '<tr><td class="fieldrequired titlefieldcreate">' . $langs->trans("Ref") . '</td><td>';
    print $object->ref;
    print '</td></tr>';

    // Employee
    $employee = new User($db);
    $employee->fetch($object->fk_user);

    print '<tr><td>' . $langs->trans("Employee") . '</td><td>';
    print $employee->getNomUrl(1);
    print '</td></tr>';

    // Base salary
    print '<tr><td class="fieldrequired">' . $langs->trans("BaseSalary") . '</td><td>';
    print '<input type="text" name="base_salary" class="flat minwidth200" value="' . price($object->base_salary) . '">';
    print '</td></tr>';

    // Advances (read only)
    print '<tr><td>' . $langs->trans("Advances") . '</td><td>';
    print price($object->advances);
    print ' <a href="' . DOL_URL_ROOT . '/custom/payroll/advance_list.php?search_employee=' . $object->fk_user . '&search_month=' . $payrun->period_month . '&search_year=' . $payrun->period_year . '" class="marginleftonly">';
    print img_picto($langs->trans("ListOfAdvances"), 'list') . ' ' . $langs->trans("ListOfAdvances");
    print '</a>';
    print '<input type="hidden" name="advances" value="' . $object->advances . '">';
    print '</td></tr>';

    // Bonuses
    print '<tr><td>' . $langs->trans("Bonuses") . '</td><td>';
    print '<input type="text" name="bonuses" class="flat minwidth200" value="' . price($object->bonuses) . '">';
    print '</td></tr>';

    // Net salary (read only)
    print '<tr><td><strong>' . $langs->trans("NetSalary") . '</strong></td><td>';
    $net = $object->base_salary - $object->advances + $object->bonuses;
    print '<strong>' . price($net) . '</strong>';
    print '</td></tr>';

    // Bank Account
    // Get first available bank account as default
    $default_account = 0;
    $sql_bank = "SELECT rowid FROM " . MAIN_DB_PREFIX . "bank_account WHERE clos = 0 ORDER BY rowid DESC LIMIT 1";
    $resql_bank = $db->query($sql_bank);
    if ($resql_bank) {
        $obj_bank = $db->fetch_object($resql_bank);
        if ($obj_bank) {
            $default_account = $obj_bank->rowid;
        }
    }
    $selected_account = $object->fk_account > 0 ? $object->fk_account : $default_account;

    print '<tr><td>' . $langs->trans("BankAccount") . '</td><td>';
    print $form->select_comptes($selected_account, 'fk_account', 0, '', 1, '', 0, '', 1);
    print '</td></tr>';

    // Payment Method
    require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
    print '<tr><td>' . $langs->trans("PaymentMode") . '</td><td>';
    print $form->select_types_paiements($object->fk_mode_reglement, 'fk_mode_reglement', '', 0, 1, 0, 0, 1, 'maxwidth200', 1);
    print '</td></tr>';

    print '</table>';

    print dol_get_fiche_end();

    print $form->buttonsSaveCancel();

    print '</form>';
}

// Part to show record
if ($object->id > 0 && (empty($action) || ($action != 'edit'))) {
    $head = array();
    $h = 0;

    $head[$h][0] = DOL_URL_ROOT . '/custom/payroll/payslip_card.php?id=' . $object->id;
    $head[$h][1] = $langs->trans("Card");
    $head[$h][2] = 'card';
    $h++;

    print dol_get_fiche_head($head, 'card', $langs->trans("Payslip"), -1, 'object_payroll');

    $formconfirm = '';

    // Confirmation to validate
    if ($action == 'validate') {
        $formconfirm = $form->formconfirm($_SERVER["PHP_SELF"] . '?id=' . $object->id, $langs->trans('Validate'), $langs->trans('ConfirmValidatePayslip'), 'confirm_validate', '', 0, 1);
    }

    // Confirmation to delete
    if ($action == 'delete') {
        $formconfirm = $form->formconfirm($_SERVER["PHP_SELF"] . '?id=' . $object->id, $langs->trans('Delete'), $langs->trans('ConfirmDeleteObject'), 'confirm_delete', '', 0, 1);
    }

    // Confirmation to record payment
    if ($action == 'payment') {
        $formquestion = array();
        if ($object->fk_account <= 0) {
            $formquestion[] = array('type' => 'other', 'name' => 'fk_bank', 'label' => $langs->trans("BankAccount"), 'value' => $form->select_comptes('', 'fk_bank', 0, '', 1, '', 0, '', 1));
            $formquestion[] = array('type' => 'date', 'name' => 'payment_date', 'label' => $langs->trans("Date"), 'value' => dol_now());
        }
        $formconfirm = $form->formconfirm($_SERVER["PHP_SELF"] . '?id=' . $object->id, $langs->trans('RecordPayment'), $langs->trans('ConfirmPayment'), 'confirm_payment', $formquestion, 'yes', 1);
    }

    // Print form confirm
    print $formconfirm;

    // Object card
    $linkback = '<a href="' . DOL_URL_ROOT . '/custom/payroll/payslip_list.php' . '">' . $langs->trans("BackToList") . '</a>';

    $morehtmlref = '<div class="refidno">';

    // PayRun
    $payrun = new PayRun($db);
    $payrun->fetch($object->fk_payrun);
    $morehtmlref .= $langs->trans('PayRun') . ' : ' . $payrun->getNomUrl(1);

    $morehtmlref .= '</div>';

    dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref);

    print '<div class="fichecenter">';
    print '<div class="fichehalfleft">';
    print '<div class="underbanner clearboth"></div>';
    print '<table class="border centpercent tableforfield">' . "\n";

    // Employee
    $employee = new User($db);
    $employee->fetch($object->fk_user);

    print '<tr><td class="titlefield">' . $langs->trans("Employee") . '</td><td>';
    print $employee->getNomUrl(1);
    print '</td></tr>';

    // Period
    print '<tr><td>' . $langs->trans("Period") . '</td><td>';
    print dol_print_date(dol_mktime(0, 0, 0, $payrun->period_month, 1, $payrun->period_year), '%B %Y');
    print '</td></tr>';

    // Status
    print '<tr><td>' . $langs->trans("Status") . '</td><td>';
    print $object->getLibStatut(4);
    print '</td></tr>';

    print '</table>';
    print '</div>';

    print '<div class="fichehalfright">';
    print '<div class="underbanner clearboth"></div>';
    print '<table class="border centpercent tableforfield">' . "\n";

    // Base Salary
    print '<tr><td class="titlefield">' . $langs->trans("BaseSalary") . '</td><td>';
    print price($object->base_salary);
    print '</td></tr>';

    // Advances
    if ($object->advances > 0) {
        print '<tr><td>' . $langs->trans("Advances") . '</td><td class="amount">';
        print '<span class="opacitymedium">- </span>' . price($object->advances);
        print ' <a href="' . DOL_URL_ROOT . '/custom/payroll/advance_list.php?search_employee=' . $object->fk_user . '&search_month=' . $payrun->period_month . '&search_year=' . $payrun->period_year . '" class="marginleftonly">';
        print img_picto($langs->trans("ListOfAdvances"), 'list') . ' ' . $langs->trans("ListOfAdvances");
        print '</a>';
        print '</td></tr>';
    }

    // Bonuses
    if ($object->bonuses > 0) {
        print '<tr><td>' . $langs->trans("Bonuses") . '</td><td class="amount">';
        print '<span class="opacitymedium">+ </span>' . price($object->bonuses);
        print '</td></tr>';
    }

    // Net Salary
    print '<tr><td><strong>' . $langs->trans("NetSalary") . '</strong></td><td>';
    print '<strong class="amount">' . price($object->net_salary) . '</strong>';
    print '</td></tr>';

    // Bank Account
    if ($object->fk_account > 0) {
        require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
        $account = new Account($db);
        $account->fetch($object->fk_account);
        print '<tr><td>' . $langs->trans("BankAccount") . '</td><td>';
        print $account->getNomUrl(1);
        print '</td></tr>';
    }

    // Payment Method
    if ($object->fk_mode_reglement > 0) {
        require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';
        $payment_mode = dol_getIdFromCode($db, $object->fk_mode_reglement, 'c_paiement', 'id', 'code');
        if ($payment_mode) {
            $sql = "SELECT libelle FROM " . MAIN_DB_PREFIX . "c_paiement WHERE id = " . (int) $object->fk_mode_reglement;
            $resql = $db->query($sql);
            if ($resql) {
                $obj_pm = $db->fetch_object($resql);
                if ($obj_pm) {
                    print '<tr><td>' . $langs->trans("PaymentMode") . '</td><td>';
                    print $obj_pm->libelle;
                    print '</td></tr>';
                }
            }
        }
    }

    print '</table>';
    print '</div>';
    print '</div>';

    print '<div class="clearboth"></div>';

    print dol_get_fiche_end();

    /*
     * Action buttons
     */
    print '<div class="tabsAction">' . "\n";

    if ($object->status == Payslip::STATUS_DRAFT) {
        if ($user_can_write) {
            print dolGetButtonAction($langs->trans('Modify'), '', 'default', $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=edit&token=' . newToken(), '', 1);
            print dolGetButtonAction($langs->trans('Validate'), '', 'default', $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=validate&token=' . newToken(), '', 1);
        }
    }

    if ($object->status == Payslip::STATUS_VALIDATED) {
        if (!empty($user->rights->payroll->payment->write)) {
            print dolGetButtonAction($langs->trans('RecordPayment'), '', 'default', $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=payment&token=' . newToken(), '', 1);
        }
    }

    if ($user_can_read) {
        print dolGetButtonAction($langs->trans('GeneratePDF'), '', 'default', $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=builddoc&token=' . newToken(), '', 1);

        // Check if PDF exists
        $filearray = dol_dir_list($conf->payroll->dir_output . '/payslip', "files", 0, $object->ref . '\\.pdf$');
        if (count($filearray) > 0) {
            $latest_file = end($filearray);
            $label_btn = "TÉLÉCHARGER BULLETIN";
            print '<a class="butAction" style="background-color: #28a745 !important; color: #fff !important;" href="' . DOL_URL_ROOT . '/custom/payroll/document.php?modulepart=payroll&file=' . urlencode('payslip/' . $latest_file['name']) . '">' . $label_btn . '</a>';
        }
    }

    if ($user_can_write) {
        print dolGetButtonAction($langs->trans('Delete'), '', 'delete', $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=delete&token=' . newToken(), '', 1);
    }

    print '</div>' . "\n";
}

// End of page
llxFooter();
$db->close();