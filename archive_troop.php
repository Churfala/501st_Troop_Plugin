<?php
/**
 * 501st NZ - Archive Troop Processing Script
 */
define("IN_MYBB", 1);
require_once "./global.php";

// --- CONFIGURATION ---
define('ARCHIVE_TARGET_FID',  16);           // Forum to move archived troops into
define('ARCHIVE_ALLOWED_FIDS', array(44, 82)); // Forums troops can be archived FROM
define('ARCHIVE_REPORT_URL',  'form.php?formid=1'); // Redirect after archiving

// --- 1. INPUTS ---
$tid      = $mybb->get_input('tid', MyBB::INPUT_INT);
$post_key = $mybb->get_input('my_post_key');

// --- 2. SECURITY: Post Key & Login ---
if(!verify_post_check($post_key, true) || (int)$mybb->user['uid'] === 0) {
    error("Security check failed. Please <a href='javascript:history.back()'>go back</a> and try again.");
}

$uid = (int)$mybb->user['uid'];

// --- 3. VALIDATE THREAD EXISTS & IS IN AN ALLOWED FORUM ---
if($tid <= 0) {
    error("Invalid thread specified.");
}

$thread = get_thread($tid);

if(!$thread || !$thread['tid']) {
    error("That thread does not exist.");
}

if(!in_array((int)$thread['fid'], ARCHIVE_ALLOWED_FIDS)) {
    error("This thread is not eligible for archiving.");
}

if((int)$thread['closed'] === 1) {
    error("This thread has already been archived.");
}

// --- 4. VERIFY CALLER IS THE POC FOR THIS THREAD ---
$poc_query = $db->simple_select(
    "signup_sheets",
    "sid",
    "tid='" . $db->escape_string($tid) . "' AND uid='" . $db->escape_string($uid) . "' AND checked='1'",
    array("limit" => 1)
);

if($db->num_rows($poc_query) === 0) {
    error("You are not the POC for this troop and cannot archive it.");
}

// --- 5. ARCHIVE: MOVE & CLOSE ---
$db->update_query("threads",
    array("closed" => 1, "fid" => ARCHIVE_TARGET_FID),
    "tid='" . $db->escape_string($tid) . "'"
);

$db->update_query("posts",
    array("fid" => ARCHIVE_TARGET_FID),
    "tid='" . $db->escape_string($tid) . "'"
);

// --- 6. REDIRECT TO REPORT FORM ---
header("Location: " . ARCHIVE_REPORT_URL);
exit;