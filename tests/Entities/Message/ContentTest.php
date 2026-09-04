<?php

declare(strict_types=1);

namespace EuroSms\Tests\Entities\Message;

use EuroSms\Entities\Message\Message;
use EuroSms\Entities\Message\MessageInterface;
use EuroSms\Exception\MessageException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The text of a message. A send with nothing to say is answered by the gateway with
 * EMPTY_MESSAGE, and by then the message has travelled through the whole library to find out
 * something that was knowable the moment it was written. It is answered for on the message
 * instead, where the caller still knows which message it is.
 */
#[CoversClass(Message::class)]
final class ContentTest extends TestCase
{
    /**
     * A text is kept exactly as it was handed over. Nothing is trimmed off it: the signature of
     * chapter 9.2.2 is composed over the text as it goes on the wire, so a text quietly changed
     * here is a text the gateway would find the signature no longer matches.
     */
    public function testATextIsKeptExactlyAsItWasHandedOver(): void
    {
        $message = new Message;
        $message->setContent('  Dakujeme za objednavku.  ');

        self::assertSame('  Dakujeme za objednavku.  ', $message->getContent());
    }

    /**
     * The mistake this is here for: a text built out of a template that filled in nothing leaves
     * the empty string behind, and the send goes all the way to the gateway to be told so.
     */
    public function testAnEmptyTextIsRefused(): void
    {
        $message = new Message;

        $this->expectException(MessageException::class);
        $this->expectExceptionCode(MessageInterface::ERROR_CONTENT_IS_EMPTY);

        $message->setContent('');
    }

    /**
     * The same mistake wearing whatever the template left behind. None of these says anything,
     * and every one of them is charged for as a message.
     * @param string $content
     */
    #[DataProvider('provideTextsMadeOfNothingButWhitespace')]
    public function testATextMadeOfNothingButWhitespaceIsRefused(string $content): void
    {
        $message = new Message;

        $this->expectException(MessageException::class);
        $this->expectExceptionCode(MessageInterface::ERROR_CONTENT_IS_EMPTY);

        $message->setContent($content);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideTextsMadeOfNothingButWhitespace(): array
    {
        return [
            'a space' => [' '],
            'several spaces' => ['     '],
            'a tab' => ["\t"],
            'a newline' => ["\n"],
            'a carriage return and a newline' => ["\r\n"],
            'all of them at once' => [" \t\r\n "]
        ];
    }

    /**
     * The one text that reads as nothing and is not nothing. A message whose whole text is "0"
     * is a message with a text, and the request is signed over it — dropping it would send the
     * gateway a body its own signature no longer matches.
     */
    public function testATextOfZeroIsAText(): void
    {
        $message = new Message;
        $message->setContent('0');

        self::assertSame('0', $message->getContent());
    }

    /**
     * A text that says something around the whitespace is a text, wherever the whitespace sits.
     */
    public function testATextCarryingWhitespaceAroundSomethingIsAText(): void
    {
        $message = new Message;
        $message->setContent("\n Dakujeme. \n");

        self::assertSame("\n Dakujeme. \n", $message->getContent());
    }
}
