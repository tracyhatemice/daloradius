<?php
/* JSON and data helpers for Chart.js graph endpoints. */

function dalo_chart_bar_dataset($label, $values, $color_index = 0) {
    $palette = array(
        '54, 162, 235',
        '255, 159, 64',
        '75, 192, 192',
        '153, 102, 255',
        '255, 99, 132',
        '201, 203, 207',
    );
    $rgb = $palette[intval($color_index) % count($palette)];

    return array(
        'label' => $label,
        'data' => $values,
        'backgroundColor' => sprintf('rgba(%s, 0.55)', $rgb),
        'borderColor' => sprintf('rgb(%s)', $rgb),
        'borderWidth' => 1,
    );
}

/* Human readable label for a nas_usage_rate multiplier (1.0000 -> "Ratio 1", 0.5000 -> "Ratio 0.5") */
function dalo_chart_ratio_label($ratio) {
    return 'Ratio ' . rtrim(rtrim(number_format((float)$ratio, 4, '.', ''), '0'), '.');
}

/* SQL expression for the octets of a traffic category: upload, download or traffic (both) */
function dalo_chart_traffic_octets_expr($category, $alias = 'ra') {
    if ($category === 'upload') {
        return sprintf('%s.AcctInputOctets', $alias);
    }
    if ($category === 'download') {
        return sprintf('%s.AcctOutputOctets', $alias);
    }
    return sprintf('(%s.AcctInputOctets + %s.AcctOutputOctets)', $alias, $alias);
}

/*
 * Per-period charged traffic of a user, split by NAS usage ratio. Charged traffic is
 * octets * COALESCE(nas_usage_rate.multiplier, 1), the formula the freeradius sqlcounter
 * traffic counters enforce.
 */
function dalo_chart_overall_user_traffic_by_ratio(PDO $dbSocket, $radacct_table, $rate_table, $username, $category, $type, $size, $require_existing_user = false) {
    require_once __DIR__ . '/chart_pdo.php';
    return dalo_chart_overall_ratio_pdo($dbSocket, $radacct_table, $rate_table, $username, $category, $type, $size, $require_existing_user);
}

function dalo_chart_overall_user_statistics(PDO $dbSocket, $radacct_table, $username, $category, $type, $size, $traffic_title_template, $require_existing_user = false) {
    require_once __DIR__ . '/chart_pdo.php';
    return dalo_chart_overall_pdo($dbSocket,$radacct_table,$username,$category,$type,$size,$traffic_title_template,$require_existing_user);
}

function dalo_chart_response($type, $labels, $datasets, $title, $x_title = '', $y_title = '', $stacked = false) {
    $options = array(
        'responsive' => true,
        'maintainAspectRatio' => false,
        'plugins' => array(
            'title' => array('display' => true, 'text' => $title),
            'tooltip' => array('enabled' => true),
        ),
    );

    if ($type !== 'pie' && $type !== 'doughnut') {
        $options['scales'] = array(
            'x' => array('title' => array('display' => !empty($x_title), 'text' => $x_title)),
            'y' => array('beginAtZero' => true, 'title' => array('display' => !empty($y_title), 'text' => $y_title)),
        );

        if ($stacked) {
            $options['scales']['x']['stacked'] = true;
            $options['scales']['y']['stacked'] = true;
        }
    }

    $json = json_encode(array(
        'type' => $type,
        'data' => array('labels' => array_values($labels), 'datasets' => $datasets),
        'options' => $options,
    ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    header('Content-Type: application/json; charset=utf-8');
    if ($json === false) {
        http_response_code(500);
        echo '{"error":"Unable to encode chart response"}';
        exit;
    }

    echo $json;
    exit;
}
