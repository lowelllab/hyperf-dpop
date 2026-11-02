<?php
declare(strict_types=1);
return ['default' => ['host' => getenv('DPOP_TEST_REDIS_HOST') ?: 'redis', 'port' => 6379, 'auth' => null, 'db' => 0, 'timeout' => 2.0, 'pool' => ['min_connections' => 1, 'max_connections' => 20, 'connect_timeout' => 2.0, 'wait_timeout' => 2.0, 'heartbeat' => -1, 'max_idle_time' => 60.0]]];
