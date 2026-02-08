<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher
    ): Response
    {
        if ($request->isMethod('POST')) {

            $user = new User();

            $user->setNom($request->request->get('nom'));
            $user->setEmail($request->request->get('email'));

            // 🔐 Hash password
            $hashedPassword = $passwordHasher->hashPassword(
                $user,
                $request->request->get('password')
            );
            $user->setPassword($hashedPassword);

            // 🎭 Role
            $role = $request->request->get('role');
            if ($role === 'ETUDIANT') {
                $user->setRoles(['ROLE_ETUDIANT']);
            } elseif ($role === 'SALARIE') {
                $user->setRoles(['ROLE_SALARY']);
            } else {
                $user->setRoles(['ROLE_USER']);
            }

            // 💰 Solde
            $user->setSoldeTotal((float) $request->request->get('solde'));

            // 📅 Date auto
            

            $em->persist($user);
            $em->flush();

            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/register.html.twig');
    }
}
