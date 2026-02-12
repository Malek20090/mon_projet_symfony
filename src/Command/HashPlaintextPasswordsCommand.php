<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:users:hash-plaintext-passwords',
    description: 'Hash existing plaintext user passwords in database.',
)]
class HashPlaintextPasswordsCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $users = $this->userRepository->findAll();

        $updated = 0;
        foreach ($users as $user) {
            if (!$user instanceof User) {
                continue;
            }

            $storedPassword = $user->getPassword();
            $passwordInfo = password_get_info($storedPassword);
            $isHashed = !empty($passwordInfo['algo']);

            if ($isHashed) {
                continue;
            }

            $user->setPassword($this->passwordHasher->hashPassword($user, $storedPassword));
            $updated++;
        }

        if ($updated > 0) {
            $this->entityManager->flush();
        }

        $io->success(sprintf('Done. %d plaintext password(s) were hashed.', $updated));

        return Command::SUCCESS;
    }
}

