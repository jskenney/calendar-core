<?php
/*
 * Cal4 regression tests.
 *
 * Usage:  php tests/run_tests.php
 *
 * Builds a small fixture course in a temporary directory, links the calendar
 * core into it, starts PHP's built-in web server and checks the behaviour over
 * HTTP.  Every response is also checked for PHP warnings, notices and fatal
 * errors.  No dependencies beyond the PHP CLI (with the calendar, dom and
 * session extensions that Cal4 itself needs).
 */

error_reporting(E_ALL);
$CORE = realpath(__DIR__ . '/..');
$SECRET = str_repeat('0123456789abcdef', 4);
$SAMPLE_SECRET = 'A really long phrase that should be impossible to guess, you dont need to remember this...';
$failures = 0;
$passes = 0;
$current = '';

function section($name) { global $current; $current = $name; echo "\n== $name\n"; }
function check($cond, $msg) {
  global $failures, $passes, $current;
  if ($cond) { $passes++; echo "  ok   $msg\n"; }
  else { $failures++; echo "  FAIL [$current] $msg\n"; }
}
function key_for($l0, $l1, $l2) { global $SECRET; return sha1($SECRET . $l0 . $l1 . $l2); }
function put($path, $data) { @mkdir(dirname($path), 0777, true); file_put_contents($path, $data); }
function rrmdir($dir) {
  if (is_link($dir) || is_file($dir)) { unlink($dir); return; }
  if (!is_dir($dir)) { return; }
  foreach (scandir($dir) as $f) { if ($f != '.' && $f != '..') { rrmdir("$dir/$f"); } }
  rmdir($dir);
}

/* ------------------------------------------------------------------ lint */
section('php -l on all first-party PHP files');
foreach (glob("$CORE/*.php") as $f) {
  exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
  check($rc === 0, 'lint ' . basename($f));
}

/* --------------------------------------------------------------- fixture */
$root = sys_get_temp_dir() . '/cal4test-' . getmypid();
$outside = sys_get_temp_dir() . '/cal4test-outside-' . getmypid();
rrmdir($root); rrmdir($outside);
mkdir($root, 0777, true);
symlink($CORE, "$root/calendar");
put("$outside/secret.txt", "OUTSIDE-SECRET");

$base = <<<'PHP'
<?php
  $OVERRIDE = array(9 => array(28 => 'exam'), 11 => array(2 => 'exam', 22 => 'HOLIDAY'), 12 => array(6 => 'Final Exam'));
  $COMBINE = array();
  $MONTH_START = 8; $DAY_START = 20; $MONTH_END = 12; $YEAR = 2018;
  $DOW = array('mon' => 'class', 'wed' => 'lab', 'fri' => 'class');
  $COURSE = 'TST101';
  $COURSENAME = 'Test <b>Course</b>';
  $PAGE_TITLE = "$COURSE <title>";
  $PAGE_MODIFY = array('q' => 'a"b<c>');
  $NAVBAR_DROPDOWNS = array('class' => 'glyphicon-apple', 'lab' => 'glyphicon-knight');
  $WEEKENDS = False;
  $ACCESS_LOG = 'logs/access.log';
  $ADMIN = 'testpass';
  $SECRET = '__SECRET__';
  $DEFAULT_TODAYS_LECTURE = False;
  $ACCESS_FILE = 'virtual/cal4.access';
  $CLASS_FILE = 'virtual/cal4.virtual';
  $LOAD_PAGES = array('extra', 'mdpage');
  $BOX = array(0 => 'title', 1 => 'homework', 2 => 'notes');
  $HTML = array('title' => 'index.html', 'homework' => 'homework.html');
  $LINK = array();
  $PDF = array('title' => 'slides.pdf');
  $PPT = array();
  $SRC = array('notes' => 'notes.txt');
  $IGNORE_A_TAG_WARNING = True;
PHP;
put("$root/base.php", str_replace('__SECRET__', $SECRET, $base));
$main = "  require_once('calendar/calendar_main.php');\n";
put("$root/calendar.php", "<?php require 'base.php';\n$main");
put("$root/reveal_past.php", "<?php require 'base.php'; \$YEAR = intval(date('Y')); \$MONTH_START = 1; \$DAY_START = 1; \$MONTH_END = 12;\n"
  . " \$DOW = array('mon'=>'class','tue'=>'class','wed'=>'class','thu'=>'class','fri'=>'class','sat'=>'class','sun'=>'class'); \$WEEKENDS = True; \$OVERRIDE = array(); \$REVEALHOUR = 0;\n$main");
put("$root/reveal_future.php", "<?php require 'base.php'; \$YEAR = intval(date('Y')) + 1; \$MONTH_START = 1; \$DAY_START = 1; \$MONTH_END = 12;\n"
  . " \$DOW = array('mon'=>'class','tue'=>'class','wed'=>'class','thu'=>'class','fri'=>'class','sat'=>'class','sun'=>'class'); \$WEEKENDS = True; \$OVERRIDE = array(); \$REVEALHOUR = 0;\n$main");
put("$root/sample_secret.php", "<?php require 'base.php'; \$COURSE = 'SAMPLE1'; \$SECRET = " . var_export($SAMPLE_SECRET, true) . ";\n$main");
put("$root/weak_secret.php", "<?php require 'base.php'; \$COURSE = 'WEAK1'; \$SECRET = 'short';\n$main");
put("$root/debug.php", "<?php require 'base.php'; \$DEBUG_MODE = True;\n$main");
put("$root/locked.php", "<?php require 'base.php'; \$LOCK = True;\n$main");
put("$root/md_off.php", "<?php require 'base.php'; \$MD = array();\n$main");

put("$root/home.html", "<h1>HOME-PAGE <replace value=\"coursename\"></h1>");
put("$root/extra.html", "EXTRA-PAGE");
put("$root/notlisted.html", "NOT-LISTED-PAGE");
put("$root/mdpage.md", "# Root Markdown\n\nRoot **page** for <replace value=\"course\">.");
put("$root/logs/access.log", "");
put("$root/shared docs/extra file.html", "VIRTUAL-EXTRA");

put("$root/virtual/cal4.virtual",
    "# one two three\n"
  . "\n"
  . "extra.html\tshared docs/extra file.html\tclass\t1\n"
  . "broken line here\n");
put("$root/virtual/cal4.access",
    "# a b\n"
  . "class -monday\n"
  . "class_3 99 99 9999\n"
  . "exam 99 99 9999\n"
  . "class_1/foo +frday\n");

// class 1: <student> at position 0, single-quoted <host>, <url> without default
put("$root/class/01.Intro/index.html",
    "<student>ANSWER-ONE</student><h1 id=\"intro\">Intro</h1>\n"
  . "<host server='127.0.0.1,localhost'>HOST-SHOWN</host><host server='no.such.host'>HOST-HIDDEN</host>\n"
  . "<p>Hello <url value=\"who\"></p><p title=\"<replace value=\"q\">\">Q</p>\n"
  . "zzz zzz <postmeeting>POSTMEET</postmeeting>\n"
  . "<ace name=\"ed1\" height=\"20px\">x</ace><ace>y</ace>\n");
put("$root/class/01.Intro/homework.html", "HOMEWORK-ONE");
put("$root/class/01.Intro/pic.png", "PNGDATA");
put("$root/class/01.Intro/sub/visible.txt", "VISIBLE-NESTED");
put("$root/class/01.Intro/sub/.secret.txt", "HIDDEN-NESTED");
symlink("$outside/secret.txt", "$root/class/01.Intro/outside.txt");
symlink("../02.Second/index.html", "$root/class/01.Intro/inside-link.html");
// class 2: single search match, image, legacy-named homework
put("$root/class/02.Second/index.html", "<h1>Second</h1> zzz once <img src=\"pic.png\"> <calphp src=\"script.php\">");
put("$root/class/02.Second/script.php", "<?php \$dbpass = 'PHP-SOURCE-SECRET'; echo 'CALPHP-OUTPUT';");
put("$root/class/02.Second/page.phtml", "<?php echo 'PHTML-SOURCE-SECRET';");
put("$root/class/02.Second/pic.png", "PNG2");
put("$root/class/02.Second/homework.12.31.html", "LEGACY-HOMEWORK");
// class 3: hidden by the access file, and the name keeps everything after the first dot
put("$root/class/03.Intro.Part2/index.html", "CLASS-THREE-SECRET");
// labs: natural sort
put("$root/lab/1.Alpha/index.html", "LAB-A");
put("$root/lab/2.Beta/index.html", "LAB-B");
put("$root/lab/10.Gamma/index.html", "LAB-C");
// exams: only two exam days are scheduled
put("$root/exam/01.Mid/index.html", "EXAM-ONE");
put("$root/exam/02.Mid2/index.html", "EXAM-TWO");
put("$root/exam/03.Secret/index.html", "SECRET-EXAM-3");
// class 4: Markdown title page, Markdown homework, injected Markdown
put("$root/class/04.Markdown/index.md",
    "# Markdown Lecture\n\n"
  . "Intro with *emphasis* and `a<b` code, course <replace value=\"course\">.\n\n"
  . "## Part One\n\n"
  . "Inline math \\(x^2\\) and display \$\$\\sum_i x_i\$\$.\n\n"
  . "![diagram](pic.png)\n\n"
  . "[the homework](homework.md) and [slides](slides.pdf)\n\n"
  . "<inst>\n\n**INSTRUCTOR-MD-ONLY**\n\n</inst>\n\n"
  . "<inject src=\"part.md\">\n\n"
  . "```python\nprint(1 < 2)\n```\n\n"
  . "| Col A | Col B |\n|---|---|\n| mdcell | 2 |\n\n"
  . "searchable-md-word\n");
put("$root/class/04.Markdown/homework.md", "Do **problem 7**.");
put("$root/class/04.Markdown/part.md", "## Injected Part\n\n- injected item");
put("$root/class/04.Markdown/pic.png", "PNG4");
put("$root/class/04.Markdown/slides.pdf", "%PDF-4");
put("$root/class/04.Markdown/notes.md", "raw notes");
// 'Final Exam' override
put("$root/final-exam/01.Final/index.html", "FINAL-CONTENT");

/* ---------------------------------------------------------------- server */
$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = intval(substr(strrchr(stream_socket_get_name($sock, false), ':'), 1));
fclose($sock);
$server = proc_open(
  array(PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=-1', '-d', 'log_errors=0', '-S', "127.0.0.1:$port", '-t', $root),
  array(0 => array('pipe', 'r'), 1 => array('file', "$root.server.log", 'w'), 2 => array('file', "$root.server.log", 'a')),
  $pipes, $root);
for ($i = 0; $i < 50; $i++) {
  $c = @fsockopen('127.0.0.1', $port);
  if ($c) { fclose($c); break; }
  usleep(100000);
}
register_shutdown_function(function () use (&$server, $root, $outside) {
  if (is_resource($server)) { proc_terminate($server); proc_close($server); }
  rrmdir($root); rrmdir($outside); @unlink("$root.server.log");
});

/* --------------------------------------------------------------- client */
$student = array();
$teacher = array();
function req($path, &$jar, $post = null) {
  global $port;
  $headers = array();
  if (!empty($jar)) {
    $pairs = array();
    foreach ($jar as $k => $v) { $pairs[] = "$k=$v"; }
    $headers[] = 'Cookie: ' . implode('; ', $pairs);
  }
  $opts = array('method' => 'GET', 'ignore_errors' => true, 'follow_location' => 0);
  if ($post !== null) {
    $opts['method'] = 'POST';
    $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    $opts['content'] = http_build_query($post);
  }
  $opts['header'] = implode("\r\n", $headers);
  $body = @file_get_contents("http://127.0.0.1:$port/$path", false, stream_context_create(array('http' => $opts)));
  $resp_headers = isset($http_response_header) ? $http_response_header : array();
  foreach ($resp_headers as $h) {
    if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m)) {
      if ($m[2] === '' || $m[2] === 'deleted') { unset($jar[$m[1]]); } else { $jar[$m[1]] = $m[2]; }
    }
  }
  $status = 0;
  if (isset($resp_headers[0]) && preg_match('#HTTP/\S+\s+(\d+)#', $resp_headers[0], $m)) { $status = intval($m[1]); }
  $body = (string)$body;
  check(!preg_match('#<b>(Warning|Notice|Deprecated|Fatal error|Parse error)</b>:#', $body, $m),
        "no PHP " . (isset($m[1]) ? $m[1] : 'errors') . " for /$path");
  return array('status' => $status, 'headers' => implode("\n", $resp_headers), 'body' => $body);
}
function login(&$jar, $script = 'calendar.php') {
  req($script, $jar);
  req($script, $jar, array('password' => 'testpass'));
}

login($teacher);
$r = req('calendar.php?show=calendar_display', $teacher);
check(strpos($r['body'], 'Logoff') !== false, 'instructor login works');

/* ----------------------------------------------------------------- tests */
section('C-1 unmapped events are hidden from students');
$r = req('calendar.php?type=exam&event=3', $student);
check(strpos($r['body'], 'SECRET-EXAM-3') === false, 'student can not open exam 3 (no exam day)');
$r = req('calendar.php?key=' . key_for('exam', '03.Secret', 'index.html') . '&type=exam&event=3', $student);
check(strpos($r['body'], 'SECRET-EXAM-3') === false, 'student can not open exam 3 by key');
$r = req('calendar.php?type=exam&event=3', $teacher);
check(strpos($r['body'], 'SECRET-EXAM-3') !== false, 'instructor can still open exam 3');
$r = req('calendar.php?type=exam&event=1', $student);
check(strpos($r['body'], 'EXAM-ONE') === false, 'mapped exam 1 still follows the access file');

section('C-2 / H-5 event and type validation');
$k3 = key_for('class', '03.Intro.Part2', 'index.html');
foreach (array('3', '03', '3%20', '3.0', '+3') as $ev) {
  $r = req("calendar.php?key=$k3&type=class&event=$ev", $student);
  check(strpos($r['body'], 'CLASS-THREE-SECRET') === false, "hidden class 3 not served with event=$ev");
}
foreach (array('type=exam&event=abc', 'type=exam&event[]=1', 'type[]=x&event=1', 'type=nosuch&event=1',
               'show[]=x', 'load[]=x', 'show=calendar_search&search[]=x', 'key[]=1&type=class&event=1') as $q) {
  $r = req("calendar.php?$q", $student);
  check($r['status'] == 200, "status 200 for $q");
}
$r = req('calendar.php?type=class&event=1', $student);
check(strpos($r['body'], 'Intro') !== false, 'valid event still works');

section('C-3 secret checks');
$r = req('sample_secret.php?type=class&event=1', $student);
check(strpos($r['body'], 'not been configured with a private $SECRET') !== false, 'sample secret shows configuration error');
check(strpos($r['body'], 'HOST-SHOWN') === false, 'sample secret shows no content');
$weak = array();
login($weak, 'weak_secret.php');
$r = req('weak_secret.php?load=home', $weak);
check(strpos($r['body'], 'is weak') !== false, 'weak secret warning shown to instructor');
$r = req('weak_secret.php?load=home', $student);
check(strpos($r['body'], 'is weak') === false, 'weak secret warning not shown to students');
check(strpos(file_get_contents("$CORE/installer.sh"), 'random_bytes(32)') !== false, 'installer generates a random secret');

section('C-4 editor CSRF protection');
$f = "$root/class/01.Intro/homework.html";
$kh = key_for('class', '01.Intro', 'homework.html');
$kp = key_for('class', '01.Intro', 'pic.png');
req("calendar.php?edit=1&navbaronly=1&key=$kh&type=class&event=1&editor=HACKED", $teacher);
check(file_get_contents($f) === 'HOMEWORK-ONE', 'GET request does not save');
$r = req("calendar.php?edit=1&navbaronly=1&key=$kh&type=class&event=1", $teacher, array('editor' => 'HACKED'));
check(file_get_contents($f) === 'HOMEWORK-ONE', 'POST without token does not save');
$r = req("calendar.php?edit=1&navbaronly=1&key=$kh&type=class&event=1", $teacher);
preg_match('/name="csrf" value="([0-9a-f]+)"/', $r['body'], $m);
check(isset($m[1]), 'edit form has a csrf token');
$token = isset($m[1]) ? $m[1] : '';
req("calendar.php?edit=1&navbaronly=1&key=$kh&type=class&event=1", $teacher, array('editor' => 'EDITED', 'csrf' => $token));
check(file_get_contents($f) === 'EDITED', 'POST with token saves');
file_put_contents($f, 'HOMEWORK-ONE');
$r = req("calendar.php?edit=1&navbaronly=1&key=$kp&type=class&event=1", $teacher, array('editor' => '<?php echo 1;', 'csrf' => $token));
check(file_get_contents("$root/class/01.Intro/pic.png") === 'PNGDATA', 'non-html file can not be overwritten');
check(strpos($r['body'], 'Only .html, .htm and .md files') !== false, 'editor explains html-only');
req("calendar.php?edit=1&navbaronly=1&key=$kh&type=class&event=1", $student, array('editor' => 'STUDENT', 'csrf' => $token));
check(file_get_contents($f) === 'HOMEWORK-ONE', 'students can not save');
$fresh = array();
$r = req('calendar.php', $fresh);
check((bool)preg_match('/Set-Cookie: PHPSESSID=[^\n]*HttpOnly/i', $r['headers']) && (bool)preg_match('/Set-Cookie: PHPSESSID=[^\n]*SameSite=Lax/i', $r['headers']), 'session cookie is HttpOnly + SameSite=Lax');
check((bool)preg_match('/Set-Cookie: cal4-TST101-nonce=[^\n]*HttpOnly[^\n]*SameSite=Lax/i', $r['headers']), 'nonce cookie is HttpOnly + SameSite=Lax');

section('H-1 request values are escaped');
$k1 = key_for('class', '02.Second', 'index.html');
$r = req("calendar.php?key=$k1&type=class&event=2%22%20onerror=%22alert(1)", $student);
check(strpos($r['body'], 'onerror="alert(1)') === false, 'event attribute injection blocked');
$r = req("calendar.php?key=$k1&type=class&event=2", $student);
check(strpos($r['body'], '<img src="calendar.php?key=' . key_for('class', '02.Second', 'pic.png') . '&type=class&event=2"') !== false, 'image links are still rewritten');
$r = req("calendar.php?navbaronly=1&type=class&event=1%27%3E%3Cscript%3Ealert(1)%3C/script%3E", $teacher);
check(strpos($r['body'], '<script>alert(1)') === false, 'file selector does not reflect script');
$r = req("calendar.php?navbaronly=1&type=class%27%3E%3Cscript%3Ealert(2)%3C/script%3E&event=1", $teacher);
check(strpos($r['body'], '<script>alert(2)') === false, 'bad type is not reflected');
$r = req('calendar.php?type=class&event=1&who=%22%3E%3Cx%3E', $student);
check(strpos($r['body'], 'Hello &quot;&gt;&lt;x&gt;') !== false, '<url> value is escaped');
check(strpos($r['body'], 'title="a&quot;b&lt;c&gt;"') !== false, '<replace> value escapes quotes');
$r = req('calendar.php?show=calendar_search&search=%3Cscript%3Ealert(3)%3C/script%3E', $student);
check(strpos($r['body'], '<script>alert(3)') === false, 'search term is escaped');

section('H-4 $REVEALHOUR');
$r = req('reveal_past.php?type=class&event=1', $student);
check(strpos($r['body'], 'POSTMEET') !== false, 'past meeting content revealed');
check(strpos($r['body'], 'Would show content') === false, 'no debug text');
$r = req('reveal_future.php?type=class&event=1', $student);
check(strpos($r['body'], 'POSTMEET') === false, 'future meeting content hidden');
$r = req('reveal_past.php?load=home', $student);
check($r['status'] == 200, 'home page works with $REVEALHOUR');

section('H-6 override types with spaces/capitals');
$r = req('calendar.php?show=calendar_display', $student);
check(strpos($r['body'], "calendar.php?type=final-exam&event=1'") !== false, "'Final Exam' day links to final-exam/01");
check(strpos($r['body'], 'FINAL EXAM 1') !== false, 'label is shown with spaces');
$r = req('calendar.php?type=final-exam&event=1', $student);
check(strpos($r['body'], 'FINAL-CONTENT') !== false, 'final exam content served');

section('H-7 legacy date naming removed');
$r = req('calendar.php?show=calendar_display', $student);
check(strpos($r['body'], key_for('class', '02.Second', 'homework.12.31.html')) === false, 'homework.12.31.html is not treated as homework.html');
check(strpos($r['body'], key_for('class', '01.Intro', 'homework.html')) !== false, 'normal homework.html still linked');
check(strpos(file_get_contents("$CORE/instructor.html"), 'ln -s my_powerpoint') === false, 'legacy docs removed');

section('H-8 ?show= allow-list');
$r = req('calendar.php?show=template_navbar_example', $student);
check(strpos($r['body'], '../example/status.php') === false, 'template_navbar_example not included');
req('calendar.php?show=template_navbar', $student);
$r = req('calendar.php?show=calendar_display', $student);
check(strpos($r['body'], '<h4>August 2018</h4>') !== false, 'calendar_display still works');
$r = req("calendar.php?show=calendar_file_selector&type=class&event=1", $student);
check(strpos($r['body'], 'View / Edit') === false, 'file selector is instructor only');

section('M-1 / M-2 search');
$r = req('calendar.php?show=calendar_search&search=zzz', $student);
check(strpos($r['body'], 'Class 1</td>') !== false, 'class 1 found');
check(strpos($r['body'], 'Class 2</td>') !== false, 'class 2 (single match, after a result) found');
req('calendar.php?show=calendar_search&search=/e', $student);
req('calendar.php?show=calendar_search&search=(a%2B)%2B$', $student);
$r = req('calendar.php?show=calendar_keyword&keyword=x', $student);
check($r['status'] == 200, 'keyword search works');

section('M-3 access/virtual file parsing');
$r = req('calendar.php?navbaronly=1&type=class&event=1', $teacher);
check(strpos($r['body'], '>extra.html<') !== false, 'tab separated virtual line with spaces is loaded');
check(strpos($r['body'], 'Configuration warnings') !== false, 'instructor sees configuration warnings');
check(strpos($r['body'], 'broken line here') !== false, 'bad virtual line reported');
check(strpos($r['body'], 'class_1/foo +frday') !== false, 'bad access line reported');
check(strpos($r['body'], 'one two three') === false && strpos($r['body'], 'Ignored line 1 ') === false, 'comment lines are ignored silently');
$r = req('calendar.php?load=home', $student);
check(strpos($r['body'], 'Configuration warnings') === false, 'students do not see configuration warnings');

section('M-4 ?load= allow-list');
$r = req('calendar.php?load=instructor', $student);
check(strpos($r['body'], 'Directory Structure') === false, 'students can not load the instructor guide');
$r = req('calendar.php?load=instructor', $teacher);
check(strpos($r['body'], 'Directory Structure') !== false, 'instructor can load the instructor guide');
$r = req('calendar.php?load=extra', $student);
check(strpos($r['body'], 'EXTRA-PAGE') !== false, '$LOAD_PAGES page loads');
$r = req('calendar.php?load=notlisted', $student);
check(strpos($r['body'], 'NOT-LISTED-PAGE') === false, 'unlisted root page does not load');
$r = req('calendar.php?load=home', $student);
check(strpos($r['body'], 'HOME-PAGE') !== false, 'home page loads');

section('M-5 access log');
req('calendar.php?password=wrongpw123&lock=lockcode77', $student);
$log = file_get_contents("$root/logs/access.log");
check(strpos($log, 'wrongpw123') === false && strpos($log, 'lockcode77') === false, 'password and lock code not logged');
check(strpos($log, 'password=REMOVED') !== false, 'parameter is logged as REMOVED');
$r = req('calendar.php?load=home', $teacher);
check(strpos($r['body'], 'access log') !== false, 'instructor warned that the log is inside the web root');

section('M-6 symlinks');
$r = req('calendar.php?navbaronly=1&type=class&event=1', $teacher);
check(strpos($r['body'], 'outside.txt') === false, 'symlink outside the course is ignored');
check(strpos($r['body'], 'inside-link.html') !== false, 'symlink inside the course is listed');
$r = req('calendar.php?key=' . key_for('class', '01.Intro', 'outside.txt') . '&type=class&event=1', $student);
check(strpos($r['body'], 'OUTSIDE-SECRET') === false, 'outside file not served');

section('L-1 .. L-13');
$r = req('calendar.php?type=class&event=1', $student);
check(strpos($r['body'], 'Show Problem Answers') !== false, 'L-1 <student> at position 0 shows the answers toggle');
check(strpos($r['body'], 'HOST-SHOWN') !== false, "L-7 single quoted <host server='...'> shown on a matching server");
check(strpos($r['body'], 'HOST-HIDDEN') === false, "L-7 single quoted <host server='...'> hidden on other servers");
check(strpos($r['body'], 'ace.edit("ed1")') !== false && strpos($r['body'], 'ace.edit("ace1")') !== false && strpos($r['body'], '#ed1 { height: 20px; }') !== false, 'L-8 ace editors get the right names and heights');
check(strpos($r['body'], '3 - Intro.Part2') !== false, 'L-2 event name keeps everything after the first dot');
check((bool)preg_match('/1 - Alpha.*2 - Beta.*3 - Gamma/s', $r['body']), 'L-3 natural sort order of directories');
check(stripos($r['headers'], 'X-Content-Type-Options: nosniff') !== false, 'L-9 nosniff header');
check(stripos($r['headers'], "Content-Security-Policy: object-src 'none'") !== false, 'L-9 CSP header');
check(strpos($r['body'], 'Test &lt;b&gt;Course&lt;/b&gt;') !== false, 'L-10 course name escaped');
check(strpos($r['body'], '<title>TST101 &lt;title&gt;</title>') !== false, 'L-10 page title escaped');
$r = req('calendar.php?answers=yes&type=class&event=1', $student);
check(strpos($r['body'], 'ANSWER-ONE') !== false, '<student> answers still revealable');
$r = req('calendar.php?navbaronly=1&type=class&event=1', $teacher);
check(strpos($r['body'], 'sub/visible.txt') !== false, 'L-4 nested files listed');
check(strpos($r['body'], '.secret.txt') === false, 'L-4 nested hidden files ignored');
$r = req('locked.php', $student);
check(strpos($r['body'], 'hashPassword()">\'') === false && strpos($r['body'], 'hashPassword()">') !== false, 'L-5 no stray quote in lock form');
$r = req('calendar.php?debug=1&type=class&event=1', $teacher);
check(strpos($r['body'], '$keypairs=') === false, 'L-13 debug output off by default');
$r = req('debug.php?debug=1&type=class&event=1', $teacher);
check(strpos($r['body'], '$keypairs=') !== false, 'L-13 debug output with $DEBUG_MODE');

section('Markdown (.md) files');
$k4 = key_for('class', '04.Markdown', 'pic.png');
$r = req('calendar.php?type=class&event=4', $student);
check(stripos($r['headers'], 'Content-Type: text/html') !== false, 'index.md is served as a rendered page');
check(strpos($r['body'], '<h1>Markdown Lecture</h1>') !== false, 'heading rendered');
check(strpos($r['body'], '<em>emphasis</em>') !== false && strpos($r['body'], '<code>a&lt;b</code>') !== false, 'inline markup rendered');
check(strpos($r['body'], 'course TST101.') !== false, '<replace> works inside Markdown');
check(strpos($r['body'], '<h2 id="part-one">Part One</h2>') !== false, 'h2 gets an id');
check(strpos($r['body'], '<a href="#part-one">Part one</a>') !== false, 'h2 appears in the navbar menu');
check(strpos($r['body'], '\\(x^2\\)') !== false && strpos($r['body'], '$$\\sum_i x_i$$') !== false, 'MathJax delimiters preserved');
check(strpos($r['body'], '<img src="calendar.php?key=' . $k4 . '&type=class&event=4"') !== false, 'Markdown image rewritten to keyed link');
check(strpos($r['body'], '<a href="calendar.php?key=' . key_for('class', '04.Markdown', 'homework.md') . '&type=class&event=4">the homework</a>') !== false, 'Markdown link rewritten to keyed link');
check(strpos($r['body'], 'INSTRUCTOR-MD-ONLY') === false, '<inst> block hidden from students');
check(strpos($r['body'], '<h2 id="injected-part">Injected Part</h2>') !== false && strpos($r['body'], '<li>injected item</li>') !== false, '<inject src="part.md"> is rendered');
check(strpos($r['body'], '<code class="language-python">print(1 &lt; 2)') !== false, 'fenced code block rendered');
check(strpos($r['body'], '<td>mdcell</td>') !== false, 'table rendered');
$r = req('calendar.php?type=class&event=4', $teacher);
check(strpos($r['body'], '<strong>INSTRUCTOR-MD-ONLY</strong>') !== false, '<inst> block (with Markdown inside) shown to instructor');
$r = req('calendar.php?key=' . key_for('class', '04.Markdown', 'homework.md') . '&type=class&event=4', $student);
check(strpos($r['body'], 'Do <strong>problem 7</strong>.') !== false, 'keyed homework.md is rendered');
$r = req('calendar.php?key=' . key_for('class', '04.Markdown', 'notes.md') . '&type=class&event=4', $student);
check(strpos($r['body'], '<p>raw notes</p>') !== false, 'any .md file in the event is rendered');
$r = req('calendar.php?key=' . key_for('class', '04.Markdown', 'slides.pdf') . '&type=class&event=4', $student);
check($r['body'] === '%PDF-4', 'other file types are still served unchanged');
$r = req('calendar.php?show=calendar_display', $student);
check(strpos($r['body'], "calendar.php?type=class&event=4'>Markdown</a>") !== false, 'index.md is the calendar title link');
check(strpos($r['body'], key_for('class', '04.Markdown', 'homework.md')) !== false, 'homework.md fills the homework box');
$r = req('md_off.php?show=calendar_display', $student);
check(strpos($r['body'], key_for('class', '04.Markdown', 'homework.md')) === false, '$MD = array() turns off Markdown categories');
$r = req('calendar.php?show=calendar_search&search=searchable-md-word', $student);
check(strpos($r['body'], 'Class 4</td>') !== false, 'search finds Markdown pages');
$r = req('calendar.php?load=mdpage', $student);
check(strpos($r['body'], '<h1>Root Markdown</h1>') !== false && strpos($r['body'], 'Root <strong>page</strong> for TST101.') !== false, '?load= renders a root .md page');
$r = req('calendar.php?navbaronly=1&type=class&event=4', $teacher);
check(strpos($r['body'], "edit=1'>view</a>") !== false, 'file list offers view/edit for .md');
$kmd = key_for('class', '04.Markdown', 'homework.md');
$r = req("calendar.php?edit=1&navbaronly=1&key=$kmd&type=class&event=4", $teacher);
check(strpos($r['body'], 'ace/mode/markdown') !== false, 'editor uses Markdown mode');
check(strpos($r['body'], 'Do **problem 7**.') !== false, 'editor shows the Markdown source');
preg_match('/name="csrf" value="([0-9a-f]+)"/', $r['body'], $m);
req("calendar.php?edit=1&navbaronly=1&key=$kmd&type=class&event=4", $teacher, array('editor' => 'Edited **md**', 'csrf' => isset($m[1]) ? $m[1] : ''));
check(file_get_contents("$root/class/04.Markdown/homework.md") === 'Edited **md**', 'editor saves .md files');

section('PHP source is never sent to students');
$ks = key_for('class', '02.Second', 'script.php');
$r = req("calendar.php?key=$ks&type=class&event=2", $student);
check(strpos($r['body'], 'PHP-SOURCE-SECRET') === false && strpos($r['body'], '<?php') === false, 'student can not download .php source by key');
check(stripos($r['headers'], 'Content-Disposition') === false, 'no download is offered to the student');
$r = req("calendar.php?key=" . key_for('class', '02.Second', 'page.phtml') . "&type=class&event=2", $student);
check(strpos($r['body'], 'PHTML-SOURCE-SECRET') === false, 'student can not download .phtml source');
$r = req("calendar.php?key=$ks&type=class&event=2&navbaronly=1", $student);
check(strpos($r['body'], 'PHP-SOURCE-SECRET') === false, 'navbaronly does not expose the source either');
$r = req('calendar.php?type=class&event=2', $student);
check(strpos($r['body'], 'CALPHP-OUTPUT') !== false && strpos($r['body'], 'PHP-SOURCE-SECRET') === false, '<calphp> still runs the script for students');
$r = req("calendar.php?key=$ks&type=class&event=2", $teacher);
check(strpos($r['body'], 'PHP-SOURCE-SECRET') !== false, 'instructor can still download the source');

/* --------------------------------------------------------------- summary */
echo "\n$passes passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);
