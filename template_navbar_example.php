<?php

  # Build the default navbar for the example suite.
  # Modified to become part of the calendar v4 system
  # This will use my default templates and frameworks
  # J. Kenney 2016

  # Set Default Title / Information
  if (!isset($PAGE_TITLE)) {$PAGE_TITLE = 'Page Title'; }
  if (!isset($NAVBAR_TITLE)) {$NAVBAR_TITLE = 'NavBar Title'; }
  if (!isset($NAVBAR_TITLE_URL)) {$NAVBAR_TITLE_URL = '#'; }

  # Build the Navbar (Navbar version 3 format)
  $NAVBAR = array();
  $NAVBAR[] = array('url'=>'../example/status.php', 'type'=>'url', 'rtext'=>' Status', 'title'=>'example Status', 'icon'=>'glyphicon-signal');
  $NAVBAR[] = array('url'=>'../example/search.php', 'type'=>'url', 'rtext'=>' Search', 'title'=>'example Search', 'icon'=>'glyphicon-search');
  $NAVBAR[] = array('url'=>'../example/review.php', 'type'=>'url', 'rtext'=>' Alerts', 'title'=>'example Review', 'icon'=>'glyphicon-alert');
  //$NAVBAR[] = array('url'=>'#', 'type'=>'url', 'rtext'=>' &nbsp;Event Management', 'title'=>'Create or View Events', 'icon'=>'glyphicon-folder-open');
  $NAVBAR[] = array('type'=>'seperator');
  $drop_options1 = array();
  //$drop_options1[] = array('url'=>'../events/event.php', 'type'=>'url', 'title'=>'', 'text'=>'Add Item to Watch');
  //$drop_options1[] = array('url'=>'#', 'type'=>'url', 'title'=>'', 'text'=>'Manage Nodes');
  //$drop_options1[] = array('url'=>'#', 'type'=>'url', 'title'=>'', 'text'=>'Manage Users');
  //$drop_options1[] = array('type'=>'seperator');
  $drop_options1[] = array('url'=>'../navbar/navbar.php?logoff=1', 'type'=>'url', 'title'=>'', 'text'=>'Log Off');
  #$drop_options1[] = array('type'=>'header', 'text'=>'isheadertxt');
  #$drop_options1[] = array('type'=>'url', 'title'=>'mouseoverdrop1', 'url'=>'tower.php?option=1', 'text'=>'anoption');
  #$drop_options1[] = array('type'=>'direct', 'text'=>'<form method=post class="navbar-form navbar-left" role="search" target="_blank"><div class="input-group">    <input type="hidden" class="form-control" placeholder="Print" name="print" id="print">    <div class="input-group-btn">        <button class="btn btn-default" type="submit"><i class="glyphicon glyphicon-print"></i> Print</button>    </div></div></form>');
  $NAVBAR[] = array('type'=>'dropdown', 'title'=>'drop1', 'icon'=>'glyphicon-cog', 'rtext'=>' User ', 'options'=>$drop_options1);

  # Load in the appropriate libraries.
  require_once('calendar/template_navbar.php');

?>
