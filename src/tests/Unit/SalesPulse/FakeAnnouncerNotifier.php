<?php

declare(strict_types=1);

namespace Tests\Unit\SalesPulse;

use App\Domain\SalesPulse\Services\SalesPulseNotifier;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\RequestInterface;
use SergiX44\Nutgram\Testing\FakeNutgram;

/**
 * FakeAnnouncerNotifier — a test seam wrapping a real SalesPulseNotifier over a
 * FakeNutgram. The announcer/jobs post through SalesPulseNotifier::sendToChat,
 * which issues a sendMessage on the underlying (faked) Nutgram; here we scan that
 * bot's Guzzle request history for the sent HTML (urldecoded), exactly like the
 * Slice 3 SalesPulseBotTestSupport harness.
 *
 * This keeps the assertions on the REAL notifier code path (no method mock) while
 * staying fully offline.
 */
final class FakeAnnouncerNotifier
{
    private FakeNutgram $bot;

    private SalesPulseNotifier $notifier;

    public function __construct()
    {
        $this->bot = FakeNutgram::instance();
        $this->notifier = new SalesPulseNotifier($this->bot);
    }

    public function asNotifier(): SalesPulseNotifier
    {
        return $this->notifier;
    }

    /**
     * @return list<string> urldecoded bodies of every sendMessage request.
     */
    public function sent(): array
    {
        $out = [];
        foreach ($this->bot->getRequestHistory() as $entry) {
            /** @var RequestInterface $request */
            $request = $entry['request'];
            if (! str_ends_with($request->getUri()->getPath(), 'sendMessage')) {
                continue;
            }
            $out[] = urldecode((string) $request->getBody());
        }

        return $out;
    }

    public function count(): int
    {
        return count($this->sent());
    }

    public function assertSent(string $needle): void
    {
        foreach ($this->sent() as $body) {
            if (str_contains($body, $needle)) {
                Assert::assertTrue(true);

                return;
            }
        }

        Assert::fail("No sendMessage containing \"{$needle}\" was sent.");
    }

    public function assertNothingSent(): void
    {
        Assert::assertSame(0, $this->count(), 'Expected no outbound messages.');
    }
}
