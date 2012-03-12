<?php

require __DIR__ . '/../vendor/.composer/autoload.php';

use \Symfony\Component\Routing\RouteCollection;
use \Symfony\Component\Routing\Route;
use \Symfony\Component\Routing\RequestContext;
use \Symfony\Component\Routing\Matcher\UrlMatcher;
use \Symfony\Component\EventDispatcher\EventDispatcher;
use \Symfony\Component\HttpKernel\EventListener\RouterListener;
use \Symfony\Component\HttpKernel\Controller\ControllerResolver;
use \Symfony\Component\HttpKernel\HttpKernel;
use \Symfony\Component\HttpKernel\Exception\HttpException;
use \Symfony\Component\HttpFoundation\Request;
use \Symfony\Component\HttpFoundation\Response;
use \Hurricane\HttpFoundationAdapter;

$adapter = new HttpFoundationAdapter('127.0.0.1', 3000);
$adapter->run(function($request) {
    try {
        $routes = new RouteCollection();
        $routes->add('hello', new Route('/', array('_controller' =>
            function (Request $request) {
                return new Response(sprintf('Hello %s', $request->get('name')));
            }
        )));

        $context = new RequestContext();
        $context->fromRequest($request);
        $matcher = new UrlMatcher($routes, $context);
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new RouterListener($matcher));
        $resolver = new ControllerResolver();
        $kernel = new HttpKernel($dispatcher, $resolver);
        $response = $kernel->handle($request);
    } catch (HttpException $e) {
        $response = new Response($e->getMessage(), 404, array());
    } catch (Exception $e) {
        $response = new Response($e->getMessage(), 500, array());
    }
    return $response;
});
