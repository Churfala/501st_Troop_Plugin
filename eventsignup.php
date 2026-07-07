<?php
/**
 * Troop Roster (POC Ownership & Clean Link)
 * Location: inc/plugins/eventsignup.php
 */

if(!defined("IN_MYBB")) die("Direct initialization of this file is not allowed.");

// --- CONFIGURATION CONSTANTS ---
// Edit these instead of touching plugin logic
define('EVENTSIGNUP_ALLOWED_FIDS', array(44, 82));  // Forums where roster appears
define('EVENTSIGNUP_ARCHIVE_FID', 16);               // Forum where archived troops live
define('EVENTSIGNUP_STAR_IMG', 'images/star.png');   // Path to star image

$plugins->add_hook("postbit", "eventsignup_postbit");
$plugins->add_hook("postbit", "eventsignup_postbit_stars");

function eventsignup_info() {
    return array(
        "name"          => "Troop Roster (Clean Version)",
        "description"   => "Roster with POC handoff and ICS calendar download. Archiving is handled by archive_troop.php.",
        "author"        => "Brendan - ST84218",
        "version"       => "12.5",
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

function eventsignup_is_installed() {
    global $db;
    return $db->table_exists("signup_sheets");
}

function eventsignup_uninstall() {
    global $db;
    if($db->table_exists("signup_sheets")) {
        $db->drop_table("signup_sheets");
    }
}

/**
 * Extract event date/time/location from the mybb_bridge's first-post text.
 * The bridge always writes plain "Label: value" lines (see outpost42-mybb-bridge.php).
 */
function eventsignup_parse_event_meta($message) {
    $text = preg_replace('/<br\s*\/?>/i', "\n", $message);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

    $get = function($label) use ($text) {
        if(preg_match('/^\s*' . preg_quote($label, '/') . ':\s*([^\r\n]+)/im', $text, $m)) {
            return trim($m[1]);
        }
        return '';
    };

    $event_name = $get('Event Name');
    $event_date = $get('Date');
    $start_time = $get('Start Time');
    $end_time   = $get('End Time');
    $venue      = $get('Venue Address');
    $city       = $get('City');

    if($event_date === '') return false;

    // Slash dates are day-first here (NZ), but strtotime() reads them as
    // US month-first — rewrite d/m/y to ISO before parsing.
    if(preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2,4})$#', $event_date, $m)) {
        $year = (strlen($m[3]) === 2) ? '20' . $m[3] : $m[3];
        $event_date = sprintf('%s-%02d-%02d', $year, $m[2], $m[1]);
    }

    $date_ts = strtotime($event_date);
    if($date_ts === false) return false;

    // Times like ": " (empty form fields) won't parse — fall back to all-day
    $start_ts = strtotime(trim($event_date . ' ' . $start_time));
    if(!preg_match('/\d/', $start_time) || $start_ts === false) {
        return array(
            'name'     => ($event_name !== '') ? $event_name : 'Troop Event',
            'allday'   => true,
            'start'    => $date_ts,
            'end'      => $date_ts,
            'location' => trim($venue . ($city !== '' ? ', ' . $city : ''), ', '),
        );
    }

    $end_ts = ($end_time !== '') ? strtotime(trim($event_date . ' ' . $end_time)) : false;
    if($end_ts === false || $end_ts <= $start_ts) {
        $end_ts = $start_ts + (2 * 3600); // default 2-hour block
    }

    return array(
        'name'     => ($event_name !== '') ? $event_name : 'Troop Event',
        'allday'   => false,
        'start'    => $start_ts,
        'end'      => $end_ts,
        'location' => trim($venue . ($city !== '' ? ', ' . $city : ''), ', '),
    );
}

/**
 * Escape a string per RFC5545 TEXT value rules.
 */
function eventsignup_ics_escape($str) {
    $str = str_replace(array("\\", ",", ";"), array("\\\\", "\\,", "\\;"), $str);
    $str = preg_replace('/\r\n|\r|\n/', '\\n', $str);
    return $str;
}

/**
 * Build an RFC5545 .ics file. Times are emitted as floating local time
 * (no Z / TZID) since the garrison operates in a single timezone.
 */
function eventsignup_build_ics($event, $tid) {
    $lines = array(
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//501st NZ//Troop Roster//EN',
        'CALSCALE:GREGORIAN',
        'BEGIN:VEVENT',
        'UID:troop-' . (int)$tid . '@501st.nz',
        'DTSTAMP:' . gmdate('Ymd\THis\Z'),
    );

    if(!empty($event['allday'])) {
        // All-day events use date-only values; DTEND is exclusive (next day)
        $lines[] = 'DTSTART;VALUE=DATE:' . date('Ymd', $event['start']);
        $lines[] = 'DTEND;VALUE=DATE:' . date('Ymd', $event['end'] + 86400);
    } else {
        $lines[] = 'DTSTART:' . date('Ymd\THis', $event['start']);
        $lines[] = 'DTEND:' . date('Ymd\THis', $event['end']);
    }

    $lines[] = 'SUMMARY:' . eventsignup_ics_escape($event['name']);

    if($event['location'] !== '') {
        $lines[] = 'LOCATION:' . eventsignup_ics_escape($event['location']);
    }

    $lines[] = 'DESCRIPTION:' . eventsignup_ics_escape('501st NZ Troop Event - see the forum thread for the roster and details.');
    $lines[] = 'END:VEVENT';
    $lines[] = 'END:VCALENDAR';

    return implode("\r\n", $lines);
}

function eventsignup_postbit(&$post) {
    global $mybb, $db, $thread, $attendance_roster;

    if($post['pid'] != $thread['firstpost']) return;
    if(!in_array((int)$thread['fid'], EVENTSIGNUP_ALLOWED_FIDS)) return;

    $tid = (int)$thread['tid'];
    $uid = (int)$mybb->user['uid'];

    // --- HANDLE SIGNUPS & POC SWAP ---
    if($mybb->request_method == "post" && !isset($mybb->input['archive_submit'])) {
        verify_post_check($mybb->get_input('my_post_key'));

        if($uid > 0) {
            if(isset($mybb->input['signup_submit'])) {
                $info1 = trim($mybb->get_input('s_info1'));
                $info2 = trim($mybb->get_input('s_info2'));

                $is_poc = (int)$mybb->get_input('s_poc');

                // If claiming POC, clear any existing POC flags for this thread first
                if($is_poc == 1) {
                    $db->update_query("signup_sheets", array('checked' => 0), "tid='" . $db->escape_string($tid) . "'");
                }

                $db->insert_query("signup_sheets", array(
                    'tid'    => $tid,
                    'uid'    => $uid,
                    'info_1' => $db->escape_string($info1),
                    'info_2' => $db->escape_string($info2),
                    'checked' => $is_poc
                ));

                if($is_poc == 1) {
                    $username = $db->escape_string($mybb->user['username']);
                    $db->update_query("threads", array("uid" => $uid, "username" => $username), "tid='" . $db->escape_string($tid) . "'");
                    $db->update_query("posts", array("uid" => $uid, "username" => $username), "pid='" . (int)$thread['firstpost'] . "'");
                }

                redirect("showthread.php?tid=$tid", "Roster updated.");

            } elseif(isset($mybb->input['withdraw_submit'])) {
                $db->delete_query("signup_sheets", "tid='" . $db->escape_string($tid) . "' AND uid='" . $db->escape_string($uid) . "'");
                redirect("showthread.php?tid=$tid", "Withdrawn.");
            }
        }
    }

    // --- FETCH ROSTER ---
    $query = $db->query("
        SELECT s.*, u.username, u.usergroup, u.displaygroup 
        FROM " . TABLE_PREFIX . "signup_sheets s 
        LEFT JOIN " . TABLE_PREFIX . "users u ON u.uid = s.uid 
        WHERE s.tid = '" . $db->escape_string($tid) . "'
        ORDER BY s.checked DESC, u.username ASC
    ");

    $rows       = "";
    $count      = 0;
    $is_signed  = false;
    $is_poc     = false;

    while($user = $db->fetch_array($query)) {
        $count++;
        if((int)$user['uid'] === $uid) {
            $is_signed = true;
            if((int)$user['checked'] === 1) {
                $is_poc = true;
            }
        }

        $formatted_name = format_name($user['username'], $user['usergroup'], $user['displaygroup']);
        $poc_label = ($user['checked'])
            ? "<span style='color:#f39c12; font-weight:bold;'>YES</span>"
            : "<span style='color:#666;'>-</span>";

        $rows .= "
        <tr>
            <td class='trow1' style='padding:8px; border-bottom:1px solid #333;'>{$formatted_name}</td>
            <td class='trow1' style='padding:8px; border-bottom:1px solid #333;'>" . htmlspecialchars_uni($user['info_1']) . "</td>
            <td class='trow1' style='padding:8px; border-bottom:1px solid #333;'>" . nl2br(htmlspecialchars_uni($user['info_2'])) . "</td>
            <td class='trow1' style='padding:8px; border-bottom:1px solid #333;' align='center'>{$poc_label}</td>
        </tr>";
    }

    // --- INTERACTION BLOCK ---
    $interaction = "";

    if($uid > 0) {
        if($is_signed) {
            $interaction = "
            <div style='margin-top:10px;'>
                <form method='post' style='display:inline;'>
                    <input type='hidden' name='my_post_key' value='{$mybb->post_code}'/>
                    <input type='submit' name='withdraw_submit' value='Withdraw' class='button'
                        style='background:#441111; color:#fff; border:1px solid #662222; cursor:pointer;'/>
                </form>";

            // Archive button is only shown to the POC
            if($is_poc) {
                $interaction .= "
                <a href='archive_troop.php?tid={$tid}&my_post_key={$mybb->post_code}'
                    class='button'
                    style='margin-left:10px; background:#b10000; color:#fff; border:1px solid #800;
                           padding:6px 12px; text-decoration:none; font-size:12px;
                           border-radius:3px; font-weight:bold; display:inline-block;'
                    onclick='return confirm(\"Archive this troop and go to form?\");'>
                    Complete Troop &amp; File Report
                </a>";
            }

            $interaction .= "</div>";

        } else {
            $interaction = "
            <div style='margin-top:15px;'>
                <button type='button' class='button'
                    onclick=\"document.getElementById('signup_box').style.display='block'; this.style.display='none';\">
                    + I'm Attending
                </button>
                <form method='post' id='signup_box'
                    style='display:none; margin-top:10px; padding:15px; border:1px solid #444; background:#111;'>
                    <input type='hidden' name='my_post_key' value='{$mybb->post_code}'/>
                    <div style='margin-bottom:10px;'>
                        <input type='text' name='s_info1' placeholder='Costume'
                            class='textbox' style='width:95%;' />
                    </div>
                    <div style='margin-bottom:10px;'>
                        <textarea name='s_info2' placeholder='Notes'
                            class='textbox' style='width:95%; height:60px;'></textarea>
                    </div>
                    <div style='margin-bottom:10px; color:#ccc;'>
                        <label><input type='checkbox' name='s_poc' value='1' /> I am the POC</label>
                    </div>
                    <input type='submit' name='signup_submit' value='Confirm' class='button' />
                </form>
            </div>";
        }
    }

    // --- CALENDAR DOWNLOAD ---
    $ics_button = "";
    $event_meta = eventsignup_parse_event_meta($post['message']);
    if($event_meta !== false) {
        $ics_content  = eventsignup_build_ics($event_meta, $tid);
        $ics_filename = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $event_meta['name']) . '.ics';
        $ics_button   = "<a href='data:text/calendar;charset=utf-8;base64," . base64_encode($ics_content) . "'
            download='{$ics_filename}' class='button'
            title='Downloads a calendar file that opens in Google, Apple or Outlook calendars'
            style='background:#2a6f2a; color:#fff; border:1px solid #1f4f1f;
                   padding:6px 12px; text-decoration:none; font-size:12px;
                   border-radius:3px; font-weight:bold; display:inline-block;'>
            &#128197; Add to Calendar
        </a>";
    }

    // --- RENDER ---
    $empty_row = "<tr><td colspan='4' class='trow1' align='center'>No signups yet.</td></tr>";

    $roster_html = "
    <div class='tborder' style='margin-top:30px; border:1px solid #444; background:#222;'>
        <div class='thead' style='padding:10px; display:flex; justify-content:space-between; align-items:center;'>
            <strong>Troop Roster (Total: {$count})</strong>
            {$ics_button}
        </div>
        <table width='100%' cellspacing='0'>
            <tr class='tcat'>
                <td style='padding:8px; width:20%;'>Member</td>
                <td style='padding:8px; width:25%;'>Costumes</td>
                <td style='padding:8px;'>Notes</td>
                <td style='padding:8px; width:10%;' align='center'>POC</td>
            </tr>
            " . ($rows ?: $empty_row) . "
        </table>
    </div>
    {$interaction}";

    $post['message'] .= $roster_html;
}

/**
 * Postbit Display - Visual Service Stars
 */
function eventsignup_postbit_stars(&$post) {
    global $db;

    $archive_fid = (int)EVENTSIGNUP_ARCHIVE_FID;
    $uid         = (int)$post['uid'];

    if($uid <= 0) return;

    $query = $db->query("
        SELECT COUNT(t.tid) as total 
        FROM " . TABLE_PREFIX . "signup_sheets s
        INNER JOIN " . TABLE_PREFIX . "threads t ON (t.tid = s.tid)
        WHERE s.uid = '{$uid}' AND t.fid = '{$archive_fid}'
    ");

    $count = (int)$db->fetch_field($query, "total");

    if($count === 0) return;

    $star_img   = EVENTSIGNUP_STAR_IMG;
    $stars_html = "";

    if($count >= 100) {
        $num_stars  = 5;
        $star_style = "filter: hue-rotate(290deg) drop-shadow(0 0 2px red);";
    } elseif($count >= 50) {
        $num_stars  = 4;
        $star_style = "filter: sepia(1) saturate(5) hue-rotate(10deg);";
    } elseif($count >= 25) {
        $num_stars  = 3;
        $star_style = "filter: grayscale(1) brightness(1.2);";
    } elseif($count >= 10) {
        $num_stars  = 2;
        $star_style = "";
    } else {
        $num_stars  = 1;
        $star_style = "opacity: 0.8;";
    }

    for($i = 0; $i < $num_stars; $i++) {
        $stars_html .= "<img src='{$star_img}' style='vertical-align:middle; width:12px; margin-right:1px; {$star_style}' />";
    }

    $post['user_details'] .= "<br /><strong>Service:</strong> <span title='Total archived deployments: {$count}' style='cursor:help;'>{$stars_html}</span>";
}
