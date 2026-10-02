<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\RequestLoggingSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class RequestLoggingSubscriberTest extends TestCase
{
    public function testAuthenticatedResponseIsPrivateAndUntrustedTraceIsReplaced(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with('http_request', self::callback(function (array $context): bool {
            self::assertSame('/api/search', $context['path']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $context['request_id']);

            return !array_key_exists('authorization', $context);
        }));
        $request = Request::create('/api/search?q=secret');
        $request->headers->set('Authorization', 'Bearer test');
        $request->headers->set('X-Request-ID', "untrusted\ttrace");
        $response = new Response();
        (new RequestLoggingSubscriber($logger))->onResponse(new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertContains('Authorization', $response->getVary());
    }

    public function testValidRequestIdAndPublicCachePolicyArePreserved(): void
    {
        $request = Request::create('/api/health');
        $request->headers->set('X-Request-ID', 'abcdefgh-1234');
        $response = new Response('', 200, ['Cache-Control' => 'public, max-age=10']);
        (new RequestLoggingSubscriber($this->createMock(LoggerInterface::class)))->onResponse(new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response));
        self::assertSame('abcdefgh-1234', $response->headers->get('X-Request-ID'));
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
    }
}
