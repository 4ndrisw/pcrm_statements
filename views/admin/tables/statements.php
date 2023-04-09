<?php

defined('BASEPATH') or exit('No direct script access allowed');

$project_id = $this->ci->input->post('project_id');

$aColumns = [
    'number',
    'total',
    'total_tax',
    'YEAR(date) as year',
    'date',
    get_sql_select_client_company(),
    db_prefix() . 'projects.name as project_name',
    '(SELECT GROUP_CONCAT(name SEPARATOR ",") FROM ' . db_prefix() . 'taggables JOIN ' . db_prefix() . 'tags ON ' . db_prefix() . 'taggables.tag_id = ' . db_prefix() . 'tags.id WHERE rel_id = ' . db_prefix() . 'statements.id and rel_type="statement" ORDER by tag_order ASC) as tags',
    'duedate',
    db_prefix() . 'statements.status',
    ];

$sIndexColumn = 'id';
$sTable       = db_prefix() . 'statements';

$join = [
    'LEFT JOIN ' . db_prefix() . 'clients ON ' . db_prefix() . 'clients.userid = ' . db_prefix() . 'statements.clientid',
    'LEFT JOIN ' . db_prefix() . 'currencies ON ' . db_prefix() . 'currencies.id = ' . db_prefix() . 'statements.currency',
    'LEFT JOIN ' . db_prefix() . 'projects ON ' . db_prefix() . 'projects.id = ' . db_prefix() . 'statements.project_id',
];

$custom_fields = get_table_custom_fields('statement');

foreach ($custom_fields as $key => $field) {
    $selectAs = (is_cf_date($field) ? 'date_picker_cvalue_' . $key : 'cvalue_' . $key);

    array_push($customFieldsColumns, $selectAs);
    array_push($aColumns, 'ctable_' . $key . '.value as ' . $selectAs);
    array_push($join, 'LEFT JOIN ' . db_prefix() . 'customfieldsvalues as ctable_' . $key . ' ON ' . db_prefix() . 'statements.id = ctable_' . $key . '.relid AND ctable_' . $key . '.fieldto="' . $field['fieldto'] . '" AND ctable_' . $key . '.fieldid=' . $field['id']);
}

$where  = [];
$filter = [];

if ($this->ci->input->post('not_sent')) {
    array_push($filter, 'AND sent = 0 AND ' . db_prefix() . 'statements.status NOT IN(' . Statements_model::STATUS_PAID . ',' . Statements_model::STATUS_CANCELLED . ')');
}
if ($this->ci->input->post('not_have_payment')) {
    array_push($filter, 'AND ' . db_prefix() . 'statements.id NOT IN(SELECT statementid FROM ' . db_prefix() . 'statementpaymentrecords) AND ' . db_prefix() . 'statements.status != ' . Statements_model::STATUS_CANCELLED);
}
if ($this->ci->input->post('recurring')) {
    array_push($filter, 'AND recurring > 0');
}

$statuses  = $this->ci->statements_model->get_statuses();
$statusIds = [];
foreach ($statuses as $status) {
    if ($this->ci->input->post('statements_' . $status)) {
        array_push($statusIds, $status);
    }
}
if (count($statusIds) > 0) {
    array_push($filter, 'AND ' . db_prefix() . 'statements.status IN (' . implode(', ', $statusIds) . ')');
}

$agents    = $this->ci->statements_model->get_sale_agents();
$agentsIds = [];
foreach ($agents as $agent) {
    if ($this->ci->input->post('sale_agent_' . $agent['sale_agent'])) {
        array_push($agentsIds, $agent['sale_agent']);
    }
}
if (count($agentsIds) > 0) {
    array_push($filter, 'AND sale_agent IN (' . implode(', ', $agentsIds) . ')');
}

$modesIds = [];
foreach ($data['payment_modes'] as $mode) {
    if ($this->ci->input->post('statement_payments_by_' . $mode['id'])) {
        array_push($modesIds, $mode['id']);
    }
}
if (count($modesIds) > 0) {
    array_push($where, 'AND ' . db_prefix() . 'statements.id IN (SELECT statementid FROM ' . db_prefix() . 'statementpaymentrecords WHERE paymentmode IN ("' . implode('", "', $modesIds) . '"))');
}

$years     = $this->ci->statements_model->get_statements_years();
$yearArray = [];
foreach ($years as $year) {
    if ($this->ci->input->post('year_' . $year['year'])) {
        array_push($yearArray, $year['year']);
    }
}
if (count($yearArray) > 0) {
    array_push($where, 'AND YEAR(date) IN (' . implode(', ', $yearArray) . ')');
}

if (count($filter) > 0) {
    array_push($where, 'AND (' . prepare_dt_filter($filter) . ')');
}

if ($clientid != '') {
    array_push($where, 'AND ' . db_prefix() . 'statements.clientid=' . $this->ci->db->escape_str($clientid));
}

if ($project_id) {
    array_push($where, 'AND project_id=' . $this->ci->db->escape_str($project_id));
}

if (!has_permission('statements', '', 'view')) {
    $userWhere = 'AND ' . get_statements_where_sql_for_staff(get_staff_user_id());
    array_push($where, $userWhere);
}

$aColumns = hooks()->apply_filters('statements_table_sql_columns', $aColumns);

// Fix for big queries. Some hosting have max_join_limit
if (count($custom_fields) > 4) {
    @$this->ci->db->query('SET SQL_BIG_SELECTS=1');
}

$result = data_tables_init($aColumns, $sIndexColumn, $sTable, $join, $where, [
    db_prefix() . 'statements.id',
    db_prefix() . 'statements.clientid',
    db_prefix() . 'currencies.name as currency_name',
    'project_id',
    'hash',
    'recurring',
    'deleted_customer_name',
    ]);
$output  = $result['output'];
$rResult = $result['rResult'];

foreach ($rResult as $aRow) {
    $row = [];

    $numberOutput = '';

    // If is from client area table
    if (is_numeric($clientid) || $project_id) {
        $numberOutput = '<a href="' . admin_url('statements/list_statements/' . $aRow['id']) . '" target="_blank">' . format_statement_number($aRow['id']) . '</a>';
    } else {
        $numberOutput = '<a href="' . admin_url('statements/list_statements/' . $aRow['id']) . '" onclick="init_statement(' . $aRow['id'] . '); return false;">' . format_statement_number($aRow['id']) . '</a>';
    }

    if ($aRow['recurring'] > 0) {
        $numberOutput .= '<br /><span class="label label-primary inline-block tw-mt-1"> ' . _l('statement_recurring_indicator') . '</span>';
    }

    $numberOutput .= '<div class="row-options">';

    $numberOutput .= '<a href="' . site_url('statement/' . $aRow['id'] . '/' . $aRow['hash']) . '" target="_blank">' . _l('view') . '</a>';
    if (has_permission('statements', '', 'edit')) {
        $numberOutput .= ' | <a href="' . admin_url('statements/statement/' . $aRow['id']) . '">' . _l('edit') . '</a>';
    }
    $numberOutput .= '</div>';

    $row[] = $numberOutput;

    $row[] = app_format_money($aRow['total'], $aRow['currency_name']);

    $row[] = app_format_money($aRow['total_tax'], $aRow['currency_name']);

    $row[] = $aRow['year'];

    $row[] = _d($aRow['date']);

    if (empty($aRow['deleted_customer_name'])) {
        $row[] = '<a href="' . admin_url('clients/client/' . $aRow['clientid']) . '">' . $aRow['company'] . '</a>';
    } else {
        $row[] = $aRow['deleted_customer_name'];
    }

    $row[] = '<a href="' . admin_url('projects/view/' . $aRow['project_id']) . '">' . $aRow['project_name'] . '</a>';
    ;

    $row[] = render_tags($aRow['tags']);

    $row[] = _d($aRow['duedate']);

    $row[] = format_statement_status($aRow[db_prefix() . 'statements.status']);

    // Custom fields add values
    foreach ($customFieldsColumns as $customFieldColumn) {
        $row[] = (strpos($customFieldColumn, 'date_picker_') !== false ? _d($aRow[$customFieldColumn]) : $aRow[$customFieldColumn]);
    }

    $row['DT_RowClass'] = 'has-row-options';

    $row = hooks()->apply_filters('statements_table_row_data', $row, $aRow);

    $output['aaData'][] = $row;
}