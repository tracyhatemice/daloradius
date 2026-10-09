--
-- daloRADIUS: per-NAS usage ratio ("charged" traffic) and the User Traffic graph
--
-- Apply this script when upgrading an existing daloRADIUS installation to a
-- version that reads the nas_usage_rate table (Charged Traffic column in the
-- top users report, ratio-split user upload/download/traffic graphs) and ships
-- app/operators/graphs-overall_traffic.php.
--
-- Fresh installations already get these from contrib/db/mariadb-daloradius.sql
-- and contrib/db/fr3-mariadb-freeradius.sql.
--

-- per-NAS traffic multiplier, shared with the freeradius sqlcounter traffic counters
CREATE TABLE IF NOT EXISTS nas_usage_rate (
    nasipaddress varchar(45) NOT NULL,
    multiplier decimal(10,4) NOT NULL,
    PRIMARY KEY (nasipaddress)
);

-- register the new page and grant it to every operator who can already see the user upload graph
INSERT INTO operators_acl_files (file, category, section)
SELECT 'graphs_overall_traffic', 'Graphs', 'General'
 WHERE NOT EXISTS (SELECT 1 FROM operators_acl_files WHERE file = 'graphs_overall_traffic');

INSERT INTO operators_acl (operator_id, file, access)
SELECT a.operator_id, 'graphs_overall_traffic', a.access
  FROM operators_acl a
 WHERE a.file = 'graphs_overall_upload'
   AND NOT EXISTS (SELECT 1 FROM operators_acl b WHERE b.operator_id = a.operator_id AND b.file = 'graphs_overall_traffic');
