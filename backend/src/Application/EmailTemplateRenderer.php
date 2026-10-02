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

    /** @param list<string|int|float|\DateTimeInterface> $parameters
     *  @return array{subject: string, message: string}
     */
    public function businessNotification(string $type, string $locale, array $parameters): array
    {
        $templates = [
            'ACTION_ASSIGNED' => ['Nouvelle action affectée', 'New action assigned', 'L’action « %s » vous est affectée avec une échéance au %s.', 'Action “%s” is assigned to you, due on %s.'],
            'ACTION_OWNER_CHANGED' => ['Action réaffectée', 'Action reassigned', 'L’action « %s » vous est affectée avec une échéance au %s.', 'Action “%s” has been reassigned to you, due on %s.'],
            'ACTION_OVERDUE' => ['Action en retard', 'Overdue action', 'L’action « %s » est attendue pour le %s.', 'Action “%s” was due on %s.'],
            'ACTION_DUE_SOON' => ['Échéance proche', 'Action due soon', 'L’action « %s » est attendue pour le %s.', 'Action “%s” is due on %s.'],
            'RISK_ACCEPTANCE_EXPIRED' => ['Acceptation de risque expirée', 'Risk acceptance expired', 'Le risque « %s » doit être réévalué.', 'Risk “%s” must be reassessed.'],
            'RISK_REVIEW_REMINDER' => ['Revue de risque à finaliser', 'Risk review reminder', '« %s » doit être revu avant le %s dans la campagne « %s ».', '“%s” must be reviewed by %s in campaign “%s”.'],
            'OPERATIONAL_REMINDER' => ['Échéance à traiter', 'Operational deadline reminder', '« %s » arrive à échéance le %s.', '“%s” is due on %s.'],
            'CRITICAL_RISK_CREATED' => ['Risque critique créé', 'Critical risk created', 'Le scénario « %s » a un score brut de %d.', 'Scenario “%s” has a gross risk score of %d.'],
            'RISK_REVIEW_REQUIRED' => ['Risque à valider', 'Risk validation required', 'Le scénario « %s » est en attente de validation.', 'Scenario “%s” is awaiting validation.'],
            'COMPLIANCE_ASSESSMENT_COMPLETED' => ['Évaluation de conformité terminée', 'Compliance assessment completed', 'L’évaluation %s %s est terminée avec un score de %.2f%%.', 'Assessment %s %s is complete with a score of %.2f%%.'],
            'RISK_ACCEPTANCE_REQUIRED' => ['Acceptation de risque à décider', 'Risk acceptance decision required', 'Le risque « %s » nécessite une décision formelle.', 'Risk “%s” requires a formal decision.'],
            'RISK_ACCEPTANCE_DECIDED' => ['Décision d’acceptation de risque', 'Risk acceptance decision', 'La demande pour « %s » est %s.', 'The request for “%s” has been %s.'],
            'RISK_REVIEW_CAMPAIGN' => ['Campagne de revue affectée', 'Risk review campaign assigned', 'La campagne « %s » contient %d risque(s) à revoir.', 'Campaign “%s” contains %d risk(s) to review.'],
        ];
        $template = $templates[$type] ?? throw new \InvalidArgumentException('Unknown business notification type.');
        $english = 'en' === $locale;
        if ('RISK_ACCEPTANCE_DECIDED' === $type) {
            $parameters[1] = 'APPROVED' === ($parameters[1] ?? null) ? ($english ? 'approved' : 'approuvée') : ($english ? 'rejected' : 'refusée');
        }
        $parameters = array_map(static fn ($value) => $value instanceof \DateTimeInterface ? $value->format($english ? 'Y-m-d' : 'd/m/Y') : $value, $parameters);

        return ['subject' => $template[$english ? 1 : 0], 'message' => vsprintf($template[$english ? 3 : 2], $parameters)];
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
