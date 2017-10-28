<?php

  # SQLite3 Configuration support for Calendar V4

  # Define the name of the db file
  if (!isset($DB_FILENAME)) {
    $DB_FILENAME = 'calendar.db';
  }

  # Attempt to open file!
  try{ $db = new SQLite3($DB_FILENAME); } catch(Exception $exception) {}

  # Do work if we were successful in opening the database file
  if (isset($db)) {
    echo "<h1>DEBUGGING - Database Time!</h1>";

    # Does the database need to be initialized
    if (filesize($DB_FILENAME) === 0) {
      echo "Building Tables<br>";

      # Lets start building tables.

      # cal4.Virtual
      # type # event # filename # real_file #
      $query = "CREATE TABLE virtual (
        type TEXT,
        event TEXT,
        filename TEXT,
        realfile TEXT,
        PRIMARY KEY (type, event, filename)
      )";
      $success = $db->query($query);

      # cal4.access
      # type # event # filename # year # month # day # comment # justLock #
      $query = "CREATE TABLE access (
        type TEXT,
        event TEXT,
        filename TEXT,
        year INT,
        month INT,
        day INT,
        comment TEXT,
        justlock INT,
        PRIMARY KEY (type, event, filename)
      )";
      $success = $db->query($query);

      # $OVERRIDE
      # type # month # day #
      $query = "CREATE TABLE override (
        type TEXT,
        month INT,
        day INT,
        PRIMARY KEY (month, day)
      )";
      $success = $db->query($query);

      # $COMBINE
      # type # event # type # seqnum #
      $query = "CREATE TABLE combine (
        type TEXT,
        event TEXT,
        newtype TEXT,
        seqnum INT,
        PRIMARY KEY (type, event, seqnum)
      )";
      $success = $db->query($query);

      # $PAGE_MODIFY
      # replace # with #
      $query = "CREATE TABLE modify (
        replace TEXT,
        value TEXT,
        PRIMARY KEY (replace)
      )";
      $success = $db->query($query);

      # Configuration
      # variable # subvar # value #
      $query = "CREATE TABLE configuration (
        variable TEXT,
        subvar TEXT,
        value TEXT,
        PRIMARY KEY (variable, subvar)
      )";
      $success = $db->query($query);

      # Documentation
      # variable # comment # subYN # validVal # validSubVal #

    };

    # Begin processing configuration and replacing existing values.
  }

 ?>
