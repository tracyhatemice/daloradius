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

/* Human readable label for a nas_usage_rate multiplier (e.g. 1.0000 -> "Ratio 1", 0.5000 -> "Ratio 0.5") */
function dalo_chart_ratio_label($ratio) {
    $formatted = rtrim(rtrim(number_format(floatval($ratio), 4, '.', ''), '0'), '.');
    return 'Ratio ' . $formatted;
}

/* SQL expression for the octets of a traffic category: upload, download or traffic (upload + download) */
function dalo_chart_traffic_octets_expr($category, $alias = 'ra') {
    switch ($category) {
        case 'upload':
            return sprintf('%s.AcctInputOctets', $alias);
        case 'download':
            return sprintf('%s.AcctOutputOctets', $alias);
        default:
            return sprintf('(%s.AcctInputOctets + %s.AcctOutputOctets)', $alias, $alias);
    }
}

/*
 * Per-period traffic of a user, split by nas_usage_rate multiplier ("ratio") and already
 * multiplied by it (the same formula used by the freeradius sqlcounter traffic counters).
 *
 * Returns labels (periods, most recent first, at most 36), datasets (ratio => values aligned
 * with labels, missing periods filled with 0), ratios (sorted ascending), title and ytitle.
 */
function dalo_chart_overall_user_traffic_by_ratio($dbSocket, $radacct_table, $rate_table, $username, $category, $type, $size, $require_existing_user = false) {
    $labels = array();
    $datasets = array();
    $ratios = array();

    if (!empty($username)) {
        $escaped_username = $dbSocket->escapeSimple($username);
        $has_matching_user = true;

        if ($require_existing_user) {
            $check_sql = sprintf(
                "SELECT DISTINCT(username) FROM %s WHERE username='%s'",
                $radacct_table,
                $escaped_username
            );
            $has_matching_user = $dbSocket->query($check_sql)->numRows() === 1;
        }

        if ($has_matching_user) {
            $octets = dalo_chart_traffic_octets_expr($category);

            if ($type === 'yearly') {
                $period = 'YEAR(ra.AcctStartTime)';
                $order = 'YEAR(ra.AcctStartTime) DESC';
            } elseif ($type === 'monthly') {
                $period = "CONCAT(LEFT(MONTHNAME(ra.AcctStartTime), 3), ' (', YEAR(ra.AcctStartTime), ')')";
                $order = 'YEAR(ra.AcctStartTime) DESC, MONTH(ra.AcctStartTime) DESC';
            } else {
                $period = 'DATE(ra.AcctStartTime)';
                $order = 'DATE(ra.AcctStartTime) DESC';
            }

            $sql = sprintf(
                "SELECT %s AS period, COALESCE(nur.multiplier, 1) AS ratio, SUM(%s * COALESCE(nur.multiplier, 1)) AS charged
                   FROM %s AS ra
                   LEFT JOIN %s AS nur ON nur.nasipaddress = ra.NASIPAddress
                  WHERE ra.username='%s' AND ra.AcctStopTime>0
                  GROUP BY period, ratio
                  ORDER BY %s, ratio ASC",
                $period, $octets, $radacct_table, $rate_table, $escaped_username, $order
            );

            $res = $dbSocket->query($sql);
            $division = $size === 'gigabytes' ? 1073741824 : 1048576;

            // pivot: rows are (period, ratio, charged); keep the 36 most recent periods
            $per_period = array();
            while ($row = $res->fetchRow()) {
                $label = strval($row[0]);
                $ratio = number_format(floatval($row[1]), 4, '.', '');

                if (!array_key_exists($label, $per_period)) {
                    if (count($per_period) >= 36) {
                        break;
                    }
                    $per_period[$label] = array();
                    $labels[] = $label;
                }

                $per_period[$label][$ratio] = round(floatval($row[2]) / $division, 1);
                $ratios[$ratio] = true;
            }

            $ratios = array_keys($ratios);
            sort($ratios, SORT_NUMERIC);

            foreach ($ratios as $ratio) {
                $values = array();
                foreach ($labels as $label) {
                    $values[] = array_key_exists($ratio, $per_period[$label]) ? $per_period[$label][$ratio] : 0;
                }
                $datasets[$ratio] = $values;
            }
        }
    }

    $category_word = ($category === 'traffic') ? 'traffic' : $category;
    $ytitle = ucfirst($size) . ' ' . (($category === 'traffic') ? 'charged' : $category . 'ed');
    $title = sprintf('charged %s of user %s (by NAS ratio)', $category_word, $username);

    return array(
        'labels' => $labels,
        'datasets' => $datasets,
        'ratios' => $ratios,
        'title' => $title,
        'ytitle' => $ytitle,
    );
}

function dalo_chart_overall_user_statistics($dbSocket, $radacct_table, $username, $category, $type, $size, $traffic_title_template, $require_existing_user = false) {
    $labels = array();
    $values = array();

    if (!empty($username)) {
        $escaped_username = $dbSocket->escapeSimple($username);
        $has_matching_user = true;

        if ($require_existing_user) {
            $check_sql = sprintf(
                "SELECT DISTINCT(username) FROM %s WHERE username='%s'",
                $radacct_table,
                $escaped_username
            );
            $has_matching_user = $dbSocket->query($check_sql)->numRows() === 1;
        }

        if ($has_matching_user) {
            $dbfield = $category === 'login'
                ? 'COUNT(AcctStartTime)'
                : ($category === 'upload' ? 'SUM(AcctInputOctets)' : 'SUM(AcctOutputOctets)');

            $session_filter = ' AND AcctStopTime>0';

            if ($type === 'yearly') {
                $sql = "SELECT YEAR(AcctStartTime), %s FROM %s WHERE username='%s'%s GROUP BY YEAR(AcctStartTime) ORDER BY YEAR(AcctStartTime) DESC LIMIT 36";
            } elseif ($type === 'monthly') {
                $sql = "SELECT CONCAT(LEFT(MONTHNAME(AcctStartTime), 3), ' (', YEAR(AcctStartTime), ')'), %s FROM %s WHERE username='%s'%s GROUP BY YEAR(AcctStartTime), MONTH(AcctStartTime) ORDER BY YEAR(AcctStartTime) DESC, MONTH(AcctStartTime) DESC LIMIT 36";
            } else {
                $sql = "SELECT DATE(AcctStartTime), %s FROM %s WHERE username='%s'%s GROUP BY DATE(AcctStartTime) ORDER BY DATE(AcctStartTime) DESC LIMIT 36";
            }

            $res = $dbSocket->query(sprintf($sql, $dbfield, $radacct_table, $escaped_username, $session_filter));
            $division = $size === 'gigabytes' ? 1073741824 : 1048576;

            while ($row = $res->fetchRow()) {
                $labels[] = strval($row[0]);
                $values[] = $category === 'login'
                    ? intval($row[1])
                    : round(floatval($row[1]) / $division, 1);
            }
        }
    }

    $ytitle = $category === 'login'
        ? 'Login count'
        : ucfirst($size) . ' ' . $category . 'ed';
    $title = $category === 'login'
        ? sprintf('login statistics for user %s', $username)
        : sprintf($traffic_title_template, $category, $username);

    return array(
        'labels' => $labels,
        'values' => $values,
        'title' => $title,
        'ytitle' => $ytitle,
    );
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
