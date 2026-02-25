<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

class LoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    private RouterInterface $router;

    public function __construct(RouterInterface $router)
    {
        $this->router = $router;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): RedirectResponse
    {
        $roles = $token->getRoleNames();

        if (in_array('ROLE_ADMIN', $roles)) {
            return new RedirectResponse($this->router->generate('admin_dashboard'));
        }

        if (in_array('ROLE_SALARY', $roles)) {
            return new RedirectResponse($this->router->generate('salary_dashboard'));
        }

        if (in_array('ROLE_ETUDIANT', $roles)) {
            return new RedirectResponse($this->router->generate('student_cours_index'));
        }

        // Default fallback - redirect to homepage or a general page
        // Avoid redirecting to login to prevent infinite loop
        return new RedirectResponse($this->router->generate('app_home'));
    }
}
