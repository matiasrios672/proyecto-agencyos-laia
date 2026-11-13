<?php

namespace App\Policies;

use App\Enums\QuoteStatus;
use App\Models\Quote;
use App\Models\User;

class QuotePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('quotes.view');
    }

    public function view(User $user, Quote $quote): bool
    {
        return $user->can('quotes.view');
    }

    public function create(User $user): bool
    {
        return $user->can('quotes.manage');
    }

    public function update(User $user, Quote $quote): bool
    {
        return $user->can('quotes.manage') && $quote->isEditable();
    }

    /** Enviar por email/WhatsApp, rechazar y aprobar/convertir. */
    public function resolve(User $user, Quote $quote): bool
    {
        return $user->can('quotes.manage');
    }

    /** Solo los borradores se eliminan; lo enviado o aprobado queda como registro. */
    public function delete(User $user, Quote $quote): bool
    {
        return $user->can('quotes.manage') && $quote->status === QuoteStatus::Borrador;
    }
}
