<?php

namespace Thruway\Test;

use PHPUnit\Framework\TestCase;
use Ratchet\Client\WebSocket;
use Ratchet\ConnectionInterface;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\EventLoop\Timer\TimerInterface;
use React\Promise\Deferred;
use React\Promise\FulfilledPromise;
use React\Socket\Connector;
use React\Socket\ConnectorInterface;
use React\Socket\ServerInterface;
use Thruway\ClientSession;
use Thruway\Event\EventDispatcher;
use Thruway\Event\RouterStartEvent;
use Thruway\Message\ErrorMessage;
use Thruway\Message\HelloMessage;
use Thruway\Message\Message;
use Thruway\Message\RegisteredMessage;
use Thruway\Message\RegisterMessage;
use Thruway\Message\WelcomeMessage;
use Thruway\Peer\Client;
use Thruway\Peer\Router;
use Thruway\Transport\PawlTransportProvider;
use Thruway\Transport\RatchetTransportProvider;
use Thruway\Transport\WebSocketRouterTransportProvider;

class WebSocketRouterTransportProviderTest extends TestCase
{
    public function testServerFactoryGetsCalledOnRouterStart() {
        $eventDispatcher = new EventDispatcher();

        $loopMock = $this->createMock(LoopInterface::class);
        $router = $this->createMock(Router::class);

        $factoryCalled = true;

        $transportProvider = new WebSocketRouterTransportProvider(
            'tcp://something/123456789',
            1,
            ['some' => 'thing'],
            function ($address, $loop, $context) use ($loopMock, &$factoryCalled) {
                $this->assertEquals('tcp://something/123456789', $address);
                $this->assertSame($loopMock, $loop);
                $this->assertEquals(['some' => 'thing'], $context);

                $factoryCalled = true;
                return $this->createMock(ServerInterface::class);
            }
        );

        $transportProvider->initModule($router, $loopMock);

        $eventDispatcher->addSubscriber(
            $transportProvider
        );

        $eventDispatcher->dispatch('router.start', new RouterStartEvent());

        $this->assertTrue($factoryCalled);
    }

    public function testWebSocketConnect()
    {
        $loop = Factory::create();

        $router = new Router($loop);

        $router->addTransportProvider(new WebSocketRouterTransportProvider('tcp://127.0.0.1:19090'));
        //$router->addTransportProvider(new RatchetTransportProvider('127.0.0.1', 19090));

        $router->start(false);

        $socketConnector = new class($loop) implements ConnectorInterface {
            private $realConnector;
            /** @var \React\Socket\ConnectionInterface */
            private $conn;

            public function __construct($loop)
            {
                $this->realConnector = new \React\Socket\Connector($loop, [
                    'timeout' => 20
                ]);
            }

            public function connect($uri)
            {
                return $this->realConnector->connect($uri)->then(function (\React\Socket\ConnectionInterface $conn) {
                    return $this->conn = $conn;
                });
            }

            public function hang()
            {
                $this->conn->removeAllListeners('data');
            }

            public function close()
            {
                $this->conn->close();
            }
        };

        $timer = $loop->addTimer(10, function (TimerInterface $timer) {
            $this->fail('Timeout');
            $timer->getLoop()->stop();
        });

        $deferred1 = new Deferred();

        $connector = new \Ratchet\Client\Connector($loop, $socketConnector);
        $connector('ws://127.0.0.1:19090/', ['wamp.2.json'])
            ->then(function (WebSocket $ws) use ($deferred1) {
                $ws->on('message', function (MessageInterface $message) use ($ws, $deferred1) {
                    $message = Message::createMessageFromArray(json_decode($message->getPayload()));
                    if ($message instanceof WelcomeMessage) {
                        $ws->send(json_encode(new RegisterMessage(1, (object)[], 'some.procedure')));
                        return;
                    }
                    if ($message instanceof RegisteredMessage) {
                        $deferred1->resolve();
                        return;
                    }
                    $this->fail('unexpected message');
                });
                $ws->send(json_encode(new HelloMessage('some.realm', (object)[])));
            });

//        $client1 = new Client('some.realm', $loop);
//        $client1->setAttemptRetry(false);
//        $client1->addTransportProvider(new PawlTransportProvider('ws://127.0.0.1:19090'));
//
//        $client1->on('open', function (ClientSession $session) use ($deferred1) {
//            $session->register('some.procedure', function () {
//                return 1;
//            })->then([$deferred1,'resolve'], [$deferred1,'reject']);
//        });
//        $client1->start(false);


        $client2 = new Client('some.realm', $loop);
        $client2->setAttemptRetry(false);
        $client2->addTransportProvider(new PawlTransportProvider('ws://127.0.0.1:19090'));
        $client2->on('open', function (ClientSession $session) use ($deferred1, $socketConnector, $client2, $router, $timer) {
            $deferred1->promise()->then(function () use ($session) {
                return $session->register('some.procedure', function () {
                    return 2;
                });
            })->then(function () {
                $this->fail('unexpected resolution');
            })->otherwise(function ($error) use ($session, $socketConnector) {
                /** @var $error ErrorMessage */
                $this->assertInstanceOf(ErrorMessage::class, $error);
                $this->assertEquals('wamp.error.procedure_already_exists', $error->getErrorURI());
                $socketConnector->hang();
                return $session->register('some.procedure', function () {
                    return 3;
                }, [ 'replace_orphaned_session' => 'yes' ]);
            })->otherwise(function () {
                $this->fail('failed to replace orphaned registration');
            })->always(function () use ($client2, $router, $socketConnector, $timer) {
                $timer->cancel();
                if ($client2->getSession()) {
                    $client2->getSession()->close();
                }
                $socketConnector->close();
                $router->stop();
            });
        });
        $client2->start(false);

        $loop->run();
    }
}
