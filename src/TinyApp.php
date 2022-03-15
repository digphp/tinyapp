<?php

declare(strict_types=1);

namespace DigPHP\TinyApp;

use DigPHP\Psr3\LocalLogger;
use DigPHP\Psr11\Container;
use DigPHP\Psr14\Event;
use DigPHP\Psr15\RequestHandler;
use DigPHP\Psr16\LocalAdapter;
use DigPHP\Psr17\Factory;
use DigPHP\Responser\Emitter;
use DigPHP\Router\Collector;
use DigPHP\Router\Route;
use DigPHP\Router\Router;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use ReflectionFunction;
use ReflectionMethod;

class TinyApp
{
    private $uri;
    private $middlewares = [];

    public function __construct(array $alias = [])
    {
        $this->uri = $this->getPsr17Factory()->createUriFromGlobals();

        self::getContainer()->set(Route::class, function (): Route {
            return $this->getRouter()->dispatch(
                isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET',
                $this->uri->getScheme() . '://' . $this->uri->getHost() . (in_array($this->uri->getPort(), [null, 80, 443]) ? '' : ':' . $this->uri->getPort()) . $this->uri->getPath()
            );
        });

        self::getContainer()->set(ServerRequestInterface::class, function (): ServerRequestInterface {
            $server_request = $this->getPsr17Factory()->createServerRequestFromGlobals();
            return $server_request
                ->withAttribute('route_params', $this->getRoute()->getParams())
                ->withQueryParams(array_merge($server_request->getQueryParams(), $this->getRoute()->getParams()));
        });

        foreach (array_merge([
            LoggerInterface::class => LocalLogger::class,
            CacheInterface::class => LocalAdapter::class,
            RequestHandlerInterface::class => RequestHandler::class,
            ResponseFactoryInterface::class => Factory::class,
            UriFactoryInterface::class => Factory::class,
            ServerRequestFactoryInterface::class => Factory::class,
            RequestFactoryInterface::class => Factory::class,
            StreamFactoryInterface::class => Factory::class,
            UploadedFileFactoryInterface::class => Factory::class,
            EventDispatcherInterface::class => Event::class,
            ListenerProviderInterface::class => Event::class,
        ], $alias) as $key => $obj) {
            self::getContainer()->set($key, is_string($obj) ? function () use ($obj) {
                return self::getContainer()->get($obj);
            } : $obj);
        }
    }

    public function run()
    {
        $request_handler = $this->getRequestHandler();

        foreach ($this->middlewares as $middleware) {
            $request_handler->appendMiddleware(is_string($middleware) ? self::getContainer()->get($middleware) : $middleware);
        }
        foreach ($this->getRoute()->getMiddleWares() as $middleware) {
            $request_handler->appendMiddleware(is_string($middleware) ? self::getContainer()->get($middleware) : $middleware);
        }

        $handler = $this->getHandler($this->getRoute());
        $response = $request_handler->setHandler($handler)->handle($this->getServerRequest());

        $this->getEmitter()->emit($response);
    }

    public function bindMiddleware(...$middlewares): self
    {
        array_push($this->middlewares, ...$middlewares);
        return $this;
    }

    public function get(string $route, $handler, array $middlewares = [], array $params = [], string $name = null): self
    {
        $this->getRouter()->addGroup($this->getSitePath(), function (Collector $collector) use ($route, $handler, $middlewares, $params, $name) {
            $collector->addRoute(['GET'], $route, $handler, $middlewares, $params, $name);
        });
        return $this;
    }

    public function post(string $route, $handler, array $middlewares = [], array $params = [], string $name = null): self
    {
        $this->getRouter()->addGroup($this->getSitePath(), function (Collector $collector) use ($route, $handler, $middlewares, $params, $name) {
            $collector->addRoute(['POST'], $route, $handler, $middlewares, $params, $name);
        });
        return $this;
    }

    public function put(string $route, $handler, array $middlewares = [], array $params = [], string $name = null): self
    {
        $this->getRouter()->addGroup($this->getSitePath(), function (Collector $collector) use ($route, $handler, $middlewares, $params, $name) {
            $collector->addRoute(['PUT'], $route, $handler, $middlewares, $params, $name);
        });
        return $this;
    }

    public function delete(string $route, $handler, array $middlewares = [], array $params = [], string $name = null): self
    {
        $this->getRouter()->addGroup($this->getSitePath(), function (Collector $collector) use ($route, $handler, $middlewares, $params, $name) {
            $collector->addRoute(['DELETE'], $route, $handler, $middlewares, $params, $name);
        });
        return $this;
    }

    public function patch(string $route, $handler, array $middlewares = [], array $params = [], string $name = null): self
    {
        $this->getRouter()->addGroup($this->getSitePath(), function (Collector $collector) use ($route, $handler, $middlewares, $params, $name) {
            $collector->addRoute(['PATCH'], $route, $handler, $middlewares, $params, $name);
        });
        return $this;
    }

    public function head(string $route, $handler, array $middlewares = [], array $params = [], string $name = null): self
    {
        $this->getRouter()->addGroup($this->getSitePath(), function (Collector $collector) use ($route, $handler, $middlewares, $params, $name) {
            $collector->addRoute(['HEAD'], $route, $handler, $middlewares, $params, $name);
        });
        return $this;
    }

    public function any(string $route, $handler, array $middlewares = [], array $params = [], string $name = null): self
    {
        $this->getRouter()->addGroup($this->getSitePath(), function (Collector $collector) use ($route, $handler, $middlewares, $params, $name) {
            $collector->addRoute(['*'], $route, $handler, $middlewares, $params, $name);
        });
        return $this;
    }

    public function addGroup(string $prefix, callable $callback, array $middlewares = [], array $params = []): self
    {
        $this->getRouter()->addGroup($this->getSitePath(), function (Collector $collector) use ($prefix, $callback, $middlewares, $params) {
            $collector->addGroup($prefix, $callback, $middlewares, $params);
        });
        return $this;
    }

    public static function execute(callable $callable, array $default_args = [])
    {
        if (is_array($callable)) {
            $args = self::getContainer()->reflectArguments(new ReflectionMethod(...$callable), $default_args);
        } elseif (is_object($callable)) {
            $args = self::getContainer()->reflectArguments(new ReflectionMethod($callable, '__invoke'), $default_args);
        } elseif (is_string($callable) && strpos($callable, '::')) {
            $args = self::getContainer()->reflectArguments(new ReflectionMethod($callable), $default_args);
        } else {
            $args = self::getContainer()->reflectArguments(new ReflectionFunction($callable), $default_args);
        }
        return call_user_func($callable, ...$args);
    }

    private function getServerRequest(): ServerRequestInterface
    {
        return self::getContainer()->get(ServerRequestInterface::class);
    }

    private function getPsr17Factory(): Factory
    {
        return self::getContainer()->get(Factory::class);
    }

    private function getRouter(): Router
    {
        return self::getContainer()->get(Router::class);
    }

    private function getRoute(): Route
    {
        return self::getContainer()->get(Route::class);
    }

    private function getRequestHandler(): RequestHandler
    {
        return self::getContainer()->get(RequestHandler::class);
    }

    private function getEmitter(): Emitter
    {
        return self::getContainer()->get(Emitter::class);
    }

    private function getSitePath(): string
    {
        $site_base = $this->uri->getScheme() . '://' . $this->uri->getHost() . (in_array($this->uri->getPort(), [null, 80, 443]) ? '' : ':' . $this->uri->getPort());
        if (strpos($this->uri->getPath(), $_SERVER['SCRIPT_NAME']) === 0) {
            $site_path = $_SERVER['SCRIPT_NAME'];
        } else {
            $dir_script = dirname($_SERVER['SCRIPT_NAME']);
            $site_path = strlen($dir_script) > 1 ? $dir_script : '';
        }
        return $site_base . $site_path;
    }

    private function getHandler(Route $route): callable
    {
        if (!$route->isFound()) {
            return function (): ResponseInterface {
                return $this->getPsr17Factory()->createResponse(404);
            };
        }

        if (!$route->isAllowed()) {
            return function (): ResponseInterface {
                return $this->getPsr17Factory()->createResponse(405);
            };
        }

        $handler = $route->getHandler();

        if (is_array($handler)) {
            if (false === (new ReflectionMethod(...$handler))->isStatic()) {
                if (!is_object($handler[0])) {
                    $handler[0] = self::getContainer()->get($handler[0]);
                }
            }
        } elseif (is_string($handler) && class_exists($handler)) {
            $handler = self::getContainer()->get($handler);
        }

        return function () use ($handler): ResponseInterface {
            return $this->toResponse(self::execute($handler));
        };
    }

    private function toResponse($resp): ResponseInterface
    {
        if (is_null($resp)) {
            return $this->getPsr17Factory()->createResponse(200);
        }
        if ($resp instanceof ResponseInterface) {
            return $resp;
        }
        $response = $this->getPsr17Factory()->createResponse(200);
        if (is_scalar($resp) || (is_object($resp) && method_exists($resp, '__toString'))) {
            $response->getBody()->write($resp);
            return $response;
        } else {
            $response->getBody()->write(json_encode($resp));
            return $response->withHeader('Content-Type', 'application/json');
        }
    }

    private static function getContainer(): Container
    {
        static $container;
        if (!$container) {
            $container = new Container();
            $container->set(ContainerInterface::class, function (Container $container) {
                return $container;
            });
            $container->set(Container::class, function (Container $container) {
                return $container;
            });
        }
        return $container;
    }
}
