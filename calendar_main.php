<?php

  # Calendar Version 4.4, J. Kenney 2015-2023
  define('CALENDAR_VERSION', '4.4.20230226');

  # Determine what directories should be scanned for files.
  $COMPONENTS = array();
  foreach ($DOW as $i => $type) {
    $type = str_replace(' ', '-', strtolower($type));
    $COMPONENTS[$type] = $type;
  }
  if (isset($OVERRIDE)) {
    foreach ($OVERRIDE as $i => $m) {
      foreach ($m as $d => $type) {
        $type = str_replace(' ', '-', strtolower($type));
        $COMPONENTS[$type] = $type;
      }
    }
  }
  if (isset($COMBINE)) {
    foreach ($COMBINE as $type => $cset) {
      $COMPONENTS[$type] = $type;
      foreach ($cset as $day => $inside) {
        foreach ($inside as $ntype) {
          $COMPONENTS[$ntype] = $ntype;
        }
      }
    }
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
      foreach($vdata as $line) {
        $line = trim($line);
        $line = preg_split('/\s+/', $line);
        if (count($line) == 4) {
          if (!isset($virtual[$line[2]][$line[3]])) {
            $virtual[$line[2]][$line[3]] = array();
          }
          $virtual[$line[2]][$line[3]][] = array($line[0], $line[1]);
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
      foreach($vdata as $line) {
        $line = trim($line);
        $line = preg_split('/\s+/', $line);
        if (count($line) == 2 && (strpos($line[1], '-') === 0 || strpos($line[1], '+') === 0)) {
          $access[$line[0]] = array('month'=>1, 'day'=>1, 'year'=>1, 'dynamic'=> $line[1]);
        } elseif (count($line) == 3) {
          $access[$line[0]] = array('month'=> intval($line[1]), 'day'=> intval($line[2]), 'year'=>$YEAR, 'dynamic'=>'');
        } elseif (count($line) == 4) {
          $access[$line[0]] = array('month'=> intval($line[1]), 'day'=> intval($line[2]), 'year'=>intval($line[3]), 'dynamic'=>'');
        }
      }
    }
  }

  # Validate a filename to see if is using the legacy security format
  # which was filename.month.day.extension,
  # This is a rather old concept that should be removed!!!!
  # Returns array(VisibleInGeneral, VisibleOnCalendar, Year, Month, Day)
  function validate_file($instructor, $filename, $filewithpath, $access) {
    $dynamic = '';
    if (is_dir($filewithpath)) {
      return array(False, False, 1, 1, 1, '', $dynamic);
    }
    $today = getdate();
    $open_year = $today['year'];
    $open_month = 1;
    $open_day = 1;
    $cat_file = $filename;
    if (True) {
      $cp = array_reverse(explode('.', basename($filewithpath)));
      if (count($cp) > 3 && is_numeric($cp[1]) && is_numeric($cp[2])) {
        $open_day = intval($cp[1]);
        $open_month = intval($cp[2]);
        $cat_file = $cp[3] . '.' . $cp[0];
      }
    }
    return array(True, True, $open_year, $open_month, $open_day, $cat_file, $dynamic);
  }

  # Determine what type of category the file is
  # types = html, link, src, txt, powerpoint, pdf (aka the extension!)
  function categorize_file($instructor, $filename, $filewithpath, $filereduced, $categories) {
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
  function get_files($instructor=False, $sources=array('class', 'lab', 'project', 'exam', 'review', 'capstone', 'continued-lab'), $file_path = '.', $access=array(), $virtual=array(), $categories=array(), $secret='really') {
    $results = array();
    $basedir = scandir($file_path);
    foreach ($sources as $i => $l0) {
      if (in_array($l0, $basedir) && is_dir($file_path . '/' . $l0)) {
        $level1 = scandir($file_path . '/' . $l0);
        sort($level1);
        foreach ($level1 as $i => $l1) {
          if (substr($l1, 0, 1) != '.' && is_dir($file_path . '/' . $l0 . '/' . $l1)) {
            // The following code allows the system recursively look through the Directory
            // structure within a class, and the system can now process RELATIVE links
            // that go into those subordinate directories
            $level2 = array();
            try {
              $Iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($file_path . '/' . $l0 . '/' . $l1),
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD);
              foreach($Iterator as $name => $object) {
                $name = explode("/", $name);
                unset($name[2]);
                unset($name[1]);
                unset($name[0]);
                $name = implode("/", $name);
                $level2[] = $name;
              }
            } catch (Exception $e) {
            // Unable to recurse, so don't bother...
            }
            // $level2 = scandir($file_path . '/' . $l0 . '/' . $l1);
            $results[$l0][$l1] = array();
            sort($level2);
            foreach ($level2 as $i => $l2) {
              $key = sha1($secret.$l0.$l1.$l2);
              if (substr($l2, 0, 1) != '.' && ancient(validate_file($instructor, $l2, $file_path . '/' . $l0 . '/' . $l1 . '/' . $l2, $access), 0)) {
                if (is_link($file_path . '/' . $l0 . '/' . $l1 . '/' . $l2)) {
                  if (validate_file($instructor, readlink($file_path . '/' . $l0 . '/' . $l1 . '/' . $l2), ancient(readlink($file_path . '/' . $l0 . '/' . $l1 . '/' . $l2), $access),0)) {
                    $vf = validate_file($instructor, readlink($file_path . '/' . $l0 . '/' . $l1 . '/' . $l2), readlink($file_path . '/' . $l0 . '/' . $l1 . '/' . $l2), $access);
                    $category = categorize_file($instructor, $l2, readlink($file_path . '/' . $l0 . '/' . $l1 . '/' . $l2), $vf[5], $categories);
                    $results[$l0][$l1][$l2] = array('actual' => $file_path . '/' . $l0 . '/' . $l1 . '/' . readlink($file_path . '/' . $l0 . '/' . $l1 . '/' . $l2),
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
                } else {
                  $vf = validate_file($instructor, $l2, $file_path . '/' . $l0 . '/' . $l1 . '/' . $l2, $access);
                  $category = categorize_file($instructor, $l2, $file_path . '/' . $l0 . '/' . $l1 . '/' . $l2, $vf[5], $categories);
                  $results[$l0][$l1][$l2] = array('actual' => $file_path . '/' . $l0 . '/' . $l1 . '/' . $l2,
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
              if (ancient(validate_file($instructor, $real_file, $real_file, $access),0)) {
                $key = sha1($secret.$real_file.$virt_file);
                $vf = validate_file($instructor, $real_file, $real_file, $access);
                $category = categorize_file($instructor, $virt_file, $real_file, $vf[5], $categories);
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
              if (strtoupper($d0->format('l')) != strtoupper($delta)) {
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
  function build_types($BOX, $HTML, $LINK, $PDF, $PPT, $SRC) {
    $categories = array();
    foreach (array('html'=>$HTML, 'link'=>$LINK, 'pdf'=>$PDF, 'ppt'=>$PPT, 'src'=>$SRC) as $item => $values) {
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
        $cevent = explode('.', $event);
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
  $categories = build_types($BOX, $HTML, $LINK, $PDF, $PPT, $SRC);

  # Set valid logon time (based on cookie auth)
  if (!isset($LOGON_TIME)) {
    $LOGON_TIME = 43200;
  }

  # Perform Authentication
  session_start();
  if ($ADMIN != '') {
    if (isset($_REQUEST['password']) && $_REQUEST['password'] == $ADMIN) {
      $_SESSION["cal4-$COURSE"] = sha1($SECRET.$ADMIN.$COURSE.$_SESSION["cal4-$COURSE-nonce"].session_id());
      setcookie("cal4-$COURSE", sha1($SECRET.$ADMIN.$COURSE.$_COOKIE["cal4-$COURSE-nonce"].session_id()), time()+$LOGON_TIME);
      $_COOKIE["cal4-$COURSE"] = sha1($SECRET.$ADMIN.$COURSE.$_COOKIE["cal4-$COURSE-nonce"].session_id());
    } elseif (isset($_REQUEST['password'])
        && isset($_COOKIE["cal4-$COURSE-nonce"])
        && $_REQUEST['password'] == hash('sha256', $ADMIN.$_COOKIE["cal4-$COURSE-nonce"])) {
      $_SESSION["cal4-$COURSE"] = sha1($SECRET.$ADMIN.$COURSE.$_COOKIE["cal4-$COURSE-nonce"].session_id());
      setcookie("cal4-$COURSE", sha1($SECRET.$ADMIN.$COURSE.$_COOKIE["cal4-$COURSE-nonce"].session_id()), time()+$LOGON_TIME);
      $_COOKIE["cal4-$COURSE"] = sha1($SECRET.$ADMIN.$COURSE.$_COOKIE["cal4-$COURSE-nonce"].session_id());
    } elseif (isset($_REQUEST['password'])
        && isset($_SESSION["cal4-$COURSE-nonce"])
        && $_REQUEST['password'] == hash('sha256', $ADMIN.$_SESSION["cal4-$COURSE-nonce"])) {
      $_SESSION["cal4-$COURSE"] = sha1($SECRET.$ADMIN.$COURSE.$_SESSION["cal4-$COURSE-nonce"].session_id());
      setcookie("cal4-$COURSE", sha1($SECRET.$ADMIN.$COURSE.$_COOKIE["cal4-$COURSE-nonce"].session_id()), time()+$LOGON_TIME);
      $_COOKIE["cal4-$COURSE"] = sha1($SECRET.$ADMIN.$COURSE.$_COOKIE["cal4-$COURSE-nonce"].session_id());
    } elseif (isset($_REQUEST['password']) && (isset($_SESSION["cal4-$COURSE"]) || isset($_COOKIE["cal4-$COURSE"]) )) {
      if (isset($_SESSION["cal4-$COURSE"])) {
        unset($_SESSION["cal4-$COURSE"]);
      }
      if (isset($_COOKIE["cal4-$COURSE"])) {
        unset($_COOKIE["cal4-$COURSE"]);
        setcookie("cal4-$COURSE", '', time()-3600);
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
      setcookie("cal4-$COURSE-nonce", $myNonce, time()+$LOGON_TIME);
      $_COOKIE["cal4-$COURSE-nonce"] = $myNonce;
    }

    # Default to Student User Mode
    if (!isset($INSTRUCTOR)) {
      $INSTRUCTOR = False;
    }
  }

  # Add a record of the visit to this page if $ACCESS_LOG is set
  if (isset($ACCESS_LOG) && file_exists($ACCESS_LOG)) {
    $URI = explode("?", $_SERVER['REQUEST_URI']);
    if (isset($URI[1])) {
      $URI = $URI[1];
    } else {
      $URI = "home";
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
  $files = get_files($INSTRUCTOR, array_keys($COMPONENTS), '.', $access, $virtual, $categories, $SECRET);

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

  # Retrieve the other files in the specific lecture
  $other = array();
  if (isset($_REQUEST['event']) && isset($_REQUEST['type']) && isset($events[$_REQUEST['type']])) {
    $other = array_keys($files[$_REQUEST['type']]);
    $other = $files[$_REQUEST['type']][$other[$_REQUEST['event']-1]];
    if (isset($events_list['map'][$_REQUEST['type']][$_REQUEST['event']])) {
      $month = $events_list['map'][$_REQUEST['type']][$_REQUEST['event']][0];
      $day = $events_list['map'][$_REQUEST['type']][$_REQUEST['event']][1];
    } else {
      $month=12;
      $day=31;
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
        if (isset($events_list[$today_mon][$today_day]['event']['box']['title']['type']) && $events_list[$today_mon][$today_day]['event']['box']['title']['type'] == 'html') {
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
    }

    # If the source is not html, provide the unprocessed file
    if ($file_type != 'html' && $ext != 'html' && $actual != '' && $ext != 'htm' && !isset($_REQUEST['navbaronly'])) {
      provide_file($actual);
      die;
    } elseif ($ext == 'htm') {
      $_REQUEST['nocss'] = 'yes';
    }

    # Set the navbar title to the title of this lecture
    if (isset($events[$_REQUEST['type']][$_REQUEST['event']]['name'])) {
      $navbar_display = $events[$_REQUEST['type']][$_REQUEST['event']]['name'];
    }

    # Retrieve the contents of this file
    if ($actual != '') {
      $contents = file_get_contents($actual);
      $contents_clean = $contents;
      $actual_file = $actual;
    }
  }

  # Check to see if a web page is requested
  # This will provide .html files in the root directory
  if (isset($_REQUEST['load'])) {
    $actual = $_REQUEST['load'].'.pdf';
    if (file_exists(basename($actual))) {
      provide_file(basename($actual));
      die;
    }
    $actual = $_REQUEST['load'].'.html';
    if (file_exists('calendar/'.$actual)) {
      $contents = file_get_contents('calendar/'.basename($actual));
    } elseif (file_exists(basename($actual))) {
      $contents = file_get_contents(basename($actual));
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
      $link = "calendar.php?key=". strip_tags($value['key']);
      if (isset($_REQUEST['type'])) {
        $link .= '&type=' . strip_tags($_REQUEST['type']);
      }
      if (isset($_REQUEST['event'])) {
        $link .= '&event=' . strip_tags($_REQUEST['event']);
      }
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
        $inject_data = $PAGE_MODIFY[$tag_src];
        $inject_data = str_ireplace('<', '&lt;', $inject_data);
        $inject_data = str_ireplace('>', '&gt;', $inject_data);
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

  if ((!isset($INSTRUCTOR) || !$INSTRUCTOR) && isset($REVEALHOUR)) {
      $today_date_time = getdate();
      if (
            ($today_date_time['year'] > $YEAR)
         ||
         (
            ($today_date_time['year'] >= $YEAR)
            &&
            ($today_date_time['mon'] > $events_list[map][$_REQUEST['type']][$_REQUEST['event']][0])
         )
         ||
         (
            ($today_date_time['year'] == $YEAR)
            &&
            ($today_date_time['mon'] == $events_list[map][$_REQUEST['type']][$_REQUEST['event']][0])
            &&
            ($today_date_time['mday'] > $events_list[map][$_REQUEST['type']][$_REQUEST['event']][1])
         )
         ||
         (
            ($today_date_time['year'] == $YEAR)
            &&
            ($today_date_time['mon'] == $events_list[map][$_REQUEST['type']][$_REQUEST['event']][0])
            &&
            ($today_date_time['mday'] == $events_list[map][$_REQUEST['type']][$_REQUEST['event']][1])
            &&
            ($today_date_time['hours'] >= $REVEALHOUR)
          )
         ){
        #show the content
        echo "Would show content";
        $contents = preg_replace('/<postmeeting[^>]*>([\s\S]*?)<\/postmeeting[^>]*>/', '\1', $contents);
      }
      else {
        #DO NOT show the content
        $contents = preg_replace('/<postmeeting[^>]*>([\s\S]*?)<\/postmeeting[^>]*>/', '', $contents);
      }
  }

  # Remove the contents of <inst>...</inst> tags if not logged on
  if (!isset($INSTRUCTOR) || !$INSTRUCTOR) {
    $contents = preg_replace('/<inst[^>]*>([\s\S]*?)<\/inst[^>]*>/', '', $contents);
  }
  else {
    $contents = preg_replace('/<inst[^>]*>([\s\S]*?)<\/inst[^>]*>/', '\1', $contents);
  }

  # Remove the contents of <host server="">...</host> tags if not on the
  # specified server (use $_SERVER['SERVER_NAME'])
  preg_match_all('/<host[^>]+>/i', $contents, $injects);
  foreach($injects[0] as $row => $inject_tag) {
    preg_match_all('/server=("[^"]*")/i',$inject_tag, $tag_src);
    $codes = str_replace('"','', $tag_src[1][0]);
    $codes = str_replace("'",'', $codes);
    $codes = explode(',', $codes);
    foreach($codes as $rowi => $thiscode) {
      if ($_SERVER['SERVER_NAME'] == trim($thiscode) ||
          $_SERVER['SERVER_ADDR'] == trim($thiscode) ) {
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
      preg_match_all('/code=("[^"]*")/i',$inject_tag, $tag_src);
      $codes = str_replace('"','', $tag_src[1][0]);
      $codes = str_replace("'",'', $codes);
      $codes = explode(',', $codes);
      foreach($codes as $rowi => $thiscode) {
        if ($_SESSION['lock'] == trim($thiscode)) {
          $contents = str_ireplace($injects[0][$row], '<unlock>', $contents);
        }
      }
    }
  }
  if (!isset($INSTRUCTOR) || !$INSTRUCTOR) {
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
  $find_student = (strpos($contents, '<student>') > 0);
  $find_instructor = (strpos($contents, '<inst>') > 0);

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
    preg_match_all('/name=("[^"]*")/i',$ace[0][$i], $ace_name);
    preg_match_all('/mode=("[^"]*")/i',$ace[0][$i], $ace_mode);
    preg_match_all('/height=("[^"]*")/i',$ace[0][$i], $ace_height);
    preg_match_all('/theme=("[^"]*")/i',$ace[0][$i], $ace_theme);
    preg_match_all('/readonly=("[^"]*")/i',$ace[0][$i], $ace_readonly);
    preg_match_all('/nolinenumbers=("[^"]*")/i',$ace[0][$i], $ace_nolinenumbers);
    preg_match_all('/textwrap=("[^"]*")/i',$ace[0][$i], $ace_textwrap);
    $ace_readonly_flag = '//';
    if (isset($ace_readonly[1][0]) && (substr($ace_readonly[1][0],1,-1)=='true')) {
      $ace_readonly_flag = '';
    }
    $ace_nolinenumbers_flag = '//'; #default is to have line numbers
    if (isset($ace_nolinenumbers[1][0]) && (substr($ace_nolinenumbers[1][0],1,-1)=='true')) {
      $ace_nolinenumbers_flag = '';
    }
    $ace_textwrap_flag = '//';
    if (isset($ace_textwrap[1][0]) && (substr($ace_textwrap[1][0],1,-1)=='true')) {
      $ace_textwrap_flag = '';
    }
    if (isset($ace_name[1][0])) {
      $ace_name = substr($ace_name[1][0],1,-1);
    } else {
      $ace_name = "ace$i";
    }
    if (isset($ace_mode[1][0])) {
      $ace_mode = substr($ace_mode[1][0],1,-1);
    } else {
      $ace_mode = "html_elixir";
    }
    if (isset($ace_height[1][0])) {
      $ace_height = substr($ace_height[1][0],1,-1);
    } else {
      $ace_height = "48px";
    }
    if (isset($ace_theme[1][0])) {
      $ace_theme = substr($ace_theme[1][0],1,-1);
    } else {
      $ace_theme = "tomorrow";
    }
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
    preg_match_all('/value=("[^"]*")/i',$inject_tag, $tag_src);
    preg_match_all('/default=("[^"]*")/i',$inject_tag, $tag_default);
    $tag_src = substr($tag_src[1][0],1,-1);
    $tag_default = substr($tag_default[1][0],1,-1);
    if ($tag_src != "" && isset($_REQUEST[$tag_src])) {
      $inject_data = $_REQUEST[$tag_src];
      $_SESSION['cal4-data-'.$tag_src] = $_REQUEST[$tag_src];
    } elseif (isset($_SESSION['cal4-data-'.$tag_src])) {
      $inject_data = $_SESSION['cal4-data-'.$tag_src];
    } else {
      $inject_data = $tag_default;
    }
    $inject_data = str_ireplace('<', '&lt;', $inject_data);
    $inject_data = str_ireplace('>', '&gt;', $inject_data);
    $contents = str_ireplace('<url value="'.$tag_src.'" default="'.$tag_default.'">', $inject_data, $contents);
    $contents = str_ireplace("<url value='$tag_src' default='$tag_default'>", $inject_data, $contents);
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

  # If requested only the navbar (so that contents can be revealed)
  if (isset($_REQUEST['navbaronly']) && !isset($_REQUEST['show'])) {
    $_REQUEST['show'] = 'calendar_file_selector';
  }

  # If Edit mode was requested, lest view the file.
  if (isset($_REQUEST['edit']) && isset($_REQUEST['event']) && isset($_REQUEST['type']) && isset($_REQUEST['key'])) {
    $_REQUEST['show'] = 'calendar_file_editor';
    $_REQUEST['navbaronly'] = true;
  }

  # If a php script was requested run it (assuming its valid)
  if (isset($_REQUEST['show'])) {
    $actual = 'calendar/' . basename($_REQUEST['show']) . '.php';
    if (file_exists($actual)) {
      require_once($actual);
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

  # Debugging if desired
  if ($INSTRUCTOR && isset($_REQUEST['debug'])) {
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
