<?php
declare(strict_types=1);
use Hyperf\Server\Event;
use Hyperf\Server\Server;
$tlsCert = getenv('DPOP_EXAMPLE_TLS_CERT') ?: '';
$tlsKey = getenv('DPOP_EXAMPLE_TLS_KEY') ?: '';
if ($tlsCert !== '' && (!is_readable($tlsCert) || !is_readable($tlsKey))) { throw new RuntimeException('Invalid example TLS fixture'); }
$configuration = [
    'mode' => SWOOLE_PROCESS,
    'servers' => [['name' => 'http', 'type' => Server::SERVER_HTTP, 'host' => '127.0.0.1', 'port' => $tlsCert !== '' ? 9510 : 9509, 'sock_type' => SWOOLE_SOCK_TCP | ($tlsCert !== '' ? SWOOLE_SSL : 0), 'callbacks' => [Event::ON_REQUEST => [Hyperf\HttpServer\Server::class, 'onRequest']]]],
    'settings' => ['worker_num' => 2, 'enable_coroutine' => true, 'hook_flags' => SWOOLE_HOOK_ALL, 'pid_file' => BASE_PATH . '/runtime/server.pid', 'log_file' => BASE_PATH . '/runtime/swoole.log', 'log_level' => SWOOLE_LOG_WARNING],
    'callbacks' => [Event::ON_WORKER_START => [Hyperf\Framework\Bootstrap\WorkerStartCallback::class, 'onWorkerStart'], Event::ON_PIPE_MESSAGE => [Hyperf\Framework\Bootstrap\PipeMessageCallback::class, 'onPipeMessage'], Event::ON_WORKER_EXIT => [Hyperf\Framework\Bootstrap\WorkerExitCallback::class, 'onWorkerExit']],
];
if ($tlsCert !== '') { $configuration['settings']['ssl_cert_file'] = $tlsCert; $configuration['settings']['ssl_key_file'] = $tlsKey; }
return $configuration;
