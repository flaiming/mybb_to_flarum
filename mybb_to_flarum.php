<?php
    require "Config.php";

    $flarum_db = new mysqli(Config::$FLARUM_SERVER, Config::$FLARUM_USER, Config::$FLARUM_PASSWORD, Config::$FLARUM_DB);
    $mybb_db = new mysqli(Config::$MYBB_SERVER, Config::$MYBB_USER, Config::$MYBB_PASSWORD, Config::$MYBB_DB);

    if($flarum_db->connect_errno)
        die("Flarum db connection failed: ". $flarum_db->connect_error);
    else if($mybb_db->connect_errno)
        die("MyBB db connection failed: ". $mybb_db->connect_error);

    echo "<p>Connection successful.</p>";

    echo "<p>Migrating users ...";

    $users = $mybb_db->query("SELECT uid, username, email, postnum, threadnum, FROM_UNIXTIME( regdate ) AS regdate, FROM_UNIXTIME( lastvisit ) AS lastvisit FROM  ".Config::$MYBB_PREFIX."users ");
    if($users->num_rows > 0)
    {
        $flarum_db->query("TRUNCATE TABLE ".Config::$FLARUM_PREFIX."users");

        while($row = $users->fetch_assoc())
        {
            $password = password_hash(time(),PASSWORD_BCRYPT );
            $result = $flarum_db->query("INSERT INTO ".Config::$FLARUM_PREFIX."users (id, username, email, is_activated, password, join_time, last_seen_time, discussions_count, comments_count) VALUES ({$row["uid"]},'{$row["username"]}', '{$row["email"]}', 1, '$password', '{$row["regdate"]}', '{$row["lastvisit"]}', {$row["threadnum"]}, {$row["postnum"]})");
            if($result === false)
                echo "Error executing query: ". $flarum_db->error. "<br/>";
        }
    }
    echo " done: migrated ".$users->num_rows." users.</p>";
    echo "<p>Migrating categories to tags and forums to sub-tags ...";

    //categories
    $categories = $mybb_db->query("SELECT fid, name, description FROM ".Config::$MYBB_PREFIX."forums WHERE type = 'c'");
    if($categories->num_rows > 0)
    {
        $flarum_db->query("TRUNCATE TABLE ".Config::$FLARUM_PREFIX."tags");

        $c_pos = 0;
        while($crow = $categories->fetch_assoc())
        {
            $slug = str_replace(" ", "-", strtolower($crow["name"]));
            $result = $flarum_db->query("INSERT INTO ".Config::$FLARUM_PREFIX."tags (id, name, slug, description, position) VALUES ({$crow["fid"]},'{$crow["name"]}', '$slug', '{$crow["description"]}', $c_pos)");
            if($result === false)
                echo "Error executing query: ".$flarum_db->error."<br />";
            else
            {
                //subforums
                $forums = $mybb_db->query("SELECT * FROM ".Config::$MYBB_PREFIX."forums WHERE type = 'f' AND pid = {$crow["fid"]}");
                if($forums->num_rows > 0)
                {
                    $f_pos = 0;
                    while($srow = $forums->fetch_assoc())
                    {
                        $slug = str_replace(" ", "-", strtolower($srow["name"]));
                        $flarum_db->query("INSERT INTO ".Config::$FLARUM_PREFIX."tags (id, name, slug, description, parent_id, position) VALUES ({$srow["fid"]},'{$srow["name"]}', '$slug', '{$srow["description"]}', {$crow["fid"]}, $f_pos)");

                        $f_pos++;
                    }
                }
            }
            $c_pos++;
        }
    }
    echo " done: migrated ".$categories->num_rows." categories and their forums";

    echo "<p>Migrating threads and thread posts...";

    $threads = $mybb_db->query("SELECT tid, fid, subject, replies, FROM_UNIXTIME(dateline) as dateline, uid, firstpost, FROM_UNIXTIME(lastpost) as lastpost, lastposteruid, closed, sticky FROM ".Config::$MYBB_PREFIX."threads");
    if($threads->num_rows > 0)
    {
        $flarum_db->query("TRUNCATE TABLE ".Config::$FLARUM_PREFIX."discussions");
        $flarum_db->query("TRUNCATE TABLE ".Config::$FLARUM_PREFIX."discussions_tags");
        $flarum_db->query("TRUNCATE TABLE ".Config::$FLARUM_PREFIX."posts");

        while($trow = $threads->fetch_assoc())
        {
            $slug = str_replace(" ", "-", strtolower($trow["subject"]));
            $result = $flarum_db->query("INSERT INTO ".Config::$FLARUM_PREFIX."discussions (id, title, comments_count, start_time, start_user_id, start_post_id, last_time, last_user_id, slug, is_approved, is_locked, is_sticky) 
            VALUES ({$trow["tid"]}, '{$flarum_db->real_escape_string($trow["subject"])}', {$trow["replies"]}, '{$trow["dateline"]}', {$trow["uid"]}, {$trow["firstpost"]}, '{$trow["lastpost"]}', {$trow["lastposteruid"]}, '{$flarum_db->real_escape_string($slug)}', 1, ".(empty($trow["closed"]) ? "0" : $trow["closed"]).", {$trow["sticky"]})");

            if($result === false)
                echo "Error executing query: ".$flarum_db->error."<br/>";
            else
            {
                $flarum_db->query("INSERT INTO ".Config::$FLARUM_PREFIX."discussions_tags (discussion_id, tag_id) VALUES ({$trow["tid"]}, {$trow["fid"]})");

                //posts/replies/topics
                $posts = $mybb_db->query("SELECT pid, tid, FROM_UNIXTIME(dateline) as dateline, uid, message FROM ".Config::$MYBB_PREFIX."posts WHERE tid = {$trow["tid"]}");
                if($posts->num_rows > 0)
                {
                    while($row = $posts->fetch_assoc())
                    {
                        $result = $flarum_db->query("INSERT INTO ".Config::$FLARUM_PREFIX."posts (id, discussion_id, time, user_id, type, content, is_approved) VALUES ({$row["pid"]}, {$trow["tid"]}, '{$row["dateline"]}', {$row["uid"]}, 'comment', '{$flarum_db->real_escape_string($row["message"])}', 1)");
                        if($result === false)
                            echo "Error executing query: ".$flarum_db->error."<br/>";
                    }
                }
            }
        }
    }

    echo " done: migrated ".$threads->num_rows." threads with their posts";
?>