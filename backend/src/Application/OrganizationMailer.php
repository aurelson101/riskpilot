<?php

declare(strict_types=1);

namespace App\Application;

use App\Entity\EmailSettings;
use App\Repository\EmailSettingsRepository;
use App\Security\SecretCipher;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final readonly class OrganizationMailer
{
    public function __construct(private EmailSettingsRepository $settings, private SecretCipher $cipher, private MailerInterface $fallbackMailer)
    {
    }

    public function send(int $organizationId, string $recipient, string $subject, string $message): void
    {
        $settings = $this->settings->findOneBy(['organization' => $organizationId, 'enabled' => true]);
        if (!$settings instanceof EmailSettings) {
            $this->fallbackMailer->send((new Email())->from('notifications@riskpilot.local')->to($recipient)->subject($subject)->text($message));

            return;
        }
        $this->sendWithSettings($settings, $recipient, $subject, $message);
    }

    public function sendWithSettings(EmailSettings $settings, string $recipient, string $subject, string $message): void
    {
        $password = $settings->getEncryptedPassword();
        if (null === $password) {
            throw new \RuntimeException('Le mot de passe SMTP est manquant.');
        }
        $scheme = 'ssl' === $settings->getEncryption() ? 'smtps' : 'smtp';
        $query = 'tls' === $settings->getEncryption() ? '?require_tls=true' : '';
        $dsn = sprintf('%s://%s:%s@%s:%d%s', $scheme, rawurlencode($settings->getUsername()), rawurlencode($this->cipher->decrypt($password)), $settings->getHost(), $settings->getPort(), $query);
        $email = (new Email())->from(new Address($settings->getSenderEmail(), $settings->getSenderName()))->to($recipient)->subject($subject)->text($message);
        if (null !== $settings->getReplyTo()) {
            $email->replyTo($settings->getReplyTo());
        }
        (new Mailer(Transport::fromDsn($dsn)))->send($email);
    }
}
