<?php
declare(strict_types=1);
use Hyperf\HttpServer\Router\Router;
use DpopExample\ExampleController;
use Acme\HyperfDpop\Middleware\DpopMiddleware;
Router::get('/health', [ExampleController::class, 'health']);
Router::post('/token', [ExampleController::class, 'token']);
Router::post('/refresh', [ExampleController::class, 'refresh']);
Router::post('/jwt/token', [ExampleController::class, 'jwtToken']);
Router::addGroup('', static function (): void {
    Router::get('/items', [ExampleController::class, 'resource']);
    Router::get('/Items', [ExampleController::class, 'resource']);
    Router::get('/jwt/items', [ExampleController::class, 'resource']);
    Router::get('/groups/{group}', [ExampleController::class, 'resource']);
    Router::post('/revoke', [ExampleController::class, 'revoke']);
}, ['middleware' => [DpopMiddleware::class]]);
