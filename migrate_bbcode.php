<?php

define('IN_PHPBB', 1);

$phpEx = substr(strrchr(__FILE__, '.'), 1);
include($phpbb_root_path . 'common.'.$phpEx);
include($phpbb_root_path . 'includes/message_parser.' . $phpEx); 

$buffer = 5000;
$read = 1;
$offset = 0;

$patterns = array("/\[\/quotemsg\]/", "/\[quotemsg=[0-9,]+\]/");
$replacements = array("[/quote]", "[quote]");

while( $read > 0 )
{
	$read = 0;
	$rows = array();

	$sql = "SELECT post_text, post_id FROM ".$table_prefix."posts LIMIT ".$offset.",".$buffer;
	$result = $db->sql_query($sql);
	while ($row = $db->sql_fetchrow($result)) 
	{
		$rows[$read++] = $row;
	}
	$db->sql_freeresult($result);
	$offset += $read;

	foreach( $rows as $row )
	{

		$uid = $bitfield = $options = ''; // will be modified by generate_text_for_storage
		$allow_bbcode = $allow_urls = $allow_smilies = true;
		$text = preg_replace( $patterns, $replacements, $row['post_text']);

		generate_text_for_storage($text, $uid, $bitfield, $options, $allow_bbcode, $allow_urls, $allow_smilies);

		$sql = sprintf("UPDATE posts SET post_text = '%s', bbcode_uid='%s', bbcode_bitfield='%s' WHERE post_id = %d",
			$db->sql_escape($text),
			$db->sql_escape($uid),
			$db->sql_escape($bitfield),
			$row['post_id']
		);
		$db->sql_query($sql);
	}
	echo ".";
}

?>
