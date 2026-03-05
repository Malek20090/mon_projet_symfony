<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class UserControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $manager;
    private EntityRepository $userRepository;
    private string $path = '/user/';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->manager = static::getContainer()->get('doctrine')->getManager();
        $this->userRepository = $this->manager->getRepository(User::class);

        foreach ($this->userRepository->findAll() as $object) {
            $this->manager->remove($object);
        }

        $this->manager->flush();
    }

    public function testIndex(): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', $this->path);

        self::assertResponseStatusCodeSame(200);
        self::assertPageTitleContains('Users');
        self::assertSelectorTextContains('h1', 'Users');
    }

    public function testNew(): void
    {
        $this->loginAsAdmin();

        $this->client->request('GET', $this->path . 'new');
        self::assertResponseStatusCodeSame(200);
        self::assertPageTitleContains('New User');
        self::assertSelectorExists('form[name="user"]');
    }

    public function testShow(): void
    {
        $this->loginAsAdmin();
        $targetUser = $this->createUser('show.user@example.com', ['ROLE_SALARY'], 'Show User');

        $this->client->request('GET', sprintf('%s%d', $this->path, $targetUser->getId()));

        self::assertResponseStatusCodeSame(200);
        self::assertPageTitleContains('User #' . $targetUser->getId());
        self::assertSelectorTextContains('h1', 'User Profile #' . $targetUser->getId());
    }

    public function testEdit(): void
    {
        $this->loginAsAdmin();
        $targetUser = $this->createUser('edit.user@example.com', ['ROLE_SALARY'], 'Before Edit');

        $this->client->request('GET', sprintf('%s%d/edit', $this->path, $targetUser->getId()));

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorExists('form');
    }

    public function testRemove(): void
    {
        $this->loginAsAdmin();
        $targetUser = $this->createUser('remove.user@example.com', ['ROLE_ETUDIANT'], 'To Remove');

        $crawler = $this->client->request('GET', sprintf('%s%d', $this->path, $targetUser->getId()));
        $csrf = $crawler->filter('form[action$="/user/' . $targetUser->getId() . '"] input[name="_token"]')->attr('value');

        $this->client->request('POST', sprintf('%s%d', $this->path, $targetUser->getId()), [
            '_token' => $csrf,
        ]);

        self::assertResponseRedirects('/user/');

        $this->manager->clear();
        self::assertNull($this->userRepository->find($targetUser->getId()));
    }

    private function loginAsAdmin(): User
    {
        $admin = $this->createUser('admin.test@example.com', ['ROLE_ADMIN'], 'Admin Test');
        $this->client->loginUser($admin);

        return $admin;
    }

    private function createUser(string $email, array $roles, string $name): User
    {
        $user = (new User())
            ->setNom($name)
            ->setEmail($email)
            ->setPassword('password-123')
            ->setRoles($roles)
            ->setSoldeTotal(100.0);

        $this->manager->persist($user);
        $this->manager->flush();

        return $user;
    }
}
