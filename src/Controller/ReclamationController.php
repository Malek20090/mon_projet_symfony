<?php

namespace App\Controller;

use App\Entity\Reclamation;
use App\Entity\User;
use App\Form\ReclamationType;
use App\Repository\ReclamationRepository;
use App\Repository\UserRepository;
use App\Service\BadWordsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class ReclamationController extends AbstractController
{
    #[Route('/reclamations', name: 'app_reclamation_index', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        ReclamationRepository $reclamationRepository,
        UserRepository $userRepository,
        BadWordsService $badWordsService,
        EntityManagerInterface $em,
        MailerInterface $mailer
    ): Response {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('User session is required.');
        }

        $reclamation = new Reclamation();
        $form = $this->createForm(ReclamationType::class, $reclamation);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $reclamation->setUser($user);
            $reclamation->setStatus(Reclamation::STATUS_PENDING);
            $reclamation->setCreatedAt(new \DateTimeImmutable());

            $analysis = $badWordsService->analyze(
                $reclamation->getSubject() . ' ' . $reclamation->getMessage()
            );

            if (($analysis['has_bad_words'] ?? false) === true) {
                $matchedWords = (array) ($analysis['matched'] ?? []);
                $reclamation->setContainsBadWords(true);
                $reclamation->setStatus(Reclamation::STATUS_BLOCKED);
                $reclamation->setAdminResponse('Automatic moderation: inappropriate language detected.');
                $reclamation->setResolvedAt(new \DateTimeImmutable());

                $user->setIsBlocked(true);
                $user->setBlockedReason('Inappropriate language detected in reclamation.');
                $user->setBlockedAt(new \DateTimeImmutable());

                $em->persist($reclamation);
                $em->flush();

                $this->notifyAdminsBadWords($mailer, $userRepository, $user, $reclamation, $matchedWords);

                $this->addFlash('error', 'Your account has been blocked due to inappropriate language.');
                return $this->redirectToRoute('app_logout');
            }

            $em->persist($reclamation);
            $em->flush();

            $this->addFlash('success', 'Reclamation submitted successfully.');
            return $this->redirectToRoute('app_reclamation_index');
        }

        return $this->render('reclamation/index.html.twig', [
            'reclamations' => $reclamationRepository->findByUser($user),
            'form' => $form->createView(),
            'back_route' => $this->isGranted('ROLE_SALARY')
                ? 'salary_profile'
                : ($this->isGranted('ROLE_ETUDIANT') ? 'student_profile' : 'app_home'),
        ]);
    }

    /**
     * @param string[] $matchedWords
     */
    private function notifyAdminsBadWords(
        MailerInterface $mailer,
        UserRepository $userRepository,
        User $user,
        Reclamation $reclamation,
        array $matchedWords
    ): void {
        $configuredAdmin = trim((string) ($_ENV['ADMIN_ALERT_EMAIL'] ?? ''));
        $adminEmails = $configuredAdmin !== '' ? [$configuredAdmin] : $userRepository->findAdminEmails();
        if ($adminEmails === []) {
            return;
        }

        $fromAddress = (string) ($_ENV['MAILER_FROM_ADDRESS'] ?? 'no-reply@decides.local');
        $words = $matchedWords !== [] ? implode(', ', $matchedWords) : 'n/a';
        $userName = (string) ($user->getNom() ?: $user->getEmail());
        $subject = sprintf('[ALERT] Reclamation blocked (user #%d)', (int) $user->getId());
        $text = sprintf(
            "A reclamation was blocked automatically.\n\nUser: %s (%s)\nReclamation ID: %d\nSubject: %s\nDetected bad words: %s\nStatus: %s\n\nAdmin URL: /admin/reclamations",
            $userName,
            $user->getEmail(),
            (int) $reclamation->getId(),
            $reclamation->getSubject(),
            $words,
            $reclamation->getStatus()
        );

        try {
            $mailer->send(
                (new Email())
                    ->from($fromAddress)
                    ->to(...$adminEmails)
                    ->subject($subject)
                    ->text($text)
            );
        } catch (\Throwable) {
            // Do not break user moderation flow when mail fails.
        }
    }
}
