<?php

declare(strict_types=1);

namespace App\Tests\Support;

use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

/** Fournisseur d'identité SAML de test : génère une paire de clés et signe des réponses avec xmlseclibs. */
final class SamlTestIdp
{
    public const ENTITY_ID = 'https://idp.example.test/realms/test';
    public readonly string $certificate;
    private readonly string $privateKey;

    public function __construct()
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $csr = openssl_csr_new(['commonName' => 'idp.example.test'], $key);
        $x509 = openssl_csr_sign($csr, null, $key, 2);
        openssl_x509_export($x509, $certificate);
        $this->privateKey = $private;
        $this->certificate = $certificate;
    }

    /** Extrait l'identifiant de la demande d'authentification contenue dans l'URL de redirection. */
    public static function requestId(string $redirectUrl): string
    {
        parse_str((string) parse_url($redirectUrl, PHP_URL_QUERY), $query);
        $xml = (string) gzinflate((string) base64_decode((string) $query['SAMLRequest'], true));
        preg_match('/ID="([^"]+)"/', $xml, $match);

        return $match[1];
    }

    /**
     * @param array{subject?: string, attributes?: array<string, list<string>>, audience?: string, destination?: string, inResponseTo?: ?string, notOnOrAfter?: string, sign?: string, algorithm?: string, tamper?: bool, issuer?: string} $options
     *                                                                                                                                                                                                                                              sign : 'assertion' (défaut), 'response', 'both' ou 'none'
     */
    public function response(string $acsUrl, string $spEntityId, string $requestId, array $options = []): string
    {
        $subject = $options['subject'] ?? 'user-1';
        $now = new \DateTimeImmutable('-1 minute');
        $later = $options['notOnOrAfter'] ?? (new \DateTimeImmutable('+5 minutes'))->format('Y-m-d\TH:i:s\Z');
        $instant = $now->format('Y-m-d\TH:i:s\Z');
        $issuer = $options['issuer'] ?? self::ENTITY_ID;
        $inResponseTo = array_key_exists('inResponseTo', $options) ? $options['inResponseTo'] : $requestId;
        $irt = null === $inResponseTo ? '' : ' InResponseTo="'.$inResponseTo.'"';
        $attributes = '';
        foreach ($options['attributes'] ?? [] as $name => $values) {
            $attributes .= '<saml:Attribute Name="'.htmlspecialchars($name, ENT_XML1).'">'.implode('', array_map(static fn (string $v): string => '<saml:AttributeValue xsi:type="xs:string">'.htmlspecialchars($v, ENT_XML1).'</saml:AttributeValue>', $values)).'</saml:Attribute>';
        }
        $assertionId = '_a'.bin2hex(random_bytes(8));
        $responseId = '_r'.bin2hex(random_bytes(8));
        $xml = '<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xs="http://www.w3.org/2001/XMLSchema" ID="'.$responseId.'" Version="2.0" IssueInstant="'.$instant.'" Destination="'.($options['destination'] ?? $acsUrl).'"'.$irt.'>'
            .'<saml:Issuer>'.$issuer.'</saml:Issuer><samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status>'
            .'<saml:Assertion ID="'.$assertionId.'" Version="2.0" IssueInstant="'.$instant.'"><saml:Issuer>'.$issuer.'</saml:Issuer>'
            .'<saml:Subject><saml:NameID Format="urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified">'.htmlspecialchars($subject, ENT_XML1).'</saml:NameID>'
            .'<saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer"><saml:SubjectConfirmationData NotOnOrAfter="'.$later.'" Recipient="'.$acsUrl.'"'.$irt.'/></saml:SubjectConfirmation></saml:Subject>'
            .'<saml:Conditions NotBefore="'.$instant.'" NotOnOrAfter="'.$later.'"><saml:AudienceRestriction><saml:Audience>'.($options['audience'] ?? $spEntityId).'</saml:Audience></saml:AudienceRestriction></saml:Conditions>'
            .'<saml:AuthnStatement AuthnInstant="'.$instant.'" SessionIndex="_s1"><saml:AuthnContext><saml:AuthnContextClassRef>urn:oasis:names:tc:SAML:2.0:ac:classes:Password</saml:AuthnContextClassRef></saml:AuthnContext></saml:AuthnStatement>'
            .('' === $attributes ? '' : '<saml:AttributeStatement>'.$attributes.'</saml:AttributeStatement>')
            .'</saml:Assertion></samlp:Response>';
        $document = new \DOMDocument();
        $document->loadXML($xml);
        $sign = $options['sign'] ?? 'assertion';
        $algorithm = $options['algorithm'] ?? 'sha256';
        if (in_array($sign, ['assertion', 'both'], true)) {
            $assertion = $document->getElementsByTagNameNS('urn:oasis:names:tc:SAML:2.0:assertion', 'Assertion')->item(0);
            $this->sign($document, $assertion, $assertion->getElementsByTagNameNS('urn:oasis:names:tc:SAML:2.0:assertion', 'Subject')->item(0), $algorithm);
        }
        if (in_array($sign, ['response', 'both'], true)) {
            $root = $document->documentElement;
            $this->sign($document, $root, $root->getElementsByTagNameNS('urn:oasis:names:tc:SAML:2.0:protocol', 'Status')->item(0), $algorithm);
        }
        $result = $document->saveXML();
        if (true === ($options['tamper'] ?? false)) {
            // Modifie le NameID après signature : la signature ne correspond plus au contenu.
            $result = str_replace('>'.$subject.'<', '>admin-sub<', $result);
        }

        return base64_encode($result);
    }

    private function sign(\DOMDocument $document, \DOMElement $target, \DOMNode $before, string $algorithm): void
    {
        $signature = new XMLSecurityDSig();
        $signature->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
        $digest = 'sha1' === $algorithm ? XMLSecurityDSig::SHA1 : XMLSecurityDSig::SHA256;
        $signature->addReference($target, $digest, ['http://www.w3.org/2000/09/xmldsig#enveloped-signature', XMLSecurityDSig::EXC_C14N], ['id_name' => 'ID', 'overwrite' => false]);
        $key = new XMLSecurityKey('sha1' === $algorithm ? XMLSecurityKey::RSA_SHA1 : XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $key->loadKey($this->privateKey, false);
        $signature->sign($key);
        $signature->add509Cert($this->certificate, true);
        $signature->insertSignature($target, $before);
    }
}
