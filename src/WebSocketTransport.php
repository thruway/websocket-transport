<?php

namespace Thruway\Transport;

use Thruway\Exception\PingNotSupportedException;
use Thruway\Message\Message;

final class WebSocketTransport  extends AbstractTransport {
    private $sender;
    private $closer;
    private $getTransDetails;
    private $ping;

    public function __construct(callable $sender, callable $closer, callable $getTransportDetails, callable $ping = null)
    {
        $this->sender          = $sender;
        $this->closer          = $closer;
        $this->getTransDetails = $getTransportDetails;
    }

    public function getTransportDetails()
    {
        return call_user_func($this->getTransDetails);
    }

    public function sendMessage(Message $msg)
    {
        return call_user_func($this->sender, $msg);
    }

    public function close()
    {
        return call_user_func($this->closer);
    }

    public function ping()
    {
        if ($this->ping === null) {
            throw new PingNotSupportedException();
        }
        return call_user_func($this->ping);
    }
}