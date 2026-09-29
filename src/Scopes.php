<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp;

final class Scopes
{
    public const string READ = 'nova:read';

    public const string WRITE = 'nova:write';

    public const string OFFLINE = 'offline_access';

    /** @return array<string, string> */
    public static function descriptions(): array
    {
        return [
            self::READ => 'Read the Nova resources and fields available to your account.',
            self::WRITE => 'Modify records and relationships, restore or permanently delete records, and run synchronous actions where your Nova permissions allow it.',
            self::OFFLINE => 'Keep this connection active until it expires or you revoke it.',
        ];
    }

    /** @return list<string> */
    public static function supported(): array
    {
        return array_keys(self::descriptions());
    }

    /** @param array<int, string> $scopes */
    public static function allows(array $scopes): bool
    {
        return in_array(self::READ, $scopes, true) && array_diff($scopes, self::supported()) === [];
    }
}
