<?php
/*
 *********************************************************************************************************
 * daloRADIUS - RADIUS Web Platform
 * Copyright (C) 2007 - Liran Tal <liran@lirantal.com> All Rights Reserved.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 *
 *********************************************************************************************************
 *
 * Description:    this table extension lists, per period (day, month or year), the charged
 *                 traffic of a user split by NAS usage ratio. Charged traffic is
 *                 octets * nas_usage_rate.multiplier (1 when the NAS has no rate), the
 *                 same formula used by the freeradius sqlcounter traffic counters.
 *                 The including file sets $traffic_category to upload, download or traffic.
 *
 * Authors:        Liran Tal <liran@lirantal.com>
 *                 Filippo Lauria <filippo.lauria@iit.cnr.it>
 *
 *********************************************************************************************************
 */

// prevent this file to be directly accessed
$extension_file = '/library/tables/overall_users_traffic_common.php';
if (strpos($_SERVER['PHP_SELF'], $extension_file) !== false) {
    header("Location: ../../index.php");
    exit;
}

if (!isset($traffic_category) || !in_array($traffic_category, array( "upload", "download", "traffic" ))) {
    $traffic_category = "traffic";
}

$username = (array_key_exists('username', $_GET) && isset($_GET['username']))
          ? str_replace('%', '', $_GET['username']) : "";
$username_enc = (!empty($username)) ? htmlspecialchars($username, ENT_QUOTES, 'UTF-8') : "";

$type = (array_key_exists('type', $_GET) && isset($_GET['type']) &&
             in_array(strtolower($_GET['type']), array( "daily", "monthly", "yearly" )))
          ? strtolower($_GET['type']) : "daily";

$size = (array_key_exists('size', $_GET) && isset($_GET['size']) &&
         in_array(strtolower($_GET['size']), array( "gigabytes", "megabytes" )))
      ? strtolower($_GET['size']) : "megabytes";

// whenever possible we use a whitelist approach
$orderType = (array_key_exists('orderType', $_GET) && isset($_GET['orderType']) &&
              in_array(strtolower($_GET['orderType']), array( "desc", "asc" )))
           ? strtolower($_GET['orderType']) : "asc";

// used for presentation purpose
$label_param = array();
$label_param['day'] = "Day of month";
$label_param['month'] = "Month of year";
$label_param['year'] = "Year";

$category_words = array(
                        "upload" => array( "key" => "uploads", "noun" => "upload", "none" => "No upload(s) found for this user" ),
                        "download" => array( "key" => "downloads", "noun" => "download", "none" => "No download(s) found for this user" ),
                        "traffic" => array( "key" => "traffic", "noun" => "total traffic", "none" => "No traffic found for this user" ),
                       );
$data_key = $category_words[$traffic_category]['key'];
$data_noun = $category_words[$traffic_category]['noun'];

$size_division = array("gigabytes" => 1073741824, "megabytes" => 1048576);
$short_size = array("gigabytes" => "GBs", "megabytes" => "MBs");

$is_valid = false;

include('../common/includes/db_open.php');
include_once('../common/includes/chart.php');
include('include/management/pages_common.php');


if (!empty($username)) {
    $sql = sprintf("SELECT DISTINCT(username) FROM %s WHERE username='%s'",
                   $configValues['CONFIG_DB_TBL_RADACCT'], $dbSocket->escapeSimple($username));
    $res = $dbSocket->query($sql);
    $numrows = $res->numRows();

    $is_valid = $numrows == 1;
}

if ($is_valid) {
    $escaped_username = $dbSocket->escapeSimple($username);
    $radacct = $configValues['CONFIG_DB_TBL_RADACCT'];
    $rate_table = $configValues['CONFIG_DB_TBL_NASUSAGERATE'];
    $octets = dalo_chart_traffic_octets_expr($traffic_category);
    $ratio_expr = "COALESCE(nur.multiplier, 1)";

    // the distinct NAS ratios this user's sessions were accounted with (one table column each)
    $sql = sprintf("SELECT DISTINCT %s AS ratio FROM %s AS ra LEFT JOIN %s AS nur ON nur.nasipaddress = ra.NASIPAddress
                     WHERE ra.username='%s' AND ra.AcctStopTime>0 ORDER BY ratio ASC",
                   $ratio_expr, $radacct, $rate_table, $escaped_username);
    $res = $dbSocket->query($sql);

    $ratios = array();
    while ($row = $res->fetchRow()) {
        $ratios[] = number_format(floatval($row[0]), 4, '.', '');
    }

    // one charged-traffic column per ratio, plus the charged total used for ordering and totals
    $ratio_columns = array();
    foreach ($ratios as $i => $ratio) {
        $ratio_columns[] = sprintf("SUM(CASE WHEN %s = %s THEN %s * %s ELSE 0 END) AS ratio_%d",
                                   $ratio_expr, $ratio, $octets, $ratio_expr, $i);
    }
    $data_columns = sprintf("SUM(%s * %s) AS %s", $octets, $ratio_expr, $data_key);
    if (count($ratio_columns) > 0) {
        $data_columns .= ", " . implode(", ", $ratio_columns);
    }

    switch ($type) {
        case "yearly":
            $selected_param = "year";
            $orderBy = (array_key_exists('orderBy', $_GET) && isset($_GET['orderBy']) &&
                        in_array(strtolower($_GET['orderBy']), array( $data_key, "year" )))
                     ? strtolower($_GET['orderBy']) : $data_key;

            $sql = "SELECT YEAR(ra.AcctStartTime) AS year, %s
                      FROM %s AS ra
                      LEFT JOIN %s AS nur ON nur.nasipaddress = ra.NASIPAddress
                     WHERE ra.username='%s' AND ra.AcctStopTime>0
                     GROUP BY year";
            break;

        case "monthly":
            $selected_param = "month";
            $orderBy = (array_key_exists('orderBy', $_GET) && isset($_GET['orderBy']) &&
                        in_array(strtolower($_GET['orderBy']), array( $data_key, "month" )))
                     ? strtolower($_GET['orderBy']) : $data_key;

            $sql = "SELECT CONCAT(LEFT(MONTHNAME(ra.AcctStartTime), 3), ' (', YEAR(ra.AcctStartTime), ')'), %s,
                           CAST(CONCAT(YEAR(ra.AcctStartTime), '-', MONTH(ra.AcctStartTime), '-01') AS DATE) AS month
                      FROM %s AS ra
                      LEFT JOIN %s AS nur ON nur.nasipaddress = ra.NASIPAddress
                     WHERE ra.username='%s' AND ra.AcctStopTime>0
                     GROUP BY month";
            break;

        default:
        case "daily":
            $selected_param = "day";
            $orderBy = (array_key_exists('orderBy', $_GET) && isset($_GET['orderBy']) &&
                        in_array(strtolower($_GET['orderBy']), array( $data_key, "day" )))
                     ? strtolower($_GET['orderBy']) : $data_key;

            $sql = "SELECT DATE(ra.AcctStartTime) AS day, %s
                      FROM %s AS ra
                      LEFT JOIN %s AS nur ON nur.nasipaddress = ra.NASIPAddress
                     WHERE ra.username='%s' AND ra.AcctStopTime>0
                     GROUP BY day";
            break;
    }

    $sql = sprintf($sql . " ORDER BY %s %s", $data_columns, $radacct, $rate_table, $escaped_username, $orderBy, $orderType);

    $res = $dbSocket->query($sql);

    $numrows = $res->numRows();

    if ($numrows > 0) {
        // $cols is needed only if $numwrows > 0
        // ratio columns use integer keys so that they are not offered for ordering
        $cols = array( $selected_param => $label_param[$selected_param] );
        foreach ($ratios as $ratio) {
            $cols[] = dalo_chart_ratio_label($ratio);
        }
        $cols[$data_key] = "Total charged " . $data_noun . " in " . $size;

        $colspan = count($cols);
        $half_colspan = intval($colspan / 2);

        /* START - Related to pages_numbering.php */

        // when $numrows is set, $maxPage is calculated inside this include file
        include('include/management/pages_numbering.php');    // must be included after opendb because it needs to read
                                                              // the CONFIG_IFACE_TABLES_LISTING variable from the config file

        // here we decide if page numbers should be shown
        $drawNumberLinks = strtolower($configValues['CONFIG_IFACE_TABLES_LISTING_NUM']) == "yes" && $maxPage > 1;

        /* END */


        $total_data = 0;
        while ($row = $res->fetchRow()) {
            $total_data += floatval($row[1]);
        }

        $total_data = number_format(floatval($total_data / $size_division[$size]), 1, ".", "");

        $sql .= sprintf(" LIMIT %s, %s", $offset, $rowsPerPage);
        $res = $dbSocket->query($sql);
        $logDebugSQL = "$sql;\n";

        $per_page_numrows = $res->numRows();

        // the partial query is built starting from user input
        // and for being passed to setupNumbering and setupLinks functions
        $partial_query_string = sprintf("&type=%s&size=%s&username=%s&goto_stats=true", $type, $size, $username_enc);

        echo '<div class="my-3 text-center">';
        printf("<h4>%s of charged %s %s produced by user %s (split by NAS ratio)</h4>",
               $size, $data_noun, $type, htmlspecialchars($username, ENT_QUOTES, 'UTF-8'));

        $descriptors = array();

        $params = array(
                            'num_rows' => $numrows,
                            'rows_per_page' => $rowsPerPage,
                            'page_num' => $pageNum,
                            'order_by' => $orderBy,
                            'order_type' => $orderType,
                            'partial_query_string' => $partial_query_string,
                        );
        $descriptors['center'] = array( 'draw' => $drawNumberLinks, 'params' => $params );

        print_table_prologue($descriptors);

        // print table top
        print_table_top();

        // second line of table header
        printTableHead($cols, $orderBy, $orderType, $partial_query_string);

        // closes table header, opens table body
        print_table_middle();

        $per_page_data = 0;
        while ($row = $res->fetchRow()) {
            // row layout: [0] period label, [1] charged total, [2 .. 2+n-1] one cell per ratio
            $data = floatval($row[1]);
            $per_page_data += $data;

            echo "<tr>"
               . "<td>" . htmlspecialchars($row[0], ENT_QUOTES, 'UTF-8') . "</td>";

            foreach ($ratios as $i => $ratio) {
                $cell = floatval($row[2 + $i]);
                echo "<td>" . number_format(floatval($cell / $size_division[$size]), 1, ".", "") . " " . $short_size[$size] . "</td>";
            }

            echo "<td>" . number_format(floatval($data / $size_division[$size]), 1, ".", "") . " " . $short_size[$size] . "</td>"
               . "</tr>";

        }
        $per_page_data = number_format(floatval($per_page_data / $size_division[$size]), 1, ".", "");

        // close tbody,
        // print tfoot
        // and close table + form (if any)
        $table_foot = array(
                                'num_rows' => $numrows,
                                'rows_per_page' => $per_page_numrows,
                                'colspan' => $colspan,
                                'multiple_pages' => $drawNumberLinks,
                           );
        $descriptor = array( 'table_foot' => $table_foot );

        print_table_bottom($descriptor);

        // get and print "links"
        $links = setupLinks_str($pageNum, $maxPage, $orderBy, $orderType, $partial_query_string);
        printLinks($links, $drawNumberLinks);

        echo '</div>';

    } else {
        // $numrows <= 0
        $failureMsg = $category_words[$traffic_category]['none'];
    }

} else {
    // username not valid
    $failureMsg = "You must provide a valid username";
}

if (!empty($failureMsg)) {
    include_once("include/management/actionMessages.php");
}

include('../common/includes/db_close.php');

?>
