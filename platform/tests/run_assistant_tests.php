<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/TestHarness.php';
require_once __DIR__ . '/regression/assistant_persistence_test.php';

run_assistant_persistence_tests();
exit(TestHarness::summary());
