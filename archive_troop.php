<?php
/**
 * 501st NZ - Archive Troop Processing Script 
 */

define("IN_MYBB", 1);
require_once "./global.php";

// 1. Get Inputs
$tid = $mybb->get_input('tid', MyBB::INPUT_INT);
$post_key = $mybb->get_input('my_post_key');

// 2. Security Check (Post Key & User Login)
if(!verify_post_check($post_key, true) || (int)$mybb->user['uid'] === 0) {
    die("Security check failed. Please go back and refresh.");
}

// 3. Move and Close using direct DB query
if($tid > 0) {
    $db->update_query("threads", array(
        "closed" => 1, 
        "fid" => 16
    ), "tid='{$tid}'");
    
    // Also move the posts associated with this thread
    $db->update_query("posts", array("fid" => 16), "tid='{$tid}'");
}

// 4. Force Redirect using Meta Tag
echo "<html><head><meta http-equiv='refresh' content='0;url=form.php?formid=1'></head><body>Redirecting to report form...</body></html>";
exit;