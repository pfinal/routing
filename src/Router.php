<?php

namespace PFinal\Routing;

use PFinal\Pipeline\Pipeline;
use PFinal\Routing\Exception\MethodNotAllowedException;
use PFinal\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 路由
 *
 * @method get($path, $callback, $middleware = [])
 * @method post($path, $callback, $middleware = [])
 * @method put($path, $callback, $middleware = [])
 * @method patch($path, $callback, $middleware = [])
 * @method delete($path, $callback, $middleware = [])
 * @method trace($path, $callback, $middleware = [])
 * @method connect($path, $callback, $middleware = [])
 * @method head($path, $callback, $middleware = [])
 * @method options($path, $callback, $middleware = [])
 * @method any($path, $callback, $middleware = [])
 *
 * @author  Zou Yiliang
 * @since   1.0
 */
class Router
{
    const AT = '@';
    const HANDLER = '#';
    const SEPARATOR = '/';
    const PARAMETER = ':';

    /**
     * @var \PFinal\Container\Container
     */
    protected $container;

    protected $tree = array();

    protected $groupStack = array(
        //array('middleware' => 'auth'),
        //array('middleware' => array('cors','csrf')),
    );

    /**
     * @var string the GET variable name for route. For example, 'r'
     */
    public $routeVar = null;

    /**
     * Router constructor.
     * @param \PFinal\Container\Container $container
     */
    public function __construct($container = null)
    {
        $this->container = $container;
    }

    /**
     * 添加路由信息
     * @param string|array $method 请求方式
     * @param $path
     * @param $callback
     * @param array $middleware
     * @return $this
     */
    public function add($method, $path, $callback, $middleware = array(), array $clearMiddleware = array())
    {
        $groupStack = array();
        foreach ($this->groupStack as $attribute) {
            if (array_key_exists('middleware', $attribute)) {
                $groupStack = array_merge($groupStack, (array)$attribute['middleware']);
            }
        }

        $middleware = array_merge($groupStack, (array)$middleware); // groupStack 优先
        $middleware = array_unique($middleware);

        if (count($clearMiddleware) > 0) {
            $middleware = array_diff($middleware, $clearMiddleware);
        }

        $tokens = explode(self::SEPARATOR, str_replace('.', self::SEPARATOR, trim($path, self::SEPARATOR)));
        $this->_add($this->tree, $tokens, $callback, $middleware, array_map('strtoupper', (array)$method), $path);
        return $this;
    }

    // 创建基于URL规则的树, `handler`保存到`#`节点
    protected function _add(&$node, $tokens, $callback, $middleware, $method, $uri)
    {
        if (!array_key_exists(self::PARAMETER, $node)) {
            $node[self::PARAMETER] = array();
        }

        $token = array_shift($tokens);

        if (strncmp(self::PARAMETER, $token, 1) === 0) {
            $node = &$node[self::PARAMETER];
            $token = substr($token, 1);
        }

        if ($token === null) {

            $handler = array(
                'callback' => $callback,
                'middleware' => (array)($middleware),
                'method' => $method,
                'uri' => $uri
            );

            foreach ($method as $m) {
                $node[self::HANDLER][$m] = $handler;
            }

            return;
        }

        if (!array_key_exists($token, $node)) {
            $node[$token] = array();
        }

        $this->_add($node[$token], $tokens, $callback, $middleware, $method, $uri);
    }

    // 根据path查找handler
    protected function _resolve($node, $tokens, $method, $params = array())
    {
        $token = array_shift($tokens);

        if ($token === null && array_key_exists(self::HANDLER, $node)) {
            return $this->findHandler($method, $node[self::HANDLER], $params);
        }

        if (array_key_exists($token, $node)) {
            return $this->_resolve($node[$token], $tokens, $method, $params);
        }

        foreach ($node[self::PARAMETER] as $childToken => $childNode) {

            if ($token === null && array_key_exists(self::HANDLER, $childNode)) {
                return $this->findHandler($method, $childNode[self::HANDLER], $params);
            }

            $handler = $this->_resolve($childNode, $tokens, $method, array_merge($params, array($childToken => $token)));

            if ($handler !== false) {
                return $handler;
            }
        }
        return false;
    }

    private function findHandler($method, $handler, $params)
    {
        if (array_key_exists($method, $handler)) {
            return array_merge($handler[$method], array('arguments' => $params));
        }

        if (array_key_exists('ANY', $handler)) {
            return array_merge($handler['ANY'], array('arguments' => $params));
        }

        $allowedMethods = array_keys($handler);

        throw new MethodNotAllowedException($allowedMethods, sprintf('Method not allowed: %s', $method));
    }

    protected function resolve($path, $method)
    {
        $tokens = explode(self::SEPARATOR, str_replace('.', self::SEPARATOR, trim($path, self::SEPARATOR)));
        return $this->_resolve($this->tree, $tokens, $method);
    }

    /**
     * 将请求转换为响应
     *
     * 如果PSR-7 Request，可以使用下面的方法，转化为Symfony Request
     * composer require symfony/psr-http-message-bridge
     * composer require zendframework/zend-diactoros
     *
     * $httpFoundationFactory = new \Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory();
     * $request = $httpFoundationFactory->createRequest($psrRequest);
     *
     * 返回 symfony response对象，如果需要转化为 PSR-7 Response，可以使用下面的方法
     * $psr7Factory = new \Symfony\Bridge\PsrHttpMessage\Factory\DiactorosFactory();
     * $psrResponse = $psr7Factory->createResponse($symfonyResponse);
     *
     * http://symfony.com/blog/psr-7-support-in-symfony-is-here
     *
     * @param Request | \Psr\Http\Message\ServerRequestInterface $request
     * @return Response | \Psr\Http\Message\ResponseInterface
     */
    public function dispatch($request)
    {
        $psr7 = $request instanceof \Psr\Http\Message\ServerRequestInterface;
        if ($psr7) {
            $httpFoundationFactory = new \Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory();
            $request = $httpFoundationFactory->createRequest($request);
        }

        if ($this->routeVar === null) {
            $pathInfo = $request->getPathInfo();
        } else {
            $pathInfo = (string)$request->get($this->routeVar, '/');
        }

        $handler = $this->resolve($pathInfo, strtoupper($request->getMethod()));
        if ($handler === false) {
            throw new ResourceNotFoundException('Resource not found');
        }

        /** @var $callback */
        /** @var $middleware */
        /** @var $method */
        /** @var $arguments */
        /** @var $uri */
        extract($handler);

        if (method_exists($request, 'setRouteResolver')) {
            $request->setRouteResolver(function () use ($handler) {
                return $handler;
            });
        }

        if (is_string($callback)) {
            list($class, $func) = explode(self::AT, $callback, 2);
            $callback = array($this->container->make($class), $func);
        }

        $pipeline = new Pipeline($this->container);

        $response = $pipeline->send($request)->through($middleware)->then(function (Request $request) use ($callback, $arguments) {

            $response = call_user_func_array($callback, $this->getArguments($callback, $arguments)); //php >= 5.4

            if ($response instanceof Response) {
                return $response;
            }

            //convert a psr response to symfony response
            if ($response instanceof \Psr\Http\Message\ResponseInterface) {

                //composer require symfony/psr-http-message-bridge
                //composer require zendframework/zend-diactoros

                $httpFoundationFactory = new \Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory();
                return $httpFoundationFactory->createResponse($response);
            }

            if (is_array($response)) {
                return new JsonResponse($response);
            }

            return new Response($response);
        });

        if (!($response instanceof Response)) {
            $response = new Response($response);
        }

        if ($psr7) {
            //composer require zendframework/zend-diactoros
            $psr7Factory = new \Symfony\Bridge\PsrHttpMessage\Factory\DiactorosFactory();
            $response = $psr7Factory->createResponse($response);
        }

        return $response;
    }

    /**
     * 获取调用参数和值
     *
     * @param $controller
     * @param $attributes
     * @return array
     * @throws \ReflectionException
     */
    protected function getArguments($controller, $attributes)
    {
        if (is_array($controller)) {
            $ref = new \ReflectionMethod($controller[0], $controller[1]);
        } elseif (is_object($controller) && !$controller instanceof \Closure) {
            $ref = new \ReflectionObject($controller);
            $ref = $ref->getMethod('__invoke');
        } else {
            $ref = new \ReflectionFunction($controller);
        }

        $parameters = $ref->getParameters();
        $arguments = array();

        foreach ($parameters as $param) {

            if (PHP_MAJOR_VERSION > 7) {
                $class = $param->getType();
            } else {
                $class = $param->getClass();
            }

            if (array_key_exists($param->name, $attributes)) {
                $arguments[] = $attributes[$param->name];
            } elseif ($class && $this->container->offsetExists($class->getName())) {
                $arguments[] = $this->container[$class->getName()];
            } elseif ($param->isDefaultValueAvailable()) {
                $arguments[] = $param->getDefaultValue();
            } else {
                return $arguments; //参数不足
            }
        }
        return $arguments;
    }

    /**
     * 路由组
     *
     * $router->group(['middleware' => ['auth', 'cors']], function () use ($router) {
     *     $router->get('/users', function () {
     *         return 'users';
     *     });
     * });
     *
     * @param array $attributes
     * @param \Closure $callback
     */
    public function group(array $attributes, \Closure $callback)
    {
        $this->groupStack[] = $attributes;
        $callback();
        array_pop($this->groupStack);
    }

    public function getNodeData()
    {
        return $this->tree;
    }

    public function setNodeData(array $tree)
    {
        $this->tree = $tree;
    }

    /**
     * @param $name
     * @param $args
     * @return mixed
     * @throws \Exception
     */
    public function __call($name, $args)
    {
        if (in_array($name, array('get', 'post', 'put', 'patch', 'delete', 'trace', 'connect', 'options', 'head', 'any'))) {
            array_unshift($args, $name);
            return call_user_func_array(array($this, 'add'), $args);
        }
        throw new \Exception('Call to undefined method ' . __CLASS__ . '::' . $name . '()');
    }
}
