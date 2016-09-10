<?php

namespace PFinal\Routing;

use PFinal\Pipeline\Pipeline;
use PFinal\Routing\Exception\MethodNotAllowedException;
use PFinal\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 路由
 * @author  Zou Yiliang
 * @since   1.0
 */
class Router
{
    /**
     * @var \PFinal\Container\Container
     */
    protected $container;

    protected $tree = array();

    const AT = '@';
    const HANDLER = '#';
    const SEPARATOR = '/';
    const PARAMETER = ':';

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
    public function add($method, $path, $callback, $middleware = array())
    {
        $tokens = explode(self::SEPARATOR, str_replace('.', self::SEPARATOR, trim($path, self::SEPARATOR)));
        $this->_add($this->tree, $tokens, $callback, (array)$middleware, array_map('strtoupper', (array)$method));
        return $this;
    }

    // 创建基于URL规则的树, `handler`保存到`#`节点
    protected function _add(&$node, $tokens, $callback, $middleware, $method)
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
            $node[self::HANDLER] = array('callback' => $callback, 'middleware' => (array)($middleware), 'method' => $method);
            return;
        }

        if (!array_key_exists($token, $node)) {
            $node[$token] = array();
        }

        $this->_add($node[$token], $tokens, $callback, $middleware, $method);
    }

    // 根据path查找handler
    protected function _resolve($node, $tokens, $params = array())
    {
        $token = array_shift($tokens);

        if ($token === null && array_key_exists(self::HANDLER, $node)) {
            return $node[self::HANDLER] + array('arguments' => $params);
        }

        if (array_key_exists($token, $node)) {
            return $this->_resolve($node[$token], $tokens, $params);
        }

        foreach ($node[self::PARAMETER] as $childToken => $childNode) {

            if ($token === null && array_key_exists(self::HANDLER, $childNode)) {
                return $childNode[self::HANDLER] + array('arguments' => $params);
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

    //查找handler并带参数执行
    public function dispatch(Request $request)
    {
        $handler = $this->resolve($request->getPathInfo());
        if ($handler === false) {
            throw new ResourceNotFoundException('请求的页面不存在');
        }

        // callback、middleware、method、arguments
        extract($handler);

        if (!in_array($request->getMethod(), $method) && !in_array('ANY', $method)) {
            throw new MethodNotAllowedException($method, sprintf('不允许"%s"方式访问', $request->getMethod()));

        }

        if (is_string($callback)) {
            list($class, $method) = explode(self::AT, $callback, 2);
            $callback = array($this->container->make($class), $method);
        }

        $pipeline = new Pipeline($this->container);
        return $pipeline->send($request)->through($middleware)->then(function (Request $request) use ($callback, $arguments) {
            $response = call_user_func_array($callback, $this->getArguments($callback, $arguments));
            if ($response instanceof Response) {
                return $response;
            }
            return new Response((string)$response);
        });
    }

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

    public function __call($name, $args)
    {
        if (in_array($name, array('get', 'post', 'put', 'patch', 'delete', 'trace', 'connect', 'options', 'head', 'any'))) {
            array_unshift($args, $name);
            return call_user_func_array(array($this, 'add'), $args);
        }
        throw new \Exception(sprintf('调用的方法不存在%s::%s()', __CLASS__, $name));
    }
}
