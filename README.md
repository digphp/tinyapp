# tinyapp

超小型的开发框架，符合psr规范~

## 安装

``` bash
composer require digphp/tinyapp
```

## 用例

``` php
$app = new \DigPHP\TinyApp\TinyApp();
$app->get('[/]', function(){
    return 'hello world~';
});
$app->run();
```

## 路由对象

``` php
$app = new \DigPHP\TinyApp\TinyApp();

// 函数
$app->get('[/]', 'time');

// 回调函数
$app->get('[/]', function(){
    return 'hello world~';
});

// 静态方法
$app->get('[/]', 'SomeClass::Method');

// 非静态方法
$app->get('[/]', ['SomeClass', 'Method']);
```

## 路由规则

``` php
$app = new \DigPHP\TinyApp\TinyApp();

// 普通静态路由
$app->get('/news/list', ...);

// 可选路由
$app->get('/page[/index]', ...);

// 带参数路由
$app->get('/user/{name}', ...);

// 正则路由
$app->get('/detail/{id:\d+}', ...);
```

## 路由方法

``` php
$app = new \DigPHP\TinyApp\TinyApp();

$app->get(...);
$app->post(...);
$app->put(...);
$app->delete(...);
$app->patch(...);
$app->head(...);
$app->any(...);
```

## 分组路由

``` php
$app = new \DigPHP\TinyApp\TinyApp();

// 路由分组
$app->addGroup('/news', function (\DigPHP\Router\Collector $collector) {
    $collector->get('/lists', 'somefuncion');
    $collector->get('/detail/{id:\d+}', 'somefuncion2');
}, ['中间件绑定'], ['参数绑定']);

// 多级路由分组
$app->addGroup('/article', function (\DigPHP\Router\Collector $collector) {
    $collector->get('/lists', 'somefuncion');
    $collector->get('/detail/{id:\d+}', 'somefuncion2', ['中间件绑定'], ['参数绑定']);
    $collector->addGroup('/comment', function(\DigPHP\Router\Collector $collector){
        $collector->get('/lists', 'somefuncion3');
        $collector->get('/submit', 'somefuncion4');
    }, ['中间件绑定'], ['参数绑定']);
}, ['中间件绑定'], ['参数绑定']);
```

## 中间件

``` php
$app = new \DigPHP\TinyApp\TinyApp();

// 全局中间件
$app->bindMiddleware([SomeMiddleware::class, BarMiddleware::class]);
$app->bindMiddleware([OtherMiddleware::class]);

// 局部中间件
$app->get('/page[/index]', 'somefunction', [FooMiddleware::class, BarMiddleware::class]);

// 分组中间件 对分组下的所有路由都生效
$app->addGroup('/news', function (\DigPHP\Router\Collector $collector) {
    $collector->get('/lists', 'somefuncion');
    $collector->get('/detail/{id:\d+}', 'somefuncion2');
}, [FooMiddleware::class, BarMiddleware::class]);
```

## 路由传参

``` php
class SomeClass{
    public function action(
        \DigPHP\Request\Request $request
    ){
        var_dump($request->attr('query.id'));
    }
}

$app = new \DigPHP\TinyApp\TinyApp();

// 路由传参
$app->get('/news/list', [SomeClass::class, 'action'], ['中间件'], ['id'=>12]);
```
