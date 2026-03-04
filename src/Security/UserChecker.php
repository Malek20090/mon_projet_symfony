<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if ($user->isBlocked()) {
            $reason = $user->getBlockedReason();
            throw new CustomUserMessageAccountStatusException(
                $reason ? ('Account blocked: ' . $reason) : 'Your account is blocked by moderation.'
            );
        }

        // Email confirmation is disabled: accounts can sign in immediately.
    }

    public function checkPostAuth(UserInterface $user): void
    {
    }
}
