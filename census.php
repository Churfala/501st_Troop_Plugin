<?php
/**
 * 501st NZ - Census Master List (Annual Cycle + All-Time)
 */

define("IN_MYBB", 1);
require_once "./global.php";

// --- CONFIGURATION ---
define('CENSUS_ALLOWED_GROUPS', array(10, 20, 24)); // Groups included in census
define('CENSUS_ADMIN_GROUPS',   array(24));          // Groups allowed to view this page
define('CENSUS_ARCHIVE_FIDS',   array(16));          // Archive forum IDs to count from
define('CENSUS_CYCLE_MONTH',    11);                 // Month census cycle starts (November)

// --- 1. PERMISSIONS ---
$is_admin   = ((int)$mybb->usergroup['cancp'] === 1 || (int)$mybb->usergroup['issupermod'] === 1);
$is_allowed = in_array((int)$mybb->user['usergroup'], CENSUS_ADMIN_GROUPS);

if(!$is_admin && !$is_allowed) {
    error("You do not have permission to view this page.");
}

// --- 2. CENSUS CYCLE CALCULATION ---
$current_month = (int)date('n');
$current_year  = (int)date('Y');

if($current_month >= CENSUS_CYCLE_MONTH) {
    $census_start_ts = mktime(0, 0, 0, CENSUS_CYCLE_MONTH, 1, $current_year);
    $display_cycle   = $current_year . "-" . ($current_year + 1);
} else {
    $census_start_ts = mktime(0, 0, 0, CENSUS_CYCLE_MONTH, 1, $current_year - 1);
    $display_cycle   = ($current_year - 1) . "-" . $current_year;
}

// --- 3. BUILD SAFE WHERE CLAUSE ---
// Cast every group ID to int and wrap the whole clause in parentheses
$safe_groups  = array_map('intval', CENSUS_ALLOWED_GROUPS);
$groups_csv   = implode(',', $safe_groups);
$fids_csv     = implode(',', array_map('intval', CENSUS_ARCHIVE_FIDS));
$safe_ts      = (int)$census_start_ts;

$where_parts  = array("u.usergroup IN ({$groups_csv})");
foreach($safe_groups as $gid) {
    $where_parts[] = "FIND_IN_SET('{$gid}', u.additionalgroups)";
}
// Wrap in parens so it can safely combine with other AND conditions later
$where_sql = "(" . implode(" OR ", $where_parts) . ")";

// --- 4. SHARED QUERY BUILDER ---
// Returns the result of the census query for either export or web view
function census_run_query($db, $where_sql, $fids_csv, $safe_ts, $extra_fields = "") {
    $select_extra = $extra_fields ? ", {$extra_fields}" : "";
    return $db->query("
        SELECT
            u.uid,
            u.username,
            u.usergroup,
            u.displaygroup,
            u.email,
            uf.fid6 AS real_name,
            uf.fid4 AS tkid,
            SUM(CASE WHEN t.dateline >= {$safe_ts} THEN 1 ELSE 0 END) AS cycle_total,
            COUNT(t.tid) AS all_time_total,
            MAX(t.dateline) AS last_date,
            MAX(t.tid) AS last_tid
            {$select_extra}
        FROM " . TABLE_PREFIX . "users u
        LEFT JOIN " . TABLE_PREFIX . "userfields uf ON (uf.ufid = u.uid)
        LEFT JOIN " . TABLE_PREFIX . "signup_sheets s ON (s.uid = u.uid)
        LEFT JOIN " . TABLE_PREFIX . "threads t ON (t.tid = s.tid AND t.fid IN ({$fids_csv}))
        WHERE {$where_sql}
        GROUP BY u.uid
        ORDER BY cycle_total DESC, all_time_total DESC, u.username ASC
    ");
}

// --- 5. CSV EXPORT (requires POST confirmation to protect PII) ---
if(isset($mybb->input['export_confirm']) && $mybb->request_method === 'post') {
    verify_post_check($mybb->get_input('my_post_key'));

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=garrison_census_' . date('Y-m-d') . '.csv');

    $output = fopen('php://output', 'w');
    fputcsv($output, array('Rank', 'Username', 'Real Name', 'TKID', 'Email', 'Current Cycle', 'All Time Troops'));

    $query = census_run_query($db, $where_sql, $fids_csv, $safe_ts);
    $i = 1;
    while($row = $db->fetch_array($query)) {
        fputcsv($output, array(
            $i++,
            $row['username'],
            $row['real_name'],
            $row['tkid'],
            $row['email'],
            (int)$row['cycle_total'],
            (int)$row['all_time_total']
        ));
    }

    fclose($output);
    exit;
}

// --- 6. FETCH LAST TROOP SUBJECTS IN ONE QUERY ---
// Avoids a correlated subquery per row by fetching all needed subjects up front
$main_query = census_run_query($db, $where_sql, $fids_csv, $safe_ts);

$last_tids = array();
$user_rows = array();
while($user = $db->fetch_array($main_query)) {
    $user_rows[] = $user;
    if((int)$user['last_tid'] > 0) {
        $last_tids[] = (int)$user['last_tid'];
    }
}

$subjects = array();
if(!empty($last_tids)) {
    $tids_csv    = implode(',', $last_tids);
    $subj_query  = $db->query("SELECT tid, subject FROM " . TABLE_PREFIX . "threads WHERE tid IN ({$tids_csv})");
    while($row = $db->fetch_array($subj_query)) {
        $subjects[(int)$row['tid']] = $row['subject'];
    }
}

// --- 7. BUILD TABLE ROWS ---
$rows = "";
$pos  = 1;

foreach($user_rows as $user) {
    $formatted_name = format_name($user['username'], $user['usergroup'], $user['displaygroup']);
    $last_date      = $user['last_date'] ? my_date('jS M Y', $user['last_date']) : "-";
    $last_tid       = (int)$user['last_tid'];
    $last_subject   = isset($subjects[$last_tid]) ? htmlspecialchars($subjects[$last_tid], ENT_QUOTES, 'UTF-8') : "-";
    $real_name      = htmlspecialchars($user['real_name'], ENT_QUOTES, 'UTF-8');
    $tkid           = htmlspecialchars($user['tkid'],      ENT_QUOTES, 'UTF-8');
    $uid            = (int)$user['uid'];
    $cycle_total    = (int)$user['cycle_total'];
    $all_time       = (int)$user['all_time_total'];

    $rows .= "
    <tr>
        <td style='text-align:center;'>{$pos}</td>
        <td><a href='member.php?action=profile&uid={$uid}' style='text-decoration:none;'>{$formatted_name}</a></td>
        <td>{$real_name}</td>
        <td>{$tkid}</td>
        <td style='text-align:center; background:rgba(177,0,0,0.1); font-size:1.1em;'><strong>{$cycle_total}</strong></td>
        <td style='text-align:center; color:#aaa;'>{$all_time}</td>
        <td style='font-size:0.85em;'>{$last_subject}<br><small style='color:#777;'>{$last_date}</small></td>
    </tr>";
    $pos++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Garrison Census Master List</title>
    <style>
        body { background:#0e0e0e; color:#cfcfcf; font-family:'Segoe UI', sans-serif; padding:20px; }
        .container { width:100%; max-width:1200px; margin:0 auto; background:#1a1a1a; border:1px solid #333; box-shadow:0 10px 30px rgba(0,0,0,0.5); }
        .header { background:#b10000; color:white; padding:15px; font-weight:bold; display:flex; justify-content:space-between; align-items:center; border-bottom:2px solid #800; }
        .btn { background:#fff; color:#000; padding:6px 12px; text-decoration:none; border-radius:3px; font-size:11px; font-weight:bold; cursor:pointer; border:none; }
        .btn-danger { background:#b10000; color:#fff; }
        .export-confirm { background:#1a1a1a; border:1px solid #444; padding:15px; margin:15px; display:none; }
        table { width:100%; border-collapse:collapse; }
        th { background:#252525; padding:12px; text-align:left; border-bottom:2px solid #333; font-size:11px; color:#888; text-transform:uppercase; }
        td { padding:10px; border-bottom:1px solid #222; vertical-align:top; }
        tr:hover { background:#222; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <span>501st NZ &mdash; Census (Cycle: <?php echo htmlspecialchars($display_cycle, ENT_QUOTES, 'UTF-8'); ?>)</span>
        <button class="btn" onclick="document.getElementById('export-box').style.display='block'; this.style.display='none';">
            Download CSV
        </button>
    </div>

    <!-- Export confirmation step to prevent accidental PII disclosure -->
    <div class="export-confirm" id="export-box">
        <strong style="color:#f39c12;">&#9888; This export includes real names and email addresses.</strong>
        <p style="margin:8px 0; font-size:0.9em; color:#aaa;">Only download if you need it for official garrison use. Do not share externally.</p>
        <form method="post">
            <input type="hidden" name="my_post_key" value="<?php echo $mybb->post_code; ?>"/>
            <input type="hidden" name="export_confirm" value="1"/>
            <button type="submit" class="btn btn-danger">Yes, Download CSV</button>
            <button type="button" class="btn" style="margin-left:8px;"
                onclick="document.getElementById('export-box').style.display='none';">
                Cancel
            </button>
        </form>
    </div>

    <table>
        <thead>
            <tr>
                <th style="text-align:center; width:50px;">Rank</th>
                <th>Trooper</th>
                <th>Real Name</th>
                <th>TKID</th>
                <th style="text-align:center; color:#fff;">Cycle Total</th>
                <th style="text-align:center;">All Time</th>
                <th>Latest Troop</th>
            </tr>
        </thead>
        <tbody>
            <?php echo $rows; ?>
        </tbody>
    </table>
</div>
</body>
</html>