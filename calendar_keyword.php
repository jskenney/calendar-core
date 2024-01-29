<?php

  # Search for keywords
  if (isset($events_list)) {
    $year = $events_list['year'];
    $start = $events_list['month_start'];
    $stop = $events_list['month_end'];
    $box = $events_list['box'];
  }

  if (isset($_REQUEST['keyword'])) {
    $key = strip_tags(urldecode($_REQUEST['keyword']));
  } else {
    $key = 'NONE-SPECIFIED';
  }

  $today = getdate();

  echo "<table class='table table-striped table-bordered' width=99%><tbody>";
  echo "<tr><td bgcolor='#a7a7a7' colspan=4><font size=3em><b>Keyword Search (searching for <em><u>$key</u></em> across all web pages)</b></font></td></tr>";
  for ($month = $start; $month <= $stop; $month++) {
    $month_len = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    $month_name = cal_info(0);
    $month_name = $month_name['months'][$month];
    for ($day = 1; $day <= $month_len; $day++) {
      if (isset($events_list[$month][$day])) {
        $type = $events_list[$month][$day]['type'];
        $type_num = $events_list[$month][$day]['type_num'];
        if (isset($events_list[$month][$day]['event']['name'])) {
          $type_name = $events_list[$month][$day]['event']['name'];
          if ($today['year'] == $year && $today['mon'] == $month && $today['mday'] == $day) {
            $color = " bgcolor='#FFFEBD'";
          } else {
            $color = "";
          }
          if (isset($events_list[$month][$day]['event']['box']['title']['type']) && $events_list[$month][$day]['event']['box']['title']['type'] == 'html') {
            $actual = $events_list[$month][$day]['event']['box']['title']['actual'];
            $myfound = False;
            if (file_exists($actual)) {
              $mycontents = file_get_contents($actual);
              preg_match_all('/<a name=\"(.*?)\"\><\/a>/s', $mycontents, $mynavbar_menus);
              if (count($mynavbar_menus[1]) > 0) {
                foreach ($mynavbar_menus[1] as $checkval) {
                  if (strtolower($checkval) == strtolower($key)) {
                    $myfound = True;
                  }
                }
              }
            }

            if ($myfound) {
              echo "<tr><td $color nowrap>$month_name $day</td><td $color nowrap>" . ucfirst($type) . " $type_num</td><td>";
              if (isset($events_list[$month][$day]['event']['box']['title'])) {
                echo "<a href='calendar.php?type=$type&event=$type_num'>$type_name</a>";
              } else {
                echo "$type_name";
              }
              echo "</td><td>";
              foreach($box as $i => $btype) {
                $btype_desc = ucwords(str_ireplace("-", " ", $btype));
                if (isset($events_list[$month][$day]['event']['box'][$btype])) {
                  $line = $events_list[$month][$day]['event']['box'][$btype];
                }
                if ($btype == 'title') {
                } else {
                  if (isset($events_list[$month][$day]['event']['box'][$btype])) {
                    if ($events_list[$month][$day]['event']['box'][$btype]['type'] == 'src') {
                      $actual = $events_list[$month][$day]['event']['box'][$btype]['actual'];
                      if (file_exists($actual)) {
                        echo file_get_contents($actual);
                      }
                    } else {
                      echo "(";
                      $key = $events_list[$month][$day]['event']['box'][$btype]['key'];
                      $ftype = $events_list[$month][$day]['event']['box'][$btype]['ftype'];
                      $fclass = $events_list[$month][$day]['event']['box'][$btype]['fclass'];
                      $fmon = $events_list[$month][$day]['event']['box'][$btype]['month'];
                      $fday = $events_list[$month][$day]['event']['box'][$btype]['day'];
                      echo "<a href=calendar.php?key=$key&type=$ftype&event=$fclass>$btype_desc</a>";
                      if ($today['mon'] < $fmon || ($today['mon'] == $fmon && $today['mday'] < $fday)) {
                        echo " <font color='purple'>($fmon/$fday)</font> ";
                      }
                      echo ") ";
                    }
                  }
                }
              }
              echo "</td></tr>";
            }
          }
        }
      }
    }
  }
  echo "</table>";
?>
