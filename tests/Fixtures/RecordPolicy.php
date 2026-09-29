<?php

declare(strict_types=1);

namespace Mdrbx\NovaMcp\Tests\Fixtures;

class RecordPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Record $record): bool
    {
        return (string) $record->owner_id === (string) $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Record $record): bool
    {
        return $this->view($user, $record) && $record->name !== 'Locked record';
    }

    public function delete(User $user, Record $record): bool
    {
        return $this->view($user, $record);
    }
}
