<?php
/**
 * Troop Roster (POC Ownership & Clean Link)
 * Location: inc/plugins/eventsignup.php
 */

if(!defined("IN_MYBB")) die("Direct initialization of this file is not allowed.");

$plugins->add_hook("postbit", "eventsignup_postbit");

function eventsignup_info() {
    return array(
        "name"          => "Troop Roster (Clean Version)",
        "description"   => "Roster with POC handoff. Archiving is handled by archive_troop.php.",
        "author"        => "Gemini",
        "version"       => "12.3",
        "codename"      => "eventsignup",
        "compatibility" => "18*"
    );
}

function eventsignup_install() {
    global $db;
    if(!$db->table_exists("signup_sheets")) {
        $db->write_query("CREATE TABLE " . TABLE_PREFIX . "signup_sheets (
            sid int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            tid int(10) UNSIGNED NOT NULL default '0',
            uid int(10) UNSIGNED NOT NULL default '0',
            info_1 varchar(255) NOT NULL default '',
            info_2 text NOT NULL,
            checked tinyint(1) NOT NULL default '0',
            PRIMARY KEY (sid)
        ) ENGINE=MyISAM;");
    }
}

function eventsignup_is_installed() { global $db; return $db->table_exists("signup_sheets"); }
function eventsignup_uninstall() {}

function eventsignup_postbit(&$post) {
    global $mybb, $db, $thread, $attendance_roster;

    if($post['pid'] != $thread['firstpost']) return;

    $allowed_fids = array(44, 82);
    if(!in_array($thread['fid'], $allowed_fids)) return;

    $tid = (int)$thread['tid'];
    $uid = (int)$mybb->user['uid'];

    // --- HANDLE SIGNUPS & POC SWAP ONLY ---
    if($mybb->request_method == "post" && !isset($mybb->input['archive_submit'])) {
        verify_post_check($mybb->get_input('my_post_key'));

        if($uid > 0) {
            if(isset($mybb->input['signup_submit'])) {
                $is_poc = (int)$mybb->get_input('s_poc');
                $db->insert_query("signup_sheets", array(
                    'tid' => $tid, 
                    'uid' => $uid, 
                    'info_1' => $db->escape_string($mybb->get_input('s_info1')), 
                    'info_2' => $db->escape_string($mybb->get_input('s_info2')),
                    'checked' => $is_poc
                ));

                if($is_poc == 1) {
                    $username = $db->escape_string($mybb->user['username']);
                    $db->update_query("threads", array("uid" => $uid, "username" => $username), "tid='$tid'");
                    $db->update_query("posts", array("uid" => $uid, "username" => $username), "pid='{$thread['firstpost']}'");
                }
                redirect("showthread.php?tid=$tid", "Roster updated.");
            } elseif(isset($mybb->input['withdraw_submit'])) {
                $db->delete_query("signup_sheets", "tid='$tid' AND uid='$uid'");
                redirect("showthread.php?tid=$tid", "Withdrawn.");
            }
        }
    }

    // --- VIEW GENERATION ---
    $query = $db->query("
        SELECT s.*, u.username, u.usergroup, u.displaygroup 
        FROM ".TABLE_PREFIX."signup_sheets s 
        LEFT JOIN ".TABLE_PREFIX."users u ON u.uid=s.uid 
        WHERE s.tid='$tid' 
        ORDER BY s.checked DESC, u.username ASC
    ");
    
    $rows = ""; $count = 0; $is_signed = false;
    while($user = $db->fetch_array($query)) {
        $count++;
        if($user['uid'] == $mybb->user['uid']) $is_signed = true;
        $formatted_name = format_name($user['username'], $user['usergroup'], $user['displaygroup']);
        $poc_label = ($user['checked']) ? "<span style='color:#f39c12; font-weight:bold;'>YES</span>" : "<span style='color:#666;'>-</span>";
        
        $rows .= "<tr>
            <td class='trow1' style='padding:8px; border-bottom:1px solid #333;'>$formatted_name</td>
            <td class='trow1' style='padding:8px; border-bottom:1px solid #333;'>".htmlspecialchars_uni($user['info_1'])."</td>
            <td class='trow1' style='padding:8px; border-bottom:1px solid #333;'>".nl2br(htmlspecialchars_uni($user['info_2']))."</td>
            <td class='trow1' style='padding:8px; border-bottom:1px solid #333;' align='center'>$poc_label</td>
        </tr>";
    }

    $interaction = "";
    if($mybb->user['uid']) {
        if($is_signed) {
            $interaction = "
            <div style='margin-top:10px;'>
                <form method='post' style='display:inline;'>
                    <input type='hidden' name='my_post_key' value='{$mybb->post_code}'/>
                    <input type='submit' name='withdraw_submit' value='Withdraw' class='button' style='background:#441111; color:#fff; border:1px solid #662222; cursor:pointer;'/>
                </form>
                <a href='archive_troop.php?tid={$tid}&my_post_key={$mybb->post_code}' class='button' style='margin-left:10px; background:#b10000; color:#fff; border:1px solid #800; padding:6px 12px; text-decoration:none; font-size:12px; border-radius:3px; font-weight:bold; display:inline-block;' onclick='return confirm(\"Archive this troop and go to form?\");'>Complete Troop & File Report</a>
            </div>";
        } else {
            // [Sign up form logic remains the same]
            $interaction = "<div style='margin-top:15px;'><button type='button' class='button' onclick=\"document.getElementById('signup_box').style.display='block'; this.style.display='none';\">+ I'm Attending</button><form method='post' id='signup_box' style='display:none; margin-top:10px; padding:15px; border:1px solid #444; background:#111;'><input type='hidden' name='my_post_key' value='{$mybb->post_code}'/><div style='margin-bottom:10px;'><input type='text' name='s_info1' placeholder='Costume' class='textbox' style='width:95%;' /></div><div style='margin-bottom:10px;'><textarea name='s_info2' placeholder='Notes' class='textbox' style='width:95%; height:60px;'></textarea></div><div style='margin-bottom:10px; color:#ccc;'><label><input type='checkbox' name='s_poc' value='1' /> I am the POC</label></div><input type='submit' name='signup_submit' value='Confirm' class='button' /></form></div>";
        }
    }

    $roster_html = "<div class='tborder' style='margin-top:30px; border:1px solid #444; background:#222;'><div class='thead' style='padding:10px;'><strong>Troop Roster (Total: $count)</strong></div><table width='100%' cellspacing='0'><tr class='tcat'><td style='padding:8px; width:20%;'>Member</td><td style='padding:8px; width:25%;'>Costumes</td><td style='padding:8px;'>Notes</td><td style='padding:8px; width:10%;' align='center'>POC</td></tr>".($rows ?: "<tr><td colspan='4' class='trow1' align='center'>No signups yet.</td></tr>")."</table></div>$interaction";

    $post['message'] .= $roster_html;
}

/**
 * Postbit Display - Visual Service Stars (Clean Version)
 */
$plugins->add_hook("postbit", "eventsignup_postbit_stars");

function eventsignup_postbit_stars(&$post) {
    global $db;

    $archive_fid = 16;
    $uid = (int)$post['uid'];

    if($uid > 0) {
        $query = $db->query("
            SELECT COUNT(t.tid) as total 
            FROM ".TABLE_PREFIX."signup_sheets s
            INNER JOIN ".TABLE_PREFIX."threads t ON (t.tid = s.tid)
            WHERE s.uid = '$uid' AND t.fid = '$archive_fid'
        ");
        
        $count = (int)$db->fetch_field($query, "total");

        // If they haven't trooped yet, we don't show the "Service" line at all
        // to keep the profile clean for recruits.
        if ($count == 0) return;

        $star_img = "images/star.png"; 
        $stars_html = "";

        // Define the visual brackets
        if ($count >= 100) {
            $num_stars = 5;
            $star_style = "filter: hue-rotate(290deg) drop-shadow(0 0 2px red);"; 
        } elseif ($count >= 50) {
            $num_stars = 4;
            $star_style = "filter: sepia(1) saturate(5) hue-rotate(10deg);"; 
        } elseif ($count >= 25) {
            $num_stars = 3;
            $star_style = "filter: grayscale(1) brightness(1.2);"; 
        } elseif ($count >= 10) {
            $num_stars = 2;
            $star_style = ""; 
        } else {
            $num_stars = 1;
            $star_style = "opacity: 0.8;"; 
        }

        for($i = 0; $i < $num_stars; $i++) {
            $stars_html .= "<img src='{$star_img}' style='vertical-align:middle; width:12px; margin-right:1px; {$star_style}' />";
        }

        // Output: Just the label and the icons. 
        // Hovering over the stars will show the count for leadership reference.
        $post['user_details'] .= "<br /><strong>Service:</strong> <span title='Total archived deployments: {$count}' style='cursor:help;'>{$stars_html}</span>";
    }
}