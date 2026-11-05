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
      $link = cal4_file_link($value['key'], isset($_REQUEST['type']) ? $_REQUEST['type'] : null, isset($_REQUEST['event']) ? $_REQUEST['event'] : null);
      echo "<tr><td>".h($value['type'])."</td>";
      echo "<td>".h($value['category'])."</td>";
      echo "<td><a href='".h($link)."'>".h($fn)."</a></td>";
      echo "<td>".h($value['visible'])."</td>";
      echo "<td>".h($value['month'])."</td>";
      echo "<td>".h($value['day'])."</td>";
      echo "<td>".h($value['year'])."</td>";
      echo "<td>".h($value['dynamic'])."</td>";
      $link .='&edit=1';
      $ext = pathinfo($fn, PATHINFO_EXTENSION);
      if ($ext == 'htm' || $ext == 'html' || $ext == 'md') {
        echo "<td><a href='".h($link)."'>view</a></td>";
      } else {
        echo "<td></td>";
      }
      echo "</tr>";
    }
    echo "</table>";
  }

?>
