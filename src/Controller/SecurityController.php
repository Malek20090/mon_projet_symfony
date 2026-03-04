<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\FacePlusPlusService;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    private const FACE_ID_AUTH_SESSION_KEY = 'faceid_auth';
    private const GOOGLE_OAUTH_STATE_SESSION_KEY = 'google_oauth_state';
    private const FACEBOOK_OAUTH_STATE_SESSION_KEY = 'facebook_oauth_state';

    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/login/form', name: 'app_login_form')]
    public function loginForm(): Response
    {
        return $this->redirectToRoute('app_login');
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        // Handled by Symfony security firewall.
    }

    #[Route('/connect/google', name: 'app_connect_google', methods: ['GET'])]
    public function connectGoogle(Request $request, UrlGeneratorInterface $urlGenerator): Response
    {
        $clientId = $this->getEnvValue('GOOGLE_OAUTH_CLIENT_ID');
        $clientSecret = $this->getEnvValue('GOOGLE_OAUTH_CLIENT_SECRET');
        if ($clientId === '' || $clientSecret === '') {
            $this->addFlash('error', 'Google sign-in is not configured yet. Set GOOGLE_OAUTH_CLIENT_ID and GOOGLE_OAUTH_CLIENT_SECRET.');

            return $this->redirectToRoute('app_login_form');
        }

        $state = bin2hex(random_bytes(24));
        $request->getSession()->set(self::GOOGLE_OAUTH_STATE_SESSION_KEY, $state);

        $params = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $urlGenerator->generate('app_connect_google_check', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
        ]);

        return $this->redirect('https://accounts.google.com/o/oauth2/v2/auth?' . $params);
    }

    #[Route('/connect/google/check', name: 'app_connect_google_check', methods: ['GET'])]
    public function connectGoogleCheck(
        Request $request,
        HttpClientInterface $httpClient,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        TokenStorageInterface $tokenStorage,
        UrlGeneratorInterface $urlGenerator
    ): Response {
        $state = (string) $request->query->get('state', '');
        $sessionState = (string) $request->getSession()->get(self::GOOGLE_OAUTH_STATE_SESSION_KEY, '');
        $request->getSession()->remove(self::GOOGLE_OAUTH_STATE_SESSION_KEY);

        if ($state === '' || $sessionState === '' || !hash_equals($sessionState, $state)) {
            $this->addFlash('error', 'Invalid Google authentication state.');

            return $this->redirectToRoute('app_login_form');
        }

        if ($request->query->has('error')) {
            $this->addFlash('error', 'Google sign-in was cancelled.');

            return $this->redirectToRoute('app_login_form');
        }

        $code = trim((string) $request->query->get('code', ''));
        if ($code === '') {
            $this->addFlash('error', 'Missing Google authorization code.');

            return $this->redirectToRoute('app_login_form');
        }

        $clientId = $this->getEnvValue('GOOGLE_OAUTH_CLIENT_ID');
        $clientSecret = $this->getEnvValue('GOOGLE_OAUTH_CLIENT_SECRET');
        if ($clientId === '' || $clientSecret === '') {
            $this->addFlash('error', 'Google sign-in is not configured yet. Set GOOGLE_OAUTH_CLIENT_ID and GOOGLE_OAUTH_CLIENT_SECRET.');

            return $this->redirectToRoute('app_login_form');
        }

        try {
            $redirectUri = $urlGenerator->generate('app_connect_google_check', [], UrlGeneratorInterface::ABSOLUTE_URL);
            $tokenResponse = $httpClient->request('POST', 'https://oauth2.googleapis.com/token', [
                'body' => [
                    'code' => $code,
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'redirect_uri' => $redirectUri,
                    'grant_type' => 'authorization_code',
                ],
            ]);
            $tokenData = $tokenResponse->toArray(false);
            $accessToken = trim((string) ($tokenData['access_token'] ?? ''));
            if ($accessToken === '') {
                throw new \RuntimeException('Missing access token.');
            }

            $profileResponse = $httpClient->request('GET', 'https://openidconnect.googleapis.com/v1/userinfo', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);
            $profile = $profileResponse->toArray(false);
        } catch (\Throwable) {
            $this->addFlash('error', 'Google sign-in failed. Please try again.');

            return $this->redirectToRoute('app_login_form');
        }

        $email = strtolower(trim((string) ($profile['email'] ?? '')));
        $emailVerified = (bool) ($profile['email_verified'] ?? false);
        if ($email === '' || !$emailVerified) {
            $this->addFlash('error', 'Google account email is not verified.');

            return $this->redirectToRoute('app_login_form');
        }

        $user = $userRepository->findOneBy(['email' => $email]);
        if (!$user) {
            $user = new User();
            $user->setEmail($email);
            $displayName = trim((string) ($profile['name'] ?? $profile['given_name'] ?? ''));
            if ($displayName === '') {
                $displayName = strstr($email, '@', true) ?: $email;
            }
            $user->setNom($displayName);
            $user->setRoles(['ROLE_ETUDIANT']);
            $user->setSoldeTotal(0);
            $user->setPassword($passwordHasher->hashPassword($user, bin2hex(random_bytes(32))));
            $user->setEmailVerified(true);
            $user->setEmailVerificationToken(null);
            $user->markEmailVerifiedAt(new \DateTimeImmutable());

            $entityManager->persist($user);
        } elseif (!$user->isEmailVerified()) {
            $user->setEmailVerified(true);
            $user->setEmailVerificationToken(null);
            $user->markEmailVerifiedAt(new \DateTimeImmutable());
        }

        $entityManager->flush();

        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $tokenStorage->setToken($token);
        $request->getSession()->set('_security_main', serialize($token));

        return $this->redirect($this->redirectRouteByRole($urlGenerator, $user->getRoles()));
    }

    #[Route('/connect/facebook', name: 'app_connect_facebook', methods: ['GET'])]
    public function connectFacebook(Request $request, UrlGeneratorInterface $urlGenerator): Response
    {
        $clientId = $this->getEnvValue('FACEBOOK_OAUTH_CLIENT_ID');
        $clientSecret = $this->getEnvValue('FACEBOOK_OAUTH_CLIENT_SECRET');
        if ($clientId === '' || $clientSecret === '') {
            $this->addFlash('error', 'Facebook sign-in is not configured yet. Set FACEBOOK_OAUTH_CLIENT_ID and FACEBOOK_OAUTH_CLIENT_SECRET.');

            return $this->redirectToRoute('app_login_form');
        }

        $state = bin2hex(random_bytes(24));
        $request->getSession()->set(self::FACEBOOK_OAUTH_STATE_SESSION_KEY, $state);

        $params = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $urlGenerator->generate('app_connect_facebook_check', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'state' => $state,
            // Keep only public_profile to avoid "Invalid Scopes: email" on apps
            // that are not yet configured/reviewed for email permission.
            'scope' => 'public_profile',
        ]);

        return $this->redirect('https://www.facebook.com/v23.0/dialog/oauth?' . $params);
    }

    #[Route('/connect/facebook/check', name: 'app_connect_facebook_check', methods: ['GET'])]
    public function connectFacebookCheck(
        Request $request,
        HttpClientInterface $httpClient,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        TokenStorageInterface $tokenStorage,
        UrlGeneratorInterface $urlGenerator
    ): Response {
        $state = (string) $request->query->get('state', '');
        $sessionState = (string) $request->getSession()->get(self::FACEBOOK_OAUTH_STATE_SESSION_KEY, '');
        $request->getSession()->remove(self::FACEBOOK_OAUTH_STATE_SESSION_KEY);

        if ($state === '' || $sessionState === '' || !hash_equals($sessionState, $state)) {
            $this->addFlash('error', 'Invalid Facebook authentication state.');

            return $this->redirectToRoute('app_login_form');
        }

        if ($request->query->has('error')) {
            $this->addFlash('error', 'Facebook sign-in was cancelled.');

            return $this->redirectToRoute('app_login_form');
        }

        $code = trim((string) $request->query->get('code', ''));
        if ($code === '') {
            $this->addFlash('error', 'Missing Facebook authorization code.');

            return $this->redirectToRoute('app_login_form');
        }

        $clientId = $this->getEnvValue('FACEBOOK_OAUTH_CLIENT_ID');
        $clientSecret = $this->getEnvValue('FACEBOOK_OAUTH_CLIENT_SECRET');
        if ($clientId === '' || $clientSecret === '') {
            $this->addFlash('error', 'Facebook sign-in is not configured yet. Set FACEBOOK_OAUTH_CLIENT_ID and FACEBOOK_OAUTH_CLIENT_SECRET.');

            return $this->redirectToRoute('app_login_form');
        }

        try {
            $redirectUri = $urlGenerator->generate('app_connect_facebook_check', [], UrlGeneratorInterface::ABSOLUTE_URL);
            $tokenResponse = $httpClient->request('GET', 'https://graph.facebook.com/v23.0/oauth/access_token', [
                'query' => [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'redirect_uri' => $redirectUri,
                    'code' => $code,
                ],
            ]);
            $tokenData = $tokenResponse->toArray(false);
            $accessToken = trim((string) ($tokenData['access_token'] ?? ''));
            if ($accessToken === '') {
                throw new \RuntimeException('Missing access token.');
            }

            $profileResponse = $httpClient->request('GET', 'https://graph.facebook.com/me', [
                'query' => [
                    'fields' => 'id,name,email',
                    'access_token' => $accessToken,
                ],
            ]);
            $profile = $profileResponse->toArray(false);
        } catch (\Throwable) {
            $this->addFlash('error', 'Facebook sign-in failed. Please try again.');

            return $this->redirectToRoute('app_login_form');
        }

        $email = strtolower(trim((string) ($profile['email'] ?? '')));
        if ($email === '') {
            $facebookId = trim((string) ($profile['id'] ?? ''));
            if ($facebookId === '') {
                $this->addFlash('error', 'Facebook sign-in failed: missing account identifier.');

                return $this->redirectToRoute('app_login_form');
            }
            // Fallback identity when Facebook email permission is unavailable.
            $email = sprintf('facebook_%s@local.oauth', $facebookId);
            $this->addFlash('success', 'Signed in with Facebook profile (email permission not available).');
        }

        $user = $userRepository->findOneBy(['email' => $email]);
        if (!$user) {
            $user = new User();
            $user->setEmail($email);
            $displayName = trim((string) ($profile['name'] ?? ''));
            if ($displayName === '') {
                $displayName = strstr($email, '@', true) ?: $email;
            }
            $user->setNom($displayName);
            $user->setRoles(['ROLE_ETUDIANT']);
            $user->setSoldeTotal(0);
            $user->setPassword($passwordHasher->hashPassword($user, bin2hex(random_bytes(32))));
            $user->setEmailVerified(true);
            $user->setEmailVerificationToken(null);
            $user->markEmailVerifiedAt(new \DateTimeImmutable());

            $entityManager->persist($user);
        } elseif (!$user->isEmailVerified()) {
            $user->setEmailVerified(true);
            $user->setEmailVerificationToken(null);
            $user->markEmailVerifiedAt(new \DateTimeImmutable());
        }

        $entityManager->flush();

        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $tokenStorage->setToken($token);
        $request->getSession()->set('_security_main', serialize($token));

        return $this->redirect($this->redirectRouteByRole($urlGenerator, $user->getRoles()));
    }

    #[Route('/auth/faceid/options', name: 'app_faceid_options', methods: ['POST'])]
    public function faceIdOptions(Request $request, UserRepository $userRepository): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['success' => false, 'message' => 'Invalid JSON payload.'], 400);
        }

        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        if ($email === '') {
            return $this->json(['success' => false, 'message' => 'Email is required.'], 400);
        }

        $user = $userRepository->findOneBy(['email' => $email]);
        if (!$user || !$user->isFaceIdEnabled() || !$user->getFaceIdCredentialId()) {
            return $this->json(['success' => false, 'message' => 'Face ID is not enabled for this account.'], 404);
        }

        $challenge = $this->base64UrlEncode(random_bytes(32));
        $request->getSession()->set(self::FACE_ID_AUTH_SESSION_KEY, [
            'email' => $email,
            'challenge' => $challenge,
            'expires_at' => time() + 120,
        ]);

        return $this->json([
            'success' => true,
            'publicKey' => array_filter([
                'challenge' => $challenge,
                'timeout' => 60000,
                'rpId' => $this->isIpAddress($request->getHost()) ? null : $request->getHost(),
                'userVerification' => 'required',
                'allowCredentials' => [[
                    'type' => 'public-key',
                    'id' => $user->getFaceIdCredentialId(),
                    'transports' => ['internal', 'hybrid', 'usb', 'nfc', 'ble'],
                ]],
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }

    #[Route('/auth/faceid/verify', name: 'app_faceid_verify', methods: ['POST'])]
    public function faceIdVerify(
        Request $request,
        UserRepository $userRepository,
        TokenStorageInterface $tokenStorage,
        UrlGeneratorInterface $urlGenerator
    ): JsonResponse {
        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['success' => false, 'message' => 'Invalid JSON payload.'], 400);
        }

        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $credentialId = trim((string) ($payload['credential_id'] ?? ''));
        $clientDataJsonB64 = trim((string) ($payload['client_data_json'] ?? ''));

        if ($email === '' || $credentialId === '' || $clientDataJsonB64 === '') {
            return $this->json(['success' => false, 'message' => 'Missing Face ID parameters.'], 400);
        }

        $sessionData = $request->getSession()->get(self::FACE_ID_AUTH_SESSION_KEY, []);
        if (
            !is_array($sessionData) ||
            ($sessionData['email'] ?? null) !== $email ||
            !isset($sessionData['challenge'], $sessionData['expires_at']) ||
            (int) $sessionData['expires_at'] < time()
        ) {
            return $this->json(['success' => false, 'message' => 'Face ID challenge expired.'], 401);
        }

        $user = $userRepository->findOneBy(['email' => $email]);
        if (!$user || !$user->isFaceIdEnabled() || !$user->getFaceIdCredentialId()) {
            return $this->json(['success' => false, 'message' => 'Face ID is not enabled for this account.'], 404);
        }

        if (!hash_equals($user->getFaceIdCredentialId(), $credentialId)) {
            return $this->json(['success' => false, 'message' => 'Invalid Face ID credential.'], 401);
        }

        $decodedClientData = $this->base64UrlDecode($clientDataJsonB64);
        if ($decodedClientData === false) {
            return $this->json(['success' => false, 'message' => 'Invalid client data.'], 400);
        }

        $clientData = json_decode($decodedClientData, true);
        if (!is_array($clientData)) {
            return $this->json(['success' => false, 'message' => 'Malformed client data.'], 400);
        }

        if (($clientData['type'] ?? '') !== 'webauthn.get') {
            return $this->json(['success' => false, 'message' => 'Invalid Face ID type.'], 401);
        }

        if (($clientData['challenge'] ?? '') !== (string) $sessionData['challenge']) {
            return $this->json(['success' => false, 'message' => 'Face ID challenge mismatch.'], 401);
        }

        $request->getSession()->remove(self::FACE_ID_AUTH_SESSION_KEY);

        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $tokenStorage->setToken($token);
        $request->getSession()->set('_security_main', serialize($token));

        return $this->json([
            'success' => true,
            'redirect' => $this->redirectRouteByRole($urlGenerator, $user->getRoles()),
        ]);
    }

    #[Route('/auth/facepp/enroll-token', name: 'app_facepp_enroll_token', methods: ['POST'])]
    public function facePlusEnrollToken(Request $request, FacePlusPlusService $facePlusPlusService): JsonResponse
    {
        $image = $request->files->get('image');
        if (!$image instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            return $this->json(['success' => false, 'message' => 'Image is required.'], 400);
        }

        $result = $facePlusPlusService->detectFaceToken($image);
        if (!$result['success']) {
            return $this->json([
                'success' => false,
                'message' => $result['message'] ?? 'Face++ enrollment failed.',
            ], 422);
        }

        return $this->json([
            'success' => true,
            'face_token' => $result['token'],
            'message' => 'Face++ token generated successfully.',
        ]);
    }

    #[Route('/auth/facepp/verify', name: 'app_facepp_verify', methods: ['POST'])]
    public function facePlusVerify(
        Request $request,
        UserRepository $userRepository,
        FacePlusPlusService $facePlusPlusService,
        TokenStorageInterface $tokenStorage,
        UrlGeneratorInterface $urlGenerator
    ): JsonResponse {
        $email = strtolower(trim((string) $request->request->get('email', '')));
        $image = $request->files->get('image');

        if ($email === '' || !$image instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            return $this->json(['success' => false, 'message' => 'Email and image are required.'], 400);
        }

        $user = $userRepository->findOneBy(['email' => $email]);
        if (!$user || !$user->isFacePlusEnabled() || !$user->getFacePlusToken()) {
            return $this->json(['success' => false, 'message' => 'Face++ is not enabled for this account.'], 404);
        }

        $result = $facePlusPlusService->compareWithStoredToken($image, $user->getFacePlusToken());
        if (!$result['success']) {
            return $this->json([
                'success' => false,
                'message' => $result['message'] ?? 'Face++ verification failed.',
            ], 422);
        }

        $confidence = (float) $result['confidence'];
        $minConfidence = $facePlusPlusService->getMinConfidence();
        if ($confidence < $minConfidence) {
            return $this->json([
                'success' => false,
                'message' => sprintf('Face++ confidence too low (%.2f < %.2f).', $confidence, $minConfidence),
            ], 401);
        }

        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $tokenStorage->setToken($token);
        $request->getSession()->set('_security_main', serialize($token));

        return $this->json([
            'success' => true,
            'confidence' => $confidence,
            'redirect' => $this->redirectRouteByRole($urlGenerator, $user->getRoles()),
        ]);
    }

    #[Route('/health/facepp', name: 'app_health_facepp', methods: ['GET'])]
    public function facePlusHealth(FacePlusPlusService $facePlusPlusService): JsonResponse
    {
        if (!$facePlusPlusService->isConfigured()) {
            return $this->json([
                'ok' => false,
                'configured' => false,
                'message' => 'FACEPP_API_KEY / FACEPP_API_SECRET / FACEPP_API_BASE_URL is missing.',
            ], 503);
        }

        return $this->json([
            'ok' => true,
            'configured' => true,
            'min_confidence' => $facePlusPlusService->getMinConfidence(),
            'message' => 'Face++ configuration is loaded.',
        ]);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string|false
    {
        $padded = strtr($data, '-_', '+/');
        $padding = strlen($padded) % 4;
        if ($padding > 0) {
            $padded .= str_repeat('=', 4 - $padding);
        }

        return base64_decode($padded, true);
    }

    private function redirectRouteByRole(UrlGeneratorInterface $urlGenerator, array $roles): string
    {
        if (in_array('ROLE_ADMIN', $roles, true)) {
            return $urlGenerator->generate('admin_dashboard');
        }
        if (in_array('ROLE_SALARY', $roles, true)) {
            return $urlGenerator->generate('salary_dashboard');
        }
        if (in_array('ROLE_ETUDIANT', $roles, true)) {
            return $urlGenerator->generate('student_cours_index');
        }

        return $urlGenerator->generate('app_home');
    }

    private function isIpAddress(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    private function getEnvValue(string $key): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return trim(is_string($value) ? $value : '');
    }
}
