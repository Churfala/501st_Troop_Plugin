<?php
/**
 * 501st NZ - Census Master List (Annual Cycle + All-Time)
 */

define("IN_MYBB", 1);
require_once "./global.php";

// 1. Permissions
$allowed_groups = array(24); 
if(!($mybb->usergroup['cancp'] == 1 || $mybb->usergroup['issupermod'] == 1 || in_array($mybb->user['usergroup'], $allowed_groups))) die("Access Denied.");

// 2. Automatic Census Cycle Calculation
$current_month = (int)date('n');
$current_year = (int)date('Y');

if ($current_month >= 11) {
    $census_start_timestamp = mktime(0, 0, 0, 11, 1, $current_year);
    $display_cycle = $current_year . "-" . ($current_year + 1);
} else {
    $census_start_timestamp = mktime(0, 0, 0, 11, 1, $current_year - 1);
    $display_cycle = ($current_year - 1) . "-" . $current_year;
}

$target_groups = array(10, 20, 24);
$fids_list = "16"; 

$where_sql = "u.usergroup IN (" . implode(',', $target_groups) . ")";
foreach($target_groups as $gid) { $where_sql .= " OR FIND_IN_SET('$gid', u.additionalgroups)"; }

// --- HANDLE CSV EXPORT ---
if(isset($mybb->input['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=garrison_census_'.date('Y-m-d').'.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, array('Rank', 'Username', 'Real Name', 'TKID', 'Email', 'Current Cycle (Nov 1)', 'All Time Troops'));

    $query = $db->query("
        SELECT u.username, uf.fid6 as real_name, uf.fid4 as tkid, u.email,
               SUM(CASE WHEN t.dateline >= $census_start_timestamp THEN 1 ELSE 0 END) as cycle_total,
               COUNT(t.tid) as all_time_total
        FROM ".TABLE_PREFIX."users u
        LEFT JOIN ".TABLE_PREFIX."userfields uf ON (uf.ufid=u.uid)
        LEFT JOIN ".TABLE_PREFIX."signup_sheets s ON (s.uid=u.uid)
        LEFT JOIN ".TABLE_PREFIX."threads t ON (t.tid=s.tid AND t.fid IN ($fids_list))
        WHERE $where_sql
        GROUP BY u.uid
        ORDER BY cycle_total DESC, all_time_total DESC
    ");

    $i = 1;
    while($row = $db->fetch_array($query)) {
        fputcsv($output, array($i++, $row['username'], $row['real_name'], $row['tkid'], $row['email'], $row['cycle_total'], $row['all_time_total']));
    }
    fclose($output);
    exit;
}

// --- WEB VIEW QUERY ---
$query = $db->query("
    SELECT u.uid, u.username, u.usergroup, u.displaygroup, uf.fid6 as real_name, uf.fid4 as tkid, u.email,
           SUM(CASE WHEN t.dateline >= $census_start_timestamp THEN 1 ELSE 0 END) as cycle_total,
           COUNT(t.tid) as all_time_total,
           MAX(t.dateline) as last_date,
           (SELECT subject FROM ".TABLE_PREFIX."threads WHERE tid = MAX(t.tid) AND fid IN ($fids_list)) as last_subject
    FROM ".TABLE_PREFIX."users u
    LEFT JOIN ".TABLE_PREFIX."userfields uf ON (uf.ufid=u.uid)
    LEFT JOIN ".TABLE_PREFIX."signup_sheets s ON (s.uid=u.uid)
    LEFT JOIN ".TABLE_PREFIX."threads t ON (t.tid=s.tid AND t.fid IN ($fids_list))
    WHERE $where_sql
    GROUP BY u.uid
    ORDER BY cycle_total DESC, all_time_total DESC, u.username ASC
");

$rows = ""; $pos = 1;
while($user = $db->fetch_array($query)) {
    $formatted_name = format_name($user['username'], $user['usergroup'], $user['displaygroup']);
    $last_date = ($user['last_date']) ? my_date('jS M Y', $user['last_date']) : "-";
    
    $rows .= "<tr>
        <td style='text-align:center;'>$pos</td>
        <td><a href='member.php?action=profile&uid={$user['uid']}' style='text-decoration:none;'>{$formatted_name}</a></td>
        <td>" . htmlspecialchars($user['real_name']) . "</td>
        <td>" . htmlspecialchars($user['tkid']) . "</td>
        <td style='text-align:center; background: rgba(177, 0, 0, 0.1); font-size:1.1em;'><strong>{$user['cycle_total']}</strong></td>
        <td style='text-align:center; color:#aaa;'>{$user['all_time_total']}</td>
        <td style='font-size: 0.85em;'>" . htmlspecialchars($user['last_subject']) . "<br><small style='color:#777;'>$last_date</small></td>
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
        body { background: #0e0e0e; color: #cfcfcf; font-family: 'Segoe UI', sans-serif; padding: 20px; }
        .container { width: 100%; max-width: 1200px; margin: 0 auto; background: #1a1a1a; border: 1px solid #333; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        .header { background: #b10000; color: white; padding: 15px; font-weight: bold; display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #800; }
        .btn { background: #fff; color: #000; padding: 6px 12px; text-decoration: none; border-radius: 3px; font-size: 11px; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #252525; padding: 12px; text-align: left; border-bottom: 2px solid #333; font-size: 11px; color: #888; text-transform: uppercase; }
        td { padding: 10px; border-bottom: 1px solid #222; vertical-align: top; }
        tr:hover { background: #222; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <span>501st NZ - Census (Cycle: <?php echo $display_cycle; ?>)</span>
            <a href="?export=1" class="btn">Download CSV</a>
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