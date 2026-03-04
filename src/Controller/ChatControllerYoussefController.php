<?php

namespace App\Controller;

use App\Service\ChatInvestmentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
class ChatControllerYoussefController extends AbstractController
{
    #[Route('/chat', name: 'chat_index')]
    public function index(Request $request, ChatInvestmentService $chatService): Response
    {
        $result = null;

        if ($request->isMethod('POST')) {

            $message = $request->request->get('message');

            if ($message && $this->getUser()) {
                $result = $chatService->handleMessage($message, $this->getUser());
            }
        }

        return $this->render('chat_controller_youssef/index.html.twig', [
            'result' => $result
        ]);
    }
    #[Route('/chat-investment', name: 'chat_investment', methods: ['POST'])]
public function chatInvestment(
    Request $request,
    ChatInvestmentService $chatService
): JsonResponse {

    $data = json_decode($request->getContent(), true);

    if (!isset($data['message'])) {
        return $this->json([
            'success' => false,
            'error' => 'No message provided'
        ], 400);
    }

    $result = $chatService->handleMessage(
        $data['message'],
        $this->getUser()
    );

    return $this->json($result);
}
}
