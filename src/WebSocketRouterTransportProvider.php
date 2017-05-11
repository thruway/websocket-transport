<?php

namespace Thruway\Transport;

use function GuzzleHttp\Psr7\parse_request;
use function GuzzleHttp\Psr7\str;
use Ratchet\RFC6455\Handshake\PermessageDeflateOptions;
use Ratchet\RFC6455\Handshake\RequestVerifier;
use Ratchet\RFC6455\Handshake\ServerNegotiator;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\FrameInterface;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use React\Socket\ConnectionInterface;
use React\Socket\Server;
use Thruway\Event\ConnectionOpenEvent;
use Thruway\Event\RouterStartEvent;
use Thruway\Event\RouterStopEvent;
use Thruway\Logging\Logger;
use Thruway\Message\HelloMessage;
use Thruway\Serializer\DeserializationException;
use Thruway\Session;

final class WebSocketRouterTransportProvider extends AbstractRouterTransportProvider
{
    /** @var string */
    private $listenAddress;
    private $context;
    /** @var \SplObjectStorage */
    private $sessions;

    public function __construct($listenAddress = 'tcp://127.0.0.1:9090/', $context = [])
    {
        $this->listenAddress = $listenAddress;
        $this->context       = $context;
        $this->sessions      = new \SplObjectStorage();
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

            echo $header;

            try {
                $psrRequest = parse_request($header);
            } catch (\Throwable $e) {
                Logger::log($this, LOG_ERR, $e->getMessage());
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

            /** @var Session $session */
            $session = $this->router->createNewSession(new WebSocketTransport(
                $psrRequest,
                $response,
                $connection,
                $messageBuffer = new MessageBuffer(
                    new CloseFrameChecker(),
                    function (\Ratchet\RFC6455\Messaging\Message $message) use (&$session) {
                        if ($message->isBinary()) {
                            throw new \Exception('Received binary websocket frame.');
                        }

                        $msg = $message->getPayload();

                        Logger::debug($this, "onMessage: ({$msg})");

                        try {
                            $msg = $session->getTransport()->getSerializer()->deserialize($msg);

                            if ($msg instanceof HelloMessage) {
                                $details = $msg->getDetails();

                                $details->transport = (object)$session->getTransport()->getTransportDetails();

                                $msg->setDetails($details);
                            }

                            $session->dispatchMessage($msg);
                        } catch (DeserializationException $e) {
                            Logger::alert($this, "Deserialization exception occurred.");
                        } catch (\Exception $e) {
                            Logger::alert($this, "Exception occurred during onMessage: " . $e->getMessage());
                        }
                    },
                    function ($data) use ($connection) {
                        $connection->write($data);
                    },
                    function (FrameInterface $frame) use (&$messageBuffer, $connection) {
                        switch ($frame->getOpCode()) {
                            case Frame::OP_CLOSE:
                                Logger::info($this, 'Got close frame');
                                $connection->end($frame->getContents());
                                break;
                            case Frame::OP_PING:
                                $connection->write($messageBuffer->newFrame($frame->getPayload(), true,
                                    Frame::OP_PONG)->getContents());
                                break;
                        }
                    },
                    true,
                    null,
                    PermessageDeflateOptions::fromRequestOrResponse($response)[0]
                )));;

            $connection->removeAllListeners();
            $connection->on('data', [$messageBuffer, 'onData']);
            $connection->on('error', function (\Exception $e) {
            });
            $connection->on('end', function () {
            });

            $this->router->getEventDispatcher()->dispatch("connection_open", new ConnectionOpenEvent($session));

            $messageBuffer->onData($bodyStart);
        });

        $connection->on('error', function (\Exception $e) {
            Logger::error($this, 'Connection error');
        });

        $connection->on('end', function () {
            Logger::info($this, "Connection ended.");
        });
    }

    public function handleRouterStart(RouterStartEvent $event)
    {
        $socket = new Server($this->listenAddress, $this->getLoop(), $this->context);

        $socket->on('connection', [$this, 'onNewConnection']);
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