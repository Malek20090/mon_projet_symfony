<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher,
        ValidatorInterface $validator
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

            if ($password !== $confirmPassword) {
                $errors[] = 'Password confirmation does not match.';
            }

            $user = new User();
            $user->setNom($nom);
            $user->setEmail($email);
            $user->setPassword($password);
            $user->setSoldeTotal((float) $solde);

            $roleMap = [
                'ETUDIANT' => ['ROLE_ETUDIANT'],
                'SALARIE' => ['ROLE_SALARY'],
            ];

            if (!isset($roleMap[$role])) {
                $errors[] = 'Please choose a valid role.';
            } else {
                $user->setRoles($roleMap[$role]);
            }

            foreach ($validator->validate($user) as $violation) {
                $errors[] = $violation->getMessage();
            }

            if (!empty($errors)) {
                foreach (array_unique($errors) as $error) {
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

            $user->setPassword($passwordHasher->hashPassword($user, $password));

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
