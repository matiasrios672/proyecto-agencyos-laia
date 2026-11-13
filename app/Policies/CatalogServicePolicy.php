<?php

namespace App\Policies;

use App\Models\CatalogService;
use App\Models\User;

class CatalogServicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('quotes.view');
    }

    public function create(User $user): bool
    {
        return $user->can('quotes.manage');
    }

    public function update(User $user, CatalogService $service): bool
    {
        return $user->can('quotes.manage');
    }

    public function delete(User $user, CatalogService $service): bool
    {
        return $user->can('quotes.manage');
    }
}
