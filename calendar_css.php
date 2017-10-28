<?php
  if (!defined('CALENDAR_PATH')) {
    define('CALENDAR_PATH', '');
  }
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- The above 3 meta tags *must* come first in the head;
         any other head content must come *after* these tags -->

    <!-- Icon to use on the browser bar -->
    <link rel="icon" href="<?php echo CALENDAR_PATH; ?>calendar/images/web-icon.png">

    <!-- Bootstrap core CSS -->
    <link href="<?php echo CALENDAR_PATH; ?>calendar/css/bootstrap.min.css" rel="stylesheet">

    <!-- Skeleton CSS -->
    <link rel="stylesheet" href="<?php echo CALENDAR_PATH; ?>calendar/skeleton/normalize.css">
    <link rel="stylesheet" href="<?php echo CALENDAR_PATH; ?>calendar/skeleton/skeleton.css">
    <link rel="stylesheet" href="<?php echo CALENDAR_PATH; ?>calendar/skeleton/custom.css">

    <!-- IE10 viewport hack for Surface/desktop Windows 8 bug -->
    <link href="<?php echo CALENDAR_PATH; ?>calendar/css/ie10-viewport-bug-workaround.css" rel="stylesheet">

    <!-- Fonts -->
    <link href='<?php echo CALENDAR_PATH; ?>calendar/css/raleway.css' rel='stylesheet' type='text/css'>

    <!-- Ace Code Editor - https://ace.c9.io/ -->
    <script type="text/javascript"
      src="<?php echo CALENDAR_PATH; ?>calendar/ace-builds/src-noconflict/ace.js" charset="utf-8">
    </script>

    <!-- Styles for the submission System -->
    <link href="<?php echo CALENDAR_PATH; ?>calendar/css/default.css" rel="stylesheet">

    <!-- HTML5 shim and Respond.js for IE8 support of HTML5 elements and media queries -->
    <!--[if lt IE 9]>
      <script src="https://oss.maxcdn.com/html5shiv/3.7.2/html5shiv.min.js"></script>
      <script src="https://oss.maxcdn.com/respond/1.4.2/respond.min.js"></script>
    <![endif]-->

    <!-- MathJax -->
    <script type="text/x-mathjax-config">
      MathJax.Hub.Config({
        tex2jax: {
          inlineMath: [ ["\\(","\\)"] ],
          processEscapes: true
        }
      });
    </script>
    <script type="text/javascript"
      src="https://cdnjs.cloudflare.com/ajax/libs/mathjax/2.7.1/MathJax.js?config=TeX-MML-AM_CHTML">
    </script>

    <!-- Highlight.js -->
    <link rel="stylesheet" href="<?php echo CALENDAR_PATH; ?>calendar/highlight/styles/color-brewer.css">
    <script src='<?php echo CALENDAR_PATH; ?>calendar/highlight/highlight.pack.js'></script>
    <script>hljs.initHighlightingOnLoad();</script>

    <!-- Font-Awesome -->
    <link rel="stylesheet" type="text/css" href="<?php echo CALENDAR_PATH; ?>calendar/font-awesome/css/font-awesome.min.css">

    <!-- Printing -->
    <link rel="stylesheet" type="text/css" media="print" href="<?php echo CALENDAR_PATH; ?>calendar/css/print_style.css" />

    <!-- Bootstrap core JavaScript
    ================================================== -->
    <!-- Placed at the end of the document so the pages load faster -->
    <script src="<?php echo CALENDAR_PATH; ?>calendar/js/jquery.min.js"></script>
    <script src="<?php echo CALENDAR_PATH; ?>calendar/js/bootstrap.min.js"></script>
    <!-- IE10 viewport hack for Surface/desktop Windows 8 bug -->
    <script src="<?php echo CALENDAR_PATH; ?>calendar/js/ie10-viewport-bug-workaround.js"></script>
  </head>

  <title><?php echo $PAGE_TITLE; ?></title>

  <body>
