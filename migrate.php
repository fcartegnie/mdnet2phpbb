<?php
/* ====================================================================== 
        MesDiscussions.net to phpBB forum conversion script
	Tested versions:
	- phpBB 3.0.8
	- Forum MesDiscussions.Net 2009.1.2

   ====================================================================== */

/*
	Databases configuration

*/

$md_db = new mysqli("", "user", "pass", "db", null, "/var/lib/mysql/mysql.sock");
if ($md_db->errno) die("MD : " . $md_db->connect_error);

$bb_db = new mysqli("", "user", "pass", "db", null, "/var/lib/mysql/mysql.sock");
if ($bb_db->errno) die("BB : " . $bb_db->connect_error);
$bb_db->autocommit(false);

define("BBPREFIX", ""); /* phpBB tables prefix */
define("MDPREFIX", ""); /* MD tables prefix */
define("USERIDBASE", 0); /* Use this to shift up UIDs if there's existing users */

/*
See http://phpbb.cvs.sourceforge.net/viewvc/phpbb/phpBB2/develop/adjust_bbcodes.php?view=markup
to fix BBCodes after import
*/

$groupid_translation = array(
	1 => 2, /* registered users */
	2 => 2,
	3 => 3, /* moderators */
	4 => 3,
	5 => 5 /* admins */
);

$authroleid_translation = array(
	1 => 6,
	2 => 6,
	3 => 6,
	4 => 6,
	5 => 5
);

function usertype_translation($ban, $confirmed)
{
	if ($ban)
		return array('type' => 1, 'reason' => 3); /* inactive & deactivated */
	if (!$confirmed)
		return array('type' => 1, 'reason' => 4); /* inactive & unconfirmed */
	return array('type' => 0, 'reason' => 0); /* normal active */
}

/* ====================================================================== */

/* create our url translation table */

$sql = "CREATE TABLE IF NOT EXISTS `md_to_bb_url` (
  `type` enum('forum','topic') NOT NULL,
  `md_id` int(10) unsigned NOT NULL,
  `bb_id` int(10) unsigned NOT NULL,
  `url` varchar(255) NOT NULL
);";

if ( !$bb_db->query($sql) ) { $bb_db->rollback(); die( $bb_db->error . $sql ); }



function log_url_translation(&$db, $type, $md_id, $bb_id, $url)
{
	// populate our url tranlation support table
	$sql = sprintf("INSERT INTO md_to_bb_url
					 ( type, md_id, bb_id, url )
			VALUES ( '%s', %d, %d, '%s')",
			$type,
			$md_id,
			$bb_id,
			$db->escape_string($url)
	);
	if ( !$db->query($sql) ) { echo $db->error . $sql ; }
}

/*
	Commands (comment for debugging purposes)

*/

$commands['migrate_users'] = 1;
$commands['migrate_forums'] = 1;
$commands['migrate_topics'] = 1;

/*
	Users Migration

*/

if ( isset($commands['migrate_users']) )
{
	printf("migrating users...\n");
	$result = $md_db->query("SELECT * FROM inscrit");

	if ($result)
	{
		while ($row = $result->fetch_assoc())
		{
				$usertype = usertype_translation($row['ban'], $row['valinscr']);
				$sql = sprintf("INSERT INTO ".BBPREFIX."users
								 ( user_id, user_type, user_inactive_reason, group_id, user_regdate, username, username_clean, user_email, user_email_hash, user_pass_convert, user_password, user_passchg, user_sig, user_new, user_birthday, user_from, user_website, user_lang )
						VALUES ( '%d', %d,  %d, %d, %d, '%s', '%s', '%s', '%s', %d, '%s', %d, '%s', %d, '%s', '%s', '%s', '%s' )",
						USERIDBASE + $row['id'],
						$usertype['type'],
						$usertype['reason'],
						$groupid_translation[$row['group_id']],
						1303846975,
						$bb_db->escape_string($row['pseudo']),
						$bb_db->escape_string(strtolower($row['pseudo'])),
						$bb_db->escape_string($row['email']),
						$bb_db->escape_string(crc32($row['email'])),
						1,
						$bb_db->escape_string($row['password']),
						0,
						$bb_db->escape_string($row['signature_forum']),
						0,
						$row['birthday'],
						$bb_db->escape_string($row['ville']),
						$bb_db->escape_string($row['homepage']),
						'fr'
				);

				if ( !$bb_db->query($sql) )
				{
					if ( !$bb_db->query($sql) ) { $bb_db->rollback(); die( $bb_db->error . $sql ); }
				} else {
					$sql = sprintf("INSERT INTO ".BBPREFIX."user_group
									 ( group_id, user_id, user_pending )
							VALUES ( %d, %d, 0 )",
							$groupid_translation[$row['group_id']],
							USERIDBASE + $row['id']
					);
					if ( !$bb_db->query($sql) ) { $bb_db->rollback(); die( $bb_db->error . $sql ); }
				}
		}

		$result->close();

	} else {
		die( $md_db->error . $sql );
	}
}

/*
	Forums Migration

*/

if (isset($commands['migrate_forums']))
{
	printf("migrating forums...\n");

	/* retrieve all forums id */
	$result = $md_db->query("SELECT nom_supercat, nom, numero, description, url_nom, ordre FROM forumcat".MDPREFIX." ORDER BY ordre ASC");
	if ($result)
	{
		while ($row = $result->fetch_assoc())
		{
			$mdforums[$row['ordre']] = $row;
		}
		$result->close();
	} else {
		die( $md_db->error . $sql );
	}

	foreach($mdforums as $forum)
	{
		if ( !empty($forum['nom_supercat']) )
		{	/* root pre-category label*/
			$sql = sprintf("INSERT INTO ".BBPREFIX."forums
							 ( parent_id, forum_name, forum_desc, forum_type )
					VALUES ( 0, '%s', '%s', 0)",
					$bb_db->escape_string($forum['nom_supercat']),
					$bb_db->escape_string($forum['nom_supercat'])
			);

			if ( !$bb_db->query($sql) ) { $bb_db->rollback(); die( $bb_db->error . $sql ); }
			$bbcurrent_categ_id = $bb_db->insert_id;
			$bbcategs[$bbcurrent_categ_id] = $forum;

			// update left / right
			// left = previous cat right + 1
			// right = previous cat right + 1 + 1 (because empty)
			$sql = "SET @leftid = (SELECT MAX(right_id) FROM ".BBPREFIX."forums)";
			if ( !$bb_db->query($sql) ) { $bb_db->rollback(); die( $bb_db->error . $sql ); }
			$sql =  sprintf("UPDATE ".BBPREFIX."forums SET left_id = @leftid + 2, right_id = @leftid + 3 WHERE forum_id = %d",
							$bbcurrent_categ_id);
			if ( !$bb_db->query($sql) ) { $bb_db->rollback(); die( $bb_db->error . $sql ); }
		}

		if ( empty( $bbcurrent_categ_id ) ) die('no root label for first entry of forum '.$forum['forum_id']);

		$serialdata = serialize( array( 1 => array( 0 => $forum['nom'], 1 => 0 ) ) );

		$sql = sprintf("INSERT INTO ".BBPREFIX."forums
						 ( parent_id, forum_name, forum_desc, forum_type, forum_parents )
				VALUES ( %d, '%s', '%s', 1, '%s')",
				$bbcurrent_categ_id,
				$bb_db->escape_string($forum['nom']),
				$bb_db->escape_string($forum['description']),
				$bb_db->escape_string($serialdata)
		);

		if ( !$bb_db->query($sql) ) { $bb_db->rollback(); die( $bb_db->error . $sql ); }
		$bbforums[$bb_db->insert_id] = $forum;
		$mdcat_translation[$forum['numero']] = $bb_db->insert_id;
		$current_forum = $bb_db->insert_id;

		// update right/left
		// left = parent's left + 1
		// right = left + 1
		$sql = sprintf("SET @leftid = (SELECT right_id FROM ".BBPREFIX."forums WHERE forum_id = %d)", $bbcurrent_categ_id);
		if ( !$bb_db->query($sql) ) { $bb_db->rollback(); die( $bb_db->error . $sql ); }
		$sql =  sprintf("UPDATE ".BBPREFIX."forums SET left_id=@leftid, right_id=@leftid+1 WHERE forum_id = %d ", $current_forum);
		if ( !$bb_db->query($sql) ) { $bb_db->rollback(); die( $bb_db->error . $sql ); }

		// update parent's right/left
		// right = max(child's right) + 1 == right + 2
		$sql =  sprintf("UPDATE ".BBPREFIX."forums SET right_id = right_id + 2 WHERE forum_id = %d ", $bbcurrent_categ_id);
		if ( !$bb_db->query($sql) ) { $bb_db->rollback(); die( $bb_db->error . $sql ); }

		log_url_translation($bb_db, 'forum', $forum['numero'], $bbcurrent_categ_id, $forum['url_nom']);
	}

	/* fix default rights and display forums */
	foreach( array_merge( array_keys($bbcategs), array_keys($bbforums) ) as $forumid)
	{
		$rolesrights = /* see acl_groups && acl_roles: group_id, forum_id, auth_option_id, auth_role_id, auth_setting */
		array("1, %d, 0, 17, 0",
 				"2, %d, 0, 21, 0",
				"3, %d, 0, 15, 0",
 				"4, %d, 0, 14, 0",
 				"5, %d, 0, 14, 0",
				"5, %d, 0, 10, 0",
 				"6, %d, 0, 19, 0",
 				"7, %d, 0, 21, 0"
		);

		foreach($rolesrights as $role)
		{		
			$sql = sprintf("INSERT INTO ".BBPREFIX."acl_groups
						 (group_id, forum_id, auth_option_id, auth_role_id, auth_setting)
				VALUES ( %s )",
				sprintf( $role, $forumid )
			);
			if ( !$bb_db->query($sql) ) { $bb_db->rollback(); die( $bb_db->error . $sql ); }
		}
	}

}

/*
	Topics and Posts Migration : the heavy job :)

*/

if (isset($commands['migrate_topics']) && isset($commands['migrate_forums']))
{
	printf("migrating topics...\n");

	foreach($mdforums as $forum)
	{
		$sql = sprintf("SELECT * FROM forumcont".MDPREFIX."%d ORDER BY numeropost ASC", $forum['numero']);
		$result = $md_db->query($sql);

		if ($result)
		{
			while ($row = $result->fetch_assoc())
			{
				$lasttopicdate = $row['sujetrelatif_lastupdate'];
				$sql = sprintf("INSERT INTO ".BBPREFIX."topics
					 			 (forum_id, topic_approved, topic_title, topic_first_poster_name, topic_last_poster_name)
						VALUES (%d, 1, '%s', '%s', '%s')",
						$mdcat_translation[$forum['numero']],
						$bb_db->escape_string($row['titre']),
						$bb_db->escape_string($row['auteur']),
						$bb_db->escape_string($row['lastauteur'])
				);
				if ( !$bb_db->query($sql) ) { $bb_db->rollback(); die( $bb_db->error . $sql ); }

				$mdtopics_translation[] = array('mdforum' => $forum['numero'], 'mdtopic' =>$row['numeropost'], 'bbtopic' => $bb_db->insert_id, 'subject' => $row['titre'], 'views' => $row['vue']);				

				log_url_translation($bb_db, 'topic', $row['numeropost'], $bb_db->insert_id, $row['rewrite_titre']);
			}

			$result->close();
		} else {
			// OR it is a topicless forum !
		}
	}

	printf("migrating topics threads...\n");

	foreach( $mdtopics_translation as $t )
	{
		$sql = sprintf("SELECT * FROM thread".MDPREFIX."%d WHERE numeropost = %d ORDER BY numreponse ASC", $t['mdforum'], $t['mdtopic']);
		$result = $md_db->query($sql);
		if ($result)
		{
			unset($row);unset($lastrow);
			$firstdone = false;
			$replies_count=0;
			$lastpostid = 0;

			while ($row = $result->fetch_assoc())
			{
				$replies_count++;

				$sql = sprintf("INSERT INTO ".BBPREFIX."posts
					 			 ( topic_id, forum_id, post_time, post_text, post_subject, poster_id )
						VALUES ( %d, %d, UNIX_TIMESTAMP('%s'), '%s', 'Re: %s', %d )",
						$t['bbtopic'],
						$mdcat_translation[$t['mdforum']],
						$bb_db->escape_string($row['date']),
						$bb_db->escape_string($row['contenu']),
						$bb_db->escape_string($t['subject']),
						USERIDBASE + $row['id']
				);
				if ( !$bb_db->query($sql) ) { $bb_db->rollback(); die( $bb_db->error . $sql ); }
				$lastpostid = $bb_db->insert_id;

				if (!$firstdone)
				{
					$firstdone = true;

					$sql = sprintf("UPDATE ".BBPREFIX."topics SET topic_poster=%d, topic_time=UNIX_TIMESTAMP('%s'), topic_views = %d WHERE topic_id = %d",
						USERIDBASE + $row['id'],
						$bb_db->escape_string($row['date']),
						$t['views'],
						$t['bbtopic']
					);
					if ( !$bb_db->query($sql) ) { $bb_db->rollback(); die( $bb_db->error . $sql ); }
				}
				$lastrow = $row;
			}

			if ($lastrow)
			{
				if ( !$bb_db->query($sql) ) die( $bb_db->error . $sql );
				$sql = sprintf("UPDATE ".BBPREFIX."topics JOIN ".BBPREFIX."users ON ( user_id = %d ) SET topic_replies = %d, topic_replies_real = %d, topic_last_poster_id = %d, topic_last_poster_name= username, topic_last_post_id = %d, topic_last_post_subject = 'Re: %s', topic_last_post_time = UNIX_TIMESTAMP('%s') WHERE topic_id = %d",
					USERIDBASE + $lastrow['id'],					
					$replies_count,
					$replies_count,
					USERIDBASE + $lastrow['id'],
					$lastpostid,
					$bb_db->escape_string($t['subject']),
					$bb_db->escape_string($lastrow['date']),
					$t['bbtopic']
				);
				if ( !$bb_db->query($sql) ) { $bb_db->rollback(); die( $bb_db->error . $sql ); }

				$sql = sprintf("UPDATE ".BBPREFIX."forums JOIN ".BBPREFIX."users ON ( user_id = %d ) SET forum_last_post_subject='Re: %s', forum_last_post_time=UNIX_TIMESTAMP('%s'), forum_last_poster_name = username, forum_last_post_id=%d, forum_last_poster_id=%d WHERE forum_id = %d",
					USERIDBASE + $lastrow['id'],
					$bb_db->escape_string($t['subject']),
					$bb_db->escape_string($lastrow['date']),
					$lastpostid,
					USERIDBASE + $lastrow['id'],
					$mdcat_translation[$t['mdforum']]
				);
				if ( !$bb_db->query($sql) ) { $bb_db->rollback(); die( $bb_db->error . $sql ); }
			}

			$result->close();
		} else echo $sql."<br>"; // OR it is a threadless topic !
	}

	unset($mdtopics_translation);

	printf("migrating topics and users stats...\n");
	/* fixme: could make this as 1 liner sql */
	$sql = "SELECT COUNT(*) AS nb, poster_id FROM ".BBPREFIX."posts GROUP BY poster_id";
	$result = $bb_db->query($sql);
	if ($result)
	{
		while ($row = $result->fetch_assoc())
		{
			$sql = sprintf("UPDATE ".BBPREFIX."users SET user_posts = %d WHERE user_id = %d", $row['nb'], $row['poster_id']);
			if ( !$bb_db->query($sql) ) { $bb_db->rollback(); die( $bb_db->error . $sql ); }
		}
		$result->close();
	} else {
		$bb_db->rollback(); die( $bb_db->error . $sql );
	}

	/* fixme: could make this as 1 liner sql */
	$sql = "SELECT COUNT(*) AS nbtopics, SUM(topic_replies) as nbposts, forum_id FROM ".BBPREFIX."topics GROUP BY forum_id";
	$result = $bb_db->query($sql);
	if ($result)
	{
		while ($row = $result->fetch_assoc()){
			$sql = sprintf("UPDATE ".BBPREFIX."forums SET forum_posts = %d, forum_topics = %d, forum_topics_real = %d WHERE forum_id = %d",
				$row['nbposts'],
				$row['nbtopics'],
				$row['nbtopics'],
				$row['forum_id']
			);
			if ( !$bb_db->query($sql) ) { $bb_db->rollback(); die( $bb_db->error . $sql ); }
		}
		$result->close();
	} else {
		$bb_db->rollback(); die( $bb_db->error . $sql );
	}
}

$bb_db->commit();
?>
