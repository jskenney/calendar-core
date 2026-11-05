<?php

  if (!isset($_SESSION['previewmode'])) {
    $_SESSION['previewmode'] = false;
  }

  # Per-session token that every save must include (protects against
  # cross-site request forgery).
  if (!isset($_SESSION['cal4-csrf']) || !is_string($_SESSION['cal4-csrf'])) {
    $_SESSION['cal4-csrf'] = bin2hex(random_bytes(32));
  }

  # Only html files can be edited.
  $cal4_edit_ok = isset($actual_file) && in_array(strtolower(pathinfo($actual_file, PATHINFO_EXTENSION)), array('html', 'htm'), True);
  $cal4_is_post = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';
  $cal4_token_ok = $cal4_is_post && isset($_POST['csrf']) && is_string($_POST['csrf']) && hash_equals($_SESSION['cal4-csrf'], $_POST['csrf']);

  if (isset($_POST['preview']) && $cal4_token_ok) {
    $_SESSION['previewmode'] = !$_SESSION['previewmode'];
  }

  if ($INSTRUCTOR && !$cal4_edit_ok) {
    echo "<h5>Only .html and .htm files can be edited.</h5>";
  } elseif ($INSTRUCTOR) {
?>

  <h5><?php echo h($actual_file); ?>

  <?php
    # Saves must be POST requests that carry the session token.
    if (isset($_POST['editor']) && is_string($_POST['editor'])) {
      if (!$cal4_token_ok) {
        echo " (<font color=red>Not saved: the form has expired, please reload and try again</font>)";
      } elseif (file_put_contents($actual_file, $_POST['editor']) === FALSE) {
        echo " (<font color=red>Permission Denied</font>)";
      }
      $contents_clean = file_get_contents($actual_file);
    }
   ?>

  </h5>

  <div class="row">
    <?php if ($_SESSION['previewmode']) { ?>
    <div class="six columns">
      <?php
        }
        $contents_clean = htmlspecialchars($contents_clean, ENT_NOQUOTES, 'UTF-8');
        echo "<div id='htmleditor'>$contents_clean</div>";
      ?>
      <form method="POST">
        <textarea name="editor" id="myeditor" style="display: none;"></textarea>
        <input type="hidden" name="key" value="<?php echo h($_REQUEST['key']); ?>">
        <input type="hidden" name="type" value="<?php echo h($_REQUEST['type']); ?>">
        <input type="hidden" name="event" value="<?php echo h($_REQUEST['event']); ?>">
        <input type="hidden" name="edit" value="<?php echo h($_REQUEST['edit']); ?>">
        <input type="hidden" name="csrf" value="<?php echo h($_SESSION['cal4-csrf']); ?>">
        <br><input type="submit" value="Save Changes"> &nbsp; <input type="submit" name="preview" value="Save and Toggle Side Preview Mode">
      </form>
      <?php if ($_SESSION['previewmode']) { ?>
    </div>
    <div class="six columns" id="right">
      <?php
      } ?>
      <h5>&nbsp;</h5>
      <div id="ace_htmleditor"></div>
      <?php if ($_SESSION['previewmode']) { ?>
    </div>
    <?php } ?>

  </div>

  <script type="text/javascript">
      var htmleditor = ace.edit("htmleditor");
      htmleditor.setTheme("ace/theme/crimson_editor");
      htmleditor.getSession().setMode("ace/mode/html_elixir");
      htmleditor.renderer.setShowGutter(false);
      function showHTMLInIFramehtmleditor() {
        <?php if ($_SESSION['previewmode']) { ?>
          $('#ace_htmleditor').html(htmleditor.getValue());
        <?php } ?>

          var textarea = $('textarea[name="editor"]');
          textarea.val(htmleditor.getSession().getValue());
      }
      htmleditor.on("input", showHTMLInIFramehtmleditor);
      htmleditor.setAutoScrollEditorIntoView(true);
      showHTMLInIFramehtmleditor();
  </script>

  <style type="text/css" media="screen">
     #htmleditor { height: 600px; }
     #right {
      height:100%;
      position: absolute;
      top: 0;
      bottom: 0;
      right: 0;
      overflow-y: scroll;
    }
  </style>

<?php
  }
?>
