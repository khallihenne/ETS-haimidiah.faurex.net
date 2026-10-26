<?php
/* Copyright (C) 2025 Your Company
 *
 * This program is free software
 */

/**
 * \file    payrun_summary.php
 * \ingroup payroll
 * \brief   Summary page for a pay run
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

require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT . '/custom/payroll/class/payrun.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/payroll/class/payslip.class.php';

$langs->loadLangs(array("payroll@payroll", "main"));

$id = GETPOST('id', 'int');
$action = GETPOST('action', 'aZ09');



// Security check
$user_can_read = !empty($user->rights->payroll->payrun->read);
if (!$user_can_read) {
    accessforbidden();
}

$object = new PayRun($db);
if ($id > 0) {
    $result = $object->fetch($id);
    if ($result <= 0) {
        dol_print_error($db);
        exit;
    }
} else {
    accessforbidden();
}

/*
 * View
 */

$title = $langs->trans("PayRunSummary") . " - " . $object->ref;
llxHeader('', $title);

$head = payrun_prepare_head($object);
print dol_get_fiche_head($head, 'summary', $langs->trans("PayRun"), -1, $object->picto);

$linkback = '<a href="' . DOL_URL_ROOT . '/custom/payroll/payrun_list.php?restore_lastsearch_values=1">' . $langs->trans("BackToList") . '</a>';

dol_banner_tab($object, 'id', $linkback, 1, 'rowid', 'ref', '');

print '<div class="fichecenter">';
print '<div class="underbanner clearboth"></div>';

print '<table class="border centpercent">';

// Totals
print '<tr><td class="titlefield">' . $langs->trans("TotalGross") . '</td><td align="right"><b>' . price($object->total_gross) . '</b></td></tr>';
print '<tr><td>' . $langs->trans("TotalBonuses") . '</td><td align="right">' . price($object->total_bonuses) . '</td></tr>';
print '<tr><td>' . $langs->trans("TotalAdvances") . '</td><td align="right"><font color="red">' . price(-1 * $object->total_advances) . '</font></td></tr>';
print '<tr><td class="titlefield">' . $langs->trans("TotalNet") . '</td><td align="right"><b>' . price($object->total_net) . '</b></td></tr>';

print '</table>';

print '<br>';

// List of payslips in this payrun
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>' . $langs->trans("Employee") . '</td>';
print '<td align="right">' . $langs->trans("BaseSalary") . '</td>';
print '<td align="right">' . $langs->trans("Bonuses") . '</td>';
print '<td align="right">' . $langs->trans("Advances") . '</td>';
print '<td align="right">' . $langs->trans("NetSalary") . '</td>';
print '</tr>';

$sql = "SELECT t.rowid, t.ref, t.fk_user, t.base_salary, t.bonuses, t.advances, t.net_salary,";
$sql .= " u.firstname, u.lastname";
$sql .= " FROM " . MAIN_DB_PREFIX . "payroll_payslip as t";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "user as u ON t.fk_user = u.rowid";
$sql .= " WHERE t.fk_payrun = " . (int) $object->id;
$sql .= " ORDER BY u.lastname ASC";

$resql = $db->query($sql);
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        print '<tr class="oddeven">';

        // Employee
        print '<td>';
        $user_static = new User($db);
        $user_static->id = $obj->fk_user;
        $user_static->lastname = $obj->lastname;
        $user_static->firstname = $obj->firstname;
        print $user_static->getNomUrl(1);
        print '</td>';

        print '<td align="right">' . price($obj->base_salary) . '</td>';
        print '<td align="right">' . price($obj->bonuses) . '</td>';
        print '<td align="right">' . price($obj->advances) . '</td>';
        print '<td align="right"><b>' . price($obj->net_salary) . '</b></td>';
        print '</tr>';
    }
}

print '</table>';

// Buttons
print '<div class="tabsAction">';
print '</div>';

print '</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();

/**
 * Prepare head
 *
 * @param PayRun $object Object
 * @return array
 */
function payrun_prepare_head($object)
{
    global $langs, $conf;

    $h = 0;
    $head = array();

    $head[$h][0] = DOL_URL_ROOT . '/custom/payroll/payrun_card.php?id=' . $object->id;
    $head[$h][1] = $langs->trans("Card");
    $head[$h][2] = 'card';
    $h++;

    $head[$h][0] = DOL_URL_ROOT . '/custom/payroll/payrun_summary.php?id=' . $object->id;
    $head[$h][1] = $langs->trans("Summary");
    $head[$h][2] = 'summary';
    $h++;

    return $head;
}
