<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class RegistrationController extends AbstractController
{
    private const CAPTCHA_CODE_SESSION_KEY = 'register_text_captcha_code';
    private const CAPTCHA_API_STORE_SESSION_KEY = 'captcha_api_store';
    private const CAPTCHA_API_TTL_SECONDS = 300;

    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher,
        ValidatorInterface $validator,
        MailerInterface $mailer,
        UrlGeneratorInterface $urlGenerator
    ): Response {
        $session = $request->getSession();
        if ($request->isMethod('GET')) {
            $this->refreshCaptcha($session);
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('register', (string) $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Invalid form token. Please try again.');
                $this->refreshCaptcha($session);

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
            $captchaInput = strtoupper(trim((string) $request->request->get('captcha_input')));
            $faceIdCredentialId = trim((string) $request->request->get('faceid_credential_id', ''));
            $facePlusToken = trim((string) $request->request->get('facepp_token', ''));

            $errors = [];

            if ($password !== $confirmPassword) {
                $errors[] = 'Password confirmation does not match.';
            }

            $captchaCode = (string) $session->get(self::CAPTCHA_CODE_SESSION_KEY, '');
            if ($captchaCode === '' || $captchaInput !== $captchaCode) {
                $errors[] = 'Captcha incorrect. Please type the characters shown in the image.';
            }

            // Check if user already exists
            $existingUser = $em->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($existingUser) {
                $errors[] = 'An account with this email already exists. Please log in instead.';
            }

            if (!empty($errors)) {
                foreach (array_unique($errors) as $error) {
                    $this->addFlash('error', $error);
                }
                $this->refreshCaptcha($session);

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

            if ($faceIdCredentialId !== '') {
                $user->setFaceIdEnabled(true);
                $user->setFaceIdCredentialId($faceIdCredentialId);
            }

            if ($facePlusToken !== '') {
                $user->setFacePlusEnabled(true);
                $user->setFacePlusToken($facePlusToken);
            }
            $user->setEmailVerified(true);
            $user->setEmailVerifiedAt(new \DateTimeImmutable());
            $user->setEmailVerificationToken(null);

            foreach ($validator->validate($user) as $violation) {
                $errors[] = $violation->getMessage();
            }

            if (!empty($errors)) {
                foreach (array_unique($errors) as $error) {
                    $this->addFlash('error', $error);
                }
                $this->refreshCaptcha($session);

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

            $this->refreshCaptcha($session);

            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/register.html.twig', [
            'form_data' => [],
        ]);
    }

    #[Route('/register/confirm-email/{token}', name: 'app_register_confirm_email', methods: ['GET'])]
    public function confirmEmail(string $token, UserRepository $userRepository, EntityManagerInterface $em): Response
    {
        $token = trim($token);
        if ($token === '') {
            $this->addFlash('error', 'Invalid confirmation link.');
            return $this->redirectToRoute('app_login');
        }

        $user = $userRepository->findOneBy(['emailVerificationToken' => $token]);
        if (!$user) {
            $this->addFlash('error', 'Confirmation link is invalid or expired.');
            return $this->redirectToRoute('app_login');
        }

        if ($user->isEmailVerified()) {
            $this->addFlash('success', 'Your email is already confirmed. You can sign in.');
            return $this->redirectToRoute('app_login');
        }

        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setEmailVerificationToken(null);
        $em->flush();

        $this->addFlash('success', 'Email confirmed successfully. You can sign in now.');
        return $this->redirectToRoute('app_login');
    }

    #[Route('/register/captcha/image', name: 'app_register_captcha_image', methods: ['GET'])]
    public function captchaImage(Request $request): Response
    {
        $session = $request->getSession();
        $captchaId = trim((string) $request->query->get('id', ''));
        $refresh = (bool) $request->query->get('refresh', false);

        if ($captchaId !== '') {
            $store = $this->getCaptchaStore($session);
            if (!isset($store[$captchaId])) {
                return new Response('Captcha not found.', 404);
            }

            $record = $store[$captchaId];
            if (($record['expires_at'] ?? 0) < time()) {
                unset($store[$captchaId]);
                $session->set(self::CAPTCHA_API_STORE_SESSION_KEY, $store);
                return new Response('Captcha expired.', 410);
            }

            $code = (string) $record['code'];
        } else {
            $code = (string) $session->get(self::CAPTCHA_CODE_SESSION_KEY, '');
            if ($refresh || $code === '') {
                $code = $this->refreshCaptcha($session);
            }
        }

        $svg = $this->buildCaptchaSvg($code);
        $response = new Response($svg);
        $response->headers->set('Content-Type', 'image/svg+xml; charset=UTF-8');
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    #[Route('/api/captcha/new', name: 'api_captcha_new', methods: ['POST'])]
    public function captchaNew(Request $request): JsonResponse
    {
        $session = $request->getSession();
        $store = $this->getCaptchaStore($session);
        $this->cleanupCaptchaStore($store);

        $captchaId = bin2hex(random_bytes(12));
        $code = $this->generateCaptchaCode();
        $expiresAt = time() + self::CAPTCHA_API_TTL_SECONDS;

        $store[$captchaId] = [
            'code' => $code,
            'expires_at' => $expiresAt,
        ];
        $session->set(self::CAPTCHA_API_STORE_SESSION_KEY, $store);

        return $this->json([
            'captcha_id' => $captchaId,
            'image_url' => $this->generateUrl('app_register_captcha_image', ['id' => $captchaId]),
            'expires_at' => $expiresAt,
            'ttl_seconds' => self::CAPTCHA_API_TTL_SECONDS,
        ]);
    }

    #[Route('/api/captcha/verify', name: 'api_captcha_verify', methods: ['POST'])]
    public function captchaVerifyApi(Request $request): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['success' => false, 'message' => 'Invalid JSON payload.'], 400);
        }

        $captchaId = trim((string) ($payload['captcha_id'] ?? ''));
        $codeInput = strtoupper(trim((string) ($payload['code'] ?? '')));
        if ($captchaId === '' || $codeInput === '') {
            return $this->json(['success' => false, 'message' => 'captcha_id and code are required.'], 400);
        }

        $session = $request->getSession();
        $store = $this->getCaptchaStore($session);
        $this->cleanupCaptchaStore($store);

        if (!isset($store[$captchaId])) {
            $session->set(self::CAPTCHA_API_STORE_SESSION_KEY, $store);
            return $this->json(['success' => false, 'message' => 'Captcha not found or expired.'], 404);
        }

        $record = $store[$captchaId];
        unset($store[$captchaId]); // one-time use
        $session->set(self::CAPTCHA_API_STORE_SESSION_KEY, $store);

        $isValid = hash_equals((string) $record['code'], $codeInput);
        return $this->json([
            'success' => $isValid,
            'message' => $isValid ? 'Captcha verified.' : 'Captcha code is incorrect.',
        ], $isValid ? 200 : 422);
    }


    #[Route('/api/email/validate', name: 'api_email_validate', methods: ['POST'])]
    public function validateEmailApi(Request $request, UserRepository $userRepository): JsonResponse
    {
        try {
            $payload = json_decode((string) $request->getContent(), true);
            if (!is_array($payload)) {
                return $this->json([
                    'success' => true,
                    'valid' => false,
                    'available' => false,
                    'message' => 'Invalid JSON payload.'
                ], 200);
            }

            $email = strtolower(trim((string) ($payload['email'] ?? '')));
            if ($email === '') {
                return $this->json([
                    'success' => true,
                    'valid' => false,
                    'available' => false,
                    'message' => 'Email is required.'
                ], 200);
            }

            if (strlen($email) > 180 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return $this->json([
                    'success' => true,
                    'valid' => false,
                    'available' => false,
                    'email' => $email,
                    'message' => 'Invalid email format.',
                ], 200);
            }

            $exists = $userRepository->findOneBy(['email' => $email]) !== null;

            return $this->json([
                'success' => true,
                'valid' => true,
                'available' => !$exists,
                'email' => $email,
                'message' => $exists ? 'Email is already used.' : 'Email is available.',
            ], 200);
        } catch (\Exception $e) {
            return $this->json([
                'success' => true,
                'valid' => true,
                'available' => true,
                'message' => 'Email validation temporarily unavailable. Please try again.'
            ], 200);
        }
    }
    private function refreshCaptcha(SessionInterface $session): string
    {
        $code = $this->generateCaptchaCode();

        $session->set(self::CAPTCHA_CODE_SESSION_KEY, $code);

        return $code;
    }

    private function generateCaptchaCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < 5; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }

    private function getCaptchaStore(SessionInterface $session): array
    {
        $store = $session->get(self::CAPTCHA_API_STORE_SESSION_KEY, []);
        return is_array($store) ? $store : [];
    }

    private function cleanupCaptchaStore(array &$store): void
    {
        $now = time();
        foreach ($store as $id => $record) {
            if (!is_array($record) || ($record['expires_at'] ?? 0) < $now) {
                unset($store[$id]);
            }
        }
    }

    private function buildCaptchaSvg(string $code): string
    {
        $width = 260;
        $height = 70;
        $chars = str_split($code);

        $text = '';
        foreach ($chars as $index => $char) {
            $x = 36 + ($index * 44);
            $y = 44 + random_int(-4, 4);
            $rotation = random_int(-25, 25);
            $size = random_int(26, 32);
            $text .= sprintf(
                '<text x="%d" y="%d" font-size="%d" transform="rotate(%d %d %d)" fill="#1f2937" font-family="monospace" font-weight="700">%s</text>',
                $x,
                $y,
                $size,
                $rotation,
                $x,
                $y,
                htmlspecialchars($char, ENT_QUOTES)
            );
        }

        $noiseLines = '';
        for ($i = 0; $i < 6; $i++) {
            $x1 = random_int(0, $width);
            $y1 = random_int(0, $height);
            $x2 = random_int(0, $width);
            $y2 = random_int(0, $height);
            $noiseLines .= sprintf(
                '<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="#9ca3af" stroke-width="1.5" opacity="0.85"/>',
                $x1,
                $y1,
                $x2,
                $y2
            );
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d"><rect width="100%%" height="100%%" fill="#f3f4f6"/>%s%s</svg>',
            $width,
            $height,
            $width,
            $height,
            $noiseLines,
            $text
        );
    }

    private function sendVerificationEmail(User $user, MailerInterface $mailer, UrlGeneratorInterface $urlGenerator): void
    {
        $token = $user->getEmailVerificationToken();
        if ($token === null || $token === '') {
            throw new \RuntimeException('Missing email verification token.');
        }

        $confirmUrl = $urlGenerator->generate(
            'app_register_confirm_email',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $fromAddress = (string) ($_ENV['MAILER_FROM_ADDRESS'] ?? 'no-reply@decides.local');

        $email = (new Email())
            ->from($fromAddress)
            ->to($user->getEmail())
            ->subject('Confirm your account')
            ->html($this->renderView('emails/confirm_email.html.twig', [
                'name' => (string) ($user->getNom() ?: 'user'),
                'confirm_url' => $confirmUrl,
            ]))
            ->text(
                "Hello,\n\n" .
                "Confirm your account using this link:\n" .
                $confirmUrl . "\n\n" .
                "If you did not create this account, you can ignore this email.\n"
            );

        $mailer->send($email);
    }
}



