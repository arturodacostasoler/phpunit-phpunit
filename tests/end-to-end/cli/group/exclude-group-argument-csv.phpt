--TEST--
phpunit --exclude-group one,two tests/FooTest.php
--FILE--
<?php declare(strict_types=1);
$traceFile = tempnam(sys_get_temp_dir(), __FILE__);

$_SERVER['argv'][] = '--do-not-cache-result';
$_SERVER['argv'][] = '--no-configuration';
$_SERVER['argv'][] = '--no-output';
$_SERVER['argv'][] = '--log-events-text';
$_SERVER['argv'][] = $traceFile;
$_SERVER['argv'][] = '--exclude-group';
$_SERVER['argv'][] = 'one,two';
$_SERVER['argv'][] = __DIR__ . '/../../_files/groups/tests/FooTest.php';

require_once __DIR__ . '/../../../bootstrap.php';

(new PHPUnit\TextUI\Application)->run($_SERVER['argv']);

print file_get_contents($traceFile);

unlink($traceFile);
--EXPECTF--
PHPUnit Started (PHPUnit %s using %s)
Test Runner Triggered Warning (Using comma-separated values with --exclude-group is deprecated and will no longer work in PHPUnit 12. You can use --exclude-group multiple times instead)
Test Runner Configured
Test Suite Loaded (3 tests)
Event Facade Sealed
Test Runner Started
Test Suite Sorted
Test Suite Filtered (1 test)
Test Runner Execution Started (1 test)
Test Suite Started (PHPUnit\TestFixture\Groups\FooTest, 1 test)
Test Preparation Started (PHPUnit\TestFixture\Groups\FooTest::testThree)
Test Prepared (PHPUnit\TestFixture\Groups\FooTest::testThree)
Test Passed (PHPUnit\TestFixture\Groups\FooTest::testThree)
Test Finished (PHPUnit\TestFixture\Groups\FooTest::testThree)
Test Suite Finished (PHPUnit\TestFixture\Groups\FooTest, 1 test)
Test Runner Execution Finished
Test Runner Finished
PHPUnit Finished (Shell Exit Code: 1)
