<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\Transaction;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class TestController extends AbstractController
{
    #[Route('/test-insert', name: 'test_insert')]
    public function insert(EntityManagerInterface $em): Response
    {
        // Création d'un utilisateur
        $user = new User();
        $user->setNom('Malek');
        $user->setEmail('malek@test.com');
        $user->setPassword('123456');
        $user->setRole('ETUDIANT');
        $user->setDateInscription(new \DateTime());
        $user->setSoldeTotal(500);

        $em->persist($user);

        // Création d'une transaction
        $transaction = new Transaction();
        $transaction->setType('EPARGNE');
        $transaction->setMontant(200);
        $transaction->setDate(new \DateTime());
        $transaction->setDescription('Première transaction');
        $transaction->setModuleSource('test');
        $transaction->setUser($user);

        $em->persist($transaction);

        $em->flush();

        return new Response('Insertion réussie ✅');
    }

    #[Route('/test-list', name: 'test_list')]
    public function list(EntityManagerInterface $em): Response
    {
        $users = $em->getRepository(User::class)->findAll();

        $html = '<h1>Liste des utilisateurs</h1>';

        foreach ($users as $user) {
            $html .= '<h3>'.$user->getNom().' ('.$user->getEmail().')</h3>';
            $html .= '<ul>';

            foreach ($user->getTransactions() as $transaction) {
                $html .= '<li>'
                    .$transaction->getType()
                    .' - '
                    .$transaction->getMontant()
                    .' DT</li>';
            }

            $html .= '</ul>';
        }

        return new Response($html);
    }
}
