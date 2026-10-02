<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\CurrentUser;
use App\Entity\ActionPlan;
use App\Entity\User;
use App\Repository\ActionPlanRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class CalendarController
{
    public function __construct(
        private CurrentUser $currentUser,
        private UserRepository $users,
        private ActionPlanRepository $actions,
        private EntityManagerInterface $entityManager,
        private string $appUrl,
    ) {
    }

    #[Route('/api/me/calendar', methods: ['GET'])]
    public function status(): JsonResponse
    {
        $user = $this->currentUser->get();

        return new JsonResponse([
            'enabled' => null !== $user->getCalendarTokenHash(),
            'createdAt' => $user->getCalendarTokenCreatedAt()?->format(DATE_ATOM),
        ]);
    }

    #[Route('/api/me/calendar', methods: ['POST'])]
    public function create(): JsonResponse
    {
        $user = $this->currentUser->get();
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $user->enableCalendarSubscription(hash('sha256', $token));
        $this->entityManager->flush();

        return new JsonResponse([
            'enabled' => true,
            'createdAt' => $user->getCalendarTokenCreatedAt()?->format(DATE_ATOM),
            'url' => rtrim($this->appUrl, '/').'/api/calendar/'.$token.'.ics',
        ], JsonResponse::HTTP_CREATED);
    }

    #[Route('/api/me/calendar', methods: ['DELETE'])]
    public function revoke(): Response
    {
        $this->currentUser->get()->disableCalendarSubscription();
        $this->entityManager->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/calendar/{token}.ics', methods: ['GET'], requirements: ['token' => '[A-Za-z0-9_-]{40,60}'])]
    public function feed(string $token): Response
    {
        $user = $this->users->findActiveByCalendarToken($token);
        if (!$user instanceof User) {
            return new Response('Calendrier introuvable.', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'private, no-store']);
        }

        $events = $this->actions->findForCalendar($user);
        $english = 'en' === $user->getLocale();
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//RiskPilot//Plans d action//FR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:RiskPilot - '.($english ? 'My actions' : 'Mes actions'),
            'X-PUBLISHED-TTL:PT1H',
            'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
        ];
        foreach ($events as $action) {
            $due = $action->getDueDate();
            $description = sprintf(
                $english ? "Priority: %s\nStatus: %s\nRisk: %s%s" : "Priorité : %s\nStatut : %s\nRisque : %s%s",
                $this->label($action->getPriority(), $english),
                $this->label($action->getStatus(), $english),
                $action->getRelatedRisk()?->getTitle() ?? ($english ? 'No linked risk' : 'Sans risque lié'),
                null === $action->getDescription() ? '' : "\n\n".$action->getDescription(),
            );
            array_push($lines,
                'BEGIN:VEVENT',
                'UID:action-'.$action->getId().'@'.substr(hash('sha256', rtrim($this->appUrl, '/')), 0, 24).'.riskpilot',
                'DTSTAMP:'.$action->getUpdatedAt()->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'),
                'CREATED:'.$action->getCreatedAt()->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'),
                'LAST-MODIFIED:'.$action->getUpdatedAt()->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'),
                'DTSTART;VALUE=DATE:'.$due->format('Ymd'),
                'DTEND;VALUE=DATE:'.$due->modify('+1 day')->format('Ymd'),
                'SUMMARY:'.$this->escape($action->getTitle()),
                'DESCRIPTION:'.$this->escape($description),
                'URL:'.rtrim($this->appUrl, '/').'/actions',
                'STATUS:CONFIRMED',
                'PRIORITY:'.$this->icalPriority($action->getPriority()),
                'END:VEVENT',
            );
        }
        $lines[] = 'END:VCALENDAR';

        return new Response(implode("\r\n", array_map($this->fold(...), $lines))."\r\n", Response::HTTP_OK, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="riskpilot-actions.ics"',
            'Cache-Control' => 'private, no-cache, no-store, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function escape(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';
        return str_replace(['\\', ';', ',', "\r\n", "\r", "\n"], ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'], $value);
    }

    private function label(string $value, bool $english): string
    {
        $labels = [
            'LOW' => ['Faible', 'Low'], 'MEDIUM' => ['Moyenne', 'Medium'],
            'HIGH' => ['Haute', 'High'], 'CRITICAL' => ['Critique', 'Critical'],
            'OPEN' => ['Ouverte', 'Open'], 'PLANNED' => ['Planifiée', 'Planned'],
            'IN_PROGRESS' => ['En cours', 'In progress'], 'BLOCKED' => ['Bloquée', 'Blocked'],
            'COMPLETED' => ['Terminée', 'Completed'], 'OVERDUE' => ['En retard', 'Overdue'],
        ];

        return $labels[$value][$english ? 1 : 0] ?? $value;
    }

    private function fold(string $line): string
    {
        $result = '';
        while (strlen($line) > 73) {
            $length = 73;
            while ($length > 0 && 0x80 === (ord($line[$length]) & 0xC0)) {
                --$length;
            }
            $result .= substr($line, 0, $length)."\r\n ";
            $line = substr($line, $length);
        }

        return $result.$line;
    }

    private function icalPriority(string $priority): int
    {
        return match ($priority) {
            'CRITICAL' => 1,
            'HIGH' => 3,
            'MEDIUM' => 5,
            default => 9,
        };
    }
}
