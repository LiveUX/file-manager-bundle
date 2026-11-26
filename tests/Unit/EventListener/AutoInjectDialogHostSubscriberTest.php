<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\EventListener;

use LiveUX\FileManagerBundle\EventListener\AutoInjectDialogHostSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class AutoInjectDialogHostSubscriberTest extends TestCase
{
    public function testInjectsDialogHostBeforeClosingBodyOnHtmlResponse(): void
    {
        $subscriber = new AutoInjectDialogHostSubscriber($this->createTwig(), true, 'default', 2500);
        $request = Request::create('/admin/products');
        $request->setLocale('tr');
        $response = new Response('<html><body><h1>Hello</h1></body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);

        $subscriber->onKernelResponse($this->createEvent($request, $response));

        $content = (string) $response->getContent();
        self::assertStringContainsString('liveux--file-manager-bundle--dialog', $content);
        self::assertStringContainsString('profile=default|locale=tr|z=2500', $content);
        self::assertStringEndsWith('</body></html>', $content);
    }

    public function testDoesNotDuplicateIfManualDialogHostAlreadyPresent(): void
    {
        $subscriber = new AutoInjectDialogHostSubscriber($this->createTwig(), true);
        $request = Request::create('/admin/products');
        $html = '<html><body><div data-controller="liveux--file-manager-bundle--dialog"></div></body></html>';
        $response = new Response($html, 200, ['Content-Type' => 'text/html']);

        $subscriber->onKernelResponse($this->createEvent($request, $response));

        self::assertSame(1, substr_count((string) $response->getContent(), 'liveux--file-manager-bundle--dialog'));
    }

    public function testSkipsNonHtmlAjaxTurboFrameRedirectsAndDisabledState(): void
    {
        $subscriber = new AutoInjectDialogHostSubscriber($this->createTwig(), true);

        // JSON response
        $jsonRes = new JsonResponse(['ok' => true]);
        $subscriber->onKernelResponse($this->createEvent(Request::create('/api'), $jsonRes));
        self::assertStringNotContainsString('liveux--file-manager-bundle--dialog', (string) $jsonRes->getContent());

        // AJAX request
        $ajaxReq = Request::create('/partial', 'GET', [], [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $ajaxRes = new Response('<html><body>Partial</body></html>', 200, ['Content-Type' => 'text/html']);
        $subscriber->onKernelResponse($this->createEvent($ajaxReq, $ajaxRes));
        self::assertStringNotContainsString('liveux--file-manager-bundle--dialog', (string) $ajaxRes->getContent());

        // Turbo Frame request
        $turboReq = Request::create('/frame', 'GET', [], [], [], ['HTTP_TURBO_FRAME' => 'modal_frame']);
        $turboRes = new Response('<html><body>Frame</body></html>', 200, ['Content-Type' => 'text/html']);
        $subscriber->onKernelResponse($this->createEvent($turboReq, $turboRes));
        self::assertStringNotContainsString('liveux--file-manager-bundle--dialog', (string) $turboRes->getContent());

        // Redirect response
        $redirectRes = new RedirectResponse('/login');
        $subscriber->onKernelResponse($this->createEvent(Request::create('/secret'), $redirectRes));
        self::assertStringNotContainsString('liveux--file-manager-bundle--dialog', (string) $redirectRes->getContent());

        // Disabled via config
        $disabled = new AutoInjectDialogHostSubscriber($this->createTwig(), false);
        $htmlRes = new Response('<html><body>Hi</body></html>', 200, ['Content-Type' => 'text/html']);
        $disabled->onKernelResponse($this->createEvent(Request::create('/'), $htmlRes));
        self::assertStringNotContainsString('liveux--file-manager-bundle--dialog', (string) $htmlRes->getContent());
    }

    private function createTwig(): Environment
    {
        return new Environment(new ArrayLoader([
            '@LiveUXFileManager/components/DialogHost.html.twig' => '<div id="liveux-fm-dialog-host" data-controller="liveux--file-manager-bundle--dialog">profile={{ profile }}|locale={{ locale }}|z={{ zIndex }}</div>',
        ]));
    }

    private function createEvent(Request $request, Response $response): ResponseEvent
    {
        $kernel = $this->createStub(HttpKernelInterface::class);

        return new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
    }
}
