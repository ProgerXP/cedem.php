<?php
/*
  Custom Engine DatabasE Merge
  in public domain | by Proger_XP | http://proger.me

  Originally written to merge two phpBB3 forums.
  The following tables were unimplemented and are left intact:

    phpbb_acl_groups,           phpbb_acl_options,            phpbb_acl_roles,
    phpbb_acl_roles_data,       phpbb_acl_users,              phpbb_banlist,
    phpbb_bbcodes,         phpbb_bookmarks,     phpbb_bots,   phpbb_config,
    phpbb_confirm,              phpbb_disallow,               phpbb_extension_groups,
    phpbb_extensions,           phpbb_forums_access,          phpbb_forums_watch,
    phpbb_icons,           phpbb_lang,          phpbb_log,    phpbb_login_attempts,
    phpbb_moderator_cache,      phpbb_modules,                phpbb_poll_options,
    phpbb_poll_votes,           phpbb_privmsgs_folder,
    phpbb_privmsgs_rules,       phpbb_profile_fields,
    phpbb_profile_fields_data,  phpbb_profile_fields_lang,
    phpbb_profile_lang,         phpbb_ranks,                  phpbb_reports,
    phpbb_reports_reasons,      phpbb_search_results,         phpbb_sessions,
    phpbb_sitelist,             phpbb_smilies,                phpbb_styles,
    phpbb_styles_imageset,      phpbb_styles_imageset_data,
    phpbb_styles_template,      phpbb_styles_template_data,
    phpbb_styles_theme,         phpbb_warnings,               phpbb_words
*/
  error_reporting(-1);

/***
  Configuration (to be edited)
 ***/

  set_time_limit(600);

  $db = 'mysql:host=localhost;dbname=';
  $dbUser = 'root';
  $dbPass = '';
  $intoDB = 'bb';
  $intoPrefix = 'phpbb_';
  $srcDB = 'bbo';
  $srcPrefix = 'phpbb_';
  $copySrcDB = 'bbc';

  $showSQL = false;
  $bumpBy = 200000;

  // These are executed in sequential order, as defined.
  $tableMorph = array(
    // Morphing tables with custom ID mappings first so if they're referred
    // to in later tables IDs will be actual.
    'groups' => array(
      'group_id' => '!bump',
      'group_name' => '!merge - group_id',
    ),
    'search_wordlist' => array(
      'word_id' => '!bump',
      'word_text' => '!merge word_count word_id',
    ),
    'search_wordmatch' => array(
      'post_id' => 'posts post_id',
      'word_id' => 'search_wordlist word_id',
    ),
    'users' => array(
      'user_id' => '!bump',
      'group_id' => 'groups group_id',
      'username_clean' => '!merge user_posts user_id',
      'user_email' => '!merge user_posts user_id',
      'user_style' => '!set 1',
    ),

    'attachments' => array(
      'attach_id' => '!bump',
      'post_msg_id' => 'posts post_id',
      'topic_id' => 'topics topic_id',
      'poster_id' => 'users user_id',
    ),
    'drafts' => array(
      'draft_id' => '!bump',
      'user_id' => 'users user_id',
      'topic_id' => 'topics topic_id',
      'forum_id' => 'forums forum_id',
    ),
    'forums' => array(
      'forum_id' => '!bump',
      'parent_id' => 'forums forum_id',
      'forum_parents' => '!ser ak forums forum_id forum_id',
      'forum_last_post_id' => 'posts post_id',
      'forum_last_poster_id' => 'users user_id',
      '!sql' => "UPDATE %T% SET left_id = left_id + 38, right_id = right_id + 38 WHERE forum_id > $bumpBy",
    ),
    'forums_track' => array(
      '!rem user_id' => array(145 => 205),
      'user_id' => 'users user_id',
      'forum_id' => 'forums forum_id',
    ),

    'posts' => array(
      'post_id' => '!bump',
      'topic_id' => 'topics topic_id',
      'forum_id' => 'forums forum_id',
      'poster_id' => 'users user_id',
    ),
    'privmsgs' => array(
      'msg_id' => '!bump',
      'author_id' => 'users user_id',
    ),
    'privmsgs_to' => array(
      'msg_id' => 'privmsgs msg_id',
      'user_id' => 'users user_id',
      'author_id' => 'users user_id',
    ),
    'sessions_keys' => array(
      'user_id' => 'users user_id',
    ),
    'topics' => array(
      'topic_id' => '!bump',
      'forum_id' => 'forums forum_id',
      'topic_poster' => 'users user_id',
      'topic_first_post_id' => 'posts post_id',
      'topic_last_post_id' => 'posts post_id',
      'topic_last_poster_id' => 'users user_id',
    ),
    'topics_posted' => array(
      'user_id' => 'users id',
      'topic_id' => 'topics topic_id',
    ),
    'topics_track' => array(
      'user_id' => 'users id',
      'topic_id' => 'topics topic_id',
      'forum_id' => 'topics forum_id',
    ),
    'topics_watch' => array(
      'topic_id' => 'topics topic_id',
      'user_id' => 'users id',
    ),
    'user_group' => array(
      'group_id' => 'groups group_id',
      'user_id' => 'users user_id',
    ),
    'zebra' => array(
      'user_id' => 'users user_id',
      'zebra_id' => 'users user_id',
    ),
  );

  // 'pf_table' => array(srcID => intoID).
  $idMap = array();

/***
  Stop editing here
 ***/

  set_error_handler(function ($level, $msg, $file, $line) {
    throw new ErrorException($msg, 0, $level, $file, $line);
  });

  $srcPDO = new PDO($db.($copySrcDB ?: $srcDB), $dbUser, $dbPass);
  $srcPDO->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $srcPDO->setAttribute(PDO::ATTR_CASE, PDO::CASE_NATURAL);
  $srcPDO->setAttribute(PDO::ATTR_ORACLE_NULLS, PDO::NULL_NATURAL);
  $srcPDO->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
  $srcPDO->exec('SET NAMES utf8');

  $index = -1;
  foreach ($tableMorph as $table => $morph) {
    echo "Morphing $table (", ++$index + 1, " / ", count($tableMorph), ")...", PHP_EOL;

    if ($copySrcDB) {
      query("DROP TABLE IF EXISTS {$srcPrefix}$table");
      query("CREATE TABLE {$srcPrefix}$table LIKE $srcDB.{$srcPrefix}$table");
      query("INSERT INTO {$srcPrefix}$table SELECT * FROM $srcDB.{$srcPrefix}$table");
    }

    foreach ($morph as $column => $rule) {
      if ($column[0] === '!') {
        $func = 'morph_'.substr(strtok($column, ' '), 1);
        $column = strtok(null);
        $arg = $rule;
      } elseif ($rule[0] === '!') {
        $func = 'morph_'.substr(strtok($rule, ' '), 1);
        $arg = strtok(null);
      } else {
        $func = 'morph_asid';
        $arg = $rule;
      }

      if (!function_exists($func)) {
        throw new Exception("Unknown morph rule name, no function [$func]: [$rule].");
      }

      $func($srcPrefix.$table, $column, $arg);
    }

    echo 'Syncronizing column order...', PHP_EOL;
    syncColumns($table);

    echo 'Copying over...', PHP_EOL;
    query("INSERT INTO $intoDB.{$intoPrefix}$table SELECT * FROM {$srcPrefix}$table");

    echo PHP_EOL;
  }

  echo 'All done!', PHP_EOL;

function query($obj, $binds = array()) {
  global $srcPDO, $showSQL;

  if (is_string($obj)) {
    $showSQL and print "  $obj".PHP_EOL;
    return $srcPDO->query($obj);
  } elseif ($obj instanceof PDOStatement) {
    $sql = $showSQL ? $obj->queryString : '';

    foreach ((array) $binds as $name => $value) {
      $obj->bindValue(is_int($name) ? $name + 1 : $name, $value);
      $sql and $sql = preg_replace('~(\s)'.(is_int($name) ? '\?' : ":$name").'([\s,])~u',
                                   "\\1'$value'\\2", " $sql ", 1);
    }

    $sql and print "  ".trim($sql).PHP_EOL;
    $obj->execute();
    $obj->closeCursor();
  }
}

function syncColumns($table) {
  global $intoDB, $intoPrefix, $srcPrefix;

  $intoCols = query("SHOW COLUMNS FROM $intoDB.{$intoPrefix}$table")->fetchAll();
  $srcCols = query("SHOW COLUMNS FROM {$srcPrefix}$table")->fetchAll();
  $intoI = -1;

  foreach ($intoCols as $intoCol) {
    ++$intoI;
    $srcI = -1;

    foreach ($srcCols as $srcCol) {
      ++$srcI;

      if ($intoCol['Field'] === $srcCol['Field']) {
        if ($srcI != $intoI) {
          $pos = $intoI ? 'AFTER '.$intoCols[$intoI - 1]['Field'] : 'FIRST';
          query("ALTER TABLE {$srcPrefix}$table MODIFY COLUMN `$srcCol[Field]` $srcCol[Type] $pos");
        }

        $srcI = true;
        break;
      }
    }

    if ($srcI !== true) {
      $pos = $intoI ? 'AFTER '.$intoCols[$intoI - 1]['Field'] : 'FIRST';
      query("ALTER TABLE {$srcPrefix}$table ADD COLUMN `$srcCol[Field]` $srcCol[Type] $pos");
    }
  }

  foreach ($srcCols as $srcCol) {
    $found = false;

    foreach ($intoCols as $intoCol) {
      if ($intoCol['Field'] === $srcCol['Field']) {
        $found = true;
        break;
      }
    }

    if (!$found) {
      query("ALTER TABLE {$srcPrefix}$table DROP COLUMN `$srcCol[Field]`");
      echo "  rm $srcCol[Field]", PHP_EOL;
    }
  }
}

function mapID($table, $column, $id) {
  global $idMap, $tableMorph, $bumpBy;

  if (!$id) {   // 0 or NULL to indicate "no ID" (unfilled column).
    return $id;
  }

  $mapped = &$idMap[$table][$column][$id];
  if ($mapped !== null) {
    return $mapped;
  }

  $rule = &$tableMorph[$table][$column];
  if (!$rule) {
    throw new Exception("Cannot map old ID [$id] to new because table [$table] or".
                        " column [$column] are undefined.");
  } elseif ($rule !== '!bump') {
    throw new Exception("Cannot map old ID [$table $column $id] due to bad morph rule [$rule].");
  } else {
    return $id + $bumpBy;
  }
}

function morph_asid($table, $column, $arg) {
  global $srcPDO, $idMap, $srcPrefix;

  list($asTable, $asColumn) = explode(' ', $arg);
  morph_bump($table, $column);

  $stmt = $srcPDO->prepare("UPDATE $table SET `$column` = ? WHERE `$column` = ?");
  $map = &$idMap[$srcPrefix.$asTable][$asColumn];

  foreach ((array) $map as $srcID => $asID) {
    if ($srcID != $asID and $asID) {
      query($stmt, array($asID, $srcID));
    }
  }
}

function morph_bump($table, $column) {
  global $bumpBy;
  // UPDATE untouches NULL $column so only checking for <> 0.
  query("UPDATE $table SET `$column` = `$column` + $bumpBy WHERE `$column` <> 0");
}

// $arg = 'ak table column id_column'.
function morph_ser($table, $column, $arg) {
  global $srcPDO;

  list($mode, $readTable, $readColumn, $idColumn) = explode(' ', $arg);
  if ($mode !== 'ak') {
    throw new Exception("Unknown mode [$mode] for !ser.");
  }

  $stmt = $srcPDO->prepare("UPDATE $table SET `$column` = ? WHERE `$idColumn` = ?");
  $query = query("SELECT `$column`, `$idColumn` FROM $table");

  while ($row = $query->fetch()) {
    if ($row[0]) {
      $data = unserialize($row[0]);
      $new = array();

      foreach ($data as $key => $value) {
        $new[mapID($readTable, $readColumn, $key)] = $value;
      }

      query($stmt, array(serialize($new), $row[1]));
    }
  }
}

// $ids = array(srcIdToRemove => mapToID).
function morph_rem($table, $idColumn, $ids) {
  global $idMap;

  query("DELETE FROM $table WHERE `$idColumn` IN (".join(', ', array_keys($ids)).")");

  $mapped = &$idMap[$table][$idColumn];
  $mapped = $ids + (array) $mapped;
}

function morph_sql($table, $column, $sql) {
  query(str_replace('%T%', $table, $sql));
}

function morph_merge($table, $column, $arg) {
  global $srcPDO, $idMap, $intoDB, $intoPrefix, $srcPrefix;

  list($sumColumn, $idColumn) = explode(' ', "$arg  ");
  $sumColumn === '-' and $sumColumn = '';
  $idColumn or $idColumn = $column;

  $intoDbTable = "`$intoDB`.{$intoPrefix}".substr($table, strlen($srcPrefix));

  $delete = $srcPDO->prepare("DELETE FROM $table WHERE `$column` = ?");
  $sum = !$sumColumn ? null :
    $srcPDO->prepare("UPDATE $intoDbTable SET `$sumColumn` = `$sumColumn` + ? WHERE `$column` = ?");

  $query = query("SELECT * FROM $table AS t LEFT JOIN $intoDbTable AS i".
                 " ON t.`$column` = i.`$column`");

  while ($row = $query->fetch()) {
    $existing = $source = array();

    foreach ($row as $key => $value) {
      if (!is_int($key)) {
        $source[$key] = $row[count($source)];
        $existing[$key] = $value;
      }
    }

    if ($existing[$column] !== null) {
      $mapped = &$idMap[$table][$idColumn][$source[$idColumn]];

      if ($mapped) {
        throw new Exception("Duplicate $table row when merging $source[$column]: ID".
                            " $source[$idColumn] was already mapped to $mapped.");
      } else {
        $mapped = $existing[$idColumn];
        $sum and query($sum, array($source[$sumColumn], $existing[$column]));
        query($delete, array($source[$column]));
      }
    }
  }
}

function morph_set($table, $column, $arg) {
  global $srcPDO;
  $stmt = $srcPDO->prepare("UPDATE $table SET `$column` = ?");
  query($stmt, array($arg));
}