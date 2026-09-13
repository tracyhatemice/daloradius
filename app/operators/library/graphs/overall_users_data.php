<?php
include('../checklogin.php');
include('../../../common/includes/chart.php');

$category = (isset($_GET['category']) && in_array(strtolower(trim($_GET['category'])), array('upload', 'download', 'traffic', 'login')))
    ? strtolower(trim($_GET['category']))
    : 'download';
$type = (isset($_GET['type']) && in_array(strtolower($_GET['type']), array('daily', 'monthly', 'yearly')))
    ? strtolower($_GET['type'])
    : 'daily';
$size = (isset($_GET['size']) && in_array(strtolower($_GET['size']), array('gigabytes', 'megabytes')))
    ? strtolower($_GET['size'])
    : 'megabytes';
$username = isset($_GET['user']) ? str_replace('%', '', $_GET['user']) : '';

include('../../../common/includes/db_open.php');

if ($category === 'login') {
    $statistics = dalo_chart_overall_user_statistics(
        $dbSocket,
        $configValues['CONFIG_DB_TBL_RADACCT'],
        $username,
        $category,
        $type,
        $size,
        'traffic %sed by user %s',
        true
    );
    $datasets = array(dalo_chart_bar_dataset($statistics['ytitle'], $statistics['values']));
    $stacked = false;
} else {
    // traffic categories: charged (ratio-weighted) traffic, one stacked dataset per NAS ratio
    $statistics = dalo_chart_overall_user_traffic_by_ratio(
        $dbSocket,
        $configValues['CONFIG_DB_TBL_RADACCT'],
        $configValues['CONFIG_DB_TBL_NASUSAGERATE'],
        $username,
        $category,
        $type,
        $size,
        true
    );
    $datasets = array();
    foreach ($statistics['ratios'] as $i => $ratio) {
        $datasets[] = dalo_chart_bar_dataset(dalo_chart_ratio_label($ratio), $statistics['datasets'][$ratio], $i);
    }
    $stacked = true;
}

include('../../../common/includes/db_close.php');

dalo_chart_response(
    'bar',
    $statistics['labels'],
    $datasets,
    $statistics['title'],
    ucfirst($type) . ' distribution',
    $statistics['ytitle'],
    $stacked
);
