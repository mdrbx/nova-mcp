<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
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
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\BelongsToMany;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Http\Controllers\ActionController;
use Laravel\Nova\Http\Controllers\AttachableController;
use Laravel\Nova\Http\Controllers\CreationFieldController;
use Laravel\Nova\Http\Controllers\CreationPivotFieldController;
use Laravel\Nova\Http\Controllers\FilterController;
use Laravel\Nova\Http\Controllers\MorphedResourceAttachController;
use Laravel\Nova\Http\Controllers\ResourceAttachController;
use Laravel\Nova\Http\Controllers\ResourceDestroyController;
use Laravel\Nova\Http\Controllers\ResourceDetachController;
use Laravel\Nova\Http\Controllers\ResourceForceDeleteController;
use Laravel\Nova\Http\Controllers\ResourceIndexController;
use Laravel\Nova\Http\Controllers\ResourceRestoreController;
use Laravel\Nova\Http\Controllers\ResourceShowController;
use Laravel\Nova\Http\Controllers\ResourceStoreController;
use Laravel\Nova\Http\Controllers\ResourceUpdateController;
use Laravel\Nova\Http\Controllers\UpdateFieldController;
use Laravel\Nova\Http\Middleware\DispatchServingNovaEvent;
use Laravel\Nova\Http\Requests\ActionRequest;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Http\Requests\ResourceDetailRequest;
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
        'restore' => ['PUT', '/{resource}/restore', ResourceRestoreController::class],
        'force-delete' => ['DELETE', '/{resource}/force', ResourceForceDeleteController::class],
        'replicate-fields' => ['GET', '/{resource}/creation-fields', CreationFieldController::class],
        'attachable' => ['GET', '/{resource}/{id}/attachable/{relatedResource}', AttachableController::class],
        'attach-fields' => ['GET', '/{resource}/{id}/creation-pivot-fields/{relatedResource}', CreationPivotFieldController::class],
        'attach' => ['POST', '/{resource}/{id}/attach/{relatedResource}', ResourceAttachController::class],
        'detach' => ['DELETE', '/{resource}/detach', ResourceDetachController::class],
        'add-fields' => ['GET', '/{resource}/creation-fields', CreationFieldController::class],
        'add' => ['POST', '/{resource}', ResourceStoreController::class],
        'actions' => ['GET', '/{resource}/actions', ActionController::class.'@index'],
        'run-action' => ['POST', '/{resource}/action', ActionController::class.'@store'],
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
     * @param  array{relationship?: string, related_id?: string, action?: string}  $context
     * @return array{status: int, body: mixed}
     */
    public function dispatch(string $operation, string $resource, ?string $id = null, array $query = [], array $body = [], array $context = []): array
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
            abort_if($method !== 'GET' && config('nova-mcp.read_only', false), 403, 'This MCP server is read-only.');

            abort_unless(preg_match('/\A[a-zA-Z0-9_-]+\z/', $resource) === 1, 422, 'Invalid resource key.');

            if (str_contains($path, '{id}') || in_array($operation, ['destroy', 'restore', 'force-delete', 'replicate-fields', 'detach', 'add-fields', 'add', 'actions', 'run-action'], true)) {
                abort_unless(is_string($id) && $id !== '' && strlen($id) <= 255 && preg_match('~[/\x5C\x00-\x1F\x7F]~', $id) === 0, 422, 'A valid resource ID is required.');
            }

            // Nova resolves these inputs before route parameters; accepting them could change the requested target or HTTP method.
            $reserved = [
                '_method', '_token', 'resource', 'resourceId', 'resources',
                'viaResource', 'viaResourceId', 'viaRelationship', 'relatedResource',
                'relatedResourceId', 'relationshipType', 'editing', 'editMode',
                'fromResourceId', 'action', 'pivotAction', 'pivots', 'viaPivotId',
                'field', 'component', 'display',
            ];
            abort_if(array_intersect($reserved, array_keys($query + $body)) !== [], 422, 'Reserved Nova request parameters are not accepted.');

            if (in_array($operation, ['destroy', 'restore', 'force-delete'], true)) {
                $body['resources'] = [$id];
            }

            if ($operation === 'replicate-fields') {
                $query['fromResourceId'] = $id;
            }

            if (in_array($operation, ['actions', 'run-action'], true)) {
                $query['resources'] = [$id];
                $query['resourceId'] = $id;
                if ($operation === 'run-action') {
                    $query['action'] = $context['action'];
                }
            }

            if (in_array($operation, ['attachable', 'attach-fields', 'attach', 'detach', 'add-fields', 'add'], true)) {
                $field = $this->relationshipField($resource, (string) $id, $context['relationship'], $operation);
                $path = str_replace('{relatedResource}', $field->resourceName, $path);
                $query['viaRelationship'] = $field->attribute;

                if ($operation === 'attachable') {
                    $query['component'] = $field->component;
                }

                if ($operation === 'attach') {
                    // The target comes from the visible Nova field and the validated ID, never client form data.
                    abort_if(array_key_exists($field->resourceName, $body) || array_key_exists($field->attribute, $body), 422, 'The attachment target must be supplied as related_id.');
                    $body[$field->resourceName] = $context['related_id'];
                    if ($field instanceof MorphToMany) {
                        $path = str_replace('/attach/', '/attach-morphed/', $path);
                        $controller = MorphedResourceAttachController::class;
                    }
                }

                if (in_array($operation, ['detach', 'add-fields', 'add'], true)) {
                    $query['viaResource'] = $resource;
                    $query['viaResourceId'] = $id;
                    $query['relationshipType'] = $field->relationshipType();
                    $resource = $field->resourceName;
                }

                if ($operation === 'detach') {
                    $body['resources'] = [$context['related_id']];
                }
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
            $expectedAction = str_contains($controller, '@') ? $controller : "{$controller}@__invoke";
            abort_unless($route->getAction('uses') === $expectedAction, 422, 'The request does not match the expected Nova endpoint.');

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

            $actionKeys = [];
            if (in_array($operation, ['actions', 'run-action'], true)) {
                $actionRequest = ActionRequest::createFrom($request);
                $modelQuery = $actionRequest->findModelQuery($id);
                $resourceClass::detailQuery($actionRequest, $modelQuery);
                $actionResource = $actionRequest->newResourceWith($modelQuery->firstOrFail());
                $actionResource->authorizeToView($actionRequest);

                if ($operation === 'run-action') {
                    $action = $actionRequest->action();
                    abort_if($action instanceof ShouldQueue || $action->isStandalone(), 422, 'Only synchronous resource actions are supported.');
                    // Nova's dispatcher remains responsible for canRun and runAction/runDestructiveAction.
                } else {
                    $actionKeys = $actionResource->availableActions($actionRequest)
                        ->reject(fn (Action $action): bool => $action instanceof ShouldQueue || $action->isStandalone())
                        ->map(fn (Action $action): string => $action->uriKey())->all();
                }
            }

            $result = $this->response($router->dispatch($request));
            if ($operation === 'actions' && $result['status'] === 200) {
                // Keep native field metadata, but omit actions that cannot be executed without a worker.
                $result['body'] = ['actions' => array_values(array_filter(
                    $result['body']['actions'],
                    fn (array $action): bool => in_array($action['uriKey'], $actionKeys, true),
                ))];
            }

            return $result;
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

    private function relationshipField(string $resource, string $id, string $relationship, string $operation): BelongsToMany|MorphToMany|HasMany
    {
        $host = config('nova.domain') ?: request()->getHttpHost();
        $request = Request::create(request()->getScheme().'://'.$host.'/nova-api/'.$resource.'/'.rawurlencode($id));
        $request->setUserResolver(request()->getUserResolver());
        $route = clone $this->router->getRoutes()->match($request);
        $request->setRouteResolver(fn (): Route => $route);
        $request = ResourceDetailRequest::createFrom($request);
        $resourceClass = $request->resource();
        $query = $request->findModelQuery($id);
        $resourceClass::detailQuery($request, $query);
        $parent = $request->newResourceWith($query->firstOrFail());
        $parent->authorizeToView($request);

        $adding = in_array($operation, ['add-fields', 'add'], true);
        $field = $parent->availableFields($request)->filterForDetail($request, $parent->model())->authorized($request)->first(
            fn ($field): bool => ($adding ? $field instanceof HasMany : ($field instanceof BelongsToMany || $field instanceof MorphToMany)) && $field->attribute === $relationship,
        );
        abort_unless($field instanceof BelongsToMany || $field instanceof MorphToMany || $field instanceof HasMany, 404, 'A visible Nova relationship field of the expected type is required.');

        $related = $field->resourceClass;
        $included = config('nova-mcp.resources', []);
        abort_if(in_array($related, config('nova-mcp.excluded_resources', []), true), 403);
        abort_if($included !== [] && ! in_array($related, $included, true), 403);
        abort_unless(Nova::resourceForKey($field->resourceName) === $related && $related::authorizedToViewAny($request), 403);

        if ($adding) {
            abort_unless($parent->authorizedToAdd($request, $related::newModel()), 403);
        }

        if (in_array($operation, ['attachable', 'attach-fields', 'attach'], true)) {
            abort_unless($parent->authorizedToAttachAny($request, $related::newModel(), invertible: true), 403);
        }

        return $field;
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
