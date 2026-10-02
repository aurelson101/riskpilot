<?php

declare(strict_types=1);

namespace App\Application;

use App\Entity\EmailSettings;
use App\Security\SecretCipher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class OauthMailProvider
{
    private const HTTP_OPTIONS = ['timeout' => 15, 'max_duration' => 30, 'max_redirects' => 0];

    public function __construct(private SecretCipher $cipher, private EntityManagerInterface $entityManager, private HttpClientInterface $httpClient)
    {
    }

    public function authorizationUrl(EmailSettings $settings, string $redirectUri, string $state): string
    {
        $this->assertProvider($settings);
        $clientId = $settings->getOauthClientId() ?? throw new \RuntimeException('Client OAuth manquant.');
        $codeChallenge = $this->codeChallenge($state);
        if ('GOOGLE_WORKSPACE' === $settings->getProvider()) {
            return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query(['client_id' => $clientId, 'redirect_uri' => $redirectUri, 'response_type' => 'code', 'scope' => 'openid email https://www.googleapis.com/auth/gmail.send', 'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true', 'state' => $state, 'code_challenge' => $codeChallenge, 'code_challenge_method' => 'S256'], '', '&', PHP_QUERY_RFC3986);
        }
        $tenant = rawurlencode($settings->getOauthTenant() ?? 'organizations');

        return 'https://login.microsoftonline.com/'.$tenant.'/oauth2/v2.0/authorize?'.http_build_query(['client_id' => $clientId, 'redirect_uri' => $redirectUri, 'response_type' => 'code', 'response_mode' => 'query', 'scope' => 'openid email offline_access User.Read Mail.Send', 'state' => $state, 'code_challenge' => $codeChallenge, 'code_challenge_method' => 'S256'], '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array{access_token:string, refresh_token?:string, expires_in:int} */
    public function exchangeCode(EmailSettings $settings, string $redirectUri, string $code, string $state): array
    {
        if ('' === $code || '' === $state) {
            throw new \RuntimeException('Réponse OAuth incomplète.');
        }

        return $this->tokenRequest($settings, ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri, 'code_verifier' => $this->codeVerifier($state)]);
    }

    public function connectedEmail(EmailSettings $settings, string $accessToken): string
    {
        $this->assertProvider($settings);
        $url = 'GOOGLE_WORKSPACE' === $settings->getProvider() ? 'https://openidconnect.googleapis.com/v1/userinfo' : 'https://graph.microsoft.com/v1.0/me?$select=mail,userPrincipalName';
        $data = $this->httpClient->request('GET', $url, [...self::HTTP_OPTIONS, 'auth_bearer' => $accessToken])->toArray();
        if ('GOOGLE_WORKSPACE' === $settings->getProvider() && array_key_exists('email_verified', $data) && true !== $data['email_verified']) {
            throw new \RuntimeException('Adresse du compte OAuth non vérifiée.');
        }
        $email = 'GOOGLE_WORKSPACE' === $settings->getProvider() ? $data['email'] ?? null : (is_string($data['mail'] ?? null) && '' !== trim($data['mail']) ? $data['mail'] : $data['userPrincipalName'] ?? null);
        if (!is_string($email) || false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Adresse du compte OAuth introuvable.');
        }

        return $email;
    }

    public function send(EmailSettings $settings, string $recipient, string $subject, string $message, ?string $html = null): void
    {
        $this->assertProvider($settings);
        if (false === filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Destinataire invalide.');
        }
        if ('' === trim($subject) || mb_strlen($subject) > 255 || preg_match('/[\x00-\x1F\x7F]/', $subject)) {
            throw new \InvalidArgumentException('Objet du message invalide.');
        }
        $accessToken = $this->validAccessToken($settings);
        if ('GOOGLE_WORKSPACE' === $settings->getProvider()) {
            $email = (new Email())->from(new Address($settings->getSenderEmail(), $settings->getSenderName()))->to($recipient)->subject($subject)->text($message);
            if (null !== $html) {
                $email->html($html);
            }
            if (null !== $settings->getReplyTo()) {
                $email->replyTo($settings->getReplyTo());
            }
            $raw = rtrim(strtr(base64_encode($email->toString()), '+/', '-_'), '=');
            $this->httpClient->request('POST', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send', [...self::HTTP_OPTIONS, 'auth_bearer' => $accessToken, 'json' => ['raw' => $raw]])->getContent();

            return;
        }
        $payload = ['message' => [
            'subject' => $subject,
            'body' => ['contentType' => null === $html ? 'Text' : 'HTML', 'content' => $html ?? $message],
            'from' => ['emailAddress' => ['address' => $settings->getSenderEmail(), 'name' => $settings->getSenderName()]],
            'toRecipients' => [['emailAddress' => ['address' => $recipient]]],
        ], 'saveToSentItems' => true];
        if (null !== $settings->getReplyTo()) {
            $payload['message']['replyTo'] = [['emailAddress' => ['address' => $settings->getReplyTo()]]];
        }
        $this->httpClient->request('POST', 'https://graph.microsoft.com/v1.0/me/sendMail', [...self::HTTP_OPTIONS, 'auth_bearer' => $accessToken, 'json' => $payload])->getContent();
    }

    private function validAccessToken(EmailSettings $settings): string
    {
        $encrypted = $settings->getEncryptedAccessToken();
        if (null !== $encrypted && null !== $settings->getAccessTokenExpiresAt() && $settings->getAccessTokenExpiresAt() > new \DateTimeImmutable('+60 seconds')) {
            return $this->cipher->decrypt($encrypted);
        }
        $refresh = $settings->getEncryptedRefreshToken();
        if (null === $refresh) {
            throw new \RuntimeException('Reconnexion OAuth nécessaire.');
        }
        $tokens = $this->tokenRequest($settings, ['grant_type' => 'refresh_token', 'refresh_token' => $this->cipher->decrypt($refresh)]);
        $settings->connectOauth($this->cipher->encrypt($tokens['access_token']), isset($tokens['refresh_token']) ? $this->cipher->encrypt($tokens['refresh_token']) : null, new \DateTimeImmutable('+'.$tokens['expires_in'].' seconds'), $settings->getConnectedEmail() ?? $settings->getSenderEmail());
        $this->entityManager->flush();

        return $tokens['access_token'];
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return array{access_token: string, refresh_token?: string, expires_in: int}
     */
    private function tokenRequest(EmailSettings $settings, array $parameters): array
    {
        $this->assertProvider($settings);
        $secret = $settings->getEncryptedOauthClientSecret();
        if (null === $secret || null === $settings->getOauthClientId()) {
            throw new \RuntimeException('Identifiants OAuth incomplets.');
        }
        $parameters['client_id'] = $settings->getOauthClientId();
        $parameters['client_secret'] = $this->cipher->decrypt($secret);
        if ('MICROSOFT_365' === $settings->getProvider()) {
            $parameters['scope'] = 'openid email offline_access User.Read Mail.Send';
        }
        $url = 'GOOGLE_WORKSPACE' === $settings->getProvider() ? 'https://oauth2.googleapis.com/token' : 'https://login.microsoftonline.com/'.rawurlencode($settings->getOauthTenant() ?? 'organizations').'/oauth2/v2.0/token';
        $data = $this->httpClient->request('POST', $url, [...self::HTTP_OPTIONS, 'body' => $parameters])->toArray();
        if (!isset($data['access_token']) || !is_string($data['access_token']) || '' === trim($data['access_token'])) {
            throw new \RuntimeException('Jeton OAuth absent.');
        }
        $expires = $data['expires_in'] ?? 3600;
        if (!is_int($expires) || $expires < 1 || $expires > 86400) {
            throw new \RuntimeException('Durée du jeton OAuth invalide.');
        }
        if (array_key_exists('refresh_token', $data) && (!is_string($data['refresh_token']) || '' === trim($data['refresh_token']))) {
            throw new \RuntimeException('Jeton de renouvellement OAuth invalide.');
        }

        return ['access_token' => $data['access_token'], 'expires_in' => $expires, ...(isset($data['refresh_token']) ? ['refresh_token' => $data['refresh_token']] : [])];
    }

    private function assertProvider(EmailSettings $settings): void
    {
        if (!in_array($settings->getProvider(), ['GOOGLE_WORKSPACE', 'MICROSOFT_365'], true)) {
            throw new \InvalidArgumentException('Fournisseur OAuth de messagerie invalide.');
        }
    }

    private function codeVerifier(string $state): string
    {
        return $this->cipher->deriveUrlSafe('oauth-mail-pkce', $state);
    }

    private function codeChallenge(string $state): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $this->codeVerifier($state), true)), '+/', '-_'), '=');
    }
}
