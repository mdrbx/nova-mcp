<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp\Tests\Fixtures;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Resource;

/** @extends resource<Record> */
class RecordResource extends Resource
{
    public static $model = Record::class;

    public static $title = 'name';

    public static $search = ['name'];

    public static $perPageOptions = [1, 25, 50];

    public static function uriKey(): string
    {
        return 'records';
    }

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make(),
            Text::make('Name')->rules('required', 'max:100'),
            Text::make('Secret')->canSee(fn (): bool => false),
        ];
    }

    public static function indexQuery(NovaRequest $request, Builder $query): Builder
    {
        return $query->where('owner_id', $request->user()->getAuthIdentifier());
    }

    public static function detailQuery(NovaRequest $request, Builder $query): Builder
    {
        return $query->where('owner_id', $request->user()->getAuthIdentifier());
    }

    public static function beforeCreate(NovaRequest $request, Model $model): void
    {
        $model->owner_id = $request->user()->getAuthIdentifier();
    }

    public function filters(NovaRequest $request): array
    {
        return [new RecordFilter];
    }
}
