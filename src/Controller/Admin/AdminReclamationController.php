<?php

namespace App\Controller\Admin;

use App\Entity\Reclamation;
use App\Entity\User;
use App\Repository\ReclamationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/reclamations', name: 'admin_reclamation_')]
class AdminReclamationController extends AbstractController
{
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(Request $request, ReclamationRepository $reclamationRepository): Response
    {
        $status = (string) $request->query->get('status', '');
        $status = trim($status);

        return $this->render('admin/reclamations/index.html.twig', [
            'reclamations' => $reclamationRepository->findForAdmin($status !== '' ? $status : null),
            'status' => $status,
            'blocked_alert_count' => $reclamationRepository->countBlockedBadWordsNotHandled(),
            'blocked_alert_items' => $reclamationRepository->findRecentBlockedBadWords(5),
            'statuses' => [
                Reclamation::STATUS_PENDING,
                Reclamation::STATUS_IN_PROGRESS,
                Reclamation::STATUS_RESOLVED,
                Reclamation::STATUS_REJECTED,
                Reclamation::STATUS_BLOCKED,
            ],
        ]);
    }

    #[Route('/{id}/respond', name: 'respond', methods: ['POST'])]
    public function respond(
        Reclamation $reclamation,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        if (!$this->isCsrfTokenValid('reclamation_respond_' . $reclamation->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_reclamation_index');
        }

        $status = strtoupper(trim((string) $request->request->get('status', Reclamation::STATUS_IN_PROGRESS)));
        $response = trim((string) $request->request->get('admin_response', ''));

        $allowed = [
            Reclamation::STATUS_PENDING,
            Reclamation::STATUS_IN_PROGRESS,
            Reclamation::STATUS_RESOLVED,
            Reclamation::STATUS_REJECTED,
            Reclamation::STATUS_BLOCKED,
        ];
        if (!in_array($status, $allowed, true)) {
            $status = Reclamation::STATUS_IN_PROGRESS;
        }

        $reclamation->setStatus($status);
        $reclamation->setAdminResponse($response !== '' ? $response : null);
        $reclamation->setUpdatedAt(new \DateTimeImmutable());
        if (in_array($status, [Reclamation::STATUS_RESOLVED, Reclamation::STATUS_REJECTED, Reclamation::STATUS_BLOCKED], true)) {
            $reclamation->setResolvedAt(new \DateTimeImmutable());
        } else {
            $reclamation->setResolvedAt(null);
        }

        /** @var User|null $admin */
        $admin = $this->getUser();
        if ($admin instanceof User) {
            $reclamation->setAdminResponder($admin);
        }

        $em->flush();
        $this->addFlash('success', 'Reclamation updated.');

        return $this->redirectToRoute('admin_reclamation_index');
    }
}
