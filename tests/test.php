<?php

require __DIR__ . '/../vendor/autoload.php';

$router = new \PFinal\Routing\Router(new \PFinal\Container\Container());

//Closure
$obj = new BlogController(new User());

//echo $obj();exit;
$router->any('/blog/test/:id/:w', $obj);
$router->any('/blog/test/:id/:w', function ($id = 1, $w = 22) {

    var_dump($id);
    var_dump($w);
});

$router->any('/', function () {
    echo 'index';
});

$router->any('/blog/:id', function ($id) {
    echo $id;
});

$router->get('/blog/:name/update', function ($name) {
    echo 'name';
    echo $name;
});

$router->get('/blog', 'BlogController@index');
$router->post('/blog', 'BlogController@create');


class User
{
}

class BlogController
{
    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function __invoke($id = 99, $w = 100)
    {
        var_dump($id);
        var_dump($w);
    }

    public function index()
    {
        var_dump($this);
        return 'blog-index';
    }

    public function create()
    {
        return 'blog-create';
    }
}


//var_dump($router);
//var_dump(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

//$request = \Symfony\Component\HttpFoundation\Request::create('blog/11/update');
$request = \Symfony\Component\HttpFoundation\Request::create('blog', 'post');
//$request = \Symfony\Component\HttpFoundation\Request::create('blog', 'get');
//$request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();

$response = $router->dispatch($request);
$response->send();

dump($router);