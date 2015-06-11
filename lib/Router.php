<?php

namespace Aerys;

use FastRoute\{
    Dispatcher,
    RouteCollector,
    function simpleDispatcher
};

use Amp\{
    Reactor,
    Promise,
    Success,
    Failure,
    function any
};

class Router implements Bootable, Middleware, \SplObserver {
    private $state = Server::STOPPED;
    private $canonicalRedirector;
    private $bootLoader;
    private $routeDispatcher;
    private $routes = [];
    private $cache = [];
    private $cacheEntryCount = 0;
    private $maxCacheEntries = 512;

    public function __construct() {
        $this->canonicalRedirector = function(Request $request, Response $response) {
            $uri = $request->getUri();
            if (stripos($uri, "?")) {
                list($path, $query) = explode("?", $uri, 2);
                $redirectTo = "{$path}/?{$query}";
            } else {
                $path = $uri;
                $redirectTo = "{$uri}/";
            }
            $response->setStatus(HTTP_STATUS["FOUND"]);
            $response->setHeader("Location", $redirectTo);
            $response->setHeader("Content-Type", "text/plain; charset=utf-8");
            $response->end("Canonical resource URI: {$path}/");
        };
    }

    /**
     * Set a router option
     *
     * @param string $key
     * @param mixed $value
     * @throws \DomainException on unknown option key
     * @retur void
     */
    public function setOption(string $key, $value) {
        switch ($key) {
            case "max_cache_entries":
                if (!is_int($value)) {
                    throw new \DomainException(sprintf(
                        "max_cache_entries requires an integer; %s specified",
                        is_object($value) ? get_class($value) : gettype($value)
                    ));
                }
                $this->maxCacheEntries = ($value < 1) ? 0 : $value;
                break;
            default:
                throw new \DomainException(
                    "Unknown Router option: {$key}"
                );
        }
    }

    /**
     * Route a request
     *
     * @param \Aerys\Request $request
     * @param \Aerys\Response $response
     * @return mixed
     */
    public function __invoke(Request $request, Response $response) {
        if (!$preRoute = $request->getLocalVar("aerys.routed")) {
            return;
        }

        list($isMethodAllowed, $data) = $preRoute;
        if ($isMethodAllowed) {
            return $data($request, $response, $request->getLocalVar("aerys.routeArgs"));
        } else {
            $allowedMethods = implode(",", $data);
            $response->setStatus(HTTP_STATUS["METHOD_NOT_ALLOWED"]);
            $response->setHeader("Allow", $allowedMethods);
            $response->setHeader("Aerys-Generic-Response", "enable");
            $response->end();
        }
    }

    /**
     * Execute router middleware functionality
     */
    public function do(InternalRequest $ireq, Options $options) {
        $toMatch = $ireq->uriPath;

        if (isset($this->cache[$toMatch])) {
            list($args, $routeArgs) = $cache = $this->cache[$toMatch];
            list($action, $middlewares) = $args;
            $ireq->locals["aerys.routeArgs"] = $routeArgs;
            // Keep the most recently used entry at the back of the LRU cache
            unset($this->cache[$toMatch]);
            $this->cache[$toMatch] = $cache;

            $ireq->locals["aerys.routed"] = [$isMethodAllowed = true, $action];
            if (!empty($middlewares)) {
                yield from responseFilter($middlewares, $ireq, $options);
            }
        }

        $match = $this->routeDispatcher->dispatch($ireq->method, $toMatch);

        switch ($match[0]) {
            case Dispatcher::FOUND:
                list(, $args, $routeArgs) = $match;
                list($action, $middlewares) = $args;
                $ireq->locals["aerys.routeArgs"] = $routeArgs;
                if ($this->maxCacheEntries > 0) {
                    $this->cacheDispatchResult($toMatch, $routeArgs, $args);
                }

                $ireq->locals["aerys.routed"] = [$isMethodAllowed = true, $action];
                if (!empty($middlewares)) {
                    yield from responseFilter($middlewares, $ireq, $options);
                }
                break;
            case Dispatcher::NOT_FOUND:
                // Do nothing; allow actions further down the chain a chance to respond.
                // If no other registered host actions respond the server will send a
                // 404 automatically anyway.
                return;
                break;
            case Dispatcher::METHOD_NOT_ALLOWED:
                $allowedMethods = $match[1];
                $ireq->locals["aerys.routed"] = [$isMethodAllowed = false, $allowedMethods];
                break;
            default:
                throw new \UnexpectedValueException(
                    "Encountered unexpected Dispatcher code"
                );
        }
    }

    private function cacheDispatchResult(string $toMatch, array $routeArgs, array $action) {
        if ($this->cacheEntryCount < $this->maxCacheEntries) {
            $this->cacheEntryCount++;
        } else {
            // Remove the oldest entry from the LRU cache to make room
            $unsetMe = key($this->cache);
            unset($this->cache[$unsetMe]);
        }

        $cacheKey = $toMatch;
        $this->cache[$cacheKey] = [$action, $routeArgs];
    }

    /**
     * Allow shortcut route registration using the called method name as the HTTP method verb
     *
     * HTTP method verbs -- though case-sensitive -- are used in all-caps for most applications.
     * Shortcut method verbs will automatically be changed to all-caps. Applications wishing to
     * define case-sensitive methods should use Router::route() to specify the desired method
     * directly.
     *
     * @param string $method
     * @param string $uri
     * @param callable $actions
     * @return self
     */
    public function __call(string $method, array $args): Router {
        $uri = $args ? array_shift($args) : "";

        return $this->route(strtoupper($method), $uri, ...$args);
    }

    /**
     * Define an application route
     *
     * The variadic ...$actions argument allows applications to specify multiple separate
     * handlers for a given route URI. When matched these action callables will be invoked
     * in order until one starts a response. If the resulting action fails to send a response
     * the end result is a 404.
     *
     * Matched URI route arguments are made available to action callables as an array in the
     * following Request property:
     *
     *     $request->locals->routeArgs array.
     *
     * Route URIs ending in "/?" (without the quotes) allow a URI match with or without
     * the trailing slash. Temporary redirects are used to redirect to the canonical URI
     * (with a trailing slash) to avoid search engine duplicate content penalties.
     *
     * @param string $method The HTTP method verb for which this route applies
     * @param string $uri The string URI
     * @param callable|\Aerys\Middleware $actions The action(s) to invoke upon matching this route
     * @throws \DomainException on invalid empty parameters
     * @return self
     */
    public function route(string $method, string $uri, ...$actions): Router {
        if ($this->state !== Server::STOPPED) {
            throw new \LogicException(
                "Cannot add routes once the server has started"
            );
        }
        if ($method === "") {
            throw new \DomainException(
                __METHOD__ . " requires a non-empty string HTTP method at Argument 1"
            );
        }
        if ($uri === "") {
            throw new \DomainException(
                __METHOD__ . " requires a non-empty string URI at Argument 2"
            );
        }
        if (empty($actions)) {
            throw new \DomainException(
                __METHOD__ . " requires at least one callable route action or middleware at Argument 3"
            );
        }

        $uri = "/" . ltrim($uri, "/");
        if (substr($uri, -2) === "/?") {
            $canonicalUri = substr($uri, 0, -1);
            $redirectUri = substr($uri, 0, -2);
            $this->routes[] = [$method, $canonicalUri, $actions];
            $this->routes[] = [$method, $redirectUri, [$this->canonicalRedirector]];
        } else {
            $this->routes[] = [$method, $uri, $actions];
        }

        return $this;
    }

    public function boot(Reactor $reactor, Server $server, Logger $logger) {
        $server->attach($this);
        $this->bootLoader = function(Bootable $bootable) use ($reactor, $server, $logger) {
            return $bootable->boot($reactor, $server, $logger);
        };

        return [$this, "__invoke"];
    }

    private function bootRouteTarget(array $actions): array {
        $middlewares = [];
        $applications = [];

        foreach ($actions as $key => $action) {
            if ($action instanceof Bootable) {
                $action = ($this->bootLoader)($action);
            }
            if ($action instanceof Middleware) {
                $middlewares[] = [$action, "do"];
            } elseif (is_array($action) && $action[0] instanceof Middleware) {
                $middlewares[] = [$action[0], "do"];
            }
            if (is_callable($action)) {
                $applications[] = $action;
            }
        }

        if (empty($applications[1])) {
            return [$applications[0], $middlewares];
        }

        return [function(Request $request, Response $response) use ($applications) {
            foreach ($applications as $application) {
                $result = ($application)($request, $response);
                if ($result instanceof \Generator) {
                    yield from $result;
                }
                if ($response->state() & Response::STARTED) {
                    return;
                }
            }
        }, $middlewares];
    }

    /**
     * React to server state changes
     *
     * Here we generate our dispatcher when the server notifies us that it is
     * ready to start (Server::STARTING).
     *
     * @param \SplSubject $subject
     * @return \Amp\Promise
     */
    public function update(\SplSubject $subject): Promise {
        switch ($this->state = $subject->state()) {
            case Server::STOPPED:
                $this->routeDispatcher = null;
                break;
            case Server::STARTING:
                if (empty($this->routes)) {
                    return new Failure(new \DomainException(
                        "Router start failure: no routes registered"
                    ));
                }
                $this->routeDispatcher = simpleDispatcher(function(RouteCollector $rc) {
                    foreach ($this->routes as list($method, $uri, $actions)) {
                        $rc->addRoute($method, $uri, $this->bootRouteTarget($actions));
                    }
                });
                break;
        }

        return new Success;
    }
}
