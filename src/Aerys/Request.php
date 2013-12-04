<?php

namespace Aerys;

class Request {

    private $id;
    private $client;
    private $host;
    private $trace;
    private $protocol;
    private $method;
    private $headers = [];
    private $ucHeaders = [];
    private $body;
    private $uri;
    private $uriHost;
    private $uriPort;
    private $uriPath;
    private $uriQuery;
    private $hasAbsoluteUri;
    private $asgiEnv;
    private $asgiResponse;
    private $isComplete = FALSE;
    private $closeConnectionAfterSend = FALSE;

    function __construct($requestId) {
        $this->id = $requestId;
    }

    function getId() {
        return $this->id;
    }

    function setClient(Client $client) {
        $this->client = $client;
        
        return $this;
    }
    
    function getClient() {
        return $this->client;
    }
    
    function setHost(Host $host) {
        $this->host = $host;
        
        return $this;
    }
    
    function getHost() {
        return $this->host;
    }
    
    function getHostHandler() {
        return $this->host->getHandler();
    }
    
    function setTrace($headerTrace) {
        $this->trace = $headerTrace;
        
        return $this;
    }
    
    function getTrace() {
        return $this->trace;
    }

    function setProtocol($protocol) {
        $this->protocol = (string) $protocol;
        
        return $this;
    }
    
    function getProtocol() {
        return $this->protocol;
    }
    
    function isHttp11() {
        return ($this->protocol === '1.1');
    }
    
    function setMethod($method) {
        $this->method = $method;
        
        return $this;
    }
    
    function getMethod() {
        return $this->method;
    }
    
    function setHeaders(array $headers) {
        $this->headers = $headers;
        $this->ucHeaders = array_change_key_case($headers, CASE_UPPER);
        
        return $this;
    }
    
    function hasHeader($headerField) {
        $headerField = strtoupper($headerField);
        
        return isset($this->ucHeaders[$headerField]);
    }
    
    function getHeader($headerField) {
        $headerField = strtoupper($headerField);
        
        return isset($this->ucHeaders[$headerField])
            ? $this->ucHeaders[$headerField]
            : NULL;
    }
    
    function setBody($body) {
        $this->body = $body;
        
        return $this;
    }

    function setUri($requestUri) {
        $this->uri = $requestUri;
        if (stripos($requestUri, 'http://') === 0 || stripos($requestUri, 'https://') === 0) {
            $parts = parse_url($requestUri);
            $this->hasAbsoluteUri = TRUE;
            $this->uriHost = $parts['host'];
            $this->uriPort = $parts['port'];
            $this->uriPath = $parts['path'];
            $this->uriQuery = $parts['query'];
        } elseif ($qPos = strpos($requestUri, '?')) {
            $this->uriQuery = substr($requestUri, $qPos + 1);
            $this->uriPath = substr($requestUri, 0, $qPos);
        } else {
            $this->uriPath = $requestUri;
        }
        
        return $this;
    }

    function getUri() {
        return $this->uri;
    }
    
    function getUriHost() {
        return $this->uriHost;
    }

    function getUriPort() {
        return $this->uriPort;
    }

    function getUriPath() {
        return $this->uriPath;
    }

    function getUriQuery() {
        return $this->uriQuery;
    }

    function hasAbsoluteUri() {
        return (bool) $this->hasAbsoluteUri;
    }

    function isEncrypted() {
        return (bool) $this->client->isEncrypted;
    }
    
    function getClientPort() {
        return $this->client->clientPort;
    }
    
    function getClientAddress() {
        return $this->client->clientAddress;
    }
    
    function getServerPort() {
        return $this->client->serverPort;
    }
    
    function getServerAddress() {
        return $this->client->serverAddress;
    }
    
    function getClientSocketInfo() {
        return [
            'clientAddress' => $this->client->clientAddress,
            'clientPort'    => $this->client->clientPort,
            'serverAddress' => $this->client->serverAddress,
            'serverPort'    => $this->client->serverPort,
            'isEncrypted'   => $this->client->isEncrypted
        ];
    }
    
    function getAsgiEnv() {
        return $this->asgiEnv;
    }
    
    function generateAsgiEnv() {
        $serverName = $this->host->hasName()
            ? $this->host->getName()
            : $this->client->serverAddress;
        
        // It's important to pull the $uriScheme from the encryption status of the client socket and
        // NOT the scheme parsed from the request URI as the request could have passed an erroneous
        // absolute https or http scheme in an absolute URI -or- a valid scheme that doesn't reflect
        // the encryption status of the client's connection to the server (for forward proxying).
        $uriScheme = $this->client->isEncrypted
            ? 'https'
            : 'http';
            
        $asgiEnv = [
            'ASGI_VERSION'      => '0.1',
            'ASGI_CAN_STREAM'   => TRUE,
            'ASGI_NON_BLOCKING' => TRUE,
            'ASGI_LAST_CHANCE'  => (bool) $this->body, // @TODO Fix this to use "headersOnly" from parsed request array
            'ASGI_ERROR'        => NULL, // @TODO Should this be passed as a method param? Do we even need this?
            'ASGI_INPUT'        => $this->body,
            'ASGI_URL_SCHEME'   => $uriScheme,
            'SERVER_PORT'       => $this->client->serverPort,
            'SERVER_ADDR'       => $this->client->serverAddress,
            'SERVER_NAME'       => $serverName,
            'SERVER_PROTOCOL'   => $this->protocol,
            'REMOTE_ADDR'       => $this->client->clientAddress,
            'REMOTE_PORT'       => $this->client->clientPort,
            'REQUEST_METHOD'    => $this->method,
            'REQUEST_URI'       => $this->uri,
            'REQUEST_URI_PATH'  => $this->uriPath,
            'QUERY_STRING'      => $this->uriQuery
        ];

        $headers = $this->ucHeaders;

        if (!empty($headers['CONTENT-TYPE'])) {
            $asgiEnv['CONTENT_TYPE'] = $headers['CONTENT-TYPE'][0];
            unset($headers['CONTENT-TYPE']);
        }

        if (!empty($headers['CONTENT-LENGTH'])) {
            $asgiEnv['CONTENT_LENGTH'] = $headers['CONTENT-LENGTH'][0];
            unset($headers['CONTENT-LENGTH']);
        }

        if (!empty($headers['COOKIE']) && ($cookies = $this->parseCookies($headers['COOKIE']))) {
            $asgiEnv['COOKIES'] = $cookies;
        }

        foreach ($headers as $field => $value) {
            $field = 'HTTP_' . str_replace('-', '_', $field);
            $asgiEnv[$field] = isset($value[1]) ? implode(',', $value) : $value[0];
        }
        
        return $this->asgiEnv = $asgiEnv;
    }
    
    private function parseCookies($cookieHeader) {
        $cookies = [];
        $pairs = array_filter(str_getcsv($cookieHeader, $delimiter = ';'));
        foreach ($pairs as $pair) {
            if (strpos($pair, '=')) {
                list($key, $value) = explode('=', $pair, 2);
                $value = str_replace(['\\"', '\\""'], ['"', '""'], $value);
                $value = trim($value, '"');
                $cookies[trim($key)] = $value;
            }
        }
        
        return $cookies;
    }
    
    function setAsgiEnv(array $asgiEnv) {
        $this->asgiEnv = $asgiEnv;
        
        return $this;
    }
    
    function expects100Continue() {
        if (!isset($this->ucHeaders['EXPECT'])) {
            $expectsContinue = FALSE;
        } elseif (strcasecmp($this->ucHeaders['EXPECT'], '100-continue')) {
            $expectsContinue = FALSE;
        } else {
            $expectsContinue = TRUE;
        }
        
        return $expectsContinue;
    }
    
    function hasResponse() {
        return (bool) $this->asgiResponse;
    }
    
    function setAsgiResponse($asgiResponse) {
        $this->asgiResponse = $asgiResponse;
        
        return $this;
    }
    
    function getAsgiResponse() {
        return $this->asgiResponse;
    }
    
    function markComplete() {
        $this->isComplete = TRUE;
    }
    
    function isComplete() {
        return $this->isComplete;
    }
    
    function setConnectionCloseFlag($shouldClose) {
        $this->closeConnectionAfterSend = $shouldClose;
    }
    
    function shouldCloseAfterSend() {
        return $this->closeConnectionAfterSend;
    }

}









































