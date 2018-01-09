<?php

namespace Thruway\Transport;

use function GuzzleHttp\Psr7\parse_query;
use function GuzzleHttp\Psr7\parse_request;
use function GuzzleHttp\Psr7\str;
use Ratchet\RFC6455\Handshake\PermessageDeflateOptions;
use Ratchet\RFC6455\Handshake\RequestVerifier;
use Ratchet\RFC6455\Handshake\ServerNegotiator;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\FrameInterface;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Socket\ConnectionInterface;
use React\Socket\Server;
use Thruway\Event\ConnectionCloseEvent;
use Thruway\Event\ConnectionOpenEvent;
use Thruway\Event\RouterStartEvent;
use Thruway\Event\RouterStopEvent;
use Thruway\Logging\Logger;
use Thruway\Message\HelloMessage;
use Thruway\Message\Message;
use Thruway\Serializer\DeserializationException;
use Thruway\Serializer\JsonSerializer;
use Thruway\Session;

final class WebSocketRouterTransportProvider extends AbstractRouterTransportProvider
{
    /** @var string */
    private $listenAddress;
    private $context;
    /** @var \SplObjectStorage */
    private $sessions;

    /** @var int */
    private $timeout;

    private $serverFactory;

    public function __construct($listenAddress = 'tcp://127.0.0.1:9090', $timeout = 0, $context = [], callable $serverFactory = null)
    {
        $this->listenAddress = $listenAddress;
        $this->context       = $context;
        $this->sessions      = new \SplObjectStorage();
        $this->timeout       = $timeout;

        $defaultServerFactory = function ($listenAddress, LoopInterface $loop, array $context) {
            return new Server($listenAddress, $loop, $context);
        };

        $this->serverFactory = $serverFactory === null ? $defaultServerFactory : $serverFactory;
    }

    public function onNewConnection(ConnectionInterface $connection)
    {
        // we are an HTTP server right now
        $connection->on('data', function ($data) use ($connection) {
            static $buffer = '';

            $buffer    .= $data;
            $headerPos = strpos($buffer, "\r\n\r\n");
            if ($headerPos === false) {
                return;
            }

            $header = substr($buffer, 0, $headerPos) . "\r\n";

            try {
                $psrRequest = parse_request($header);
            } catch (\Throwable $e) {
                Logger::error($this, 'Error parsing HTTP Request: ' . $e->getMessage());
                $connection->close();
                return;
            }

            $serverNegotiator = new ServerNegotiator(new RequestVerifier(), true);
            $serverNegotiator->setStrictSubProtocolCheck(true);
            $serverNegotiator->setSupportedSubProtocols(['wamp.2.json']);

            $response = $serverNegotiator->handshake($psrRequest);

            $connection->write(str($response));

            if ($response->getStatusCode() != 101) {
                $connection->end();
                return;
            }

            $headerEnd = strpos($buffer, "\r\n\r\n");

            $bodyStart = substr($buffer, $headerEnd + 4);

            $bodyStart = $bodyStart === false ? '' : $bodyStart;

            $bytesToWire = 0;
            $bytesFromWire = 0;
            $bytesFromSerializer = 0;
            $bytesToDeserializer = 0;

            $connectionOpened = false;

            $timeoutTimer = null;

            /** @var Session $session */
            $session = null;
            $sessionCleanup = function () use (&$session, &$connectionOpened, &$connection, &$timeoutTimer) {
                if ($timeoutTimer !== null) {
                    $timeoutTimer->cancel();
                }
                $this->sessions->detach($session);
                $connection->close();
                if (!$connectionOpened) {
                    return;
                }
                $this->router->getEventDispatcher()
                    ->dispatch('connection_close', new ConnectionCloseEvent($session));
            };

            $serializer = new JsonSerializer();

            /** @var Deferred[] $pongDeferreds */
            $pongDeferreds = [];
            $handlePong = function () use (&$pongDeferreds) {
                while (null !== $deferred = array_shift($pongDeferreds)) {
                    $deferred->resolve();
                }
            };

            $messageBuffer = new MessageBuffer(
                new CloseFrameChecker(),
                function (\Ratchet\RFC6455\Messaging\Message $message) use (&$session, $connection, &$messageBuffer, $serializer, &$bytesToDeserializer) {
                    if ($message->isBinary()) {
                        throw new \Exception('Received binary websocket frame.');
                    }

                    $msg = $message->getPayload();
                    $bytesToDeserializer += strlen($msg);

                    Logger::debug($this, "onMessage: ({$msg})");

                    try {
                        $msg = $serializer->deserialize($msg);

                        if ($msg instanceof HelloMessage) {
                            $details = $msg->getDetails();

                            $details->transport = (object)$session->getTransport()->getTransportDetails();

                            $msg->setDetails($details);
                        }

                        $session->dispatchMessage($msg);
                    } catch (DeserializationException $e) {
                        Logger::alert($this, "Deserialization exception occurred.");
                        /** @var MessageBuffer $messageBuffer */
                        $connection->end($messageBuffer->newCloseFrame(Frame::CLOSE_BAD_DATA, 'Deserialization error'));
                    } catch (\Exception $e) {
                        Logger::alert($this, "Exception occurred during onMessage: " . $e->getMessage());
                    }
                },
                function (FrameInterface $frame) use (&$messageBuffer, $connection, $sessionCleanup, &$bytesToWire, &$session, $handlePong) {
                    switch ($frame->getOpCode()) {
                        case Frame::OP_CLOSE:
                            Logger::debug($this, 'Got close frame');
                            $connection->end($frame->getContents());
                            $sessionCleanup();
                            break;
                        case Frame::OP_PING:
                            $rawMsg      = $messageBuffer->newFrame($frame->getPayload(), true,
                                Frame::OP_PONG)->getContents();
                            $bytesToWire += strlen($rawMsg);

                            $connection->write($rawMsg);
                            break;
                        case Frame::OP_PONG:
                            $handlePong();
                            break;
                    }
                },
                true,
                null,
                function ($data) use ($connection, &$bytesToWire) {
                    $bytesToWire += strlen($data);
                    $connection->write($data);
                },
                PermessageDeflateOptions::fromRequestOrResponse($response)[0]
            );

            $session = $this->router->createNewSession(new WebSocketTransport(
                function (Message $msg) use ($messageBuffer, $serializer, &$bytesFromSerializer) {
                    if ($messageBuffer === null) {
                        throw new \Exception('messageBuffer is not set.');
                    }
                    $serializedMsg = $serializer->serialize($msg);
                    $bytesFromSerializer += strlen($serializedMsg);
                    $messageBuffer->sendMessage($serializedMsg);
                },
                function () use ($connection) { // connection close
                    $connection->close();
                },
                function () use ($psrRequest, $connection, &$bytesToWire, &$bytesFromWire, &$bytesFromSerializer, &$bytesToDeserializer) { // transport details
                    return [
                        "type"              => "ratchet",
                        "transport_address" => trim(parse_url($connection->getRemoteAddress(), PHP_URL_HOST), '[]'),
                        "headers"           => $psrRequest->getHeaders(),
                        "url"               => $psrRequest->getUri()->getPath(),
                        "query_params"      => parse_query($psrRequest->getUri()->getQuery()),
                        "cookies"           => $psrRequest->getHeader("Cookie"),
                        "compression"       => [
                            "to_wire"         => $bytesToWire,
                            "from_wire"       => $bytesFromWire,
                            "from_serializer" => $bytesFromSerializer,
                            "to_deserializer" => $bytesToDeserializer
                        ]
                    ];
                },
                function () use ($messageBuffer, &$pongDeferreds) { // ping
                    $deferred = new Deferred();
                    $messageBuffer->sendFrame(new Frame('reregister', true, Frame::OP_PING));
                    $pongDeferreds[] = $deferred;
                    return $deferred->promise();
                }
            ));

            $this->sessions->attach($session);

            $lastRecvTime = floor(microtime(true)) * 1000;
            if ($this->timeout > 0) {
                // timeoutTimer is used in session cleanup
                $timeoutTimer = $this->loop->addPeriodicTimer($this->timeout / 1000, function ($timer) use (&$lastRecvTime, $sessionCleanup, $messageBuffer) {
                    $currentMs = floor(microtime(true)) * 1000;
                    if ($currentMs - $lastRecvTime > 2 * $this->timeout) {
                        $sessionCleanup();
                        return;
                    }

                    if ($currentMs - $lastRecvTime > $this->timeout) {
                        $messageBuffer->sendFrame(new Frame(null, true, Frame::OP_PING));
                    }
                });
            }

            $connection->removeAllListeners();
            $connection->on('data', function ($data) use ($messageBuffer, &$bytesFromWire) {
                $bytesFromWire += strlen($data);
                $messageBuffer->onData($data);
            });
            $connection->on('error', function (\Exception $e) use (&$session, $sessionCleanup) {
                Logger::error($this, 'Error on connection: ' . $e->getMessage());
                $sessionCleanup();
            });
            $connection->on('end', function () use ($sessionCleanup, &$session) {
                $sessionCleanup();
            });

            $this->router->getEventDispatcher()->dispatch("connection_open", new ConnectionOpenEvent($session));
            $connectionOpened = true;


            $bytesFromWire += strlen($bodyStart);
            $messageBuffer->onData($bodyStart);
        });

        $connection->on('error', function (\Exception $e) use ($connection) {
            Logger::error($this, 'Connection error');
            $connection->close();
        });

        $connection->on('end', function () {
            Logger::info($this, "Connection ended.");
        });
    }

    public function handleRouterStart(RouterStartEvent $event)
    {
        $serverFactory = $this->serverFactory;
        $socket = $serverFactory($this->listenAddress, $this->getLoop(), $this->context);

        $socket->on('connection', [$this, 'onNewConnection']);

        $socket->on('error', function (\Exception $error) {
            Logger::error($this, 'Error on listening socket: ' . $error->getMessage());
        });
    }

    public function handleRouterStop(RouterStopEvent $event)
    {
        // stop listening for connections
        if ($this->server) {
            $this->server->socket->close();
        }

        foreach ($this->sessions as $k) {
            $this->sessions[$k]->shutdown();
        }
    }

    public static function getSubscribedEvents()
    {
        return [
            "router.start" => ["handleRouterStart", 10],
            "router.stop"  => ["handleRouterStop", 10]
        ];
    }
}