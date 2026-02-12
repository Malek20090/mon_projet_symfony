<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
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
        UserPasswordHasherInterface $passwordHasher,
        UserRepository $userRepository
    ): Response {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('register', (string) $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Invalid form token. Please try again.');
                return $this->render('security/register.html.twig', [
                    'form_data' => [],
                ]);
            }

            $nom = trim((string) $request->request->get('nom'));
            $email = strtolower(trim((string) $request->request->get('email')));
            $password = (string) $request->request->get('password');
            $confirmPassword = (string) $request->request->get('confirm_password');
            $role = (string) $request->request->get('role');
            $solde = (string) $request->request->get('solde', '0');

            $errors = [];

            if ($nom === '' || mb_strlen($nom) < 2) {
                $errors[] = 'Full name must contain at least 2 characters.';
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please enter a valid email address.';
            }

            if ($userRepository->findOneBy(['email' => $email])) {
                $errors[] = 'This email is already used.';
            }

            if (strlen($password) < 8) {
                $errors[] = 'Password must be at least 8 characters.';
            }

            if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/\d/', $password)) {
                $errors[] = 'Password must include uppercase, lowercase and a number.';
            }

            if ($password !== $confirmPassword) {
                $errors[] = 'Password confirmation does not match.';
            }

            if (!in_array($role, ['ETUDIANT', 'SALARIE'], true)) {
                $errors[] = 'Please choose a valid role.';
            }

            if (!is_numeric($solde) || (float) $solde < 0) {
                $errors[] = 'Initial balance must be a non-negative number.';
            }

            if (!empty($errors)) {
                foreach ($errors as $error) {
                    $this->addFlash('error', $error);
                }

                return $this->render('security/register.html.twig', [
                    'form_data' => [
                        'nom' => $nom,
                        'email' => $email,
                        'role' => $role,
                        'solde' => $solde,
                    ],
                ]);
            }

            $user = new User();
            $user->setNom($nom);
            $user->setEmail($email);
            $user->setPassword($passwordHasher->hashPassword($user, $password));

            if ($role === 'ETUDIANT') {
                $user->setRoles(['ROLE_ETUDIANT']);
            } elseif ($role === 'SALARIE') {
                $user->setRoles(['ROLE_SALARY']);
            } else {
                $user->setRoles(['ROLE_USER']);
            }

            $user->setSoldeTotal((float) $solde);

            $em->persist($user);
            $em->flush();

            $this->addFlash('success', 'Account created successfully. You can sign in now.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/register.html.twig', [
            'form_data' => [],
        ]);
    }
}
