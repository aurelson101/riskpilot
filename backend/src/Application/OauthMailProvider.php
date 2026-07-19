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
    public function __construct(private SecretCipher $cipher, private EntityManagerInterface $entityManager, private HttpClientInterface $httpClient)
    {
    }

    public function authorizationUrl(EmailSettings $settings, string $redirectUri, string $state): string
    {
        $clientId = $settings->getOauthClientId() ?? throw new \RuntimeException('Client OAuth manquant.');
        if ('GOOGLE_WORKSPACE' === $settings->getProvider()) {
            return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query(['client_id' => $clientId, 'redirect_uri' => $redirectUri, 'response_type' => 'code', 'scope' => 'openid email https://www.googleapis.com/auth/gmail.send', 'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true', 'state' => $state], '', '&', PHP_QUERY_RFC3986);
        }
        $tenant = rawurlencode($settings->getOauthTenant() ?? 'common');

        return 'https://login.microsoftonline.com/'.$tenant.'/oauth2/v2.0/authorize?'.http_build_query(['client_id' => $clientId, 'redirect_uri' => $redirectUri, 'response_type' => 'code', 'response_mode' => 'query', 'scope' => 'openid email offline_access User.Read Mail.Send', 'state' => $state], '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array{access_token:string, refresh_token?:string, expires_in:int} */
    public function exchangeCode(EmailSettings $settings, string $redirectUri, string $code): array
    {
        return $this->tokenRequest($settings, ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri]);
    }

    public function connectedEmail(EmailSettings $settings, string $accessToken): string
    {
        $url = 'GOOGLE_WORKSPACE' === $settings->getProvider() ? 'https://openidconnect.googleapis.com/v1/userinfo' : 'https://graph.microsoft.com/v1.0/me?$select=mail,userPrincipalName';
        $data = $this->httpClient->request('GET', $url, ['auth_bearer' => $accessToken])->toArray();
        $email = 'GOOGLE_WORKSPACE' === $settings->getProvider() ? $data['email'] ?? null : $data['mail'] ?? $data['userPrincipalName'] ?? null;
        if (!is_string($email) || false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Adresse du compte OAuth introuvable.');
        }

        return $email;
    }

    public function send(EmailSettings $settings, string $recipient, string $subject, string $message): void
    {
        $accessToken = $this->validAccessToken($settings);
        if ('GOOGLE_WORKSPACE' === $settings->getProvider()) {
            $email = (new Email())->from(new Address($settings->getSenderEmail(), $settings->getSenderName()))->to($recipient)->subject($subject)->text($message);
            if (null !== $settings->getReplyTo()) {
                $email->replyTo($settings->getReplyTo());
            }
            $raw = rtrim(strtr(base64_encode($email->toString()), '+/', '-_'), '=');
            $this->httpClient->request('POST', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send', ['auth_bearer' => $accessToken, 'json' => ['raw' => $raw]])->getContent();

            return;
        }
        $payload = ['message' => ['subject' => $subject, 'body' => ['contentType' => 'Text', 'content' => $message], 'toRecipients' => [['emailAddress' => ['address' => $recipient]]]], 'saveToSentItems' => true];
        if (null !== $settings->getReplyTo()) {
            $payload['message']['replyTo'] = [['emailAddress' => ['address' => $settings->getReplyTo()]]];
        }
        $this->httpClient->request('POST', 'https://graph.microsoft.com/v1.0/me/sendMail', ['auth_bearer' => $accessToken, 'json' => $payload])->getContent();
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
        $settings->connectOauth($this->cipher->encrypt($tokens['access_token']), isset($tokens['refresh_token']) ? $this->cipher->encrypt($tokens['refresh_token']) : null, new \DateTimeImmutable('+'.max(60, $tokens['expires_in']).' seconds'), $settings->getConnectedEmail() ?? $settings->getSenderEmail());
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
        $secret = $settings->getEncryptedOauthClientSecret();
        if (null === $secret || null === $settings->getOauthClientId()) {
            throw new \RuntimeException('Identifiants OAuth incomplets.');
        }
        $parameters['client_id'] = $settings->getOauthClientId();
        $parameters['client_secret'] = $this->cipher->decrypt($secret);
        if ('MICROSOFT_365' === $settings->getProvider()) {
            $parameters['scope'] = 'openid email offline_access User.Read Mail.Send';
        }
        $url = 'GOOGLE_WORKSPACE' === $settings->getProvider() ? 'https://oauth2.googleapis.com/token' : 'https://login.microsoftonline.com/'.rawurlencode($settings->getOauthTenant() ?? 'common').'/oauth2/v2.0/token';
        $data = $this->httpClient->request('POST', $url, ['body' => $parameters])->toArray();
        if (!isset($data['access_token']) || !is_string($data['access_token'])) {
            throw new \RuntimeException('Jeton OAuth absent.');
        }

        return ['access_token' => $data['access_token'], 'expires_in' => (int) ($data['expires_in'] ?? 3600), ...(isset($data['refresh_token']) && is_string($data['refresh_token']) ? ['refresh_token' => $data['refresh_token']] : [])];
    }
}
