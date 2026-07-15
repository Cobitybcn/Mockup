<?php
declare(strict_types=1);
require_once __DIR__ . '/TestHarness.php';
require_once __DIR__ . '/regression/public_pages_test.php';
run_public_pages_regression_tests();
exit(TestHarness::summary());
