<?php
/**
 * Helpers d'affichage UI pour Faurex Magasin
 */

function faurex_css_head()
{
    return '<link rel="stylesheet" type="text/css" href="'.dol_buildpath('/faurex_magasin/lib/faurex.css', 1).'">';
}

function faurex_page_header($title, $icon = '📦', $subtitle = '', $right = '')
{
    $h  = '<div class="fx-page-header">';
    $h .= '<div><h1><span class="fx-icon">'.$icon.'</span> '.dol_escape_htmltag($title).'</h1>';
    if ($subtitle) $h .= '<div class="fx-subtitle">'.dol_escape_htmltag($subtitle).'</div>';
    $h .= '</div>';
    if ($right) $h .= '<div>'.$right.'</div>';
    $h .= '</div>';
    return $h;
}

function faurex_status_badge($label, $type = 'validated')
{
    $cls = 'fx-badge-validated';
    if ($type === 'partial')   $cls = 'fx-badge-partial';
    if ($type === 'done')      $cls = 'fx-badge-done';
    if ($type === 'cancelled') $cls = 'fx-badge-cancelled';
    return '<span class="fx-badge '.$cls.'">'.dol_escape_htmltag($label).'</span>';
}

function faurex_stat_card($label, $value, $type = '')
{
    return '<div class="fx-stat-card '.$type.'">'.
        '<div class="fx-stat-label">'.dol_escape_htmltag($label).'</div>'.
        '<div class="fx-stat-value">'.$value.'</div>'.
        '</div>';
}

function faurex_info_item($label, $value)
{
    return '<div class="fx-info-item">'.
        '<div class="fx-label">'.dol_escape_htmltag($label).'</div>'.
        '<div class="fx-value">'.$value.'</div>'.
        '</div>';
}
