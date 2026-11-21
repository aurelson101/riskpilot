<?php

declare(strict_types=1);

namespace App\Application;

use App\Entity\PlatformIntegration;
use App\Security\SecretCipher;
use OneLogin\Saml2\AuthnRequest;
use OneLogin\Saml2\Constants;
use OneLogin\Saml2\LogoutRequest;
use OneLogin\Saml2\LogoutResponse;
use OneLogin\Saml2\Response;
use OneLogin\Saml2\Settings;
use OneLogin\Saml2\Utils;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

/**
 * Connexion SSO SAML 2.0 (profil « Web Browser SSO », liaisons HTTP-Redirect puis HTTP-POST).
 *
 * La vérification des signatures XML est déléguée à onelogin/php-saml (xmlseclibs) en mode strict : réponse non sollicitée
 * refusée (InResponseTo obligatoire), destinataire, audience et fenêtres de validité contrôlés, signature obligatoire sur la réponse
 * ou sur l'assertion. S'y ajoutent ici le refus des algorithmes SHA-1 et le rattachement de chaque réponse à une demande émise
 * par RiskPilot, consommée une seule fois (anti-rejeu).
 */
final readonly class SamlClient
{
    public function __construct(private SecretCipher $cipher)
    {
    }

    /** @return array{url: string, requestId: string} */
    public function authorizationUrl(PlatformIntegration $integration, string $appUrl, string $relayState): array
    {
        $settings = $this->settings($integration, $appUrl);
        $request = new AuthnRequest($settings);
        $query = http_build_query(['SAMLRequest' => $request->getRequest(), 'RelayState' => $relayState], '', '&', PHP_QUERY_RFC3986);
        $idp = $settings->getIdPData();
        $url = $idp['singleSignOnService']['url'];

        return ['url' => $url.(str_contains($url, '?') ? '&' : '?').$query, 'requestId' => $request->getId()];
    }

    /**
     * @return array{subject: string, claims: array<string, mixed>, sessionIndex: ?string, nameIdFormat: ?string}
     *
     * @throws \RuntimeException lorsque la réponse n'est pas authentique ou ne répond pas à la demande
     */
    public function authenticate(PlatformIntegration $integration, string $appUrl, string $samlResponse, string $requestId): array
    {
        $settings = $this->settings($integration, $appUrl);
        $this->pinRequestUrl($appUrl, $integration);
        try {
            $response = new Response($settings, $samlResponse);
            if (!$response->isValid($requestId)) {
                throw new \RuntimeException('Réponse SAML refusée : '.$response->getError(false));
            }
            $this->assertStrongAlgorithms($response);
            $subject = $response->getNameId();
            $attributes = $response->getAttributes();
            $sessionIndex = $response->getSessionIndex();
            $nameIdFormat = $response->getNameIdFormat();
        } catch (\RuntimeException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new \RuntimeException('Réponse SAML invalide : '.$error->getMessage(), 0, $error);
        }
        if (!is_string($subject) || '' === $subject) {
            throw new \RuntimeException('Réponse SAML sans NameID.');
        }

        return ['subject' => $subject, 'claims' => $attributes, 'sessionIndex' => $sessionIndex, 'nameIdFormat' => $nameIdFormat];
    }

    /** Adresse de déconnexion unique initiée par RiskPilot (LogoutRequest, liaison HTTP-Redirect), ou null si le fournisseur n'en annonce pas. */
    public function logoutUrl(PlatformIntegration $integration, string $appUrl, string $nameId, ?string $sessionIndex, ?string $nameIdFormat, string $relayState): ?string
    {
        $sloUrl = $integration->getConfiguration()['sloUrl'] ?? null;
        if (!is_string($sloUrl) || '' === $sloUrl) {
            return null;
        }
        $request = new LogoutRequest($this->settings($integration, $appUrl), null, $nameId, $sessionIndex, $nameIdFormat);
        $query = http_build_query(['SAMLRequest' => $request->getRequest(), 'RelayState' => $relayState], '', '&', PHP_QUERY_RFC3986);

        return $sloUrl.(str_contains($sloUrl, '?') ? '&' : '?').$query;
    }

    /**
     * Traite une déconnexion initiée par le fournisseur : la requête doit porter une signature valide du certificat configuré.
     *
     * @param array<string, string> $query paramètres de la requête GET (SAMLRequest, RelayState, SigAlg, Signature)
     *
     * @return array{nameId: string, responseUrl: ?string}
     */
    public function processLogoutRequest(PlatformIntegration $integration, string $appUrl, array $query, string $rawQuery): array
    {
        $settings = $this->settings($integration, $appUrl, false, true);
        $this->pinRequestUrl($appUrl, $integration, 'sls');
        $_GET = $query;
        $_SERVER['QUERY_STRING'] = $rawQuery;
        $request = new LogoutRequest($settings, $query['SAMLRequest']);
        if (!$request->isValid(true)) {
            throw new \RuntimeException('Demande de déconnexion SAML refusée.');
        }
        $xml = (string) gzinflate((string) base64_decode($query['SAMLRequest'], true));
        $nameId = LogoutRequest::getNameId($xml);
        $sloUrl = $integration->getConfiguration()['sloUrl'] ?? null;
        $responseUrl = null;
        if (is_string($sloUrl) && '' !== $sloUrl) {
            $response = new LogoutResponse($settings);
            $response->build(LogoutRequest::getID($xml));
            $responseQuery = http_build_query(['SAMLResponse' => $response->getResponse(), 'RelayState' => rtrim($appUrl, '/').'/login'], '', '&', PHP_QUERY_RFC3986);
            $responseUrl = $sloUrl.(str_contains($sloUrl, '?') ? '&' : '?').$responseQuery;
        }

        return ['nameId' => $nameId, 'responseUrl' => $responseUrl];
    }

    /** Métadonnées du fournisseur de service, à importer chez le fournisseur d'identité. */
    public function metadata(PlatformIntegration $integration, string $appUrl): string
    {
        $settings = $this->settings($integration, $appUrl, true);
        $xml = $settings->getSPMetadata();
        $errors = $settings->validateMetadata($xml);
        if ([] !== $errors) {
            throw new \RuntimeException('Métadonnées SAML invalides.');
        }

        return $xml;
    }

    public function acsUrl(PlatformIntegration $integration, string $appUrl): string
    {
        return rtrim($appUrl, '/').'/api/auth/sso/saml/'.$integration->getId().'/acs';
    }

    public function spEntityId(PlatformIntegration $integration, string $appUrl): string
    {
        $configured = $integration->getConfiguration()['spEntityId'] ?? null;

        return is_string($configured) && '' !== $configured ? $configured : rtrim($appUrl, '/').'/api/auth/sso/saml/'.$integration->getId().'/metadata';
    }

    private function settings(PlatformIntegration $integration, string $appUrl, bool $spOnly = false, bool $signedMessages = false): Settings
    {
        $configuration = $integration->getConfiguration();
        $encrypt = true === ($configuration['encryptAssertions'] ?? false) && is_string($configuration['spCertificate'] ?? null) && null !== $integration->getEncryptedCredential();
        $slo = is_string($configuration['sloUrl'] ?? null) && '' !== $configuration['sloUrl'];

        return new Settings([
            'strict' => true,
            'debug' => false,
            'sp' => [
                'entityId' => $this->spEntityId($integration, $appUrl),
                'assertionConsumerService' => ['url' => $this->acsUrl($integration, $appUrl), 'binding' => Constants::BINDING_HTTP_POST],
                'NameIDFormat' => Constants::NAMEID_UNSPECIFIED,
                ...($slo ? ['singleLogoutService' => ['url' => rtrim($appUrl, '/').'/api/auth/sso/saml/'.$integration->getId().'/sls', 'binding' => Constants::BINDING_HTTP_REDIRECT]] : []),
                ...($encrypt ? ['x509cert' => (string) $configuration['spCertificate'], 'privateKey' => $this->cipher->decrypt((string) $integration->getEncryptedCredential())] : []),
            ],
            'idp' => [
                'entityId' => (string) $configuration['issuer'],
                'singleSignOnService' => ['url' => (string) $configuration['ssoUrl'], 'binding' => Constants::BINDING_HTTP_REDIRECT],
                'x509cert' => (string) $configuration['idpCertificate'],
                ...($slo ? ['singleLogoutService' => ['url' => (string) $configuration['sloUrl'], 'binding' => Constants::BINDING_HTTP_REDIRECT]] : []),
            ],
            'security' => [
                // Au moins la réponse ou l'assertion doit être signée ; une réponse sans signature est toujours refusée.
                'wantAssertionsSigned' => false, 'wantMessagesSigned' => $signedMessages, 'wantAssertionsEncrypted' => $encrypt,
                'wantNameId' => true, 'requestedAuthnContext' => false,
                'rejectUnsolicitedResponsesWithInResponseTo' => true,
                'destinationStrictlyMatches' => true, 'relaxDestinationValidation' => false,
                'wantXMLValidation' => true, 'authnRequestsSigned' => false,
                'signatureAlgorithm' => XMLSecurityKey::RSA_SHA256, 'digestAlgorithm' => XMLSecurityDSig::SHA256,
            ],
        ], $spOnly);
    }

    /** La bibliothèque compare le destinataire à l'URL de la requête : on la fixe à l'URL publique configurée (reverse proxy, tests). */
    private function pinRequestUrl(string $appUrl, PlatformIntegration $integration, string $endpoint = 'acs'): void
    {
        $parts = parse_url($appUrl);
        $scheme = is_array($parts) ? ($parts['scheme'] ?? 'https') : 'https';
        Utils::setSelfProtocol($scheme);
        Utils::setSelfHost((string) ($parts['host'] ?? 'localhost'));
        Utils::setSelfPort((int) ($parts['port'] ?? ('https' === $scheme ? 443 : 80)));
        $_SERVER['REQUEST_URI'] = '/api/auth/sso/saml/'.$integration->getId().'/'.$endpoint;
    }

    private function assertStrongAlgorithms(Response $response): void
    {
        $xpath = new \DOMXPath($response->getXMLDocument());
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        foreach ($xpath->query('//ds:SignatureMethod/@Algorithm | //ds:DigestMethod/@Algorithm') ?: [] as $attribute) {
            if (str_contains(strtolower($attribute->nodeValue ?? ''), 'sha1')) {
                throw new \RuntimeException('Réponse SAML refusée : algorithme de signature SHA-1 non accepté.');
            }
        }
    }
}
