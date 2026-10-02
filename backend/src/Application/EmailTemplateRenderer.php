<?php

declare(strict_types=1);

namespace App\Application;

final readonly class EmailTemplateRenderer
{
    public function __construct(private string $appUrl)
    {
    }

    /** @return array{subject: string, message: string} */
    public function passwordReset(string $locale, string $token): array
    {
        $url = $this->internalUrl('/reset-password?token='.rawurlencode($token));
        if (null === $url) {
            throw new \RuntimeException('Invalid application URL for password reset.');
        }

        if ('en' === $locale) {
            return [
                'subject' => 'Reset your RiskPilot password',
                'message' => "A request was made to reset your RiskPilot password.\n\n".$url."\n\nThis single-use link expires in 30 minutes. Ignore this message if you did not request a password reset.\n\nRiskPilot",
            ];
        }

        return [
            'subject' => 'Réinitialisation de votre mot de passe RiskPilot',
            'message' => "Une réinitialisation de votre mot de passe RiskPilot a été demandée.\n\n".$url."\n\nCe lien à usage unique expire dans 30 minutes. Ignorez ce message si vous n’êtes pas à l’origine de la demande.\n\nRiskPilot",
        ];
    }

    /** @return array{subject: string, message: string} */
    public function connectionTest(string $locale): array
    {
        return 'en' === $locale
            ? ['subject' => 'RiskPilot email configuration test', 'message' => "This is a test email sent by RiskPilot to verify your email configuration.\n\nIf you received this message, delivery to this address succeeded.\n\nRiskPilot"]
            : ['subject' => 'Test de messagerie RiskPilot', 'message' => "Cet email de test a été envoyé par RiskPilot pour vérifier votre configuration de messagerie.\n\nSi vous recevez ce message, la livraison à cette adresse a réussi.\n\nRiskPilot"];
    }

    public function notificationMessage(string $message, ?string $link, string $locale): string
    {
        $url = null === $link ? null : $this->internalUrl($link);
        if (null === $url) {
            return $message;
        }

        return $message."\n\n".('en' === $locale ? 'View in RiskPilot: ' : 'Consulter dans RiskPilot : ').$url;
    }

    private function internalUrl(string $path): ?string
    {
        $base = rtrim($this->appUrl, '/');
        $parts = parse_url($base);
        if (false === filter_var($base, FILTER_VALIDATE_URL) || !is_array($parts)
            || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !preg_match('~^/[a-zA-Z0-9]~', $path)
            || preg_match('/[\\\\\x00-\x20\x7F]/', rawurldecode($path))) {
            return null;
        }

        return $base.$path;
    }
}
