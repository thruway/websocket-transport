<?php

namespace Thruway\Transport;

use function GuzzleHttp\Psr7\parse_query;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use React\Socket\ConnectionInterface;
use Thruway\Message\Message;
use Thruway\Serializer\JsonSerializer;

final class WebSocketTransport  extends AbstractTransport {
    private $request;
    private $response;
    private $connection;
    private $messageBuffer;

    public function __construct(RequestInterface $request, ResponseInterface $response, ConnectionInterface $connection, MessageBuffer &$messageBuffer = null)
    {
        $this->request       = $request;
        $this->response      = $response;
        $this->connection    = $connection;
        $this->messageBuffer = &$messageBuffer;
        $this->setSerializer(new JsonSerializer());
    }



    public function getTransportDetails()
    {
        return [
            "type"              => "ratchet",
            "transport_address" => trim(parse_url($this->connection->getRemoteAddress(), PHP_URL_HOST), '[]'),
            "headers"           => $this->request->getHeaders(),
            "url"               => $this->request->getUri()->getPath(),
            "query_params"      => parse_query($this->request->getUri()->getQuery()),
            "cookies"           => $this->request->getHeader("Cookie")
        ];
    }

    public function sendMessage(Message $msg)
    {
        echo "Sending: " . json_encode($msg) . "\n";
        if ($this->messageBuffer === null) {
            throw new \Exception('messageBuffer is not set.');
        }
        $this->messageBuffer->sendMessage($this->getSerializer()->serialize($msg));
    }

    public function close() {
        $this->connection->close();
    }
}