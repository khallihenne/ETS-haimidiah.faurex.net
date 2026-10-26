<?php
/* Copyright (C) 2025 Your Company
 *
 * This program is free software
 */

/**
 * \file    payrun_card.php
 * \ingroup payroll
 * \brief   Card page for pay run
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
require_once DOL_DOCUMENT_ROOT . '/custom/payroll/class/payrun.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/payroll/class/payslip.class.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';

// Load translation files
$langs->loadLangs(array("payroll@payroll", "other"));

// Get parameters
$id = GETPOST('id', 'int');
$ref = GETPOST('ref', 'alpha');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$cancel = GETPOST('cancel', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

// Initialize technical objects
$object = new PayRun($db);

// Load object
include DOL_DOCUMENT_ROOT . '/core/actions_fetchobject.inc.php';

// Security check
$user_can_read = !empty($user->rights->payroll->payrun->read);
$user_can_write = !empty($user->rights->payroll->payrun->write);
$user_can_delete = !empty($user->rights->payroll->payrun->delete);
$user_can_validate = !empty($user->rights->payroll->payrun->validate);

if ($id || $ref) {
    if (!$user_can_read) {
        accessforbidden();
    }
} else {
    if (!$user_can_write) {
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

    $backurlforlist = DOL_URL_ROOT . '/custom/payroll/payrun_list.php';

    if (empty($backtopage) || ($cancel && empty($id))) {
        if (empty($backtopage) || ($cancel && strpos($backtopage, '__ID__'))) {
            if (empty($id) && (($action != 'add' && $action != 'create') || $cancel)) {
                $backtopage = $backurlforlist;
            } else {
                $backtopage = DOL_URL_ROOT . '/custom/payroll/payrun_card.php?id=' . ((!empty($id) && $id > 0) ? $id : '__ID__');
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

    // Action to create
    if ($action == 'add' && $user_can_write) {
        $object->ref = GETPOST('ref', 'alpha');
        $object->label = GETPOST('label', 'alpha');
        $object->period_month = GETPOST('periodmonth', 'int');
        $object->period_year = GETPOST('periodyear', 'int');
        $object->status = PayRun::STATUS_DRAFT;

        if (empty($object->period_month) || empty($object->period_year)) {
            setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("Period")), null, 'errors');
            $error++;
        }

        if (!$error) {
            // Check if period already exists
            $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "payroll_payrun";
            $sql .= " WHERE period_month = " . (int) $object->period_month . " AND period_year = " . (int) $object->period_year;
            $sql .= " AND entity = " . $conf->entity;
            $resql = $db->query($sql);
            if ($resql && $db->num_rows($resql) > 0) {
                setEventMessages($langs->trans("ErrorPayRunPeriodAlreadyExists"), null, 'errors');
                $error++;
            }

            // Check if label already exists
            if (!$error && !empty($object->label)) {
                $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "payroll_payrun";
                $sql .= " WHERE label = '" . $db->escape($object->label) . "'";
                $sql .= " AND entity = " . $conf->entity;
                $resql = $db->query($sql);
                if ($resql && $db->num_rows($resql) > 0) {
                    setEventMessages($langs->trans("ErrorPayRunLabelAlreadyExists"), null, 'errors');
                    $error++;
                }
            }
        }

        if (!$error) {
            $result = $object->create($user);
            if ($result > 0) {
                setEventMessages($langs->trans("PayRunCreated"), null, 'mesgs');
                header("Location: " . DOL_URL_ROOT . "/custom/payroll/payrun_card.php?id=" . $result);
                exit;
            } else {
                $error++;
                setEventMessages($object->error, $object->errors, 'errors');
                $action = 'create';
            }
        } else {
            $action = 'create';
        }
    }

    // Action to generate payslips
    if ($action == 'generatepayslips' && $user_can_write) {
        $result = $object->generatePayslips($user);
        if ($result > 0) {
            setEventMessages($langs->trans("PayslipsGenerated") . ': ' . $result, null, 'mesgs');
        } else {
            setEventMessages($object->error, $object->errors, 'errors');
        }
        $action = '';
    }

    // Action to validate
    if ($action == 'confirm_validate' && $confirm == 'yes' && $user_can_validate) {
        $error = 0;
        // Check if all payslips have a bank account and payment mode (handling NULL and <= 0)
        $sql = "SELECT COUNT(*) as nb FROM " . MAIN_DB_PREFIX . "payroll_payslip";
        $sql .= " WHERE fk_payrun = " . (int) $object->id;
        $sql .= " AND (fk_account <= 0 OR fk_account IS NULL OR fk_mode_reglement <= 0 OR fk_mode_reglement IS NULL)";
        $resql = $db->query($sql);
        if ($resql) {
            $obj = $db->fetch_object($resql);
            if ($obj->nb > 0) {
                setEventMessages($langs->trans("ErrorMissingBankOrPaymentMode"), null, 'errors');
                $error++;
            }
        }

        if (!$error) {
            $result = $object->setValidated($user);
            if ($result > 0) {
                setEventMessages($langs->trans("PayRunValidated"), null, 'mesgs');
                header("Location: " . $_SERVER["PHP_SELF"] . '?id=' . $object->id);
                exit;
            } else {
                setEventMessages($object->error, $object->errors, 'errors');
            }
        }
        $action = '';
    }

    // Action to record payment
    if ($action == 'confirm_payment' && $confirm == 'yes' && ($user_can_write || !empty($user->rights->payroll->payment->write))) {
        dol_syslog("Processing payment for payrun " . $object->id);
        $result = $object->recordPayment($user);
        if ($result > 0) {
            setEventMessages($langs->trans("PaymentRecordedSuccess") . ': ' . $result, null, 'mesgs');
            header("Location: " . $_SERVER["PHP_SELF"] . '?id=' . $object->id);
            exit;
        } elseif ($result == 0) {
            setEventMessages($object->error ? $object->error : "No validation/payment to record", null, 'warnings');
        } else {
            setEventMessages($object->error, $object->errors, 'errors');
        }
        $action = '';
    }

    // Action to delete
    if ($action == 'confirm_delete' && $confirm == 'yes' && $user_can_delete) {
        $result = $object->delete($user);
        if ($result > 0) {
            setEventMessages($langs->trans("RecordDeleted"), null, 'mesgs');
            header('Location: ' . $backurlforlist);
            exit;
        } else {
            setEventMessages($object->error, $object->errors, 'errors');
        }
    }

    // Individual Action: Validate single payslip
    if ($action == 'confirm_validate_payslip' && $confirm == 'yes' && $user_can_validate) {
        $payslip_id = GETPOST('payslip_id', 'int');
        $payslip = new Payslip($db);
        if ($payslip->fetch($payslip_id) > 0) {
            $result = $payslip->setValidated($user);
            if ($result > 0) {
                setEventMessages($langs->trans("PayslipValidated"), null, 'mesgs');
                header("Location: " . $_SERVER["PHP_SELF"] . '?id=' . $object->id);
                exit;
            } else {
                setEventMessages($payslip->error, $payslip->errors, 'errors');
            }
        }
        $action = '';
    }

    // Individual Action: Record payment for single payslip
    if ($action == 'confirm_payment_payslip' && $confirm == 'yes' && !empty($user->rights->payroll->payment->write)) {
        $payslip_id = GETPOST('payslip_id', 'int');
        $fk_bank = GETPOST('fk_bank', 'int');

        $payslip = new Payslip($db);
        if ($payslip->fetch($payslip_id) > 0) {
            if ($fk_bank <= 0 && $payslip->fk_account > 0) {
                $fk_bank = $payslip->fk_account;
            }

            $payment_date = dol_mktime(12, 0, 0, GETPOST('payment_date_month', 'int'), GETPOST('payment_date_day', 'int'), GETPOST('payment_date_year', 'int'));
            if (empty($payment_date))
                $payment_date = dol_now();

            if ($fk_bank <= 0) {
                setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("BankAccount")), null, 'errors');
                $action = 'payment_payslip';
            } else {
                $result = $payslip->recordPayment($user, $fk_bank, $payment_date);
                if ($result > 0) {
                    setEventMessages($langs->trans("PaymentRecorded"), null, 'mesgs');
                    header("Location: " . $_SERVER["PHP_SELF"] . '?id=' . $object->id);
                    exit;
                } else {
                    setEventMessages($payslip->error ? $payslip->error : "Failed to record payment", $payslip->errors, 'errors');
                }
            }
        }
        $action = '';
    }
}

/*
 * View
 */

$form = new Form($db);
$formfile = new FormFile($db);
require_once DOL_DOCUMENT_ROOT . '/core/class/html.formother.class.php';
$formother = new FormOther($db);

$title = $langs->trans("PayRun");
$help_url = '';

llxHeader('', $title, $help_url);

// Part to create
if ($action == 'create') {
    print load_fiche_titre($langs->trans("NewPayRun"), '', 'object_payroll');

    print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    print '<input type="hidden" name="action" value="add">';
    if ($backtopage) {
        print '<input type="hidden" name="backtopage" value="' . $backtopage . '">';
    }

    print dol_get_fiche_head(array(), '');

    print '<table class="border centpercent tableforfieldcreate">' . "\n";

    // Reference
    print '<tr><td class="fieldrequired">' . $langs->trans("Ref") . '</td><td>';
    print '<input type="text" name="ref" class="flat minwidth300" value="' . dol_escape_htmltag(GETPOST('ref', 'alpha')) . '" placeholder="' . $langs->trans("AutoGenerated") . '">';
    print '</td></tr>';

    // Label
    print '<tr><td>' . $langs->trans("Label") . '</td><td>';
    print '<input type="text" name="label" class="flat minwidth300" value="' . dol_escape_htmltag(GETPOST('label', 'alpha')) . '">';
    print '</td></tr>';

    // Period
    print '<tr><td class="fieldrequired">' . $langs->trans("Period") . '</td><td>';
    print $formother->select_month(GETPOST('periodmonth', 'int') ? GETPOST('periodmonth', 'int') : date('m'), 'periodmonth', 0, 0);
    print ' ';
    print $formother->select_year(GETPOST('periodyear', 'int') ? GETPOST('periodyear', 'int') : date('Y'), 'periodyear', 0, 5, 5);
    print ' <a href="#" id="set_current_period" class="marginleftonly">' . $langs->trans("ThisMonth") . '</a>';
    print '<script type="text/javascript">
    jQuery(document).ready(function() {
        jQuery("#set_current_period").click(function(e) {
            e.preventDefault();
            var now = new Date();
            var month = now.getMonth() + 1;
            var year = now.getFullYear();
            jQuery("#periodmonth").val(month).trigger("change");
            jQuery("#periodyear").val(year).trigger("change");
        });
    });
    </script>';
    print '</td></tr>';

    print '</table>';

    print dol_get_fiche_end();

    print $form->buttonsSaveCancel("Create");

    print '</form>';

    dol_set_focus('input[name="label"]');
}

// Part to edit record
if (($id || $ref) && $action == 'edit') {
    print load_fiche_titre($langs->trans("PayRun"), '', 'object_payroll');

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

    // Label
    print '<tr><td>' . $langs->trans("Label") . '</td><td>';
    print '<input type="text" name="label" class="flat minwidth300" value="' . dol_escape_htmltag($object->label) . '">';
    print '</td></tr>';

    // Period
    print '<tr><td>' . $langs->trans("Period") . '</td><td>';
    print dol_print_date(dol_mktime(0, 0, 0, $object->period_month, 1, $object->period_year), '%B %Y');
    print '</td></tr>';

    print '</table>';

    print dol_get_fiche_end();

    print $form->buttonsSaveCancel();

    print '</form>';
}

// Part to show record
if ($object->id > 0 && (empty($action) || ($action != 'edit' && $action != 'create'))) {
    $head = array();
    $h = 0;

    $head[$h][0] = DOL_URL_ROOT . '/custom/payroll/payrun_card.php?id=' . $object->id;
    $head[$h][1] = $langs->trans("Card");
    $head[$h][2] = 'card';
    $h++;

    $head[$h][0] = DOL_URL_ROOT . '/custom/payroll/payrun_summary.php?id=' . $object->id;
    $head[$h][1] = $langs->trans("Summary");
    $head[$h][2] = 'summary';
    $h++;

    print dol_get_fiche_head($head, 'card', $langs->trans("PayRun"), -1, 'object_payroll');

    $formconfirm = '';

    // Confirmation to delete
    if ($action == 'delete') {
        $formconfirm = $form->formconfirm($_SERVER["PHP_SELF"] . '?id=' . $object->id, $langs->trans('DeletePayRun'), $langs->trans('ConfirmDeleteObject'), 'confirm_delete', '', 0, 1);
    }

    // Confirmation to validate
    if ($action == 'validate') {
        $formconfirm = $form->formconfirm($_SERVER["PHP_SELF"] . '?id=' . $object->id, $langs->trans('ValidatePayRun'), $langs->trans('ConfirmValidatePayRun'), 'confirm_validate', '', 0, 1);
    }

    // Confirmation to record payment
    if ($action == 'payment') {
        $formconfirm = $form->formconfirm($_SERVER["PHP_SELF"] . '?id=' . $object->id, $langs->trans('RecordPayment'), $langs->trans('ConfirmRecordPayment'), 'confirm_payment', '', 0, 1);
    }

    // Confirmation to validate single payslip
    if ($action == 'validate_payslip') {
        $payslip_id = GETPOST('payslip_id', 'int');
        $formconfirm = $form->formconfirm($_SERVER["PHP_SELF"] . '?id=' . $object->id . '&payslip_id=' . $payslip_id, $langs->trans('Validate'), $langs->trans('ConfirmValidatePayslip'), 'confirm_validate_payslip', '', 0, 1);
    }

    // Confirmation to record payment for single payslip
    if ($action == 'payment_payslip') {
        $payslip_id = GETPOST('payslip_id', 'int');
        $payslip = new Payslip($db);
        $payslip->fetch($payslip_id);

        $formquestion = array();
        if ($payslip->fk_account <= 0) {
            $formquestion[] = array('type' => 'other', 'name' => 'fk_bank', 'label' => $langs->trans("BankAccount"), 'value' => $form->select_comptes('', 'fk_bank', 0, '', 1, '', 0, '', 1));
            $formquestion[] = array('type' => 'date', 'name' => 'payment_date', 'label' => $langs->trans("Date"), 'value' => dol_now());
        }
        $formconfirm = $form->formconfirm($_SERVER["PHP_SELF"] . '?id=' . $object->id . '&payslip_id=' . $payslip_id, $langs->trans('RecordPayment'), $langs->trans('ConfirmPayment'), 'confirm_payment_payslip', $formquestion, 'yes', 1);
    }

    // Print form confirm
    print $formconfirm;

    // Object card
    $linkback = '<a href="' . DOL_URL_ROOT . '/custom/payroll/payrun_list.php' . (!empty($socid) ? '?socid=' . $socid : '') . '">' . $langs->trans("BackToList") . '</a>';

    $morehtmlref = '<div class="refidno">';
    $morehtmlref .= '</div>';

    dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref);

    print '<div class="fichecenter">';
    print '<div class="fichehalfleft">';
    print '<div class="underbanner clearboth"></div>';
    print '<table class="border centpercent tableforfield">' . "\n";

    // Label
    print '<tr><td class="titlefield">' . $langs->trans("Label") . '</td><td>';
    print dol_escape_htmltag($object->label);
    print '</td></tr>';

    // Period
    print '<tr><td>' . $langs->trans("Period") . '</td><td>';
    print dol_print_date(dol_mktime(0, 0, 0, $object->period_month, 1, $object->period_year), '%B %Y');
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

    // Total Gross
    print '<tr><td class="titlefield">' . $langs->trans("TotalGross") . '</td><td>';
    print price($object->total_gross);
    print '</td></tr>';



    // Total Bonuses
    if ($object->total_bonuses > 0) {
        print '<tr><td>' . $langs->trans("TotalBonuses") . '</td><td>';
        print price($object->total_bonuses);
        print '</td></tr>';
    }

    // Total Net
    print '<tr><td class="titlefield">' . $langs->trans("TotalNet") . '</td><td>';
    print '<strong>' . price($object->total_net) . '</strong>';
    print '</td></tr>';

    print '</table>';
    print '</div>';
    print '</div>';

    print '<div class="clearboth"></div>';

    print dol_get_fiche_end();

    /*
     * Action buttons
     */
    $nb_payslips = 0;
    $sql = "SELECT COUNT(*) as nb FROM " . MAIN_DB_PREFIX . "payroll_payslip WHERE fk_payrun = " . (int) $object->id;
    $resql_nb = $db->query($sql);
    if ($resql_nb) {
        $obj_nb = $db->fetch_object($resql_nb);
        $nb_payslips = $obj_nb->nb;
    }

    print '<div class="tabsAction">' . "\n";

    if ($object->status == PayRun::STATUS_DRAFT) {
        if ($user_can_write) {
            print dolGetButtonAction($langs->trans('Modify'), '', 'default', $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=edit&token=' . newToken(), '', 1);
            print dolGetButtonAction($langs->trans('GenerateRefreshPayslips'), '', 'default', $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=generatepayslips&token=' . newToken(), '', 1);
        }
        if ($nb_payslips > 0 && $user_can_validate) {
            print dolGetButtonAction($langs->trans('Validate'), '', 'default', $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=validate&token=' . newToken(), '', 1);
        }
    }

    if ($object->status == PayRun::STATUS_VALIDATED) {
        if (!empty($user->rights->payroll->payment->write)) {
            print dolGetButtonAction($langs->trans('RecordPayment'), '', 'default', $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=payment&token=' . newToken(), '', 1);
        }
    }

    if ($user_can_delete) {
        print dolGetButtonAction($langs->trans('Delete'), '', 'delete', $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=delete&token=' . newToken(), '', 1);
    }

    print '</div>' . "\n";

    /*
     * List of payslips
     */
    print '<div class="div-table-responsive-no-min">';
    print '<table class="noborder centpercent">';

    print '<tr class="liste_titre">';
    print '<th>' . $langs->trans("Ref") . '</th>';
    print '<th>' . $langs->trans("Employee") . '</th>';
    print '<th class="right">' . $langs->trans("BaseSalary") . '</th>';
    print '<th class="right">' . $langs->trans("Advances") . '</th>';
    print '<th class="right">' . $langs->trans("Bonuses") . '</th>';
    print '<th class="right">' . $langs->trans("NetSalary") . '</th>';
    print '<th>' . $langs->trans("BankAccount") . '</th>';
    print '<th>' . $langs->trans("PaymentMode") . '</th>';
    print '<th class="center">' . $langs->trans("Status") . '</th>';
    print '<th class="center">' . $langs->trans("Actions") . '</th>';
    print '</tr>';

    $sql = "SELECT ps.rowid, ps.ref, ps.fk_user, ps.base_salary, ps.advances,";
    $sql .= " ps.bonuses, ps.net_salary, ps.status, ps.fk_account, ps.fk_mode_reglement,";
    $sql .= " u.firstname, u.lastname, ba.label as bank_label, cp.libelle as payment_label";
    $sql .= " FROM " . MAIN_DB_PREFIX . "payroll_payslip as ps";
    $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "user as u ON ps.fk_user = u.rowid";
    $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "bank_account as ba ON ps.fk_account = ba.rowid";
    $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "c_paiement as cp ON ps.fk_mode_reglement = cp.id";
    $sql .= " WHERE ps.fk_payrun = " . (int) $object->id;
    $sql .= " ORDER BY u.lastname, u.firstname";

    $resql = $db->query($sql);
    if ($resql) {
        $num = $db->num_rows($resql);
        $i = 0;

        if ($num == 0) {
            print '<tr><td colspan="10"><span class="opacitymedium">' . $langs->trans("NoPayslipsYet") . '</span></td></tr>';
        }

        while ($i < $num) {
            $obj = $db->fetch_object($resql);

            $payslip = new Payslip($db);
            $payslip->id = $obj->rowid;
            $payslip->ref = $obj->ref;
            $payslip->status = $obj->status;

            $employee = new User($db);
            $employee->id = $obj->fk_user;
            $employee->firstname = $obj->firstname;
            $employee->lastname = $obj->lastname;

            print '<tr class="oddeven">';
            print '<td>' . $payslip->getNomUrl(1) . '</td>';
            print '<td>' . $employee->getNomUrl(1) . '</td>';
            print '<td class="right">' . price($obj->base_salary) . '</td>';
            print '<td class="right">' . price($obj->advances) . '</td>';
            print '<td class="right">' . price($obj->bonuses) . '</td>';
            print '<td class="right"><strong>' . price($obj->net_salary) . '</strong></td>';

            // Bank Account
            print '<td>';
            if (!empty($obj->bank_label)) {
                print dol_escape_htmltag($obj->bank_label);
            } else {
                print '<span class="opacitymedium">-</span>';
            }
            print '</td>';

            // Payment Method
            print '<td>';
            if (!empty($obj->payment_label)) {
                print dol_escape_htmltag($obj->payment_label);
            } else {
                print '<span class="opacitymedium">-</span>';
            }
            print '</td>';

            print '<td class="center">' . $payslip->getLibStatut(5) . '</td>';

            // Actions
            print '<td class="center">';
            if ($obj->status == Payslip::STATUS_DRAFT && $user_can_validate) {
                print '<a href="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=validate_payslip&payslip_id=' . $obj->rowid . '&token=' . newToken() . '">';
                print img_picto($langs->trans("Validate"), 'check') . '</a> ';
            }
            if ($obj->status == Payslip::STATUS_VALIDATED && !empty($user->rights->payroll->payment->write)) {
                print '<a href="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=payment_payslip&payslip_id=' . $obj->rowid . '&token=' . newToken() . '">';
                print img_picto($langs->trans("RecordPayment"), 'money') . '</a> ';
            }
            print '</td>';

            print '</tr>';

            $i++;
        }
        $db->free($resql);
    } else {
        dol_print_error($db);
    }

    print '</table>';
    print '</div>';
}

// End of page
llxFooter();
$db->close();