<?php
include_once __DIR__.'/../../../common/includes/config_read.php';
include __DIR__.'/../checklogin.php';
require_once __DIR__.'/../../../common/includes/chart.php';
require_once __DIR__.'/../widget_reads_pdo.php';
dalo_widget_inputs(true);


$category = (isset($_GET['category']) && in_array(strtolower(trim($_GET['category'])), array('upload', 'download', 'traffic', 'login')))
    ? strtolower(trim($_GET['category']))
    : 'download';
$type = (isset($_GET['type']) && in_array(strtolower($_GET['type']), array('daily', 'monthly', 'yearly')))
    ? strtolower($_GET['type'])
    : 'daily';
$size = (isset($_GET['size']) && in_array(strtolower($_GET['size']), array('gigabytes', 'megabytes')))
    ? strtolower($_GET['size'])
    : 'megabytes';
$username = isset($_GET['user']) ? $_GET['user'] : '';

$stacked = false;
$datasets = array();
try {
$widgetPDO=dalo_widget_open();
dalo_widget_authorize($widgetPDO,array('graphs-overall_'.($category==='login'?'logins':$category)));
if ($category === 'login') {
    $statistics = dalo_chart_overall_user_statistics(
        $widgetPDO,
        $configValues['CONFIG_DB_TBL_RADACCT'],
        $username,
        $category,
        $type,
        $size,
        'traffic %sed by user %s',
        true
    );
    $datasets = array(dalo_chart_bar_dataset($statistics['ytitle'], $statistics['values']));
} else {
    // traffic categories show charged traffic, one stacked dataset per NAS ratio
    $statistics = dalo_chart_overall_user_traffic_by_ratio(
        $widgetPDO,
        $configValues['CONFIG_DB_TBL_RADACCT'],
        $configValues['CONFIG_DB_TBL_NASUSAGERATE'],
        $username,
        $category,
        $type,
        $size,
        true
    );
    foreach ($statistics['ratios'] as $index => $ratio) {
        $datasets[] = dalo_chart_bar_dataset(dalo_chart_ratio_label($ratio), $statistics['datasets'][$ratio], $index);
    }
    $stacked = true;
}
unset($widgetPDO);
} catch (Throwable $exception) {dalo_widget_failure($exception,true);}

dalo_chart_response(
    'bar',
    $statistics['labels'],
    $datasets,
    $statistics['title'],
    ucfirst($type) . ' distribution',
    $statistics['ytitle'],
    $stacked
);
