<?php

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Dcat\Admin\Admin;

Admin::routes();

Route::group([
    'prefix'     => config('admin.route.prefix'),
    'namespace'  => config('admin.route.namespace'),
    'middleware' => config('admin.route.middleware'),
], function (Router $router) {

    $router->get('/', 'HomeController@index');
    $router->get('ai-sessions/{session}/messages/{message}/raw', 'AiSessionController@raw');
    $router->resource('ai-sessions', 'AiSessionController')->only(['index', 'show', 'destroy']);

});
