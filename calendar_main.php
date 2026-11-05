<?php

  # Calendar Version 4.6, J. Kenney 2015-2026
  define('CALENDAR_VERSION', '4.6.20261105');

  # Markdown (.md) support
  require_once(__DIR__ . '/calendar_markdown.php');

  # Heading levels in .md files that get an id (and a navbar menu entry)
  if (!isset($MARKDOWN_MENU_LEVELS) || !is_array($MARKDOWN_MENU_LEVELS)) {
    $MARKDOWN_MENU_LEVELS = array(2);
  }

  # Is this file name a Markdown file?
  function cal4_is_markdown($filename) {
    return strtolower(pathinfo((string)$filename, PATHINFO_EXTENSION)) === 'md';
  }

  # Escape a value for safe output inside HTML text or a quoted attribute.
  if (!function_exists('h')) {
    function h($value) {
      return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
  }

  # Build a link to a keyed file within the current event.  Every value is URL
  # encoded so the link is safe inside a quoted HTML attribute.
  function cal4_file_link($key, $type = null, $event = null) {
    $link = 'calendar.php?key=' . rawurlencode((string)$key);
    if ($type !== null) {
      $link .= '&type=' . rawurlencode((string)$type);
    }
    if ($event !== null) {
      $link .= '&event=' . rawurlencode((string)$event);
    }
    return $link;
  }

  # Normalize a day type / component name ("Final Exam" -> "final-exam") so that
  # schedule entries always match the directory that holds their content.
  function cal4_normalize_type($type) {
    return str_replace(' ', '-', strtolower(trim((string)$type)));
  }

  # Human readable label for a normalized type ("final-exam" -> "final exam").
  function cal4_type_label($type) {
    return str_replace('-', ' ', (string)$type);
  }

  # Read a request value only if it is a plain string (arrays are rejected).
  function cal4_request_string($name) {
    if (isset($_REQUEST[$name]) && is_string($_REQUEST[$name])) {
      return $_REQUEST[$name];
    }
    return null;
  }

  # Split one line of the access / virtual configuration files.
  # Blank lines and lines starting with # are ignored (returns False).
  # Fields are separated by tabs; if a line has no tabs, any whitespace is used
  # (older files used spaces).  Tab separated lines may contain spaces in paths.
  function cal4_config_fields($line) {
    $line = trim($line);
    if ($line === '' || substr($line, 0, 1) === '#') {
      return False;
    }
    if (strpos($line, "\t") !== False) {
      return array_values(array_filter(array_map('trim', preg_split('/\t+/', $line)), 'strlen'));
    }
    return preg_split('/\s+/', $line);
  }

  # Messages about configuration problems, shown to the instructor only.
  $CONFIG_WARNINGS = array();

  # Basic security headers
  if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: object-src 'none'; base-uri 'self'");
  }

  # Determine what directories should be scanned for files.
  $COMPONENTS = array();
  foreach ($DOW as $i => $type) {
    $type = cal4_normalize_type($type);
    $COMPONENTS[$type] = $type;
  }
  if (isset($OVERRIDE)) {
    foreach ($OVERRIDE as $i => $m) {
      foreach ($m as $d => $type) {
        $type = cal4_normalize_type($type);
        $COMPONENTS[$type] = $type;
      }
    }
  }
  if (isset($COMBINE)) {
    foreach ($COMBINE as $type => $cset) {
      $type = cal4_normalize_type($type);
      $COMPONENTS[$type] = $type;
      foreach ($cset as $day => $inside) {
        foreach ($inside as $ntype) {
          $ntype = cal4_normalize_type($ntype);
          $COMPONENTS[$ntype] = $ntype;
        }
      }
    }
  }

  # Validate the request parameters that select content.
  #  - event must be a positive integer written plainly (no "02", "2.0", "2 ")
  #  - type must be one of the configured components
  #  - key must be a sha1 hex string
  # Anything else is discarded, which falls back to the home page.
  $cal4_bad_request = False;
  if (isset($_REQUEST['event'])) {
    $ev = cal4_request_string('event');
    if ($ev === null || !preg_match('/^[1-9][0-9]{0,5}$/', $ev)) {
      $cal4_bad_request = True;
    } else {
      $_REQUEST['event'] = intval($ev);
    }
  }
  if (isset($_REQUEST['type'])) {
    $ty = cal4_request_string('type');
    if ($ty === null || !isset($COMPONENTS[$ty])) {
      $cal4_bad_request = True;
    }
  }
  if (isset($_REQUEST['key'])) {
    $ky = cal4_request_string('key');
    if ($ky === null || !preg_match('/^[0-9a-f]{40}$/', $ky)) {
      $cal4_bad_request = True;
    }
  }
  if ($cal4_bad_request) {
    unset($_REQUEST['event'], $_REQUEST['type'], $_REQUEST['key'], $_REQUEST['edit'], $_REQUEST['editor']);
  }
  foreach (array('show', 'load', 'search', 'keyword', 'lock', 'password', 'edit', 'editor', 'answers', 'navbaronly', 'csrf') as $cal4_param) {
    if (isset($_REQUEST[$cal4_param]) && !is_string($_REQUEST[$cal4_param])) {
      unset($_REQUEST[$cal4_param]);
    }
  }

  # Refuse to run with the publicly known sample secret, it is used to build
  # file keys and authentication tokens.
  define('CAL4_SAMPLE_SECRET', 'A really long phrase that should be impossible to guess, you dont need to remember this...');
  if (!isset($SECRET) || !is_string($SECRET) || $SECRET === '' || $SECRET === CAL4_SAMPLE_SECRET) {
    unset($_REQUEST['event'], $_REQUEST['type'], $_REQUEST['key'], $_REQUEST['show']);
    $PAGE_MODIFY['error'] = 'The calendar has not been configured with a private $SECRET.';
    $PAGE_MODIFY['error-comment'] = 'Edit calendar.php and set $SECRET to a long random value, for example the output of: php -r \'echo bin2hex(random_bytes(32));\'';
    $_REQUEST['load'] = 'error';
    $SECRET = 'cal4-unconfigured-' . bin2hex(random_bytes(16));
  } elseif (strlen($SECRET) < 32 || preg_match('/impossible to guess -?\d{0,5}, you dont need/', $SECRET)) {
    $CONFIG_WARNINGS[] = 'The $SECRET in calendar.php is weak (short, or the old installer default with a small random number). Replace it with a long random value such as the output of: php -r \'echo bin2hex(random_bytes(32));\'';
  }

  # Load in the configuration for all virtual files
  # $virtual = array('class'=>array(1 => array(array('answers.html', 'homework/answers05.html'))));
  $virtual = array();
  if (file_exists($CLASS_FILE)) {
    if (!is_readable($CLASS_FILE)) {
      if (isset($_REQUEST['event'])) { unset($_REQUEST['event']); }
      if (isset($_REQUEST['type'])) { unset($_REQUEST['type']); }
      if (isset($_REQUEST['key'])) { unset($_REQUEST['key']); }
      if (isset($_REQUEST['show'])) { unset($_REQUEST['show']); }
      $PAGE_MODIFY['error'] = "The Virtual Class File ($CLASS_FILE) can not be opened.";
      $PAGE_MODIFY['error-comment'] = "The file was detected but the system was unable to read it, as a precaution the calendar will not show any content until this is corrected.";
      $_REQUEST['load'] = 'error';
    } else {
      $vdata = file($CLASS_FILE);
      foreach($vdata as $lineno => $rawline) {
        $line = cal4_config_fields($rawline);
        if ($line === False) {
          continue;
        }
        if (count($line) == 4 && preg_match('/^[1-9][0-9]*$/', $line[3])) {
          $line[2] = cal4_normalize_type($line[2]);
          $line[3] = intval($line[3]);
          if (!isset($virtual[$line[2]][$line[3]])) {
            $virtual[$line[2]][$line[3]] = array();
          }
          $virtual[$line[2]][$line[3]][] = array($line[0], $line[1]);
        } else {
          $CONFIG_WARNINGS[] = "Ignored line " . ($lineno + 1) . " of $CLASS_FILE (expected: virtual-name, real-file, component, event-number): " . trim($rawline);
        }
      }
    }
  }

  # Load in the configuration for all access configurations
  # 2 fields provided, $access = array('class01.html' => array('month'=>2, 'day'=>4, 'year'=>1,    dynamic=>''));
  # 3 fields provided, $access = array('class01.html' => array('month'=>2, 'day'=>4, 'year'=>2018, dynamic=>''));
  # 1 field  provided, $access = array('class01.html' => array('month'=>1, 'day'=>1, 'year'=>1,    dynamic'=>'-2'));
  $access = array();
  if (file_exists($ACCESS_FILE)) {
    if (!is_readable($ACCESS_FILE)) {
      if (isset($_REQUEST['event'])) { unset($_REQUEST['event']); }
      if (isset($_REQUEST['type'])) { unset($_REQUEST['type']); }
      if (isset($_REQUEST['key'])) { unset($_REQUEST['key']); }
      if (isset($_REQUEST['show'])) { unset($_REQUEST['show']); }
      $PAGE_MODIFY['error'] = "The Access File ($ACCESS_FILE) can not be opened.";
      $PAGE_MODIFY['error-comment'] = "The file was detected but the system was unable to read it, as a precaution the calendar will not show any content until this is corrected.";
      $_REQUEST['load'] = 'error';
    } else {
      $vdata = file($ACCESS_FILE);
      $cal4_dayname = '(mon|tue|tues|wed|thu|thur|thurs|fri|sat|sun|monday|tuesday|wednesday|thursday|friday|saturday|sunday)';
      foreach($vdata as $lineno => $rawline) {
        $line = cal4_config_fields($rawline);
        if ($line === False) {
          continue;
        }
        if (count($line) == 2 && preg_match('/^[+-]([0-9]{1,4}|' . $cal4_dayname . ')$/i', $line[1])) {
          $access[$line[0]] = array('month'=>1, 'day'=>1, 'year'=>1, 'dynamic'=> strtolower($line[1]));
        } elseif (count($line) == 3 && ctype_digit($line[1]) && ctype_digit($line[2])) {
          $access[$line[0]] = array('month'=> intval($line[1]), 'day'=> intval($line[2]), 'year'=>$YEAR, 'dynamic'=>'');
        } elseif (count($line) == 4 && ctype_digit($line[1]) && ctype_digit($line[2]) && ctype_digit($line[3])) {
          $access[$line[0]] = array('month'=> intval($line[1]), 'day'=> intval($line[2]), 'year'=>intval($line[3]), 'dynamic'=>'');
        } else {
          $CONFIG_WARNINGS[] = "Ignored line " . ($lineno + 1) . " of $ACCESS_FILE (expected: name month day [year], or name +N / -N / +dayname / -dayname): " . trim($rawline);
        }
      }
    }
  }

  # Basic file record used by the scanner.  Date based visibility is decided
  # later by get_file_security() using the access file.  (The legacy
  # filename.month.day.extension naming convention is no longer supported.)
  # Returns array(Exists, Visible, Year, Month, Day, CategoryName, Dynamic)
  function validate_file($filename, $filewithpath) {
    if (!is_file($filewithpath)) {
      return array(False, False, 1, 1, 1, '', '');
    }
    return array(True, True, 1, 1, 1, $filename, '');
  }

  # Is $path (already resolved with realpath) inside one of the allowed roots?
  function cal4_path_allowed($path, $roots) {
    foreach ($roots as $root) {
      if ($path === $root || strpos($path, rtrim($root, '/') . '/') === 0) {
        return True;
      }
    }
    return False;
  }

  # True if any segment of a relative path is hidden (starts with a dot).
  function cal4_is_hidden_path($relpath) {
    return preg_match('#(^|/)\.#', $relpath) === 1;
  }

  # Determine what type of category the file is
  # types = html, link, src, txt, powerpoint, pdf (aka the extension!)
  function categorize_file($filename, $filereduced, $categories) {
    $mytype = '';
    $myboxblock = '';
    if (isset($categories[$filereduced])) {
      $mytype = $categories[$filereduced]['type'];
      $myboxblock = $categories[$filereduced]['boxblock'];
    } elseif (isset($categories[$filename])) {
      $mytype = $categories[$filename]['type'];
      $myboxblock = $categories[$filename]['boxblock'];
    }
    return array($mytype, $myboxblock);
  }

  # Function to get function()[], a feature that doesnt exist in older versions of PHP
  function ancient($results, $index) {
    if (!isset($results[$index])) {
      return False;
    }
    return $results[$index];
  }

  # Retrieve a list of directories and their files, that are sourced from the
  # content directories.  Files will be validated for access
  # $allowed_roots: absolute directories that files (and symlink targets) must
  # live in; defaults to the course directory itself.
  function get_files($sources=array('class', 'lab', 'project', 'exam', 'review', 'capstone', 'continued-lab'), $file_path = '.', $virtual=array(), $categories=array(), $secret='really', $allowed_roots=array()) {
    $results = array();
    if (empty($allowed_roots)) {
      $allowed_roots = array(realpath($file_path));
    }
    $basedir = scandir($file_path);
    foreach ($sources as $i => $l0) {
      if (in_array($l0, $basedir) && is_dir($file_path . '/' . $l0)) {
        $level1 = scandir($file_path . '/' . $l0);
        # Natural order, so 1.Intro, 2.Loops, 10.Review number as 1, 2, 3.
        sort($level1, SORT_NATURAL);
        foreach ($level1 as $i => $l1) {
          if (substr($l1, 0, 1) != '.' && is_dir($file_path . '/' . $l0 . '/' . $l1)) {
            // The following code allows the system recursively look through the Directory
            // structure within a class, and the system can now process RELATIVE links
            // that go into those subordinate directories
            $prefix = $file_path . '/' . $l0 . '/' . $l1 . '/';
            $level2 = array();
            try {
              $Iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($prefix, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD);
              foreach($Iterator as $name => $object) {
                $level2[] = substr($name, strlen($prefix));
              }
            } catch (Exception $e) {
            // Unable to recurse, so don't bother...
            }
            $results[$l0][$l1] = array();
            sort($level2);
            foreach ($level2 as $i => $l2) {
              if ($l2 === '' || $l2 === False || cal4_is_hidden_path($l2)) {
                continue;
              }
              $full = $prefix . $l2;
              # Resolve symbolic links and make sure the target stays inside
              # the allowed directories.
              $real = realpath($full);
              if ($real === False || !cal4_path_allowed($real, $allowed_roots)) {
                continue;
              }
              $vf = validate_file($l2, $real);
              if (!$vf[0]) {
                continue;
              }
              $key = sha1($secret.$l0.$l1.$l2);
              $category = categorize_file($l2, $vf[5], $categories);
              $results[$l0][$l1][$l2] = array('actual' => is_link($full) ? $real : $full,
                                              'visible' => $vf[1],
                                              'year' => $vf[2],
                                              'month' => $vf[3],
                                              'day' => $vf[4],
                                              'dynamic' => $vf[6],
                                              'type' => $category[0],
                                              'category' => $category[1],
                                              'key' => $key,
                                              'filename' => $l2);
            }
          }
        }
      }
    }
    # Add Virtual files, format like array('class'=>array(1 => array(array('answers.html', 'homework/answers05.html'))))
    foreach ($virtual as $l0 => $l1array) {
      if (isset($results[$l0])) {
        $classes = array_keys($results[$l0]);
        foreach ($l1array as $day => $l2array) {
          if (isset($classes[$day-1])) {
            foreach ($l2array as $i => $values) {
              $virt_file = $values[0];
              $real_file = $values[1];
              $real = realpath($real_file);
              if ($real !== False && cal4_path_allowed($real, $allowed_roots) && ancient(validate_file($real_file, $real), 0)) {
                $key = sha1($secret.$real_file.$virt_file);
                $vf = validate_file($real_file, $real);
                $category = categorize_file($virt_file, $vf[5], $categories);
                $results[$l0][$classes[$day-1]][$virt_file] = array('actual' => $real_file,
                                                                    'visible' => $vf[1],
                                                                    'year' => $vf[2],
                                                                    'month' => $vf[3],
                                                                    'day' => $vf[4],
                                                                    'dynamic' => $vf[6],
                                                                    'type' => $category[0],
                                                                    'category' => $category[1],
                                                                    'key' => $key,
                                                                    'filename' => $real_file);
              }
            }
          }
        }
      }
    }
    return $results;
  }

  # Determine what file security parameters to use, based on access file.
  function get_file_security($YEAR, $month, $day, $l0, $l1, $cate, $filename, $access) {
    $sspec = False;
    if (isset($access[$l0."_".$l1."/".$filename])) {
      $sspec = $l0."_".$l1."/".$filename;
    } elseif (isset($access[$filename])) {
      $sspec = $filename;
    } elseif (isset($access[$l0."_".$l1."/".$cate])) {
      $sspec = $l0."_".$l1."/".$cate;
    } elseif (isset($access[$l0."/".$cate])) {
      $sspec = $l0."/".$cate;
    } elseif (isset($access[$l0."_".$l1])) {
      $sspec = $l0."_".$l1;
    } elseif (isset($access[$l0."/*"])) {
      $sspec = $l0."/*";
    } elseif (isset($access[$l0."_".$l1."/all"])) {
      $sspec = $l0."_".$l1."/all";
    } elseif (isset($access[$l0."_".$l1."/*"])) {
      $sspec = $l0."_".$l1."/*";
    } elseif (isset($access["*/".$cate])) {
      $sspec = "*/".$cate;
    } elseif (isset($access[$l0])) {
      $sspec = $l0;
    } elseif (isset($access["*"])) {
      $sspec = "*";
    }
    if ($sspec !== False && isset($access[$sspec])) {
      $today = getdate();
      if ($access[$sspec]['dynamic'] == '') {
        if (   ($today['year'] >  $access[$sspec]['year'] &&            12 >= $access[$sspec]['month'] && 31 >= $access[$sspec]['day'])
            || ($today['year'] >= $access[$sspec]['year'] && $today['mon'] >  $access[$sspec]['month'])
            || ($today['year'] == $access[$sspec]['year'] && $today['mon'] == $access[$sspec]['month'] && $today['mday'] >= $access[$sspec]['day'])) {
              return array(True, $access[$sspec]['year'], $access[$sspec]['month'], $access[$sspec]['day'], $access[$sspec]['dynamic']);
        } else {
          return array(False, $access[$sspec]['year'], $access[$sspec]['month'], $access[$sspec]['day'], $access[$sspec]['dynamic']);
        }
      } else {
        $delta = substr($access[$sspec]['dynamic'], 1);
        $d0 = new DateTime("$YEAR-$month-$day 00:00:01");
        $today = new DateTime();
        if ($delta != '0') {
          if (substr($access[$sspec]['dynamic'], 0, 1) == '+') {
            if (is_numeric($delta)) {
              $diffDay = new DateInterval("P".$delta."D");
              $d0->add($diffDay);
            } else {
              $d0->modify('next '.$delta);
            }
          } elseif (substr($access[$sspec]['dynamic'], 0, 1) == '-') {
            if (is_numeric($delta)) {
              $diffDay = new DateInterval("P".$delta."D");
              $d0->sub($diffDay);
            } else {
              if (strtolower(substr($d0->format('l'), 0, 3)) != strtolower(substr($delta, 0, 3))) {
                $d0->modify('last '.$delta);
              }
            }
          }
        }
        $d0v = explode('-', $d0->format('Y-n-j'));
        if ($today >= $d0) {
          return array(True, $d0v[0], $d0v[1], $d0v[2], $access[$sspec]['dynamic']);
        } else {
          return array(False, $d0v[0], $d0v[1], $d0v[2], $access[$sspec]['dynamic']);
        }
      }
    }
    return array(True, 1,1,1,'');
  }

  # Build type tree
  # $MD lists Markdown files (rendered like html).  When it is not configured,
  # every .html name in $HTML also gets a .md twin (index.html -> index.md).
  function build_types($BOX, $HTML, $LINK, $PDF, $PPT, $SRC, $MD = null) {
    $categories = array();
    if (!is_array($MD)) {
      $MD = array();
      foreach ($HTML as $cat => $value) {
        if (preg_match('/\.html?$/i', $value)) {
          $MD[$cat] = preg_replace('/\.html?$/i', '.md', $value);
        }
      }
    }
    foreach (array('md'=>$MD, 'html'=>$HTML, 'link'=>$LINK, 'pdf'=>$PDF, 'ppt'=>$PPT, 'src'=>$SRC) as $item => $values) {
      foreach ($values as $cat => $value) {
        $categories[$value] = array('type'=>$item, 'boxblock'=>$cat);
      }
    }
    return $categories;
  }

  # Update the events with the date of the class
  function calendar_events($events, $YEAR, $MONTH_START, $DAY_START, $WEEKENDS, $MONTH_END, $DOW, $OVERRIDE, $BOX, $COMBINE=array()) {
    $results = array('year'=>$YEAR, 'month_start'=>$MONTH_START, 'month_end'=>$MONTH_END, 'box'=>$BOX);
    $results_counter = array();
    # Normalize all schedule types so they match the component directories
    # (for example 'Final Exam' is stored in the final-exam directory).
    if (!is_array($OVERRIDE)) { $OVERRIDE = array(); }
    if (!is_array($COMBINE)) { $COMBINE = array(); }
    foreach ($DOW as $k => $v) {
      $DOW[$k] = cal4_normalize_type($v);
    }
    foreach ($OVERRIDE as $m => $days) {
      foreach ($days as $d => $v) {
        $OVERRIDE[$m][$d] = cal4_normalize_type($v);
      }
    }
    $ncombine = array();
    foreach ($COMBINE as $ctype => $cset) {
      foreach ($cset as $cnum => $inside) {
        $ncombine[cal4_normalize_type($ctype)][$cnum] = array_map('cal4_normalize_type', $inside);
      }
    }
    $COMBINE = $ncombine;
    $POSDOW = array(0 => 'sun', 1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun');
    ## Lets walk through all possible months...
    for($month=$MONTH_START; $month <= $MONTH_END; $month++) {
      $month_len = cal_days_in_month(CAL_GREGORIAN, $month, $YEAR);
      $month_name = cal_info(0);
      $month_name = $month_name['months'][$month];
      $first = date("w", mktime(0, 0, 0, $month, 1, $YEAR));
      ## Lets walk through the days of this specific month
      for($day=1; $day <= $month_len; $day++) {
        $day_type = '';
        $day_num = '';
        $force_day = False;
        ## Lets determine what type of day it is...
        ## If we are before the semester starts
        if ($month == $MONTH_START && $day < $DAY_START) {
        ## If we are overriding a specific day
        } elseif (isset($OVERRIDE[$month][$day])) {
          $day_type = $OVERRIDE[$month][$day];
          $force_day = True;
        ## If it is just a normal day
        } elseif (isset($DOW[$POSDOW[$first]])) {
          $day_type = $DOW[$POSDOW[$first]];
        }
        ## If it is an actual day, lets do some work...
        if ($day_type != '') {
          ## Lets figure out what class/lab/project/etc # we are dealing with
          if (isset($events[$day_type])) {
            if (!isset($results_counter[$day_type])) {
              $results_counter[$day_type] = 1;
            } else {
              $results_counter[$day_type]++;
            }
          }
          ## lets figure out what the event entails
          if (isset($events[$day_type]) && isset($events[$day_type][$results_counter[$day_type]])) {
            $event = $events[$day_type][$results_counter[$day_type]];
            $day_num = $results_counter[$day_type];
          } else {
            $event = array();
          }
          ## Are we forcing today to be something else...
          if ($force_day || !empty($event)) {
            $results[$month][$day] = array('month'=>$month, 'day'=>$day, 'type'=>$day_type, 'type_num'=>$day_num,
                                           'dow'=>$first, 'dow_eng'=>$POSDOW[$first], 'event'=>$event);
            $results['map'][$day_type][$day_num] = array($month, $day);
          }
          ## Did we want two things to occur on the same day???
          ## Example: $COMBINE = array('class'=>array(3=>array('class', 'class')));
          if (isset($COMBINE[$day_type][$day_num])) {
            foreach ($COMBINE[$day_type][$day_num] as $nday_type) {
              if (!isset($results_counter[$nday_type])) {
                $results_counter[$nday_type] = 1;
              } else {
                $results_counter[$nday_type]++;
              }
              ## lets figure out what the event entails
              $nday_num = -1;
              if (isset($events[$nday_type]) && isset($events[$nday_type][$results_counter[$nday_type]])) {
                $event = $events[$nday_type][$results_counter[$nday_type]];
                $nday_num = $results_counter[$nday_type];
              } else {
                $event = array();
              }
              if (!isset($results[$month][$day]['combine'])) {
                $results[$month][$day]['combine'] = array();
              }
              $results[$month][$day]['combine'][] = array('month'=>$month, 'day'=>$day,
                                                       'type'=>$nday_type, 'type_num'=>$nday_num,
                                                       'dow'=>$first, 'dow_eng'=>$POSDOW[$first],
                                                       'event'=>$event);
              $results['map'][$nday_type][$nday_num] = array($month, $day);
            }
          }
        }
        ## Some code to figure out where in the week we are...
        $first++;
        if ($first == 7) {$first = 0;}
      }
    }
    return $results;
  }

  # Build events (these will be used to display the calendar)
  function build_events($files, $categories, $BOX) {
    $events = array();
    foreach ($files as $cat => $catarray) {
      $events[$cat] = array();
      $counter = 1;
      foreach ($catarray as $event => $earray) {
        $cevent = explode('.', $event, 2);
        if (count($cevent) > 1) {
          $cevent = $cevent[1];
        } else {
          $cevent = $cevent[0];
        }
        $events[$cat][$counter] = array('name' => $cevent, 'box' => array());
        $fcounter = 0;
        foreach ($earray as $fn => $fndata) {
          if ($fndata['category'] != '' && !isset($events[$cat][$counter]['box'][$fndata['category']])) {
            $events[$cat][$counter]['box'][$fndata['category']] = $fndata;
            $events[$cat][$counter]['box'][$fndata['category']]['filename'] = $fn;
            $events[$cat][$counter]['box'][$fndata['category']]['ftype'] = $cat;
            $events[$cat][$counter]['box'][$fndata['category']]['fclass'] = $counter;
            $events[$cat][$counter]['box'][$fndata['category']]['fid'] = $fcounter;
          }
          $fcounter++;
        }
        $counter++;
      }
    }
    return $events;
  }

  # Build keypairs (these link files to a sha1 hash)
  function build_keypairs($files) {
    $keypairs = array();
    foreach ($files as $type => $classes) {
      foreach ($classes as $classname => $classfiles) {
        foreach ($classfiles as $filename => $values) {
          $keypairs[$values['key']] = $values['actual'];
        }
      }
    }
    return $keypairs;
  }

  # load and provide the contents of a file to the browser
  # sets appropriate mime types
  function provide_file($filename) {
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    $MODE = 'txt';
    $attachment = "attachment; ";
    if ($ext != '') {
      $MODE = $ext;
    }
    switch ($MODE) {
      case "bz2":   $ctype="application/x-bzip2"; break;
      case "css":   $ctype="text/css"; break;
      case "gz":    $ctype="application/x-gzip"; break;
      case "gzip":  $ctype="application/x-gzip"; break;
      case "java":  $ctype="text/x-java-source"; $attachment=""; break;
      case "tgz":   $ctype="application/x-compressed"; break;
      case "pdf":   $ctype="application/pdf"; $attachment=""; break;
      case "zip":   $ctype="application/zip"; break;
      case "doc":   $ctype="application/msword"; break;
      case "docx":  $ctype="application/vnd.openxmlformats-officedocument.wordprocessingml.document"; break;
      case "xls":   $ctype="application/vnd.ms-excel"; break;
      case "xlsx":  $ctype="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"; break;
      case "ppt":   $ctype="application/vnd.ms-powerpoint"; break;
      case "pptx":  $ctype="application/vnd.openxmlformats-officedocument.presentationml.presentation"; break;
      case "svg":   $ctype="image/svg+xml"; $attachment=""; break;
      case "gif":   $ctype="image/gif"; $attachment=""; break;
      case "png":   $ctype="image/png"; $attachment=""; break;
      case "jpe":   $ctype="image/jpg"; $attachment=""; break;
      case "jpeg":  $ctype="image/jpg"; $attachment=""; break;
      case "jpg":   $ctype="image/jpg"; $attachment=""; break;
      case "sql":   $ctype="text/plain"; $attachment=""; break;
      case "txt":   $ctype="text/plain"; $attachment=""; break;
      case "htm":   $ctype="text/html"; $attachment=""; break;
      case "html":  $ctype="text/html"; $attachment=""; break;
      case "htmls": $ctype="text/html"; $attachment=""; break;
      default: $ctype="application/octet-stream";
    }

    header("Content-Type: $ctype");
    header('Content-Disposition: '.$attachment.'filename="'.basename($filename).'"');
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');

    echo file_get_contents($filename);

  }

  # Determine the valid categories for files
  $categories = build_types($BOX, $HTML, $LINK, $PDF, $PPT, $SRC, isset($MD) ? $MD : null);

  # Set valid logon time (based on cookie auth)
  if (!isset($LOGON_TIME)) {
    $LOGON_TIME = 43200;
  }

  # Cookies: HttpOnly, SameSite=Lax, and Secure when served over HTTPS.
  function cal4_is_https() {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
  }
  function cal4_setcookie($name, $value, $expires) {
    if (headers_sent()) { return False; }
    return setcookie($name, $value, array('expires' => $expires, 'path' => '', 'secure' => cal4_is_https(), 'httponly' => True, 'samesite' => 'Lax'));
  }

  # Perform Authentication
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(array('httponly' => True, 'secure' => cal4_is_https(), 'samesite' => 'Lax'));
    session_start();
  }
  $INSTRUCTOR = False;
  if ($ADMIN != '') {
    if (isset($_REQUEST['password']) && $_REQUEST['password'] == $ADMIN) {
      $_SESSION["cal4-$COURSE"] = sha1($SECRET.$ADMIN.$COURSE.$_SESSION["cal4-$COURSE-nonce"].session_id());
      cal4_setcookie("cal4-$COURSE", sha1($SECRET.$ADMIN.$COURSE.$_COOKIE["cal4-$COURSE-nonce"].session_id()), time()+$LOGON_TIME);
      $_COOKIE["cal4-$COURSE"] = sha1($SECRET.$ADMIN.$COURSE.$_COOKIE["cal4-$COURSE-nonce"].session_id());
    } elseif (isset($_REQUEST['password'])
        && isset($_COOKIE["cal4-$COURSE-nonce"])
        && $_REQUEST['password'] == hash('sha256', $ADMIN.$_COOKIE["cal4-$COURSE-nonce"])) {
      $_SESSION["cal4-$COURSE"] = sha1($SECRET.$ADMIN.$COURSE.$_COOKIE["cal4-$COURSE-nonce"].session_id());
      cal4_setcookie("cal4-$COURSE", sha1($SECRET.$ADMIN.$COURSE.$_COOKIE["cal4-$COURSE-nonce"].session_id()), time()+$LOGON_TIME);
      $_COOKIE["cal4-$COURSE"] = sha1($SECRET.$ADMIN.$COURSE.$_COOKIE["cal4-$COURSE-nonce"].session_id());
    } elseif (isset($_REQUEST['password'])
        && isset($_SESSION["cal4-$COURSE-nonce"])
        && $_REQUEST['password'] == hash('sha256', $ADMIN.$_SESSION["cal4-$COURSE-nonce"])) {
      $_SESSION["cal4-$COURSE"] = sha1($SECRET.$ADMIN.$COURSE.$_SESSION["cal4-$COURSE-nonce"].session_id());
      cal4_setcookie("cal4-$COURSE", sha1($SECRET.$ADMIN.$COURSE.$_COOKIE["cal4-$COURSE-nonce"].session_id()), time()+$LOGON_TIME);
      $_COOKIE["cal4-$COURSE"] = sha1($SECRET.$ADMIN.$COURSE.$_COOKIE["cal4-$COURSE-nonce"].session_id());
    } elseif (isset($_REQUEST['password']) && (isset($_SESSION["cal4-$COURSE"]) || isset($_COOKIE["cal4-$COURSE"]) )) {
      if (isset($_SESSION["cal4-$COURSE"])) {
        unset($_SESSION["cal4-$COURSE"]);
      }
      if (isset($_COOKIE["cal4-$COURSE"])) {
        unset($_COOKIE["cal4-$COURSE"]);
        cal4_setcookie("cal4-$COURSE", '', time()-3600);
      }
    }

    # Verify that authentication code is correct
    if (isset($_COOKIE["cal4-$COURSE"])
        && isset($_COOKIE["cal4-$COURSE-nonce"])
        && $_COOKIE["cal4-$COURSE"]  == sha1($SECRET.$ADMIN.$COURSE.$_COOKIE["cal4-$COURSE-nonce"].session_id())) {
        $INSTRUCTOR = True;
    } elseif (isset($_SESSION["cal4-$COURSE"])
        && $_SESSION["cal4-$COURSE"] == sha1($SECRET.$ADMIN.$COURSE.$_SESSION["cal4-$COURSE-nonce"].session_id())) {
        $INSTRUCTOR = True;
    } else {
      $myNonce = hash('sha256',"{".rand()."-".rand()."}");
      $_SESSION["cal4-$COURSE-nonce"] = $myNonce;
      cal4_setcookie("cal4-$COURSE-nonce", $myNonce, time()+$LOGON_TIME);
      $_COOKIE["cal4-$COURSE-nonce"] = $myNonce;
    }

    # Default to Student User Mode
    if (!isset($INSTRUCTOR)) {
      $INSTRUCTOR = False;
    }
  }

  # Add a record of the visit to this page if $ACCESS_LOG is set
  # Sensitive parameters (passwords, lock codes, editor contents, tokens) are
  # removed before logging.  Keep the log outside of the web root.
  if (isset($ACCESS_LOG) && file_exists($ACCESS_LOG)) {
    $URI = explode("?", $_SERVER['REQUEST_URI'], 2);
    if (isset($URI[1]) && $URI[1] !== '') {
      parse_str($URI[1], $cal4_logq);
      foreach (array('password', 'lock', 'editor', 'csrf') as $cal4_secretparam) {
        if (isset($cal4_logq[$cal4_secretparam])) {
          $cal4_logq[$cal4_secretparam] = 'REMOVED';
        }
      }
      $URI = http_build_query($cal4_logq);
    } else {
      $URI = "home";
    }
    if (isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== '') {
      $cal4_logreal = realpath($ACCESS_LOG);
      $cal4_docroot = realpath($_SERVER['DOCUMENT_ROOT']);
      if ($cal4_logreal !== False && $cal4_docroot !== False && strpos($cal4_logreal, rtrim($cal4_docroot, '/') . '/') === 0) {
        $CONFIG_WARNINGS[] = 'The access log ($ACCESS_LOG) is inside the web root and may be downloadable. Move it outside of the web root.';
      }
    }
    $fp = fopen($ACCESS_LOG, 'a');
    if ($fp) {
      $fpl = date('Ymd-H:i:s'). " " . $_SERVER['REMOTE_ADDR'] . " " . $URI . PHP_EOL;
      fwrite($fp, $fpl);
      fclose($fp);
    }
  }

  # Lock the calendar if requested via lock files
  if (!$INSTRUCTOR && isset($LOCK) && ($LOCK === true || file_exists($LOCK))) {
    if (isset($_REQUEST['event'])) { unset($_REQUEST['event']); }
    if (isset($_REQUEST['type'])) { unset($_REQUEST['type']); }
    if (isset($_REQUEST['key'])) { unset($_REQUEST['key']); }
    if (isset($_REQUEST['show'])) { unset($_REQUEST['show']); }
    $_REQUEST['load'] = 'lock';
  }

  # Get accessibles files located within the various component directories
  # Directories that content files (and symbolic link targets) may live in.
  # By default only the course directory; add more with $ALLOWED_PATHS.
  $cal4_roots = array(realpath('.'));
  if (isset($ALLOWED_PATHS) && is_array($ALLOWED_PATHS)) {
    foreach ($ALLOWED_PATHS as $cal4_ap) {
      $cal4_apr = realpath($cal4_ap);
      if ($cal4_apr !== False) {
        $cal4_roots[] = $cal4_apr;
      }
    }
  }
  $files = get_files(array_keys($COMPONENTS), '.', $virtual, $categories, $SECRET, $cal4_roots);

  # Remove keys from memory, incase something goes wrong...  (such as embedded PHP)
  unset($ADMIN);
  unset($SECRET);

  # Retrieve a list of file=>keys
  $keypairs = build_keypairs($files);

  # Build an array of all of the events (day by day, class or lab by class)
  $events = build_events($files, $categories, $BOX);
  if (!isset($COMBINE)) {
    $COMBINE = array();
  }
  $events_list = calendar_events($events, $YEAR, $MONTH_START, $DAY_START, $WEEKENDS, $MONTH_END, $DOW, $OVERRIDE, $BOX, $COMBINE);

  # Trim the $events_list variable removing any dynamically controlled content
  # as part of the dynamic date security protocol.
  for ($month = 1; $month < 13; $month++) {
    for ($day = 1; $day < 32; $day++) {
      if (   isset($events_list[$month][$day])
          && isset($events_list[$month][$day]['event'])
          && isset($events_list[$month][$day]['event']['box'])) {
        foreach ($events_list[$month][$day]['event']['box'] as $ibox => $iset) {
          $x = get_file_security($YEAR, $month, $day, $iset['ftype'], $iset['fclass'], $iset['category'], $iset['filename'], $access);
          $events_list[$month][$day]['event']['box'][$ibox]['year'] = $x[1];
          $events_list[$month][$day]['event']['box'][$ibox]['month'] = $x[2];
          $events_list[$month][$day]['event']['box'][$ibox]['day'] = $x[3];
          $events_list[$month][$day]['event']['box'][$ibox]['dynamic'] = $x[4];
          if (!$x[0]) {
            $events_list[$month][$day]['event']['box'][$ibox]['visible'] = False;
            if (!$INSTRUCTOR) {
              unset($events[$iset['ftype']][$iset['fclass']]['box'][$iset['category']]);
              unset($events_list[$month][$day]['event']['box'][$ibox]);
            }
          }
        }
      }
      # Handle Dynamic [COMBINED] Content
      if (   isset($events_list[$month][$day])
          && isset($events_list[$month][$day]['combine'])) {
        foreach ($events_list[$month][$day]['combine'] as $outerbox => $outerset) {
          if (   isset($outerset['event'])
              && isset($outerset['event']['box'])) {
            foreach($events_list[$month][$day]['combine'][$outerbox]['event']['box'] as $ibox => $iset) {
              $x = get_file_security($YEAR, $month, $day, $iset['ftype'], $iset['fclass'], $iset['category'], $iset['filename'], $access);
              $events_list[$month][$day]['combine'][$outerbox]['event']['box'][$ibox]['year'] = $x[1];
              $events_list[$month][$day]['combine'][$outerbox]['event']['box'][$ibox]['month'] = $x[2];
              $events_list[$month][$day]['combine'][$outerbox]['event']['box'][$ibox]['day'] = $x[3];
              $events_list[$month][$day]['combine'][$outerbox]['event']['box'][$ibox]['dynamic'] = $x[4];
              if (!$x[0]) {
                $events_list[$month][$day]['combine'][$outerbox]['event']['box'][$ibox]['visible'] = False;
                if (!$INSTRUCTOR) {
                  unset($events[$iset['ftype']][$iset['fclass']]['box'][$iset['category']]);
                  unset($events_list[$month][$day]['combine'][$outerbox]['event']['box'][$ibox]);
                }
              }
            }
          }
        }
      }
    }
  }

  # Events that are not placed on any calendar day have no date to apply the
  # access rules against, so students can not see any of their files.
  if (!$INSTRUCTOR) {
    foreach ($events as $etype => $elist) {
      foreach ($elist as $enum => $edata) {
        if (!isset($events_list['map'][$etype][$enum])) {
          $events[$etype][$enum]['box'] = array();
        }
      }
    }
  }

  # Retrieve the other files in the specific lecture
  $other = array();
  if (isset($_REQUEST['event']) && isset($_REQUEST['type']) && isset($events[$_REQUEST['type']])) {
    $other_names = array_keys($files[$_REQUEST['type']]);
    if (isset($other_names[$_REQUEST['event']-1])) {
      $other = $files[$_REQUEST['type']][$other_names[$_REQUEST['event']-1]];
    }
    if (isset($events_list['map'][$_REQUEST['type']][$_REQUEST['event']])) {
      $month = $events_list['map'][$_REQUEST['type']][$_REQUEST['event']][0];
      $day = $events_list['map'][$_REQUEST['type']][$_REQUEST['event']][1];
    } else {
      # Unmapped event: instructors see it (rules evaluated as of Dec 31),
      # students see nothing.
      $month=12;
      $day=31;
      if (!$INSTRUCTOR) {
        $other = array();
      }
    }
    # Verify Security of other files...
    foreach($other as $ibox => $iset) {
      //  function get_file_security($YEAR, $month, $day, $l0, $l1, $cate, $filename, $access) {
      $x = get_file_security($YEAR, $month, $day, $_REQUEST['type'], $_REQUEST['event'], $iset['category'], $iset['filename'], $access);
      $other[$ibox]['year'] = $x[1];
      $other[$ibox]['month'] = $x[2];
      $other[$ibox]['day'] = $x[3];
      $other[$ibox]['dynamic'] = $x[4];
      if (!$x[0]) {
        $other[$ibox]['visible'] = False;
        if (!$INSTRUCTOR) {
          unset($other[$ibox]);
        }
      }
    }
  }

  # Was a specific event requested, if so process the html and;
  #  - embed any local images
  #  - remove all <inst> tags if not the instructor
  #  - remove all <student> tags if answers were not requested
  # Place the results in $contents
  $contents = '';
  $actual = '';
  $find_student = False;
  $find_instructor = False;
  $navbar_display = "";
  $file_type = '?';

  # Decide what to show if coming to the main page without anything selected
  if (!isset($_REQUEST['event']) && !isset($_REQUEST['type']) && !isset($_REQUEST['load']) && !isset($_REQUEST['show']) && !isset($_REQUEST['key'])) {
    if ($DEFAULT_TODAYS_LECTURE) {
      $today = getdate();
      $today_mon = $today['mon'];
      $today_day = $today['mday'];
      if (isset($events_list[$today_mon][$today_day]['event']['box']['title'])) {
        if (isset($events_list[$today_mon][$today_day]['event']['box']['title']['type']) && in_array($events_list[$today_mon][$today_day]['event']['box']['title']['type'], array('html', 'md'), True)) {
          $_REQUEST['type'] = $events_list[$today_mon][$today_day]['type'];
          $_REQUEST['event'] = $events_list[$today_mon][$today_day]['type_num'];
        }
      } else {
        $_REQUEST['load'] = 'home';
      }
    } else {
      $_REQUEST['load'] = 'home';
    }
  }

  # Check to see if event and type were provided and if they are valid
  if (isset($_REQUEST['event']) && isset($_REQUEST['type']) && isset($events[$_REQUEST['type']])) {
    $file_type = '?';
    $ext = '?';
    # If a valid keypair is provided use that file as the source
    if (isset($_REQUEST['key']) && isset($keypairs[$_REQUEST['key']])) {
      foreach ($other as $ofile => $odata) {
        if ($_REQUEST['key'] == $odata['key']) {
          $actual = $keypairs[$_REQUEST['key']];
          $ext = pathinfo($actual, PATHINFO_EXTENSION);
        }
      }
    } elseif (isset($events[$_REQUEST['type']][$_REQUEST['event']]['box']['title']['actual']) && isset($events[$_REQUEST['type']][$_REQUEST['event']]['box']['title']['type'])) {
      $actual = $events[$_REQUEST['type']][$_REQUEST['event']]['box']['title']['actual'];
      $file_type = $events[$_REQUEST['type']][$_REQUEST['event']]['box']['title']['type'];
      $ext = pathinfo($actual, PATHINFO_EXTENSION);
      # Access rules are enforced when a file is served: students only get the
      # title file if it passed the security check for this event.
      $title_name = $events[$_REQUEST['type']][$_REQUEST['event']]['box']['title']['filename'];
      if (!$INSTRUCTOR && !isset($other[$title_name])) {
        $actual = '';
        $file_type = '?';
        $ext = '?';
      }
    }

    # If the source is not html (or markdown), provide the unprocessed file
    $is_page = in_array($file_type, array('html', 'md'), True) || in_array(strtolower($ext), array('html', 'htm', 'md'), True);
    if (!$is_page && $actual != '' && !isset($_REQUEST['navbaronly'])) {
      provide_file($actual);
      die;
    } elseif ($ext == 'htm') {
      $_REQUEST['nocss'] = 'yes';
    }

    # Set the navbar title to the title of this lecture
    if (isset($events[$_REQUEST['type']][$_REQUEST['event']]['name'])) {
      $navbar_display = $events[$_REQUEST['type']][$_REQUEST['event']]['name'];
    }

    # Retrieve the contents of this file (Markdown is converted to html here,
    # so all of the tag processing below applies to it as well)
    if ($actual != '') {
      $contents = file_get_contents($actual);
      $contents_clean = $contents;
      $actual_file = $actual;
      if (cal4_is_markdown($actual)) {
        $contents = '<div class="cal4-markdown">' . PHP_EOL . cal4_markdown($contents, $MARKDOWN_MENU_LEVELS) . PHP_EOL . '</div>';
      }
    }
  }

  # Check to see if a web page is requested
  # This will provide .html (or .pdf) files in the root directory, but only for
  # pages on the allow-list.  Add your own pages with $LOAD_PAGES in calendar.php.
  if (isset($_REQUEST['load'])) {
    $cal4_load_pages = array('home', 'policy', 'resources', 'error', 'lock');
    if (isset($LOAD_PAGES) && is_array($LOAD_PAGES)) {
      $cal4_load_pages = array_merge($cal4_load_pages, $LOAD_PAGES);
    }
    if ($INSTRUCTOR) {
      $cal4_load_pages[] = 'instructor';
    }
    $cal4_load = (string)$_REQUEST['load'];
    if (preg_match('/^[A-Za-z0-9_-]+$/', $cal4_load) && in_array($cal4_load, $cal4_load_pages, True)) {
      if (file_exists($cal4_load.'.pdf')) {
        provide_file($cal4_load.'.pdf');
        die;
      }
      if (file_exists('calendar/'.$cal4_load.'.html')) {
        $contents = file_get_contents('calendar/'.$cal4_load.'.html');
      } elseif (file_exists($cal4_load.'.html')) {
        $contents = file_get_contents($cal4_load.'.html');
      } elseif (file_exists($cal4_load.'.md')) {
        $contents = '<div class="cal4-markdown">' . PHP_EOL . cal4_markdown(file_get_contents($cal4_load.'.md'), $MARKDOWN_MENU_LEVELS) . PHP_EOL . '</div>';
      }
    }
  }

  # Build default replace values
  if (!isset($PAGE_MODIFY['event']) && isset($_REQUEST['event'])) {
    $PAGE_MODIFY['event'] = $_REQUEST['event'];
  }
  if (!isset($PAGE_MODIFY['zevent']) && isset($_REQUEST['event'])) {
    $PAGE_MODIFY['zevent'] = str_pad($_REQUEST['event'],2,"0",STR_PAD_LEFT);
  }
  if (!isset($PAGE_MODIFY['type']) && isset($_REQUEST['type'])) {
    $PAGE_MODIFY['type'] = $_REQUEST['type'];
  }
  if (!isset($PAGE_MODIFY['ctype']) && isset($_REQUEST['type'])) {
    $PAGE_MODIFY['ctype'] = ucfirst($_REQUEST['type']);
  }
  if (!isset($PAGE_MODIFY['course']) && isset($COURSE)) {
    $PAGE_MODIFY['course'] = $COURSE;
  }
  if (!isset($PAGE_MODIFY['coursename']) && isset($COURSENAME)) {
    $PAGE_MODIFY['coursename'] = $COURSENAME;
  }
  if (!isset($PAGE_MODIFY['coursenamefull']) && isset($COURSENAMEFULL)) {
    $PAGE_MODIFY['coursenamefull'] = $COURSENAMEFULL;
  }
  if (!isset($PAGE_MODIFY['title']) && isset($navbar_display)) {
    $PAGE_MODIFY['title'] = $navbar_display;
  }
  if (!isset($PAGE_MODIFY['access_file']) && isset($ACCESS_FILE)) {
    $PAGE_MODIFY['access_file'] = $ACCESS_FILE;
  }
  if (!isset($PAGE_MODIFY['class_file']) && isset($CLASS_FILE)) {
    $PAGE_MODIFY['class_file'] = $CLASS_FILE;
  }

  # Allow multiple levels of loading files (5 levels by default)
  # I should probably make this loop exit if nothing changes...
  # Or continue until nothing changes (and a max...)
  for ($loop_count = 0; $loop_count < 5; $loop_count++) {

    # Replace image <img src>, <source src= />,  and anchor <a href> links with the key'd version of the file
    foreach ($other as $fn => $value) {
      $link = cal4_file_link($value['key'], isset($_REQUEST['type']) ? $_REQUEST['type'] : null, isset($_REQUEST['event']) ? $_REQUEST['event'] : null);
      $contents = str_ireplace('<img src="'.$fn.'"', '<img src="'.$link.'"', $contents);
      $contents = str_ireplace("<img src='".$fn."'", "<img src='".$link."'", $contents);
      $contents = str_ireplace('<source src="'.$fn.'"', '<source src="'.$link.'"', $contents);
      $contents = str_ireplace("<source src='".$fn."'", "<source src='".$link."'", $contents);
      $contents = str_ireplace('<a href="'.$fn.'"', '<a href="'.$link.'"', $contents);
      $contents = str_ireplace("<a href='".$fn."'", "<a href='".$link."'", $contents);
      $contents = str_ireplace('<showlink src="'.$fn.'">', $link, $contents);
      $contents = str_ireplace("<showlink src='".$fn."'>", $link, $contents);
    }

    # Search for <replace value=""> tags and directly copy the the information
    # from $PAGE_MODIFY into them.
    preg_match_all('/<replace[^>]+>/i', $contents, $injects);
    foreach($injects[0] as $row => $inject_tag) {
      preg_match_all('/value=(\x27[^\x27]*\x27|\x22[^\x22]*\x22)/i',$inject_tag, $tag_src);
      $tag_src = substr($tag_src[1][0],1,-1);
      if ($tag_src != "" && isset($PAGE_MODIFY[$tag_src])) {
        $inject_data = htmlspecialchars((string)$PAGE_MODIFY[$tag_src], ENT_QUOTES | ENT_HTML5, 'UTF-8', False);
        $contents = str_ireplace('<replace value="'.$tag_src.'">', $inject_data, $contents);
        $contents = str_ireplace("<replace value='$tag_src'>", $inject_data, $contents);
        $contents = str_ireplace('<replace value="'.$tag_src.'"/>', $inject_data, $contents);
        $contents = str_ireplace("<replace value='$tag_src'"."/>", $inject_data, $contents);
        $contents = str_ireplace('<replace value="'.$tag_src.'" />', $inject_data, $contents);
        $contents = str_ireplace("<replace value='$tag_src'"." />", $inject_data, $contents);
      }
    }

    # Search for <inject src=""> tags and directly copy the contents into this tag area
    preg_match_all('/<inject[^>]+>/i', $contents, $injects);
    foreach($injects[0] as $row => $inject_tag) {
      preg_match_all('/src=(\x27[^\x27]*\x27|\x22[^\x22]*\x22)/i',$inject_tag, $tag_src);
      $tag_src = substr($tag_src[1][0],1,-1);
      if ($tag_src != "" && isset($other[$tag_src])) {
        $inject_data = file_get_contents($other[$tag_src]['actual']);
        if (cal4_is_markdown($other[$tag_src]['actual'])) {
          $inject_data = cal4_markdown($inject_data, $MARKDOWN_MENU_LEVELS);
        }
        $inject_src[] = $tag_src;
        $contents = str_ireplace('<inject src="'.$tag_src.'">', $inject_data, $contents);
        $contents = str_ireplace("<inject src='$tag_src'>", $inject_data, $contents);
        $contents = str_ireplace('<inject src="'.$tag_src.'"/>', $inject_data, $contents);
        $contents = str_ireplace("<inject src='$tag_src'"."/>", $inject_data, $contents);
        $contents = str_ireplace('<inject src="'.$tag_src.'" />', $inject_data, $contents);
        $contents = str_ireplace("<inject src='$tag_src'"." />", $inject_data, $contents);
      }
    }

    # Search for <codeinject src=""> tags and directly copy the contents into this tag area
    preg_match_all('/<codeinject[^>]+>/i', $contents, $injects);
    foreach($injects[0] as $row => $inject_tag) {
      preg_match_all('/src=(\x27[^\x27]*\x27|\x22[^\x22]*\x22)/i',$inject_tag, $tag_src);
      $tag_src = substr($tag_src[1][0],1,-1);
      if ($tag_src != "" && isset($other[$tag_src])) {
        $inject_data = file_get_contents($other[$tag_src]['actual']);
        $inject_data = str_ireplace('&', '&amp;', $inject_data);
        $inject_data = str_ireplace('<', '&lt;', $inject_data);
        $inject_data = str_ireplace('>', '&gt;', $inject_data);
        $contents = str_ireplace('<codeinject src="'.$tag_src.'">', $inject_data, $contents);
        $contents = str_ireplace("<codeinject src='$tag_src'>", $inject_data, $contents);
        $contents = str_ireplace('<codeinject src="'.$tag_src.'"/>', $inject_data, $contents);
        $contents = str_ireplace("<codeinject src='$tag_src'"."/>", $inject_data, $contents);
        $contents = str_ireplace('<codeinject src="'.$tag_src.'" />', $inject_data, $contents);
        $contents = str_ireplace("<codeinject src='$tag_src'"." />", $inject_data, $contents);
      }
    }
  }

  # Addition to allow automatic reveal of the shown in class examples, etc AFTER the days
  # classes have occured.
  # First we check the current year vs the course year - if greater no reason to continue
  # Then we check the current month vs the requested asset month - if greater no reason to continue.
  # The we check the current day vs the requested asset day - if greater no reason to continue.
  # Lastly we check the current hour vs the set reveal hour - if greater no reason to continue.
  # If all these fail, then we strip out the revealed content.
  # Uses the new tag <postmeeting>
  # The time to reveal is from the $REVEALHOUR variable located in calendar.php

  if (!$INSTRUCTOR && isset($REVEALHOUR)) {
    $cal4_reveal = False;
    if (isset($_REQUEST['type']) && isset($_REQUEST['event'])
        && isset($events_list['map'][$_REQUEST['type']][$_REQUEST['event']])) {
      $cal4_rmon = $events_list['map'][$_REQUEST['type']][$_REQUEST['event']][0];
      $cal4_rday = $events_list['map'][$_REQUEST['type']][$_REQUEST['event']][1];
      $cal4_reveal = (time() >= mktime(intval($REVEALHOUR), 0, 0, $cal4_rmon, $cal4_rday, $YEAR));
    }
    if ($cal4_reveal) {
      # show the content
      $contents = preg_replace('/<postmeeting[^>]*>([\s\S]*?)<\/postmeeting[^>]*>/', '\1', $contents);
    } else {
      # DO NOT show the content
      $contents = preg_replace('/<postmeeting[^>]*>([\s\S]*?)<\/postmeeting[^>]*>/', '', $contents);
    }
  }

  # Remove the contents of <inst>...</inst> tags if not logged on
  if (!$INSTRUCTOR) {
    $contents = preg_replace('/<inst[^>]*>([\s\S]*?)<\/inst[^>]*>/', '', $contents);
  }
  else {
    $contents = preg_replace('/<inst[^>]*>([\s\S]*?)<\/inst[^>]*>/', '\1', $contents);
  }

  # Remove the contents of <host server="">...</host> tags if not on the
  # specified server (use $_SERVER['SERVER_NAME'])
  preg_match_all('/<host[^>]+>/i', $contents, $injects);
  foreach($injects[0] as $row => $inject_tag) {
    preg_match_all('/server=(\x27[^\x27]*\x27|\x22[^\x22]*\x22)/i',$inject_tag, $tag_src);
    if (!isset($tag_src[1][0])) {
      continue;
    }
    $codes = explode(',', substr($tag_src[1][0], 1, -1));
    foreach($codes as $rowi => $thiscode) {
      if ((isset($_SERVER['SERVER_NAME']) && $_SERVER['SERVER_NAME'] == trim($thiscode)) ||
          (isset($_SERVER['SERVER_ADDR']) && $_SERVER['SERVER_ADDR'] == trim($thiscode))) {
        $contents = str_ireplace($injects[0][$row], '<unhost>', $contents);
      }
    }
  }
  $contents = preg_replace('/<host[^>]*>([\s\S]*?)<\/host[^>]*>/', '', $contents);

  # Remove all <lock></lock> tags if no code provided
  if (isset($_REQUEST['lock'])) {
    $_SESSION['lock'] = $_REQUEST['lock'];
  }
  if (isset($_SESSION['lock'])) {
    preg_match_all('/<lock[^>]+>/i', $contents, $injects);
    foreach($injects[0] as $row => $inject_tag) {
      preg_match_all('/code=(\x27[^\x27]*\x27|\x22[^\x22]*\x22)/i',$inject_tag, $tag_src);
      if (!isset($tag_src[1][0])) {
        continue;
      }
      $codes = explode(',', substr($tag_src[1][0], 1, -1));
      foreach($codes as $rowi => $thiscode) {
        if (is_string($_SESSION['lock']) && trim($thiscode) !== '' && hash_equals(trim($thiscode), $_SESSION['lock'])) {
          $contents = str_ireplace($injects[0][$row], '<unlock>', $contents);
        }
      }
    }
  }
  if (!$INSTRUCTOR) {
    $unlocker = '
<div class="jumbotron">
<form method=POST>
<label for="input-group"><span style="color:blue">Enter Password to See Hidden Content</span></label>
<div class="input-group">
  <input type="password" class="form-control" placeholder="" name="lock" id="lock">
  <div class="input-group-btn">
    <button class="btn btn-default" type="submit"><i class="glyphicon glyphicon-lock"></i></button>
  </div>
</div>
</form>
</div>';
    $contents = preg_replace('/<lock[^>]*>([\s\S]*?)<\/lock[^>]*>/', $unlocker, $contents);
  }

  # Search for student and instructor tags
  $find_student = (strpos($contents, '<student>') !== False);
  $find_instructor = (strpos($contents, '<inst>') !== False);

  # Remove the contents of <student>...</student> tags if answers are to be hidden
  if (!isset($_REQUEST['answers'])) {
    $contents = preg_replace('/<student[^>]*>([\s\S]*?)<\/student[^>]*>/', '', $contents);
  }

  # Remove the student tag - in case it is used in problem sets
  $contents = str_replace('<student>','',$contents);
  $contents = str_replace('</student>','',$contents);

  # Remove TABs, they are evil!
  $contents = str_ireplace("\t", '  ', $contents);

  # Search for editable code via ACE
  # Example:  <ace name="ace1" mode="html_elixir" height="48px" theme="tomorrow"></ace>
  # Defaults show above
  # Additional flags: nolinenumbers="true" readonly="true"
  $ace_scripts = "";
  preg_match_all("#<\s*?ace\b[^>]*>(.*?)</ace\b[^>]*>#s", $contents, $ace);
  for ($i = 0; $i < count($ace[0]); $i++) {
    # Read one attribute value from the <ace> tag, or return the default.
    $ace_attr = function ($attr, $default) use ($ace, $i) {
      if (preg_match('/' . $attr . '=("[^"]*")/i', $ace[0][$i], $ace_match)) {
        return substr($ace_match[1], 1, -1);
      }
      return $default;
    };
    $ace_readonly_flag = ($ace_attr('readonly', '') == 'true') ? '' : '//';
    $ace_nolinenumbers_flag = ($ace_attr('nolinenumbers', '') == 'true') ? '' : '//'; #default is to have line numbers
    $ace_textwrap_flag = ($ace_attr('textwrap', '') == 'true') ? '' : '//';
    $ace_name = $ace_attr('name', "ace$i");
    $ace_mode = $ace_attr('mode', "html_elixir");
    $ace_height = $ace_attr('height', "48px");
    $ace_theme = $ace_attr('theme', "tomorrow");
    $ace_magic = $ace[1][$i];
    $ace_magic = str_ireplace('<', '&lt;', $ace_magic);
    $ace_magic = str_ireplace('>', '&gt;', $ace_magic);
    $ace_magic = "<div id=\"$ace_name\">$ace_magic</div>";
    $contents = str_ireplace($ace[0][$i], $ace_magic, $contents);
    $ace_scripts .= <<<EOF

<script type="text/javascript">
    var $ace_name = ace.edit("$ace_name");
    $ace_name.setTheme("ace/theme/$ace_theme");
    $ace_name.getSession().setMode("ace/mode/$ace_mode");
    $ace_textwrap_flag$ace_name.getSession().setUseWrapMode(true);
    $ace_readonly_flag$ace_name.setReadOnly(true);
    $ace_nolinenumbers_flag$ace_name.renderer.setShowGutter(false);
    function showHTMLInIFrame$ace_name() {
        $('#ace_$ace_name').html($ace_name.getValue());
    }
    $ace_name.on("input", showHTMLInIFrame$ace_name);
    showHTMLInIFrame$ace_name();
</script>
<style type="text/css">
   #$ace_name { height: $ace_height; }
</style>
EOF;
  }
  $contents = $contents . $ace_scripts;

  # Search for <url value="" default=""> tags,
  # and directly copy into the contents
  # of the associated value from $_REQUEST[value]
  preg_match_all('/<url[^>]+>/i', $contents, $injects);
  foreach($injects[0] as $row => $inject_tag) {
    # The value is remembered in the session, so it carries over to later pages.
    preg_match_all('/value=("[^"]*")/i',$inject_tag, $tag_src);
    preg_match_all('/default=("[^"]*")/i',$inject_tag, $tag_default);
    $tag_src = isset($tag_src[1][0]) ? substr($tag_src[1][0],1,-1) : '';
    $tag_default = isset($tag_default[1][0]) ? substr($tag_default[1][0],1,-1) : '';
    if ($tag_src != "" && isset($_REQUEST[$tag_src]) && is_string($_REQUEST[$tag_src])) {
      $inject_data = $_REQUEST[$tag_src];
      $_SESSION['cal4-data-'.$tag_src] = $_REQUEST[$tag_src];
    } elseif ($tag_src != "" && isset($_SESSION['cal4-data-'.$tag_src])) {
      $inject_data = $_SESSION['cal4-data-'.$tag_src];
    } else {
      $inject_data = $tag_default;
    }
    $inject_data = h($inject_data);
    # Replace this exact tag (whatever attribute order or quoting was used).
    $contents = str_replace($inject_tag, $inject_data, $contents);
  }

  ## Find any <a name> tags that are used to define anchors
  ## This will be used to provide menus via the navbar
  #preg_match_all('/<a name=\"(.*?)\"\><\/a>/s', $contents, $navbar_menus);
  # As the <a name> attribute is obsolute in HTML5, we will impose the following:
  #   All the internal page references will be in header tags <h1>...<h6>
  #   The id attribute will be used.
  # Thus, <h2 id="stuff"> would match and get a link created to stuff.
  # 20180604 since removing the <a name= match breaks backwards compatability, adding
  #   this back in
  # 20180605 however, even though it is back in, still would like to notify the instructor
  #   that they are using a depreciated method and should update their content.
  #   Added a message that only appears if($INSTRUCTOR) that tells them which ones are old.
  function findheaders($content){
    $dom = new DOMDocument;
    $dom->recover = true;
    libxml_use_internal_errors(true);
    if ($content == ""){
        return;
    }
    $dom->loadHTML($content);
    $xpath = new DOMXPath($dom);
    $expression = '
    (
        //h1
        |//h2
        |//h3
        |//h4
        |//h5
        |//h6
        |//a
    )';
    $idarray = array();
    $tagnamearray = array();
    $tagvaluearray = array();
    $elements = $xpath->query($expression);
    $oldLinkMode = False;
    $oldLinkModeValue = ''; #variable for holding a found <a name='value' for later...
    $oldLinkModeMessage = '<ol>'; #variable for holding the generated warning messages
    foreach ($elements as $index => $element) {
        if ($element->attributes->length > 0){ #check for there being attributes
            foreach ($element->attributes as $attribute) {
                if($attribute->name == 'name'){ #check for the older <a name= stuff
                    #This means we found an older style tag, so we when figure we can assume
                    #  pretty much nothing about the structure of where the id tags show up.
                    #  This is why we check for the older style tag first, then set a flag.
                    #  We then log each occurence of the old style headers to display later.
                    $oldLinkMode = True; #once set to True never change
                    $oldLinkModeValue = $attribute->value;
                    $oldLinkModeMessage = $oldLinkModeMessage . '<li>Tag type: <b>' . $element->tagName . '</b> &nbsp; Tag value: <b>' . $attribute->value . '</b></li>';
                    array_push($tagnamearray, $element->tagName);
                    array_push($tagvaluearray, $attribute->value);
                }
                else {
                    #20180614 Burnham
                    # Refactored this code to clean it up.
                    # This was initiated due to finding additional entries in the navbar menu for a class
                    # the reason for which was determined to be <a> tags with an id set. While documenting the
                    # code to resolve this issue, another potential logic issue was found.
                    # When it comes down to it, the only time we want a link in the navbar menu is when there
                    # is an id in a header tag or a name in an anchor tag.

                    # Code now does the following:
                    #   1) Checks to see if the attribute being looked at is "id". Thus name is ignored
                    #      (well, not really, it is handled in the if branch of this if..else statement),
                    #      class is ignored as well as any "data-" type entries or anything else.
                    #      TL:DR --> if the attribute name is not "id" we dont care.
                    #   2) Next we check if the id's value is the same as the most recent name's value.
                    #      This way we dont add duplicate entries for links in the navbar.
                    #   3) Then we determine if the tag type is <a>. We do this because if an anchor tag
                    #      exists in the document with an id value then it will get added to the navbar list.
                    #      Also, if an <a> tag exists with a name= attribute AND an id= attribute but the
                    #      values of those two are not the same then another entry will be added to the navbar
                    #      list, which is not desired behavior. Only <h[1-6]> tags with id's should be added.
                    #      TL:DR --> if attribute type is anchor and it has an id attribute we don't care.
                    #   4) Finally, if it gets this far, it is a header tag with an id. We add it to the
                    #      navbar list.
                    if ($attribute->name == 'id'){
                        if($attribute->value == $oldLinkModeValue && $oldLinkMode == True){
                            #we check the value if in old link mode and if it matches then we proceed to just
                            #skip this entry to not have duplicate links in the drop down menu.
                            continue 1; #We were implicitly doing this anyway so might as well be explicit.
                        }
                        elseif ($element->tagName == 'a'){
                            #We dont care about any anchor tags with id values.
                            continue 1;
                        }
                        else {
                            array_push($tagnamearray, $element->tagName);
                            array_push($tagvaluearray, $attribute->value);
                        }
                    }
                    else {
                        # dont care! The attribute is not an id.
                        continue 1;
                    }
                }
            } #end iteration through all the attributes of an element.
        } #end check if there are attributes to an element.
    } #end iteration through found elements in document.
    if ($oldLinkMode == True){
        #add warning message content and close the warning message tags
        $oldLinkModeMessage = '<div style="color:red;">WARNING: You are using the <em>old</em> style of internal document links.<br>Here is a list of the ones found in this document:' . $oldLinkModeMessage . '</ol></div>';
    }
    array_push($idarray, $tagnamearray, $tagvaluearray);
    return array($idarray,$oldLinkMode,$oldLinkModeMessage);
  } #end function findheaders

  #Then we call this new function
  list($navbar_menus,$headerWarningFlag,$headerWarningMessage) = findheaders($contents);
  #Then check if we should add the warningMessage
  if ($headerWarningFlag && $INSTRUCTOR && !isset($IGNORE_A_TAG_WARNING)){ #if so, add it at the top of the content
      $contents = $headerWarningMessage . $contents;
  }


  # What types of courses do you want to highlight
  # This is overridable in the calendar
  if (!isset($NAVBAR_DROPDOWNS)) {
    $NAVBAR_DROPDOWNS = array('class' => 'glyphicon-blackboard',
                              'lab' => 'glyphicon-knight');
  }

  # Show the navbar
  if (isset($_REQUEST['nocss'])) {
    # Do not load any css (debugging mode)
  } elseif (!isset($_REQUEST['print'])) {
    require_once('calendar/calendar_navbar.php');
  } else {
    require_once('calendar/calendar_css.php');
  }

  # Provide the content of the .html file
  echo '<!-- Begin providing the contents of the page -->'.PHP_EOL;
  echo '<div class="container">'.PHP_EOL;

  # Show configuration problems to the instructor
  if ($INSTRUCTOR && !empty($CONFIG_WARNINGS)) {
    echo '<div class="cal4-config-warnings" style="color:red;"><b>Configuration warnings (only visible to the instructor):</b><ul>';
    foreach ($CONFIG_WARNINGS as $cal4_w) {
      echo '<li>' . h($cal4_w) . '</li>';
    }
    echo '</ul></div>'.PHP_EOL;
  }

  # If requested only the navbar (so that contents can be revealed)
  if (isset($_REQUEST['navbaronly']) && !isset($_REQUEST['show'])) {
    $_REQUEST['show'] = 'calendar_file_selector';
  }

  # If Edit mode was requested, lets view the file (instructor only, html only).
  if ($INSTRUCTOR && isset($_REQUEST['edit']) && isset($_REQUEST['event']) && isset($_REQUEST['type']) && isset($_REQUEST['key'])) {
    $_REQUEST['show'] = 'calendar_file_editor';
    $_REQUEST['navbaronly'] = true;
  }

  # If one of the calendar pages was requested, run it.  Only pages on this
  # list can be included, and some of them are limited to the instructor.
  if (isset($_REQUEST['show'])) {
    $cal4_show_pages = array('calendar_display' => False,
                             'calendar_display_alt' => False,
                             'calendar_display_dateless' => False,
                             'calendar_search' => False,
                             'calendar_keyword' => False,
                             'calendar_file_selector' => True,
                             'calendar_file_editor' => True);
    # A custom calendar view named in $CALENDAR_TOOL is also allowed.
    if (isset($CALENDAR_TOOL) && is_string($CALENDAR_TOOL) && preg_match('/^[A-Za-z0-9_-]+$/', $CALENDAR_TOOL)
        && file_exists('calendar/' . $CALENDAR_TOOL . '.php') && !isset($cal4_show_pages[$CALENDAR_TOOL])) {
      $cal4_show_pages[$CALENDAR_TOOL] = False;
    }
    $cal4_show = (string)$_REQUEST['show'];
    if (isset($cal4_show_pages[$cal4_show]) && ($INSTRUCTOR || !$cal4_show_pages[$cal4_show])) {
      require_once('calendar/' . $cal4_show . '.php');
    }
  }

  # Lets present the contents of the page to the user
  # Also find any <calphp src="phpfile.php"> tags and
  # run the associated php code!
  if (!isset($_REQUEST['navbaronly'])) {
    do {
      preg_match_all('/<calphp[^>]+>/i', $contents, $nc, PREG_OFFSET_CAPTURE);
      if (count($nc[0]) == 0) {
        echo $contents;
        $contents = '';
      } else {
        echo substr($contents, 0, $nc[0][0][1]);
        preg_match_all('/src=("[^"]*")/i',$nc[0][0][0], $tag_src);
        $tag_src = substr($tag_src[1][0],1,-1);
        if (isset($other[$tag_src])) {
          $curdir = getcwd();
          $runphp = realpath($other[$tag_src]['actual']);
          chdir(ancient(pathinfo(realpath($other[$tag_src]['actual'])), 'dirname'));
          include($runphp);
          chdir($curdir);
        }
        $contents = substr($contents, $nc[0][0][1]+strlen($nc[0][0][0]));
      }
    } while (strlen($contents) > 0);
  }

  # Debugging if desired (requires $DEBUG_MODE = True in calendar.php)
  if ($INSTRUCTOR && isset($DEBUG_MODE) && $DEBUG_MODE === True && isset($_REQUEST['debug'])) {
    print "<pre><code>";
    echo "INSTRUCTOR=$INSTRUCTOR <br>";
    echo "\$other=";
    print_r($other);
    echo "\$_SESSION=";
    print_r($_SESSION);
    echo "\$events=";
    print_r($events);
    echo "\$events_list=";
    print_r($events_list);
    echo "\$keypairs=";
    print_r($keypairs);
    echo "\$files=";
    print_r($files);
    echo "\$categories=";
    print_r($categories);
    echo "\$_REQUEST=";
    print_r($_REQUEST);
    echo "\$access=";
    print_r($access);
    if (isset($inject_src)) {
      echo "\$inject_src=";
      print_r($inject_src);
    }
    echo "\$COMPONENTS=";
    print_r($COMPONENTS);
    echo "</code></pre>";
  }

  echo "</div> <!-- /container --></body></html>";

?>
