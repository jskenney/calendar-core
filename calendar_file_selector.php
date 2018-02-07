<?php

  if ($INSTRUCTOR && !empty($other)) {
    echo "<table class='table table-striped table-bordered'>";
    echo "<thead>";
    echo "";
    echo "<tr><th rowspan=2>Type</th><th rowspan=2>Category</th><th rowspan=2>File</th><th colspan=5>Security</th><th rowspan=2>View / Edit<br>HTML</th></tr>";
    echo "<tr><th>Visible</th><th>Month</th><th>Day</th><th>Year</th><th>Dynamic</th></tr>";
    echo "</thead>";
    echo "<tbody>";
    foreach ($other as $fn => $value) {
      $link = "calendar.php?key=".$value['key'];
      if (isset($_REQUEST['type'])) {
        $link .= '&type=' . $_REQUEST['type'];
      }
      if (isset($_REQUEST['event'])) {
        $link .= '&event=' . $_REQUEST['event'];
      }
      echo "<tr><td>".$value['type']."</td>";
      echo "<td>".$value['category']."</td>";
      echo "<td><a href='$link'>$fn</a></td>";
      echo "<td>".$value['visible']."</td>";
      echo "<td>".$value['month']."</td>";
      echo "<td>".$value['day']."</td>";
      echo "<td>".$value['year']."</td>";
      echo "<td>".$value['dynamic']."</td>";
      $link .='&edit=1';
      $ext = pathinfo($fn, PATHINFO_EXTENSION);
      if ($ext == 'htm' || $ext == 'html') {
        echo "<td><a href='$link'>view</a></td>";
      } else {
        echo "<td></td>";
      }
      echo "</tr>";
    }
    echo "</table>";
  }

?>
