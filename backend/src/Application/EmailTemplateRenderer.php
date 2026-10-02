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

    public function html(string $subject, string $message): string
    {
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $parts = preg_split('~(https?://[^\s<>"\']+)~u', $message, -1, PREG_SPLIT_DELIM_CAPTURE);
        $body = '';
        $base = rtrim($this->appUrl, '/');
        foreach (false === $parts ? [$message] : $parts as $part) {
            $text = $escape($part);
            $path = str_starts_with($part, $base.'/') ? substr($part, strlen($base)) : '';
            $body .= '' !== $path && $this->internalUrl($path) === $part
                ? '<a href="'.$text.'" style="color:#155eef;text-decoration:underline;overflow-wrap:anywhere;word-break:break-word;">'.$text.'</a>'
                : $text;
        }

        return '<!doctype html><html><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.$escape($subject).'</title></head>'
            .'<body style="margin:0;padding:16px;background:#f3f5f8;font-family:Arial,sans-serif;color:#182230;">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border:1px solid #d0d5dd;border-radius:8px;">'
            .'<tr><td style="padding:20px 24px;background:#155eef;color:#ffffff;font-size:20px;font-weight:bold;">RiskPilot</td></tr>'
            .'<tr><td style="padding:24px;">'
            .'<h1 style="margin:0 0 20px;font-size:22px;line-height:1.4;overflow-wrap:anywhere;">'.$escape($subject).'</h1>'
            .'<div style="font-size:16px;line-height:1.6;overflow-wrap:anywhere;word-break:break-word;">'.nl2br($body, false).'</div>'
            .'</td></tr></table></td></tr></table></body></html>';
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
