<?php
// tests/run.php - dependency-free test runner. No phpunit/composer needed
// (shared hosting friendly). Usage: php tests/run.php [--filter=Name]
//
// Exit code 0 = all pass, 1 = any failure/error. Each tests/*_test.php file
// defines functions named test_*() using the assert_*() helpers below.

error_reporting(E_ALL);

$filter = null;
foreach ($argv as $a) {
  if (strpos($a, '--filter=') === 0) {
    $filter = substr($a, strlen('--filter='));
  }
}

$GLOBALS['__t_pass'] = 0;
$GLOBALS['__t_fail'] = 0;
$GLOBALS['__t_failures'] = array();
$GLOBALS['__t_current'] = '';

function t_ok($cond, $msg) {
  if ($cond) {
    $GLOBALS['__t_pass']++;
  } else {
    $GLOBALS['__t_fail']++;
    $GLOBALS['__t_failures'][] = $GLOBALS['__t_current'] . ' :: ' . $msg;
    echo "  FAIL: $msg\n";
  }
}

function t_eq($actual, $expected, $msg) {
  $ok = $actual === $expected;
  t_ok($ok, $msg . ($ok ? '' : ' (expected ' . var_export($expected, true)
    . ', got ' . var_export($actual, true) . ')'));
}

function t_true($v, $msg) {
  t_ok($v === true, $msg . ($v === true ? '' : ' (got ' . var_export($v, true) . ')'));
}

function t_false($v, $msg) {
  t_ok($v === false, $msg . ($v === false ? '' : ' (got ' . var_export($v, true) . ')'));
}

// test-case temp dir (fresh per file, auto-removed on runner shutdown)
function t_tmpdir() {
  $d = sys_get_temp_dir() . '/tvmtest_' . getmypid() . '_' . substr(md5(mt_rand()), 0, 6);
  mkdir($d, 0777, true);
  $GLOBALS['__t_tmpdirs'][] = $d;
  return $d;
}
$GLOBALS['__t_tmpdirs'] = array();
register_shutdown_function(function () {
  foreach ($GLOBALS['__t_tmpdirs'] as $d) {
    $it = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS),
      RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
      $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($d);
  }
});

$files = glob(__DIR__ . '/*_test.php');
sort($files);
$total_files = 0;
foreach ($files as $f) {
  require_once $f;
}
$funcs = get_defined_functions();
$tests = array();
foreach ($funcs['user'] as $fn) {
  if (strpos($fn, 'test_') === 0 && ($filter === null || stripos($fn, $filter) !== false)) {
    $tests[] = $fn;
  }
}
sort($tests);
foreach ($tests as $fn) {
  $total_files++;
  $GLOBALS['__t_current'] = $fn;
  echo "RUN $fn\n";
  try {
    call_user_func($fn);
  } catch (Exception $e) {
    $GLOBALS['__t_fail']++;
    $GLOBALS['__t_failures'][] = $fn . ' :: EXCEPTION ' . get_class($e) . ': ' . $e->getMessage();
    echo '  EXCEPTION: ' . $e->getMessage() . "\n";
  } catch (Throwable $e) {
    $GLOBALS['__t_fail']++;
    $GLOBALS['__t_failures'][] = $fn . ' :: ERROR ' . get_class($e) . ': ' . $e->getMessage();
    echo '  ERROR: ' . $e->getMessage() . "\n";
  }
}

echo "\n==== " . $GLOBALS['__t_pass'] . " passed, " . $GLOBALS['__t_fail'] . " failed"
  . " (" . count($tests) . " tests) ====\n";
if (count($GLOBALS['__t_failures'])) {
  echo "Failures:\n";
  foreach ($GLOBALS['__t_failures'] as $fl) {
    echo "  - $fl\n";
  }
  exit(1);
}
