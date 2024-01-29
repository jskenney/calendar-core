<?php

  if (!isset($_SESSION['previewmode'])) {
    $_SESSION['previewmode'] = false;
  }

  if (isset($_REQUEST['preview'])) {
    $_SESSION['previewmode'] = !$_SESSION['previewmode'];
  }

  if ($INSTRUCTOR) {
?>

  <h5><?php echo $actual_file; ?>

  <?php
    if ($INSTRUCTOR && isset($_REQUEST['editor'])) {
      if (file_put_contents($actual_file, $_REQUEST['editor']) === FALSE) {
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
        $contents_clean = str_ireplace('<', '&lt;', $contents_clean);
        $contents_clean = str_ireplace('>', '&gt;', $contents_clean);
        echo "<div id='htmleditor'>$contents_clean</div>";
      ?>
      <form method="POST">
        <textarea name="editor" id="myeditor" style="display: none;"></textarea>
        <input type="hidden" name="key" value="<?php echo strip_tags($_REQUEST['key']); ?>">
        <input type="hidden" name="type" value="<?php echo strip_tags($_REQUEST['type']); ?>">
        <input type="hidden" name="event" value="<?php echo strip_tags($_REQUEST['event']); ?>">
        <input type="hidden" name="edit" value="<?php echo strip_tags($_REQUEST['edit']); ?>">
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
