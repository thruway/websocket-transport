<?php

use React\EventLoop\Factory;
use Thruway\Peer\Router;
use Thruway\Transport\WebSocketRouterTransportProvider;

require __DIR__ . '/../vendor/autoload.php';

$loop = Factory::create();

$router = new Router($loop);

$router->addTransportProvider(new WebSocketRouterTransportProvider());

$router->start();
