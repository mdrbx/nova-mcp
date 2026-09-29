<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Facade;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Nova\Http\Controllers\CreationFieldController;
use Laravel\Nova\Http\Controllers\FilterController;
use Laravel\Nova\Http\Controllers\ResourceDestroyController;
use Laravel\Nova\Http\Controllers\ResourceIndexController;
use Laravel\Nova\Http\Controllers\ResourceShowController;
use Laravel\Nova\Http\Controllers\ResourceStoreController;
use Laravel\Nova\Http\Controllers\ResourceUpdateController;
use Laravel\Nova\Http\Controllers\UpdateFieldController;
use Laravel\Nova\Http\Middleware\DispatchServingNovaEvent;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Nova;
use Mdrbx\NovaMcp\Support\JsonExceptionHandler;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class NovaApi
{
    private const array OPERATIONS = [
        'index' => ['GET', '/{resource}', ResourceIndexController::class],
        'show' => ['GET', '/{resource}/{id}', ResourceShowController::class],
        'createFields' => ['GET', '/{resource}/creation-fields', CreationFieldController::class],
        'updateFields' => ['GET', '/{resource}/{id}/update-fields', UpdateFieldController::class],
        'filters' => ['GET', '/{resource}/filters', FilterController::class],
        'store' => ['POST', '/{resource}', ResourceStoreController::class],
        'update' => ['PUT', '/{resource}/{id}', ResourceUpdateController::class],
        'destroy' => ['DELETE', '/{resource}', ResourceDestroyController::class],
    ];

    private const array INITIALIZED_MIDDLEWARE = [
        StartSession::class,
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        ShareErrorsFromSession::class,
        'Illuminate\\Foundation\\Http\\Middleware\\PreventRequestForgery',
        VerifyCsrfToken::class,
        ValidateCsrfToken::class,
        DispatchServingNovaEvent::class,
    ];

    public function __construct(private Router $router) {}

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     * @return array{status: int, body: mixed}
     */
    public function dispatch(string $operation, string $resource, ?string $id = null, array $query = [], array $body = []): array
    {
        $app = app();
        $outer = $app->make('request');
        $previousRoute = $app->bound(Route::class) ? $app->make(Route::class) : null;
        $previousNovaRequest = $app->bound(NovaRequest::class) ? $app->make(NovaRequest::class) : null;
        $previousRouter = $app->make('router');
        $locale = $app->getLocale();
        $preventsMissingAttributes = Model::preventsAccessingMissingAttributes();
        $auth = $app->make('auth');
        $guard = $auth->getDefaultDriver();
        $userResolver = $auth->userResolver();
        $request = $outer;
        $previousHandler = $app->make(ExceptionHandler::class);
        $jsonHandler = new JsonExceptionHandler($app, $previousHandler);

        try {
            abort_unless(isset(self::OPERATIONS[$operation]), 422, 'Unsupported Nova operation.');
            [$method, $path, $controller] = self::OPERATIONS[$operation];

            abort_unless(preg_match('/\A[a-zA-Z0-9_-]+\z/', $resource) === 1, 422, 'Invalid resource key.');

            if (str_contains($path, '{id}') || $operation === 'destroy') {
                abort_unless(is_string($id) && $id !== '' && strlen($id) <= 255 && preg_match('~[/\x5C\x00-\x1F\x7F]~', $id) === 0, 422, 'A valid resource ID is required.');
            }

            // Nova resolves these inputs before route parameters; accepting them could change the requested target or HTTP method.
            $reserved = [
                '_method', '_token', 'resource', 'resourceId', 'resources',
                'viaResource', 'viaResourceId', 'viaRelationship', 'relatedResource',
                'relatedResourceId', 'relationshipType', 'editing', 'editMode',
            ];
            abort_if(array_intersect($reserved, array_keys($query + $body)) !== [], 422, 'Reserved Nova request parameters are not accepted.');

            if ($operation === 'destroy') {
                $body['resources'] = [$id];
            }

            $path = strtr($path, ['{resource}' => $resource, '{id}' => rawurlencode($id ?? '')]);
            $host = config('nova.domain') ?: $outer->getHttpHost();
            $request = Request::create("{$outer->getScheme()}://{$host}/nova-api{$path}", $method, server: [
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => $outer->header('Authorization', ''),
                'REMOTE_ADDR' => $outer->ip(),
            ], content: json_encode($body, JSON_THROW_ON_ERROR));
            $request->query->replace($query);
            $request->request->replace($body);
            $request->setJson(new InputBag($body));
            $request->setUserResolver($outer->getUserResolver());
            $request->attributes->set('nova-mcp.scopes', $outer->attributes->get('nova-mcp.scopes', []));

            if ($outer->hasSession()) {
                $request->setLaravelSession($outer->session());
            }

            $route = clone $this->router->getRoutes()->match($request);

            // Reserved IDs such as "filters" can match a different Nova route despite a valid-looking URL.
            abort_unless($route->getAction('uses') === "{$controller}@__invoke", 422, 'The request does not match the expected Nova endpoint.');

            // The MCP boundary already initializes Nova and its session. Rebooting Nova replaces the JSON exception handler.
            $route->withoutMiddleware(self::INITIALIZED_MIDDLEWARE);
            $route->flushController();
            $route->setContainer($app);
            $request->setRouteResolver(fn (): Route => $route);
            $routes = new RouteCollection;

            foreach ($this->router->getRoutes()->getRoutes() as $registered) {
                $matches = $registered->methods() === $route->methods()
                    && $registered->uri() === $route->uri()
                    && $registered->getDomain() === $route->getDomain();
                $routes->add($matches ? $route : $registered);
            }

            $router = clone $this->router;
            $router->setRoutes($routes);
            $app->instance('router', $router);
            $app->instance('request', $request);
            $app->instance(Route::class, $route);
            $app->forgetInstance(NovaRequest::class);
            Facade::clearResolvedInstance('router');
            Facade::clearResolvedInstance('request');

            $resourceClass = Nova::resourceForKey($resource);
            abort_unless($resourceClass !== null, 404);
            // Metadata endpoints do not all check viewAny themselves; undiscoverable resources must stay inaccessible.
            abort_unless($resourceClass::authorizedToViewAny(NovaRequest::createFrom($request)), 403);

            // Nova's route pipeline also renders exceptions, so JSON rendering must cover the entire dispatch.
            $app->instance(ExceptionHandler::class, $jsonHandler);

            return $this->response($router->dispatch($request));
        } catch (Throwable $exception) {
            // Nova renders 403/404 through Inertia even for JSON requests. MCP must not depend on browser assets.
            $response = $jsonHandler->render($request, $exception);

            if ($response->getStatusCode() >= 500) {
                $previousHandler->report($exception);
            }

            return $this->response($response);
        } finally {
            $app->instance(ExceptionHandler::class, $previousHandler);
            $app->instance('router', $previousRouter);
            $app->instance('request', $outer);
            $previousRoute ? $app->instance(Route::class, $previousRoute) : $app->forgetInstance(Route::class);
            $previousNovaRequest ? $app->instance(NovaRequest::class, $previousNovaRequest) : $app->forgetInstance(NovaRequest::class);
            Facade::clearResolvedInstance('router');
            Facade::clearResolvedInstance('request');
            $auth->shouldUse($guard);
            $auth->resolveUsersUsing($userResolver);
            $app->setLocale($locale);
            Model::preventAccessingMissingAttributes($preventsMissingAttributes);
        }
    }

    /** @return array{status: int, body: mixed} */
    private function response(Response $response): array
    {
        if ($response->getStatusCode() >= 500) {
            return ['status' => $response->getStatusCode(), 'body' => ['message' => 'The Nova request failed.']];
        }

        $content = $response->getContent();
        $body = $content === '' ? null : json_decode((string) $content, true);

        if ($response->getStatusCode() >= 400) {
            // Debug exception payloads include internal paths and traces even for ordinary 403/422 errors.
            $body = is_array($body)
                ? array_intersect_key($body, ['message' => true, 'errors' => true])
                : ['message' => 'The Nova request was rejected.'];
        }

        return [
            'status' => $response->getStatusCode(),
            'body' => $body,
        ];
    }
}
