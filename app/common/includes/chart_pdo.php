<?php
/* Prepared chart reads. The borrowed PDO handle remains owned by the caller. */
if (strpos($_SERVER['PHP_SELF'] ?? '', '/includes/chart_pdo.php') !== false) {
    http_response_code(404); exit;
}
// chart.php holds the shared, database-free helpers (ratio labels, octet expressions);
// both files are include-once guarded, so either one may be loaded first.
require_once __DIR__ . '/chart.php';
function dalo_chart_table($name) {
    if (!is_string($name) || preg_match('/\A[A-Za-z0-9_]+\z/D',$name)!==1) {
        throw new InvalidArgumentException('Invalid chart table');
    }
    return '`'.$name.'`';
}
function dalo_chart_rows(PDO $pdo, $sql, $bindings=array()) {
    $statement=$pdo->prepare($sql);
    foreach ($bindings as $name=>$value) {
        if (!is_string($value) && !is_int($value)) { throw new InvalidArgumentException('Invalid chart binding'); }
        $statement->bindValue($name,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);
    }
    $statement->execute();
    return $statement->fetchAll(PDO::FETCH_NUM);
}
function dalo_chart_overall_pdo(PDO $pdo,$table,$username,$category,$type,$size,$title_template,$require_existing) {
    $table=dalo_chart_table($table);
    if (!is_string($username) || !in_array($category,array('login','upload','download'),true) ||
        !in_array($type,array('daily','monthly','yearly'),true) || !in_array($size,array('megabytes','gigabytes'),true)) {
        throw new InvalidArgumentException('Invalid chart selection');
    }
    $labels=array();$values=array();
    if ($username!=='') {
        $bindings=array(':chart_username'=>$username);
        $exists=!$require_existing || count(dalo_chart_rows($pdo,"SELECT DISTINCT(username) FROM $table WHERE username=:chart_username",$bindings))===1;
        if ($exists) {
            $aggregate=$category==='login'?'COUNT(AcctStartTime)':($category==='upload'?'SUM(AcctInputOctets)':'SUM(AcctOutputOctets)');
            if ($type==='yearly') {
                $period='YEAR(AcctStartTime)';$group='YEAR(AcctStartTime)';$order='YEAR(AcctStartTime) DESC';
            } elseif ($type==='monthly') {
                $period="CONCAT(LEFT(MONTHNAME(AcctStartTime),3),' (',YEAR(AcctStartTime),')')";
                $group='YEAR(AcctStartTime),MONTH(AcctStartTime)';$order='YEAR(AcctStartTime) DESC,MONTH(AcctStartTime) DESC';
            } else {
                $period='DATE(AcctStartTime)';$group=$period;$order=$period.' DESC';
            }
            foreach(dalo_chart_rows($pdo,"SELECT $period,$aggregate FROM $table WHERE username=:chart_username AND AcctStopTime>0 GROUP BY $group ORDER BY $order LIMIT 36",$bindings) as $row) {
                $labels[]=(string)$row[0];
                $values[]=$category==='login'?(int)$row[1]:round((float)$row[1]/($size==='gigabytes'?1073741824:1048576),1);
            }
        }
    }
    return array('labels'=>$labels,'values'=>$values,
        'title'=>$category==='login'?sprintf('login statistics for user %s',$username):sprintf($title_template,$category,$username),
        'ytitle'=>$category==='login'?'Login count':ucfirst($size).' '.$category.'ed');
}

/*
 * Charged traffic per period, split by NAS usage ratio, for one user.
 *
 * Returns labels (most recent period first, at most 36), datasets keyed by ratio with
 * values aligned to labels (missing periods zero filled), the sorted ratios, title and
 * ytitle. The borrowed PDO handle stays owned by the caller.
 */
function dalo_chart_overall_ratio_pdo(PDO $pdo,$table,$rate_table,$username,$category,$type,$size,$require_existing) {
    $table=dalo_chart_table($table);$rate=dalo_chart_table($rate_table);
    if (!is_string($username) || !in_array($category,array('upload','download','traffic'),true) ||
        !in_array($type,array('daily','monthly','yearly'),true) || !in_array($size,array('megabytes','gigabytes'),true)) {
        throw new InvalidArgumentException('Invalid chart selection');
    }
    $labels=array();$datasets=array();$ratios=array();
    if ($username!=='') {
        $bindings=array(':chart_username'=>$username);
        $exists=!$require_existing || count(dalo_chart_rows($pdo,"SELECT DISTINCT(username) FROM $table WHERE username=:chart_username",$bindings))===1;
        if ($exists) {
            $octets=dalo_chart_traffic_octets_expr($category);
            if ($type==='yearly') {
                $period='YEAR(ra.AcctStartTime)';$order='YEAR(ra.AcctStartTime) DESC';
            } elseif ($type==='monthly') {
                $period="CONCAT(LEFT(MONTHNAME(ra.AcctStartTime),3),' (',YEAR(ra.AcctStartTime),')')";
                $order='YEAR(ra.AcctStartTime) DESC,MONTH(ra.AcctStartTime) DESC';
            } else {
                $period='DATE(ra.AcctStartTime)';$order='DATE(ra.AcctStartTime) DESC';
            }
            $sql="SELECT $period AS period, COALESCE(nur.multiplier, 1) AS ratio,
                         SUM($octets * COALESCE(nur.multiplier, 1)) AS charged
                    FROM $table AS ra
                    LEFT JOIN $rate AS nur ON nur.nasipaddress = ra.NASIPAddress
                   WHERE ra.username=:chart_username AND ra.AcctStopTime>0
                   GROUP BY period, ratio
                   ORDER BY $order, ratio ASC";
            $division=$size==='gigabytes'?1073741824:1048576;

            // pivot the (period, ratio, charged) rows, keeping the 36 most recent periods
            $per_period=array();
            foreach (dalo_chart_rows($pdo,$sql,$bindings) as $row) {
                $label=(string)$row[0];
                $ratio=number_format((float)$row[1],4,'.','');
                if (!array_key_exists($label,$per_period)) {
                    if (count($per_period)>=36) { break; }
                    $per_period[$label]=array();$labels[]=$label;
                }
                $per_period[$label][$ratio]=round((float)$row[2]/$division,1);
                $ratios[$ratio]=true;
            }

            $ratios=array_keys($ratios);
            sort($ratios,SORT_NUMERIC);
            foreach ($ratios as $ratio) {
                $values=array();
                foreach ($labels as $label) {
                    $values[]=array_key_exists($ratio,$per_period[$label])?$per_period[$label][$ratio]:0;
                }
                $datasets[$ratio]=$values;
            }
        }
    }
    return array('labels'=>$labels,'datasets'=>$datasets,'ratios'=>$ratios,
        'title'=>sprintf('charged %s of user %s (by NAS ratio)',$category,$username),
        'ytitle'=>ucfirst($size).' '.($category==='traffic'?'charged':$category.'ed'));
}

