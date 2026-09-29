<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Passport\Passport;

/**
 * @property string $client_id
 * @property string $resource
 */
class OAuthClient extends Model
{
    protected $table = 'nova_mcp_clients';

    protected $primaryKey = 'client_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['client_id', 'resource'];

    public function getConnectionName(): ?string
    {
        return Passport::client()->getConnectionName();
    }
}
