<?php
/* Copyright (C) 2025 Your Company
 *
 * This program is free software
 */

/**
 * \file    advance_list.php
 * \ingroup payroll
 * \brief   List page for salary advances (payments from salary module)
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

$langs->loadLangs(array("payroll@payroll", "dict", "main", "salaries"));

$action = GETPOST('action', 'aZ09');
$limit = GETPOST('limit', 'int') ? GETPOST('limit', 'int') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOST('page', 'int');
if (empty($page) || $page == -1) {
    $page = 0;
}
$offset = $limit * $page;

if (!$sortfield) {
    $sortfield = 'ps.datep';
}
if (!$sortorder) {
    $sortorder = 'DESC';
}

$search_employee = GETPOST('search_employee', 'int');
$search_month = GETPOST('search_month', 'int');
$search_year = GETPOST('search_year', 'int');

// Security check
if (empty($user->rights->payroll->payslip->read)) {
    accessforbidden();
}

$form = new Form($db);

/*
 * View
 */

$title = $langs->trans("Advances");
llxHeader('', $title);

$sql = "SELECT ps.rowid, ps.datep, ps.amount, ps.num_payment,";
$sql .= " u.firstname, u.lastname, u.rowid as user_id,";
$sql .= " s.label as salary_label, s.rowid as salary_id,";
$sql .= " pt.libelle as payment_mode";
$sql .= " FROM " . MAIN_DB_PREFIX . "payment_salary as ps";
$sql .= " JOIN " . MAIN_DB_PREFIX . "salary as s ON ps.fk_salary = s.rowid";
$sql .= " JOIN " . MAIN_DB_PREFIX . "user as u ON s.fk_user = u.rowid";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "c_paiement as pt ON ps.fk_typepayment = pt.id";
$sql .= " WHERE ps.entity IN (" . getEntity('salary') . ")";
$sql .= " AND s.paye = 1"; // Show paid advances to match payslip total
if ($search_employee > 0) {
    $sql .= " AND s.fk_user = " . (int) $search_employee;
}
if ($search_month > 0) {
    $sql .= " AND MONTH(ps.datep) = " . (int) $search_month;
}
if ($search_year > 0) {
    $sql .= " AND YEAR(ps.datep) = " . (int) $search_year;
}

$sql .= $db->order($sortfield, $sortorder);

$resql = $db->query($sql);
if ($resql) {
    $num = $db->num_rows($resql);
} else {
    dol_print_error($db);
}

$param = '';
if ($limit > 0 && $limit != $conf->liste_limit)
    $param .= '&limit=' . urlencode($limit);
if ($search_employee > 0)
    $param .= '&search_employee=' . urlencode($search_employee);
if ($search_month > 0)
    $param .= '&search_month=' . urlencode($search_month);
if ($search_year > 0)
    $param .= '&search_year=' . urlencode($search_year);

print '<form method="POST" id="searchFormList" action="' . $_SERVER["PHP_SELF"] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="list">';

print_barre_liste($title, $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, '', $num, $num, 'payment', 0, '', '', $limit);

print '<div class="div-table-responsive">';
print '<table class="tagtable liste">';

// Filters
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre"></td>'; // Reference (Date)
print '<td class="liste_titre">';
print $form->select_dolusers($search_employee, 'search_employee', 1, null, 0, '', '', '', 0, 0, '', 0, '', 'maxwidth200');
print '</td>';
print '<td class="liste_titre">';
// Month selection
$month_array = array();
for ($m = 1; $m <= 12; $m++) {
    $month_array[$m] = dol_print_date(dol_mktime(12, 0, 0, $m, 1, 2000), "%B");
}
print $form->selectarray('search_month', $month_array, $search_month, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth100');

// Year selection
$year_array = array();
$cur_year = date("Y");
for ($y = $cur_year - 5; $y <= $cur_year + 1; $y++) {
    $year_array[$y] = $y;
}
print $form->selectarray('search_year', $year_array, $search_year, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth100');
print '</td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre" align="right">';
print '<input type="image" class="liste_titre" name="button_search" src="' . img_picto($langs->trans("Search"), 'search.png', '', 0, 1) . '" value="Selectionner" title="' . dol_escape_htmltag($langs->trans("Search")) . '">';
print '</td>';
print '</tr>';

// Header
print '<tr class="liste_titre">';
print_liste_field_titre($langs->trans("Date"), $_SERVER["PHP_SELF"], "ps.datep", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre($langs->trans("Employee"), $_SERVER["PHP_SELF"], "u.lastname", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre($langs->trans("Label"), $_SERVER["PHP_SELF"], "s.label", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre($langs->trans("RefPayment"), $_SERVER["PHP_SELF"], "ps.rowid", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre($langs->trans("PaymentMode"), $_SERVER["PHP_SELF"], "pt.libelle", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre($langs->trans("Amount"), $_SERVER["PHP_SELF"], "ps.amount", "", $param, 'align="right"', $sortfield, $sortorder);
print_liste_field_titre('', $_SERVER["PHP_SELF"], "", "", $param, 'align="right"', $sortfield, $sortorder);
print '</tr>';

$i = 0;
$total_amount = 0;
while ($i < $num) {
    $obj = $db->fetch_object($resql);
    if (!$obj)
        break;

    print '<tr class="oddeven">';

    // Date
    print '<td>' . dol_print_date($db->jdate($obj->datep), 'day') . '</td>';

    // Employee
    print '<td>';
    $user_static = new User($db);
    $user_static->id = $obj->user_id;
    $user_static->lastname = $obj->lastname;
    $user_static->firstname = $obj->firstname;
    print $user_static->getNomUrl(1);
    print '</td>';

    // Salary label
    print '<td><a href="' . DOL_URL_ROOT . '/salaries/card.php?id=' . $obj->salary_id . '">' . $obj->salary_label . '</a></td>';

    // Payment ref (RowID as shown in screenshot)
    print '<td>' . $obj->rowid . ($obj->num_payment ? ' (' . $obj->num_payment . ')' : '') . '</td>';

    // Payment Mode
    print '<td>' . $obj->payment_mode . '</td>';

    // Amount
    print '<td align="right">' . price($obj->amount) . '</td>';
    $total_amount += $obj->amount;

    print '<td align="right"></td>';
    print '</tr>';
    $i++;
}
 
// Show total
print '<tr class="liste_total">';
print '<td class="liste_total" colspan="5" align="left">' . $langs->trans("Total") . '</td>';
print '<td class="liste_total" align="right">' . price($total_amount) . '</td>';
print '<td class="liste_total"></td>';
print '</tr>';
 
print '</table>';
print '</div>';
print '</form>';

llxFooter();
$db->close();
