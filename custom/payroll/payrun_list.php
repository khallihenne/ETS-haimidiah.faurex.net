<?php
/* Copyright (C) 2025 Your Company
 *
 * This program is free software
 */

/**
 * \file    payrun_list.php
 * \ingroup payroll
 * \brief   List page for pay runs
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
require_once DOL_DOCUMENT_ROOT . '/custom/payroll/class/payrun.class.php';

// Load translation files required by the page
$langs->loadLangs(array("payroll@payroll", "other"));

// Get parameters
$action = GETPOST('action', 'aZ09');
$massaction = GETPOST('massaction', 'alpha');
$show_files = GETPOST('show_files', 'int');
$confirm = GETPOST('confirm', 'alpha');
$toselect = GETPOST('toselect', 'array');
$contextpage = GETPOST('contextpage', 'aZ') ? GETPOST('contextpage', 'aZ') : 'payrunlist';

$search_ref = GETPOST('search_ref', 'alpha');
$search_label = GETPOST('search_label', 'alpha');
$search_period = GETPOST('search_period', 'alpha');
$search_status = GETPOST('search_status', 'int');

$limit = GETPOST('limit', 'int') ? GETPOST('limit', 'int') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone') - 1) : GETPOST("page", 'int');
if (empty($page) || $page == -1) {
    $page = 0;
}
$offset = $limit * $page;
$pageprev = $page - 1;
$pagenext = $page + 1;
if (!$sortfield) {
    $sortfield = "t.period_year,t.period_month";
}
if (!$sortorder) {
    $sortorder = "DESC";
}

// Initialize technical objects
$object = new PayRun($db);

$hookmanager->initHooks(array('payrunlist'));

// Security check
$user_can_read = !empty($user->rights->payroll->payrun->read);
$user_can_write = !empty($user->rights->payroll->payrun->write);

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
if (!GETPOST('confirmmassaction', 'alpha') && $massaction != 'presend' && $massaction != 'confirm_presend') {
    $massaction = '';
}

$parameters = array();
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action);
if ($reshook < 0) {
    setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (empty($reshook)) {
    // Selection of new fields
    include DOL_DOCUMENT_ROOT . '/core/actions_changeselectedfields.inc.php';

    // Purge search criteria
    if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
        $search_ref = '';
        $search_label = '';
        $search_period = '';
        $search_status = '';
        $toselect = array();
        $search_array_options = array();
    }
}

/*
 * View
 */

$form = new Form($db);

$now = dol_now();
$title = $langs->trans("PayRuns");
$help_url = '';
$morejs = array();
$morecss = array();

llxHeader('', $title, $help_url, '', 0, 0, $morejs, $morecss, '', '');

$arrayofselected = is_array($toselect) ? $toselect : array();

$sql = "SELECT";
$sql .= " t.rowid,";
$sql .= " t.ref,";
$sql .= " t.label,";
$sql .= " t.period_month,";
$sql .= " t.period_year,";
$sql .= " t.status,";
$sql .= " t.total_gross,";
$sql .= " t.total_net,";
$sql .= " t.date_creation";

$sqlfields = $sql;

$sql .= " FROM " . MAIN_DB_PREFIX . "payroll_payrun as t";
$sql .= " WHERE t.entity IN (" . getEntity('payroll') . ")";

if ($search_ref) {
    $sql .= natural_search('t.ref', $search_ref);
}
if ($search_label) {
    $sql .= natural_search('t.label', $search_label);
}
if ($search_period) {
    $date_parts = explode('-', $search_period);
    if (count($date_parts) == 2) {
        $sql .= " AND t.period_year = " . (int) $date_parts[0];
        $sql .= " AND t.period_month = " . (int) $date_parts[1];
    }
}
if ($search_status != '' && $search_status >= 0) {
    $sql .= " AND t.status = " . (int) $search_status;
}

$nbtotalofrecords = '';
if (!getDolGlobalInt('MAIN_DISABLE_FULL_SCANLIST')) {
    $resql = $db->query($sql);
    $nbtotalofrecords = $db->num_rows($resql);
    if (($page * $limit) > $nbtotalofrecords) {
        $page = 0;
        $offset = 0;
    }
}

$sql .= $db->order($sortfield, $sortorder);
if ($limit) {
    $sql .= $db->plimit($limit + 1, $offset);
}

$resql = $db->query($sql);
if ($resql) {
    $num = $db->num_rows($resql);

    $arrayofselected = is_array($toselect) ? $toselect : array();

    $param = '';
    if (!empty($contextpage) && $contextpage != $_SERVER["PHP_SELF"]) {
        $param .= '&contextpage=' . urlencode($contextpage);
    }
    if ($limit > 0 && $limit != $conf->liste_limit) {
        $param .= '&limit=' . urlencode($limit);
    }
    if ($search_ref) {
        $param .= '&search_ref=' . urlencode($search_ref);
    }
    if ($search_label) {
        $param .= '&search_label=' . urlencode($search_label);
    }
    if ($search_period) {
        $param .= '&search_period=' . urlencode($search_period);
    }
    if ($search_status != '') {
        $param .= '&search_status=' . urlencode($search_status);
    }

    $newcardbutton = '';
    if ($user_can_write) {
        $newcardbutton = dolGetButtonTitle($langs->trans('NewPayRun'), '', 'fa fa-plus-circle', DOL_URL_ROOT . '/custom/payroll/payrun_card.php?action=create', '', 1);
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

    print_barre_liste($title, $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, '', $num, $nbtotalofrecords, 'object_payroll', 0, $newcardbutton, '', $limit, 0, 0, 1);

    print '<div class="div-table-responsive">';
    print '<table class="tagtable liste">' . "\n";

    // Fields title
    print '<tr class="liste_titre">';
    print_liste_field_titre("Ref", $_SERVER["PHP_SELF"], "t.ref", "", $param, '', $sortfield, $sortorder);
    print_liste_field_titre("Label", $_SERVER["PHP_SELF"], "t.label", "", $param, '', $sortfield, $sortorder);
    print_liste_field_titre("Period", $_SERVER["PHP_SELF"], "t.period_year,t.period_month", "", $param, '', $sortfield, $sortorder);
    print_liste_field_titre("TotalGross", $_SERVER["PHP_SELF"], "t.total_gross", "", $param, '', $sortfield, $sortorder, 'right ');
    print_liste_field_titre("TotalNet", $_SERVER["PHP_SELF"], "t.total_net", "", $param, '', $sortfield, $sortorder, 'right ');
    print_liste_field_titre("Status", $_SERVER["PHP_SELF"], "t.status", "", $param, '', $sortfield, $sortorder, 'center ');
    print_liste_field_titre('', $_SERVER["PHP_SELF"], "", '', '', '', $sortfield, $sortorder, 'maxwidthsearch ');
    print '</tr>' . "\n";

    // Fields title search
    print '<tr class="liste_titre">';
    print '<td class="liste_titre"><input type="text" class="flat" name="search_ref" value="' . dol_escape_htmltag($search_ref) . '" size="10"></td>';
    print '<td class="liste_titre"><input type="text" class="flat" name="search_label" value="' . dol_escape_htmltag($search_label) . '" size="15"></td>';
    print '<td class="liste_titre"><input type="text" class="flat" name="search_period" value="' . dol_escape_htmltag($search_period) . '" size="10" placeholder="YYYY-MM"></td>';
    print '<td class="liste_titre"></td>';
    print '<td class="liste_titre"></td>';
    print '<td class="liste_titre center">';
    print $form->selectarray('search_status', array('0' => $langs->trans('Draft'), '1' => $langs->trans('Validated'), '2' => $langs->trans('Paid')), $search_status, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth100');
    print '</td>';
    print '<td class="liste_titre maxwidthsearch">';
    $searchpicto = $form->showFilterButtons();
    print $searchpicto;
    print '</td>';
    print '</tr>' . "\n";

    // Lines of data
    $i = 0;
    $totalarray = array();
    while ($i < min($num, $limit)) {
        $obj = $db->fetch_object($resql);
        if (empty($obj)) {
            break;
        }

        $object->id = $obj->rowid;
        $object->ref = $obj->ref;
        $object->label = $obj->label;
        $object->status = $obj->status;

        print '<tr class="oddeven">';

        // Ref
        print '<td class="nowraponall">';
        print $object->getNomUrl(1);
        print '</td>';

        // Label
        print '<td class="tdoverflowmax200">';
        print dol_escape_htmltag($obj->label);
        print '</td>';

        // Period
        print '<td>';
        print dol_print_date(dol_mktime(0, 0, 0, $obj->period_month, 1, $obj->period_year), '%B %Y');
        print '</td>';

        // Total Gross
        print '<td class="right">' . price($obj->total_gross) . '</td>';

        // Total Net
        print '<td class="right">' . price($obj->total_net) . '</td>';

        // Status
        print '<td class="center">';
        print $object->getLibStatut(5);
        print '</td>';

        // Action column
        print '<td class="center">';
        print '</td>';

        print '</tr>' . "\n";

        $i++;
    }

    if ($num == 0) {
        $colspan = 7;
        print '<tr><td colspan="' . $colspan . '"><span class="opacitymedium">' . $langs->trans("NoRecordFound") . '</span></td></tr>';
    }

    print "</table>";
    print "</div>";
    print '</form>';

    $db->free($resql);
} else {
    dol_print_error($db);
}

// End of page
llxFooter();
$db->close();
