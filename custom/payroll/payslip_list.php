<?php
/* Copyright (C) 2025 Your Company
 *
 * This program is free software
 */

/**
 * \file    payslip_list.php
 * \ingroup payroll
 * \brief   List page for payslips
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
require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT . '/custom/payroll/class/payslip.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/payroll/class/payrun.class.php';

$langs->loadLangs(array("payroll@payroll", "dict", "main"));

$action = GETPOST('action', 'aZ09');
$massaction = GETPOST('massaction', 'alpha');
$show_ip = GETPOST('show_ip', 'alpha');
$contextpage = GETPOST('contextpage', 'aZ09') ? GETPOST('contextpage', 'aZ09') : 'payroll_payslip_list';

$limit = GETPOST('limit', 'int') ? GETPOST('limit', 'int') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOST('page', 'int');
if (empty($page) || $page == -1) {
    $page = 0;
}
$offset = $limit * $page;
$pageprev = $page - 1;
$pagenext = $page + 1;

if (!$sortfield) {
    $sortfield = 't.ref';
}
if (!$sortorder) {
    $sortorder = 'DESC';
}

$search_ref = GETPOST('search_ref', 'alpha');
$search_employee = GETPOST('search_employee', 'int');
$search_payrun = GETPOST('search_payrun', 'int');
$search_status = GETPOST('search_status', 'int');
if (!GETPOSTISSET('search_status') && !GETPOSTISSET('button_search')) {
    $search_status = Payslip::STATUS_PAID;
}
$moreforfilter = '';
$search_array_options = array();

// Initialize technical objects
$object = new Payslip($db);

$hookmanager->initHooks(array('paysliplist'));

// Security check
$user_can_read = !empty($user->rights->payroll->payslip->read);
$user_can_write = !empty($user->rights->payroll->payslip->write);

if (!$user_can_read) {
    accessforbidden();
}

/*
 * Actions
 */

if (GETPOST('cancel', 'alpha')) {
    $action = 'list';
    $massaction = '';
}

$pdf_download_url = '';
if ($action == 'generate_pdf' && !empty($user->rights->payroll->payrun->write)) {
    $payrun_id = GETPOST('search_payrun', 'int');
    if ($payrun_id > 0) {
        $payrun = new PayRun($db);
        $payrun->fetch($payrun_id);
        $result = $payrun->generateDocument();
        if ($result > 0) {
            setEventMessages($langs->trans("FileGenerated"), null, 'mesgs');
            $relativepath = 'payrun/' . dol_sanitizeFileName($payrun->ref) . '/' . dol_sanitizeFileName($payrun->ref) . '_summary.pdf';
            $pdf_download_url = DOL_URL_ROOT . '/custom/payroll/document.php?modulepart=payroll&file=' . urlencode($relativepath);
        } else {
            setEventMessages($langs->trans("ErrorFileGeneration"), null, 'errors');
        }
    } else {
        setEventMessages($langs->trans("ErrorFieldRequired", $langs->trans("PayRun")), null, 'errors');
    }
}

// Generate Global PDF Report
if ($action == 'generate_global_pdf' && !empty($user->rights->payroll->payslip->read)) {
    require_once DOL_DOCUMENT_ROOT . '/core/lib/pdf.lib.php';
    require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';

    $dir = $conf->payroll->dir_output . '/global_reports';
    if (!is_dir($dir)) {
        dol_mkdir($dir);
    }

    $filename = 'etat_global_' . date('Y-m-d_His') . '.pdf';
    $file = $dir . '/' . $filename;

    // Create PDF
    $pdf = pdf_getInstance('A4', 'mm', 'L');
    if ($pdf) {
        $pdf->SetCreator('Dolibarr ' . DOL_VERSION);
        $pdf->SetAuthor(dol_html_entity_decode($user->getFullName($langs), ENT_QUOTES));
        $pdf->SetTitle(dol_html_entity_decode($langs->trans("GlobalPayrollReport"), ENT_QUOTES));
        $pdf->SetSubject(dol_html_entity_decode($langs->trans("GlobalPayrollReport"), ENT_QUOTES));
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
        $logo = (!empty($conf->mycompany->logo) ? $conf->mycompany->logo : '');
        if (empty($logo)) {
            $logo = (!empty($conf->global->MAIN_INFO_SOCIETE_LOGO) ? $conf->global->MAIN_INFO_SOCIETE_LOGO : '');
        }
        if (!empty($logo)) {
            $logofile = $conf->mycompany->dir_output . '/logos/' . $logo;
            if (!file_exists($logofile)) {
                $logofile = DOL_DATA_ROOT . '/mycompany/logos/' . $logo;
            }
            if (file_exists($logofile)) {
                $pdf->Image($logofile, 10, 10, 0, 35);
            }
        }

        // Company Info (Top Right)
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetXY(190, 10);
        $pdf->MultiCell(97, 5, dol_html_entity_decode($conf->global->MAIN_INFO_SOCIETE_NOM, ENT_QUOTES), 0, 'R');
        $pdf->SetFont('helvetica', '', 9);
        $complainfo = $conf->global->MAIN_INFO_SOCIETE_ADDRESS . ' ' . $conf->global->MAIN_INFO_SOCIETE_TOWN;
        if (!empty($conf->global->MAIN_INFO_SOCIETE_TEL)) {
            $complainfo .= "\nTél: " . $conf->global->MAIN_INFO_SOCIETE_TEL;
        }
        $pdf->SetX(190);
        $pdf->MultiCell(97, 4, dol_html_entity_decode($complainfo, ENT_QUOTES), 0, 'R');

        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetXY(10, 45);
        $title_report = $langs->trans("GlobalPayrollReport");
        $payrun_id_selected = GETPOST('fk_payrun_report', 'int');
        if ($payrun_id_selected > 0) {
            $pr_tmp = new PayRun($db);
            $pr_tmp->fetch($payrun_id_selected);
            $title_report .= ' - ' . dol_print_date(dol_mktime(0, 0, 0, $pr_tmp->period_month, 1, $pr_tmp->period_year), '%B %Y');
        }
        $pdf->Cell(0, 12, dol_html_entity_decode($title_report, ENT_QUOTES), 0, 1, 'C');

        // Draw a separator line
        $pdf->SetDrawColor(200, 200, 200);
        $pdf->Line(20, $pdf->GetY(), 277, $pdf->GetY());

        $pdf->SetFont('helvetica', 'I', 9);
        $pdf->Ln(2);
        $pdf->Cell(0, 5, dol_html_entity_decode($langs->trans("GeneratedOn"), ENT_QUOTES) . ': ' . dol_print_date(dol_now(), 'dayhour'), 0, 1, 'C');

        $pdf->Ln(8);

        // Table Header
        $pdf->SetX($start_x);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->SetDrawColor(180, 180, 180);
        $h = 8;
        $pdf->Cell(65, $h, dol_html_entity_decode($langs->trans("Employee"), ENT_QUOTES), 1, 0, 'L', 1);
        $pdf->Cell(40, $h, dol_html_entity_decode($langs->trans("BaseSalary"), ENT_QUOTES), 1, 0, 'R', 1);
        $pdf->Cell(40, $h, dol_html_entity_decode($langs->trans("Bonuses"), ENT_QUOTES), 1, 0, 'R', 1);
        $pdf->Cell(40, $h, dol_html_entity_decode($langs->trans("Advances"), ENT_QUOTES), 1, 0, 'R', 1);
        $pdf->Cell(45, $h, dol_html_entity_decode($langs->trans("NetSalary"), ENT_QUOTES), 1, 1, 'R', 1);

        $pdf->SetFont('helvetica', '', 9);

        // Fetch All Payslips
        $sql_global = "SELECT t.base_salary, t.bonuses, t.advances, t.net_salary, u.firstname, u.lastname";
        $sql_global .= " FROM " . MAIN_DB_PREFIX . "payroll_payslip as t";
        $sql_global .= " LEFT JOIN " . MAIN_DB_PREFIX . "user as u ON t.fk_user = u.rowid";
        $sql_global .= " WHERE t.entity IN (" . getEntity('payroll_payslip') . ")";
        if ($search_ref) {
            $sql_global .= natural_search('t.ref', $search_ref);
        }
        if ($search_employee > 0) {
            $sql_global .= " AND t.fk_user = " . (int) $search_employee;
        }
        if ($search_payrun > 0) {
            $sql_global .= " AND t.fk_payrun = " . (int) $search_payrun;
        }
        $payrun_id_report = GETPOST('fk_payrun_report', 'int');
        if ($payrun_id_report > 0) {
            $sql_global .= " AND t.fk_payrun = " . (int) $payrun_id_report;
        }
        if ($search_status != '' && $search_status >= 0) {
            $sql_global .= " AND t.status = " . (int) $search_status;
        }
        $sql_global .= " ORDER BY u.lastname ASC";

        $resql_global = $db->query($sql_global);
        $total_base = 0;
        $total_bonuses = 0;
        $total_advances = 0;
        $total_deductions = 0;
        $total_net = 0;

        if ($resql_global) {
            $filler = 0;
            while ($obj_global = $db->fetch_object($resql_global)) {
                $empname = $obj_global->lastname . ' ' . $obj_global->firstname;
                $pdf->SetX($start_x);
                $pdf->SetFillColor(250, 250, 250);
                
                $pdf->Cell(65, $h, dol_html_entity_decode($empname, ENT_QUOTES), 1, 0, 'L', $filler);
                $pdf->Cell(40, $h, price($obj_global->base_salary, 0, $langs, 1, -1, -1, $conf->currency), 1, 0, 'R', $filler);
                $pdf->Cell(40, $h, price($obj_global->bonuses, 0, $langs, 1, -1, -1, $conf->currency), 1, 0, 'R', $filler);
                $pdf->Cell(40, $h, price(-1 * $obj_global->advances, 0, $langs, 1, -1, -1, $conf->currency), 1, 0, 'R', $filler);
                $pdf->Cell(45, $h, price($obj_global->net_salary, 0, $langs, 1, -1, -1, $conf->currency), 1, 1, 'R', $filler);

                $total_base += $obj_global->base_salary;
                $total_bonuses += $obj_global->bonuses;
                $total_advances += $obj_global->advances;
                $total_net += $obj_global->net_salary;
                
                $filler = !$filler;
            }
        }

        // Totals Row
        $pdf->SetX($start_x);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(220, 220, 220);
        $row_h = $h + 1;
        $pdf->Cell(65, $row_h, "TOTAL", 1, 0, 'L', 1);
        $pdf->Cell(40, $row_h, price($total_base, 0, $langs, 1, -1, -1, $conf->currency), 1, 0, 'R', 1);
        $pdf->Cell(40, $row_h, price($total_bonuses, 0, $langs, 1, -1, -1, $conf->currency), 1, 0, 'R', 1);
        $pdf->Cell(40, $row_h, price(-1 * $total_advances, 0, $langs, 1, -1, -1, $conf->currency), 1, 0, 'R', 1);
        $pdf->Cell(45, $row_h, price($total_net, 0, $langs, 1, -1, -1, $conf->currency), 1, 1, 'R', 1);

        // Output PDF directly to browser for download
        $pdf->Output($filename, 'D');
        exit;
    } else {
        setEventMessages($langs->trans("ErrorFileGeneration"), null, 'errors');
    }
}

/*
 * View
 */

$form = new Form($db);

$title = $langs->trans("PayslipsList");
$help_url = '';
llxHeader('', $title, $help_url);

// Automatic download
if ($pdf_download_url) {
    print '<script type="text/javascript">
        window.open("' . $pdf_download_url . '", "_blank");
    </script>';
}

$sql = "SELECT t.rowid, t.ref, t.fk_user, t.fk_payrun, t.base_salary, t.net_salary, t.status,";
$sql .= " u.firstname, u.lastname";
$sql .= " FROM " . MAIN_DB_PREFIX . "payroll_payslip as t";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "user as u ON t.fk_user = u.rowid";
$sql .= " WHERE t.entity IN (" . getEntity('payroll_payslip') . ")";
if ($search_ref) {
    $sql .= natural_search('t.ref', $search_ref);
}
if ($search_employee > 0) {
    $sql .= " AND t.fk_user = " . (int) $search_employee;
}
if ($search_payrun > 0) {
    $sql .= " AND t.fk_payrun = " . (int) $search_payrun;
}
if ($search_status != '' && $search_status >= 0) {
    $sql .= " AND t.status = " . (int) $search_status;
}

$sql .= $db->order($sortfield, $sortorder);

$resql = $db->query($sql);
if ($resql) {
    $num = $db->num_rows($resql);
    $nbtotalofrecords = $num; // Simplified for this example
} else {
    dol_print_error($db);
}

$param = '';
if ($limit > 0 && $limit != $conf->liste_limit) {
    $param .= '&limit=' . urlencode($limit);
}
if ($search_ref) {
    $param .= '&search_ref=' . urlencode($search_ref);
}
if ($search_employee > 0) {
    $param .= '&search_employee=' . urlencode($search_employee);
}
if ($search_payrun > 0) {
    $param .= '&search_payrun=' . urlencode($search_payrun);
}
if ($search_status != '') {
    $param .= '&search_status=' . urlencode($search_status);
}

print '<form method="POST" id="searchFormList" action="' . $_SERVER["PHP_SELF"] . '">';
if (!empty($optioncss)) {
    print '<input type="hidden" name="optioncss" value="' . $optioncss . '">';
}
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print '<input type="hidden" name="action" value="list">';
print '<input type="hidden" name="sortfield" value="' . $sortfield . '">';
print '<input type="hidden" name="sortorder" value="' . $sortorder . '">';
print '<input type="hidden" name="page" value="' . $page . '">';
print '<input type="hidden" name="contextpage" value="' . $contextpage . '">';

// No additional buttons
$newcardbutton = '';
if ($user->rights->payroll->payslip->read) {
    $newcardbutton .= '<a class="butAction" href="#" id="btn_generate_global_pdf" style="margin-right: 10px;">';
    $newcardbutton .= '<span class="fa fa-file-pdf-o" style="padding-right: 5px;"></span>';
    $newcardbutton .= $langs->trans("GlobalReport");
    $newcardbutton .= '</a>';
}

print_barre_liste($title, $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, '', $num, $nbtotalofrecords, 'bill', 0, $newcardbutton, '', $limit, 0, 0, 1);

// Show summary table if a payrun is selected


print '<div class="div-table-responsive">';
print '<table class="tagtable liste' . ($moreforfilter ? ' listwithfilterbefore' : '') . '">';

// Filters
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre">';
print '<input type="text" class="flat" name="search_ref" value="' . dol_escape_htmltag($search_ref) . '">';
print '</td>';
print '<td class="liste_titre">';
print $form->select_dolusers($search_employee, 'search_employee', 1, null, 0, '', '', '', 0, 0, '', 0, '', 'maxwidth200');
print '</td>';
print '<td class="liste_titre">';
// PayRun filter
$sql_pr = "SELECT rowid, ref FROM " . MAIN_DB_PREFIX . "payroll_payrun ORDER BY ref DESC";
$resql_pr = $db->query($sql_pr);
$payrun_array = array();
if ($resql_pr) {
    while ($obj_pr = $db->fetch_object($resql_pr)) {
        $payrun_array[$obj_pr->rowid] = $obj_pr->ref;
    }
}
print $form->selectarray('search_payrun', $payrun_array, $search_payrun, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth150');
print '</td>';
print '<td class="liste_titre" align="right">';
print '</td>';
print '<td class="liste_titre" align="right">';
print '</td>';
print '<td class="liste_titre" align="right">';
$status_array = array(
    Payslip::STATUS_DRAFT => $langs->trans("Draft"),
    Payslip::STATUS_VALIDATED => $langs->trans("Validated"),
    Payslip::STATUS_PAID => $langs->trans("Paid")
);
print $form->selectarray('search_status', $status_array, $search_status, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth100');
print '<input type="image" class="liste_titre" name="button_search" src="' . img_picto($langs->trans("Search"), 'search.png', '', 0, 1) . '" value="Selectionner" title="' . dol_escape_htmltag($langs->trans("Search")) . '">';
print '</td>';
print '</tr>';

// Header
print '<tr class="liste_titre">';
print_liste_field_titre($langs->trans("Ref"), $_SERVER["PHP_SELF"], "t.ref", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre($langs->trans("Employee"), $_SERVER["PHP_SELF"], "u.lastname", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre($langs->trans("PayRun"), $_SERVER["PHP_SELF"], "t.fk_payrun", "", $param, 'align="right"', $sortfield, $sortorder);
print_liste_field_titre($langs->trans("BaseSalary"), $_SERVER["PHP_SELF"], "t.base_salary", "", $param, 'align="right"', $sortfield, $sortorder);
print_liste_field_titre($langs->trans("NetSalary"), $_SERVER["PHP_SELF"], "t.net_salary", "", $param, 'align="right"', $sortfield, $sortorder);
print_liste_field_titre($langs->trans("Status"), $_SERVER["PHP_SELF"], "t.status", "", $param, 'align="right"', $sortfield, $sortorder);
print '</tr>';

$i = 0;
while ($i < min($num, $limit)) {
    $obj = $db->fetch_object($resql);
    if (!$obj)
        break;

    $object->id = $obj->rowid;
    $object->ref = $obj->ref;
    $object->status = $obj->status;

    print '<tr class="oddeven">';
    print '<td>' . $object->getNomUrl(1) . '</td>';

    // Employee
    print '<td>';
    $user_static = new User($db);
    $user_static->id = $obj->fk_user;
    $user_static->lastname = $obj->lastname;
    $user_static->firstname = $obj->firstname;
    print $user_static->getNomUrl(1);
    print '</td>';

    // Monthly payroll (PayRun)
    $payrun_tmp = new PayRun($db);
    if ($obj->fk_payrun > 0) {
        $payrun_tmp->fetch($obj->fk_payrun);
        print '<td align="right">' . $payrun_tmp->getNomUrl(1) . '</td>';
    } else {
        print '<td align="right"></td>';
    }

    print '<td align="right">' . price($obj->base_salary) . '</td>';
    print '<td align="right">' . price($obj->net_salary) . '</td>';
    print '<td align="right">' . $object->getLibStatut(5) . '</td>';
    print '</tr>';
    $i++;
}

print '</table>';
print '</div>';
print '</form>';

// Popup Selection de PayRun (Outside of main form to avoid nesting)
if ($user->rights->payroll->payslip->read) {
    $sql_pr_list = "SELECT rowid, ref, label FROM " . MAIN_DB_PREFIX . "payroll_payrun ORDER BY ref DESC";
    $resql_pr_list = $db->query($sql_pr_list);
    $payruns = array();
    if ($resql_pr_list) {
        while ($obj_pr = $db->fetch_object($resql_pr_list)) {
            $payruns[$obj_pr->rowid] = $obj_pr->ref . ($obj_pr->label ? ' - ' . $obj_pr->label : '');
        }
    }

    print '<div id="dialog-global-report" title="' . dol_escape_htmltag($langs->trans("GlobalPayrollReport")) . '" style="display:none;">';
    print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    print '<input type="hidden" name="action" value="generate_global_pdf">';
    print '<div class="paddingtopbottom">';
    print '<label>' . $langs->trans("PayRun") . '</label><br>';
    print $form->selectarray('fk_payrun_report', $payruns, '', 1, 0, 0, '', 0, 0, 0, '', 'minwidth200');
    print '</div>';
    print '<div class="center paddingtop">';
    print '<input type="submit" class="button" name="btn_submit_global" value="' . dol_escape_htmltag($langs->trans("Generate")) . '">';
    print '</div>';
    print '</form>';
    print '</div>';

    print '<script type="text/javascript">
    jQuery(document).ready(function() {
        jQuery("#btn_generate_global_pdf").click(function(e) {
            e.preventDefault();
            jQuery("#dialog-global-report").dialog({
                resizable: false,
                height: "auto",
                width: 450,
                modal: true
            });
        });
    });
    </script>';
}

llxFooter();
$db->close();
