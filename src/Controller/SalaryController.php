<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\SalaryProfileType;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

#[IsGranted('ROLE_SALARY')]
class SalaryController extends AbstractController
{
    #[Route('/salary', name: 'salary_dashboard')]
    public function index(): Response
    {
        /** @var User|null $currentUser */
        $currentUser = $this->getUser();

        return $this->render('salary/index.html.twig', [
            'current_user' => $currentUser,
        ]);
    }

    #[Route('/salary/profile', name: 'salary_profile', methods: ['GET', 'POST'])]
    public function profile(
        Request $request,
        EntityManagerInterface $em,
        SluggerInterface $slugger,
        UserPasswordHasherInterface $passwordHasher,
        TransactionRepository $transactionRepository
    ): Response {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('User session is required.');
        }

        $form = $this->createForm(SalaryProfileType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = (string) $form->get('plainPassword')->getData();
            if (trim($plainPassword) !== '') {
                $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));
            }

            /** @var UploadedFile|null $imageFile */
            $imageFile = $form->get('image')->getData();
            if ($imageFile) {
                $safeFilename = $slugger->slug(pathinfo($imageFile->getClientOriginalName(), PATHINFO_FILENAME));
                $newFilename = $safeFilename . '-' . uniqid() . '.' . $imageFile->guessExtension();

                $imageFile->move(
                    $this->getParameter('user_images_directory'),
                    $newFilename
                );

                $user->setImage($newFilename);
            }

            $em->flush();
            $this->addFlash('success', 'Profile updated successfully.');

            return $this->redirectToRoute('salary_profile');
        }

        $transactions = $transactionRepository->findBy(
            ['user' => $user],
            ['date' => 'DESC']
        );

        return $this->render('salary/profile.html.twig', [
            'user' => $user,
            'form' => $form->createView(),
            'transactions' => $transactions,
        ]);
    }
}
