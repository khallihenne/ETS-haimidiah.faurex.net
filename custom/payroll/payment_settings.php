<?php
/* Copyright (C) 2025 Your Company
 *
 * This program is free software
 */

/**
 * \file    payment_settings.php
 * \ingroup payroll
 * \brief   Payment settings configuration page
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

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';

// Load translation files
$langs->loadLangs(array("payroll@payroll", "admin"));

// Security check
if (!$user->admin) {
    accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');

/*
 * Actions
 */

if ($action == 'save') {
    $default_bank_account = GETPOST('default_bank_account', 'int');
    $default_payment_method = GETPOST('default_payment_method', 'int');

    $error = 0;

    // Save bank account
    if ($default_bank_account > 0) {
        $result = dolibarr_set_const($db, 'PAYROLL_DEFAULT_BANK_ACCOUNT', $default_bank_account, 'chaine', 0, '', $conf->entity);
        if (!$result) {
            $error++;
        }
    } else {
        dolibarr_del_const($db, 'PAYROLL_DEFAULT_BANK_ACCOUNT', $conf->entity);
    }

    // Save payment method
    if ($default_payment_method > 0) {
        $result = dolibarr_set_const($db, 'PAYROLL_DEFAULT_PAYMENT_METHOD', $default_payment_method, 'chaine', 0, '', $conf->entity);
        if (!$result) {
            $error++;
        }
    } else {
        dolibarr_del_const($db, 'PAYROLL_DEFAULT_PAYMENT_METHOD', $conf->entity);
    }

    if (!$error) {
        setEventMessages($langs->trans("PaymentSettingsSaved"), null, 'mesgs');
    } else {
        setEventMessages($langs->trans("Error"), null, 'errors');
    }
}

/*
 * View
 */

$form = new Form($db);

$title = $langs->trans("PaymentSettings");
$help_url = '';

llxHeader('', $title, $help_url);

print load_fiche_titre($langs->trans("PaymentSettings"), '', 'title_setup');

print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="save">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>' . $langs->trans("Parameter") . '</td>';
print '<td>' . $langs->trans("Value") . '</td>';
print '</tr>';

// Default Bank Account
print '<tr class="oddeven">';
print '<td><label for="default_bank_account">' . $langs->trans("DefaultBankAccount") . '</label></td>';
print '<td>';
$selected = !empty($conf->global->PAYROLL_DEFAULT_BANK_ACCOUNT) ? $conf->global->PAYROLL_DEFAULT_BANK_ACCOUNT : 0;
print $form->select_comptes($selected, 'default_bank_account', 0, '', 1, '', 0, '', 1);
print '</td>';
print '</tr>';

// Default Payment Method
print '<tr class="oddeven">';
print '<td><label for="default_payment_method">' . $langs->trans("DefaultPaymentMethod") . '</label></td>';
print '<td>';
$selected = !empty($conf->global->PAYROLL_DEFAULT_PAYMENT_METHOD) ? $conf->global->PAYROLL_DEFAULT_PAYMENT_METHOD : 0;
print $form->select_types_paiements($selected, 'default_payment_method', '', 0, 1, 0, 0, 1, 'maxwidth200', 1);
print '</td>';
print '</tr>';

print '</table>';

print '<div class="center">';
print '<input type="submit" class="button button-save" value="' . $langs->trans("Save") . '">';
print '</div>';

print '</form>';

llxFooter();
$db->close();
