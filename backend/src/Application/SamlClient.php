<?php

declare(strict_types=1);

namespace App\Application;

use App\Entity\PlatformIntegration;
use OneLogin\Saml2\AuthnRequest;
use OneLogin\Saml2\Constants;
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
     * @return array{subject: string, claims: array<string, mixed>, sessionIndex: ?string}
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
        } catch (\RuntimeException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new \RuntimeException('Réponse SAML invalide : '.$error->getMessage(), 0, $error);
        }
        if (!is_string($subject) || '' === $subject) {
            throw new \RuntimeException('Réponse SAML sans NameID.');
        }

        return ['subject' => $subject, 'claims' => $attributes, 'sessionIndex' => $sessionIndex];
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

    private function settings(PlatformIntegration $integration, string $appUrl, bool $spOnly = false): Settings
    {
        $configuration = $integration->getConfiguration();

        return new Settings([
            'strict' => true,
            'debug' => false,
            'sp' => [
                'entityId' => $this->spEntityId($integration, $appUrl),
                'assertionConsumerService' => ['url' => $this->acsUrl($integration, $appUrl), 'binding' => Constants::BINDING_HTTP_POST],
                'NameIDFormat' => Constants::NAMEID_UNSPECIFIED,
            ],
            'idp' => [
                'entityId' => (string) $configuration['issuer'],
                'singleSignOnService' => ['url' => (string) $configuration['ssoUrl'], 'binding' => Constants::BINDING_HTTP_REDIRECT],
                'x509cert' => (string) $configuration['idpCertificate'],
            ],
            'security' => [
                // Au moins la réponse ou l'assertion doit être signée ; une réponse sans signature est toujours refusée.
                'wantAssertionsSigned' => false, 'wantMessagesSigned' => false,
                'wantNameId' => true, 'requestedAuthnContext' => false,
                'rejectUnsolicitedResponsesWithInResponseTo' => true,
                'destinationStrictlyMatches' => true, 'relaxDestinationValidation' => false,
                'wantXMLValidation' => true, 'authnRequestsSigned' => false,
                'signatureAlgorithm' => XMLSecurityKey::RSA_SHA256, 'digestAlgorithm' => XMLSecurityDSig::SHA256,
            ],
        ], $spOnly);
    }

    /** La bibliothèque compare le destinataire à l'URL de la requête : on la fixe à l'URL publique configurée (reverse proxy, tests). */
    private function pinRequestUrl(string $appUrl, PlatformIntegration $integration): void
    {
        $parts = parse_url($appUrl);
        $scheme = is_array($parts) ? ($parts['scheme'] ?? 'https') : 'https';
        Utils::setSelfProtocol($scheme);
        Utils::setSelfHost((string) ($parts['host'] ?? 'localhost'));
        Utils::setSelfPort((int) ($parts['port'] ?? ('https' === $scheme ? 443 : 80)));
        $_SERVER['REQUEST_URI'] = '/api/auth/sso/saml/'.$integration->getId().'/acs';
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
