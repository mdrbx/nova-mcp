<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp\Tests\Fixtures;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

class RecordFilter extends Filter
{
    public function apply(NovaRequest $request, Builder $query, mixed $value): Builder
    {
        return $query->where('name', $value);
    }

    public function options(NovaRequest $request): array
    {
        return ['Fixture record' => 'Fixture record'];
    }
}
