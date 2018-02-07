<?php require_once('calendar_css.php'); ?>

    <!-- Fixed navbar -->
    <nav class="navbar navbar-default navbar-fixed-top">
      <div class="container">
        <div class="navbar-header">
          <button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target="#navbar" aria-expanded="false" aria-controls="navbar">
            <span class="sr-only">Toggle navigation</span>
            <span class="icon-bar"></span>
            <span class="icon-bar"></span>
            <span class="icon-bar"></span>
          </button>
          <!--
            <a class="navbar-brand" href="#">
              <img alt="Navbar!" src="css/images/web-icon.png" width="24">
            </a>
          -->
        </div>
        <div id="navbar" class="navbar-collapse collapse">
          <ul class="nav navbar-nav">

            <li><a href="calendar.php?load=home">
                <?php echo $COURSE; ?> - <?php echo $COURSENAME; ?></a></li>

            <?php
            if (!$INSTRUCTOR && isset($LOCK) && ($LOCK === true || file_exists($LOCK))) {
              ?>
                <li class="dropdown">
                  <a href="#" title="Course Website Locked" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-haspopup="true" aria-expanded="false">
                    <span class="glyphicon glyphicon-lock" aria-hidden="true"></span>
                  </a>
                  <ul class="dropdown-menu  scrollable-menu">
                  <form method=post class="navbar-form navbar-left" role="search" onsubmit="return hashPassword()">'
                    <div class="input-group">
                      <input type="password" class="form-control" placeholder="Password" name="password" id="password">
                      <div class="input-group-btn">
                          <button class="btn btn-default" type="submit"><i class="glyphicon glyphicon-lock"></i></button>
                      </div>
                    </div>
                  </form>
                </ul>
                </li>
              <?php
            } else {
              if (isset($LOCK) && ($LOCK === true || file_exists($LOCK))) {
                ?>
                <li>
                  <a href="#" title="Course Website Locked" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-haspopup="true" aria-expanded="false">
                    <span class="glyphicon glyphicon-lock" aria-hidden="true"></span>
                  </a>
                </li>
                <?php
              }

            ?>

            <li><a title="Calendar" href="calendar.php?show=calendar_display">
                <span class="glyphicon glyphicon-calendar" aria-hidden="true"></span>
                </a></li>

            <li><a title="Course Policy" href="calendar.php?load=policy">
                <span class="glyphicon glyphicon-question-sign" aria-hidden="true"></span>
                </a></li>

            <li><a title="Resources" href="calendar.php?load=resources">
                <span class="glyphicon glyphicon-inbox" aria-hidden="true"></span>
                </a></li>

        <?php
          if ($INSTRUCTOR) {
            ?>
            <li><a title="Instructor Guide" href="calendar.php?load=instructor">
                <span class="glyphicon glyphicon-apple" aria-hidden="true"></span>
                </a></li>
            <?php
          }
        }
        ?>

        <?php
          if (isset($find_student) && $find_student && isset($_REQUEST['type']) && isset($_REQUEST['event'])) {
            $key_link = "";
            if (isset($_REQUEST['key'])) {
              $key_link = "&key=".$_REQUEST['key'];
            }
            if (!isset($_REQUEST['answers'])) {
              $unlock_link = "calendar.php?type=" . $_REQUEST['type'] . "&event=" . $_REQUEST['event'] . "$key_link&answers=yes";
              echo PHP_EOL."<li><a title='Show Problem Answers' href='$unlock_link'>";
              echo '<span class="glyphicon glyphicon-search" aria-hidden="true"></span>';
              echo '</a></li>'.PHP_EOL;
            } else {
              $lock_link = "calendar.php?type=" . $_REQUEST['type'] . "&event=" . $_REQUEST['event'] . $key_link;
              echo PHP_EOL."<li><a title='Hide Problem Answers' href='$lock_link'>";
              echo '<span class="glyphicon glyphicon-minus" aria-hidden="true"></span>';
              echo '</a></li>'.PHP_EOL;
            }
          }
        ?>

          </ul>

        <?php
          if (!$INSTRUCTOR && isset($LOCK) && ($LOCK === true || file_exists($LOCK))) {
          } else {
        ?>

          <ul class="nav navbar-nav navbar-right">
            <?php
              echo '<li class="dropdown">'.PHP_EOL;
              echo '<a href="#" title="View files associated with lecture" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-haspopup="true" aria-expanded="false">'.PHP_EOL;
              echo $navbar_display.PHP_EOL;
              if ($navbar_display == '') {
                echo "Select &rarr;".PHP_EOL;
              }
              echo '</a>'.PHP_EOL;
              echo '<ul class="dropdown-menu  scrollable-menu">'.PHP_EOL;
              if (isset($navbar_menus) && count($navbar_menus) > 0) {
                $navbar_menu_div = False;
                foreach ($navbar_menus[1] as $item) {
                  if (strpos($item, 'pagename:') === False) {
                    $item_desc = ucfirst($item);
                    $item_desc = str_ireplace("-", " ", $item_desc);
                    echo "<li><a href='#$item'>$item_desc</a></li>".PHP_EOL;
                    $navbar_menu_div = True;
                  }
                }
                if ($navbar_menu_div) {
                  echo '<li role="separator" class="divider"></li>'.PHP_EOL;
                }
              }

              if ($INSTRUCTOR && !empty($other)) {
                echo '<li class="dropdown-header">Associated Files</li>'.PHP_EOL;
                foreach ($other as $fn => $value) {
                  $link = "calendar.php?key=".$value['key'];
                  if (isset($_REQUEST['type'])) {
                    $link .= '&type=' . $_REQUEST['type'];
                  }
                  if (isset($_REQUEST['event'])) {
                    $link .= '&event=' . $_REQUEST['event'];
                  }
                  echo "<li><a href='$link'>$fn</a></li>".PHP_EOL;
                }
                echo '<li role="separator" class="divider"></li>'.PHP_EOL;
              }

              # Print the page
              echo '<li class="dropdown-header">Options</li>'.PHP_EOL;
              echo '<form method=post class="navbar-form navbar-left" role="search" target="_blank">'.PHP_EOL;
              echo '  <div class="input-group">'.PHP_EOL;
              echo '    <input type="hidden" class="form-control" placeholder="Print" name="print" id="print">'.PHP_EOL;
              echo '    <div class="input-group-btn">'.PHP_EOL;
              echo '        <button class="btn btn-default" type="submit"><i class="glyphicon glyphicon-print"></i> Print</button>'.PHP_EOL;
              echo '    </div>'.PHP_EOL;
              echo '  </div>'.PHP_EOL;
              echo '</form>'.PHP_EOL;

              # Searchbox
              echo '<form method=get class="navbar-form navbar-left" role="search">'.PHP_EOL;
              echo '  <div class="input-group">'.PHP_EOL;
              echo '    <input type="hidden" name="show" value="calendar_search">'.PHP_EOL;
              echo '    <input type="text" class="form-control" placeholder="Search" name="search" id="search">'.PHP_EOL;
              echo '    <div class="input-group-btn">'.PHP_EOL;
              echo '        <button class="btn btn-default" type="submit"><i class="glyphicon glyphicon-search"></i></button>'.PHP_EOL;
              echo '    </div>'.PHP_EOL;
              echo '  </div>'.PHP_EOL;
              echo '</form>'.PHP_EOL;

              # Logon (or show logoff page)
              if ($INSTRUCTOR) {
                echo '<form method=post class="navbar-form navbar-left" role="search" onsubmit="return hashPassword()">'.PHP_EOL;
                echo '  <div class="input-group">'.PHP_EOL;
                echo '    <input type="hidden" class="form-control" placeholder="Password" name="password" id="password">'.PHP_EOL;
                echo '    <div class="input-group-btn">'.PHP_EOL;
                echo '        <button class="btn btn-default" type="submit"><i class="glyphicon glyphicon-lock"></i> Logoff</button>'.PHP_EOL;
                echo '    </div>'.PHP_EOL;
                echo '  </div>'.PHP_EOL;
                echo '</form>'.PHP_EOL;
                echo "<li><a href='#'>Version ".CALENDAR_VERSION."</a></li>".PHP_EOL;
              } else {
                #echo "<li><a href='#'>Logon as Administrator</a></li>";
                echo '<form method=post class="navbar-form navbar-left" role="search" onsubmit="return hashPassword()">'.PHP_EOL;
                echo '  <div class="input-group">'.PHP_EOL;
                echo '    <input type="password" class="form-control" placeholder="Password" name="password" id="password">'.PHP_EOL;
                echo '    <div class="input-group-btn">'.PHP_EOL;
                echo '        <button class="btn btn-default" type="submit"><i class="glyphicon glyphicon-lock"></i></button>'.PHP_EOL;
                echo '    </div>'.PHP_EOL;
                echo '</div>'.PHP_EOL;
                echo '</form>'.PHP_EOL;
              }

              echo '</ul>'.PHP_EOL;
              echo '</li>'.PHP_EOL;

              # If logged on, show instructor menus
              if ($INSTRUCTOR && isset($NAVBAR_DROPDOWNS_INSTRUCTOR)) {
                $NAVBAR_DROPDOWNS = $NAVBAR_DROPDOWNS_INSTRUCTOR;
              }

              foreach ($NAVBAR_DROPDOWNS as $type => $icon) {
            ?>
            <li class="dropdown">
              <a href="#" title="Select <?php echo $type; ?>" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-haspopup="true" aria-expanded="false">
                <span class="glyphicon <?php echo $icon; ?>" aria-hidden="true"></span>
                <?php echo ucwords($type); ?><span class="caret"></span></a>
              <ul class="dropdown-menu  scrollable-menu">
                <?php
                  if (isset($events[$type])) {
                    foreach ($events[$type] as $cname => $cdata) {
                      if (isset($cdata['box']['title']) || $INSTRUCTOR) {
                        if (isset($_REQUEST['type']) && isset($_REQUEST['event']) && $_REQUEST['type'] == $type && $_REQUEST['event'] == $cname) {
                          echo "<li><a href='calendar.php?type=$type&event=$cname'><font color='black'><b>$cname - ".$cdata['name']."</b></font></a></li>".PHP_EOL;
                        } else {
                          echo "<li><a href='calendar.php?type=$type&event=$cname'>$cname - ".$cdata['name']."</a></li>".PHP_EOL;
                        }
                      } else {
                        echo "<li><a title='Material not online at this time' href='#'><font color='#AAAAAA'>$cname - ".$cdata['name']."</font></a></li>".PHP_EOL;
                      }
                    }
                  }
                ?>
              </ul>
            </li>
            <?php
              }
            }
            ?>

          </ul>
        </div><!--/.nav-collapse -->
      </div>
    </nav>

  <!-- End TopBar and CSS Stuff! -->
