<?php

namespace App\Actions\Profile;

use App\Exceptions\BusinessException;
use App\Models\Agreement;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Resolves the correct agreement for a given role slug and records
 * the user's acceptance (or throws if mandatory and not accepted).
 */
final class SignAgreementAction
{
    /**
     * @param  string  $roleSlug  One of 'expert' | 'employer' | 'supplier'
     * @param  Request  $request  Used to read terms_accepted, IP, and user-agent
     *
     * @throws BusinessException When the agreement is mandatory and not accepted
     */
    public function execute(User $user, string $roleSlug, Request $request): void
    {
        $agreementSlug = match ($roleSlug) {
            'expert' => 'consultant_terms',
            'employer' => 'employer_terms',
            'supplier' => 'supplier_terms',
            default => null,
        };

        if (! $agreementSlug) {
            return;
        }

        $agreement = Agreement::where(Agreement::SLUG, $agreementSlug)
            ->active()
            ->latest(Agreement::CREATED_AT)
            ->first();

        if (! $agreement) {
            return;
        }

        if ($request->boolean('terms_accepted')) {
            $user->agreements()->syncWithoutDetaching([
                $agreement->id => [
                    'accepted_at' => now(),
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->header('User-Agent'),
                ],
            ]);
        } elseif ($agreement->is_mandatory) {
            throw new BusinessException(__('auth.agreements.required'));
        }
    }
}
