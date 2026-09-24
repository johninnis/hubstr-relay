<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Integration\Presentation\Http;

use Amp\Http\HttpStatus;
use Innis\Hubstr\Core\Domain\ValueObject\SiteInfo;
use Innis\Hubstr\Core\Infrastructure\Http\StaticSiteInfoProvider;
use Innis\Hubstr\Core\Infrastructure\Templating\LatteTemplateRenderer;
use Innis\Hubstr\Core\Presentation\Http\ErrorPageResponder;
use Innis\Hubstr\Core\Presentation\Http\LandingPageResponder;
use PHPUnit\Framework\TestCase;

use function Amp\ByteStream\buffer;

final class ShippedTemplatesTest extends TestCase
{
    private string $cacheDirectory;
    private LatteTemplateRenderer $renderer;
    private StaticSiteInfoProvider $site;

    protected function setUp(): void
    {
        $this->cacheDirectory = sys_get_temp_dir().'/relay-templates-'.bin2hex(random_bytes(6));
        $this->renderer = LatteTemplateRenderer::create(dirname(__DIR__, 4).'/templates', $this->cacheDirectory);
        $this->site = new StaticSiteInfoProvider(new SiteInfo('Test Relay', '1.2.3', 'npub1owner'));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDirectory.'/*') ?: [] as $path) {
            unlink($path);
        }
        if (is_dir($this->cacheDirectory)) {
            rmdir($this->cacheDirectory);
        }
    }

    public function testTheErrorPageRendersWithWhatTheResponderPasses(): void
    {
        $response = new ErrorPageResponder('error.latte', $this->renderer, $this->site)->respond(HttpStatus::NOT_FOUND, 'Not Found');

        self::assertStringContainsString('<p>404 Not Found</p>', buffer($response->getBody()));
    }

    public function testTheLandingPageRendersWithWhatTheResponderPasses(): void
    {
        $response = new LandingPageResponder('index.latte', $this->renderer, $this->site)->respond();

        self::assertStringContainsString('<p class="pubkey">npub1owner</p>', buffer($response->getBody()));
    }
}
