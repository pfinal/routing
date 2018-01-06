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
    public function __construct($container)
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
            $node[self::HANDLER] = array('callback' => $callback, 'middleware' => (array)($middleware), 'method' => $method, 'uri' => $uri);
            return;
        }

        if (!array_key_exists($token, $node)) {
            $node[$token] = array();
        }

        $this->_add($node[$token], $tokens, $callback, $middleware, $method, $uri);
    }

    // 根据path查找handler
    protected function _resolve($node, $tokens, $params = array())
    {
        $token = array_shift($tokens);

        if ($token === null && array_key_exists(self::HANDLER, $node)) {
            return array_merge($node[self::HANDLER], array('arguments' => $params));
        }

        if (array_key_exists($token, $node)) {
            return $this->_resolve($node[$token], $tokens, $params);
        }

        foreach ($node[self::PARAMETER] as $childToken => $childNode) {

            if ($token === null && array_key_exists(self::HANDLER, $childNode)) {
                return array_merge($childNode[self::HANDLER], array('arguments' => $params));
            }

            $handler = $this->_resolve($childNode, $tokens, array_merge($params, array($childToken => $token)));

            if ($handler !== false) {
                return $handler;
            }
        }
        return false;
    }

    protected function resolve($path)
    {
        $tokens = explode(self::SEPARATOR, str_replace('.', self::SEPARATOR, trim($path, self::SEPARATOR)));
        return $this->_resolve($this->tree, $tokens);
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
     * @param Request $request
     * @return Response
     */
    public function dispatch(Request $request)
    {
        if ($this->routeVar === null) {
            $pathInfo = $request->getPathInfo();
        } else {
            $pathInfo = (string)$request->get($this->routeVar, '/');
        }

        $handler = $this->resolve($pathInfo);
        if ($handler === false) {
            throw new ResourceNotFoundException('Resource not found');
        }

        // callback、middleware、method、arguments、uri
        extract($handler);

        if (!in_array($request->getMethod(), $method) && !in_array('ANY', $method)) {
            throw new MethodNotAllowedException($method, sprintf('Method not allowed', $request->getMethod()));
        }

        if (method_exists($request, 'setRouteResolver')) {
            $request->setRouteResolver(function () use ($handler) {
                return $handler;
            });
        }

        if (is_string($callback)) {
            list($class, $method) = explode(self::AT, $callback, 2);
            $callback = array($this->container->make($class), $method);
        }

        $pipeline = new Pipeline($this->container);

        $response = $pipeline->send($request)->through($middleware)->then(function (Request $request) use ($callback, $arguments) {

            //$this->getArguments php >= 5.4
            $response = call_user_func_array($callback, $this->getArguments($callback, $arguments));

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

        if ($response instanceof Response) {
            return $response;
        }

        return new Response($response);
    }

    /**
     * 获取调用参数和值
     * @param $controller
     * @param $attributes
     * @return array
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
            if (array_key_exists($param->name, $attributes)) {
                $arguments[] = $attributes[$param->name];
            } elseif ($param->getClass() && $this->container->offsetExists($param->getClass()->name)) {
                $arguments[] = $this->container[$param->getClass()->name];
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

    public function __call($name, $args)
    {
        if (in_array($name, array('get', 'post', 'put', 'patch', 'delete', 'trace', 'connect', 'options', 'head', 'any'))) {
            array_unshift($args, $name);
            return call_user_func_array(array($this, 'add'), $args);
        }
        throw new \Exception('Call to undefined method ' . __CLASS__ . '::' . $name . '()');
    }
}
