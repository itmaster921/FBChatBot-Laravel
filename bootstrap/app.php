<?php

use Laravel\Lumen\Application;

require_once __DIR__ . '/../vendor/autoload.php';

try {
    (new Dotenv\Dotenv(__DIR__ . '/../'))->load();
} catch (Dotenv\Exception\InvalidPathException $e) {
    //
}

/*
|--------------------------------------------------------------------------
| Create The Application
|--------------------------------------------------------------------------
|
| Here we will load the environment and create the application instance
| that serves as the central piece of this framework. We'll use this
| application as an "IoC" container and router for this framework.
|
*/

$app = new Laravel\Lumen\Application(realpath(__DIR__ . '/../'));

$app->withFacades(true, [
    'Illuminate\Support\Facades\Redirect' => 'Redirect'
]);
$app->bind('redirect', 'Laravel\Lumen\Http\Redirector');

/**
 * Register service prviders
 */
$app->register(Jenssegers\Mongodb\MongodbServiceProvider::class);
$app->register(Jenssegers\Mongodb\MongodbQueueServiceProvider::class);
$app->register(Jaybizzle\LaravelCrawlerDetect\LaravelCrawlerDetectServiceProvider::class);


$app->withEloquent();

$app->configure('app');
$app->configure('jwt');
$app->configure('queue');
$app->configure('admin');
$app->configure('services');

/*
|--------------------------------------------------------------------------
| Register Container Bindings
|--------------------------------------------------------------------------
|
| Now we will register a few bindings in the service container. We will
| register the exception handler and the console kernel. You may add
| your own bindings here if you like or you can make another file.
|
*/

$app->singleton(Illuminate\Contracts\Debug\ExceptionHandler::class, Common\Exceptions\Handler::class);

$app->singleton(Illuminate\Contracts\Console\Kernel::class, Common\Console\Kernel::class);

$app->singleton(Illuminate\Contracts\Routing\ResponseFactory::class, Illuminate\Routing\ResponseFactory::class);

$app->singleton(Illuminate\Auth\AuthManager::class, function (Application $app) {
    return $app->make('auth');
});

$app->singleton(Illuminate\Cache\CacheManager::class, function (Application $app) {
    return $app->make('cache');
});

/*
|--------------------------------------------------------------------------
| Register Middleware
|--------------------------------------------------------------------------
|
| Next, we will register the middleware with the application. These can
| be global middleware that run before and after each request into a
| route or middleware that'll be assigned to some specific routes.
|
*/

$app->routeMiddleware([
    'fb.webhook.verify' => Common\Http\Middleware\FacebookWebhookMiddleware::class,
]);

/*
|--------------------------------------------------------------------------
| Register Service Providers
|--------------------------------------------------------------------------
|
| Here we will register all of the application's service providers which
| are used to bind services into the container. Service providers are
| totally optional, so you are not required to uncomment this line.
|
*/

$app->register(Common\Providers\FractalServiceProvider::class);

$app->register(Dingo\Api\Provider\LumenServiceProvider::class);
$app->register(Tymon\JWTAuth\Providers\LumenServiceProvider::class);
$app->register(Laravel\Cashier\CashierServiceProvider::class);

$app->register(Common\Providers\AppServiceProvider::class);
$app->register(Common\Providers\CatchAllOptionsRequestsProvider::class);
$app->register(Common\Providers\RepositoryServiceProvider::class);
$app->register(Common\Providers\PusherServiceProvider::class);
$app->register(Illuminate\Redis\RedisServiceProvider::class);

$app->make(Dingo\Api\Auth\Auth::class)->extend('jwt', function (Application $app) {
    return new Dingo\Api\Auth\Provider\JWT($app->make(Tymon\JWTAuth\JWTAuth::class));
});

app('Dingo\Api\Transformer\Factory')->setAdapter(function ($app) {
    return new Dingo\Api\Transformer\Adapter\Fractal(
        app(League\Fractal\Manager::class), 'include', ',', false
    );
});

$app->register(Common\Providers\DingoApiExceptionHandler::class);
$app->register(Sentry\SentryLaravel\SentryLumenServiceProvider::class);

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;

$app->configureMonologUsing(function ($monolog) {
    $maxFiles = 7;

    $rotatingLogHandler = (new RotatingFileHandler(storage_path('logs/lumen.log'), $maxFiles))->setFormatter(new LineFormatter(null, null, true,
        true));

    $monolog->setHandlers([$rotatingLogHandler]);

    return $monolog;
});

/*
|--------------------------------------------------------------------------
| Load The Application Routes
|--------------------------------------------------------------------------
|
| Next we will include the routes file so that they can all be added to
| the application. This will provide all of the URLs the application
| can respond to, as well as the controllers that may handle them.
|
*/
require __DIR__ . '/../app/Http/Routes/api.php';
require __DIR__ . '/../app/Http/Routes/web.php';
require __DIR__ . '/../admin/Http/Routes/admin-api.php';

return $app;
