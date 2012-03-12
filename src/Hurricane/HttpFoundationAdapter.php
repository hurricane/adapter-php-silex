<?php

namespace Hurricane;

use \Hurricane\Gateway;
use \Hurricane\Message;
use \Hurricane\Erlang\SocketWrapper;
use \Hurricane\Erlang\DataType\Tuple;
use \Symfony\Component\HttpFoundation\Request;
use \Symfony\Component\HttpFoundation\Response;

/**
 * Encapsulates useful behavior when bridging Symfony components and
 * Hurricane.
 */
class HttpFoundationAdapter
{
    /**
     * @var string
     */
    private $_host;

    /**
     * @var integer
     */
    private $_port;

    /**
     * @var string
     */
    private $_processGroup;

    /**
     * @var \Hurricane\Gateway
     */
    private $_gateway;

    /**
     * @var array
     */
    private $_currentRequest = null;

    /**
     * Build a new adapter.
     *
     * @param string host
     * @param integer port
     * @param string processGroup
     *
     * @return void
     */
    public function __construct($host, $port, $processGroup='http_handler')
    {
        $this->setHost($host);
        $this->setPort($port);
        $this->setProcessGroup($processGroup);
        $this->_gateway = new Gateway(
            new SocketWrapper($this->getHost(), $this->getPort())
        );
        $this->_gateway->registerServer($this->getProcessGroup());
    }

    /**
     * Getter for host.
     *
     * @return string
     */
    public function getHost()
    {
        return $this->_host;
    }

    /**
     * Setter for host.
     *
     * @param string $host
     *
     * @return void
     */
    public function setHost($host)
    {
        $this->_host = $host;
    }

    /**
     * Getter for port.
     *
     * @return integer
     */
    public function getPort()
    {
        return $this->_port;
    }

    /**
     * Setter for port.
     *
     * @param integer $port
     *
     * @return void
     */
    public function setPort($port)
    {
        $this->_port = $port;
    }

    /**
     * Getter for process group.
     *
     * @return string
     */
    public function getProcessGroup()
    {
        return $this->_processGroup;
    }

    /**
     * Setter for process group.
     *
     * @param string $processGroup
     *
     * @return void
     */
    public function setProcessGroup($processGroup)
    {
        $this->_processGroup = $processGroup;
    }

    /**
     * Getter for gateway.
     *
     * @return \Hurricane\Gateway
     */
    public function getGateway()
    {
        return $this->_gateway;
    }

    /**
     * Return the current request object (null if not currently
     * handling a request).
     *
     * Return array
     */
    public function getCurrentRequest()
    {
        return $this->_currentRequest;
    }

    /**
     * Get the next HTTP request from Hurricane.
     *
     * @return \Symfony\Component\HttpFoundation\Request
     */
    public function getNextRequest()
    {
        $hurricaneRequest = $this->_gateway->recv();
        $hurricaneHttpRequest = $hurricaneRequest->getData();
        $this->_currentRequest = $hurricaneRequest;
        return self::requestToSymfony($hurricaneHttpRequest);
    }

    /**
     * Send a response to the previous HTTP request.
     *
     * @param \Symfony\Component\HttpFoundation\Response $response
     *
     * @return void
     */
    public function sendNextResponse(Response $symfonyResponse)
    {
        if ($this->getCurrentRequest() === null) {
            throw new HttpFoundationAdapter\Exception(
                'Not currently handling a request!'
            );
        }

        $hurricaneHttpResponse = new Tuple(
            array(
                $symfonyResponse->getStatusCode(),
                $symfonyResponse->headers->all(),
                $symfonyResponse->getContent(),
            )
        );

        $hurricaneResponse = Message::create()
            ->setType('response')
            ->setDestination($this->getCurrentRequest()->getDestination())
            ->setTag($this->getCurrentRequest()->getTag())
            ->setData($hurricaneHttpResponse);
        $this->_gateway->send($hurricaneResponse);

        $this->_currentRequest = null;
    }

    /**
     * Take a Hurricane HTTP request and transforms it into a Symfony HTTP
     * request.
     *
     * @param \Hurricane\Erlang\DataType\Tuple $hurricaneRequest
     *
     * @return \Symfony\Component\HttpFoundation\Request
     */
    public static function requestToSymfony($hurricaneRequest)
    {
        $query = array();
        $request = array();
        $attributes = array();
        $cookies = array();
        $files = array();
        $server = $_SERVER;
        $content = '';

        foreach ($hurricaneRequest as $tuple) {
            $key = $tuple[0]->getName();
            $value = $tuple[1];
            if ($key == 'listen_port') {
                $server['SERVER_PORT'] = $value;
            } elseif ($key == 'server_name') {
                $server['SERVER_NAME'] = $value;;
            } elseif ($key == 'peer') {
                $server['REMOTE_ADDR'] = $value;
            } elseif ($key == 'scheme') {
                if ($value == 'https') {
                    $server['HTTPS'] = true;
                } else {
                    $server['HTTPS'] = false;
                }
            } elseif ($key == 'version') {
                $server['SERVER_PROTOCOL'] =
                    'HTTP/' . $value[0] . '.' . $value[1];
            } elseif ($key == 'method') {
                $server['REQUEST_METHOD'] = $value->getName();
            } elseif ($key == 'headers') {
                foreach ($value as $header) {
                    $header_key = 'HTTP_' . strtoupper($header[0]->getName());
                    $server[$header_key] = $header[1];
                }
            } elseif ($key == 'path') {
                $server['REQUEST_URI'] = $value;
                $parsed = parse_url($value);
                $server['QUERY_STRING'] =
                    isset($parsed['query']) ? $parsed['query'] : '';
                parse_str($server['QUERY_STRING'], $parsedQuery);
                $query = $parsedQuery;
                $attributes = $parsedQuery;
            } elseif ($key == 'body') {
                if ($value instanceof Atom) {
                    if ($value->getName() != 'undefined') {
                        $content = $value->getName();
                    }
                } else {
                    $content = $value;
                }
            } else {
                throw new HttpFoundationAdapter\Exception(
                    'Unknown key in Hurricane request: ' . $key
                );
            }
        }

        if (isset($server['HTTP_CONTENT_TYPE'])) {
            $contentType = $server['HTTP_CONTENT_TYPE'];
        } else {
            $contentType = '';
        }
        if ($contentType == 'application/x-www-url-form-encoded') {
            parse_str($content, $request);
        }

        if (isset($server['HTTP_COOKIE'])) {
            $cookies = parseCookie($server['HTTP_COOKIE']);
        }

        return new Request(
            $query, $request, $attributes, $cookies, $files, $server, $content
        );
    }

    /**
     * Parse a cookie string into an array of cookies.
     *
     * @param string $value
     *
     * @return array
     */
    public static function parseCookie($value) {
        $cookies = array();

        $rawCookies = explode(';', $value);
        foreach ($rawCookies as $rawCookie) {
            $parts = explode('=', $rawCookie);
            if (count($parts) < 2) {
                $parts[1] = null;
            }
            $cookies[trim($parts[0])] = trim($parts[1]);
        }
        return $cookies;
    }
}
