<?php

namespace App\Policies;

use App\Models\Factory;
use App\Models\FactoryStock;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

/**
 * موجودیِ یک مبدأ فقط مالِ تأمین‌کننده‌ی همان کارخانه است.
 *
 * هم‌الگو با `ProductPricePolicy` — route-model binding هر `{stock}` را با id
 * پیدا می‌کند، پس این policy تنها چیزی است که بین موجودیِ دو تأمین‌کننده ایستاده.
 */
class FactoryStockPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): ?bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return null;
    }

    public function view(User $user, FactoryStock $stock): Response
    {
        return $this->owns($user, $stock);
    }

    public function update(User $user, FactoryStock $stock): Response
    {
        return $this->owns($user, $stock);
    }

    public function delete(User $user, FactoryStock $stock): Response
    {
        return $this->owns($user, $stock);
    }

    private function owns(User $user, FactoryStock $stock): Response
    {
        $ownerId = $stock->originFactory?->{Factory::SUPPLIER_USER_ID};

        return $ownerId !== null && (int) $ownerId === (int) $user->id
            ? Response::allow()
            : Response::deny(__('auth.policy.factory_access_denied'));
    }
}
