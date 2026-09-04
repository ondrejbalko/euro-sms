<?php

declare(strict_types=1);

namespace EuroSms\Tests\Exception;

use EuroSms\Exception\EuroSmsException;
use EuroSms\Exception\SendException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * A caller that only wants to know that the send did not go through has one class to catch. The
 * list of exceptions is read off the directory rather than written out here, so an exception
 * added later cannot quietly stay outside the hierarchy.
 */
#[CoversClass(EuroSmsException::class)]
final class EuroSmsExceptionTest extends TestCase
{
    /**
     * The directory every exception of the library lives in.
     */
    private const string EXCEPTION_PATH = __DIR__ . '/../../src/Exception';

    /**
     * The namespace those files are autoloaded under.
     */
    private const string EXCEPTION_NAMESPACE = 'EuroSms\\Exception\\';

    /**
     * @return void
     */
    public function testEveryExceptionOfTheLibraryDescendsFromTheOneAncestor(): void
    {
        $classes = $this->exceptionClasses();

        self::assertNotEmpty($classes);

        foreach ($classes as $class) {
            self::assertTrue(
                is_subclass_of($class, EuroSmsException::class),
                sprintf('%s does not descend from %s.', $class, EuroSmsException::class)
            );
        }
    }

    /**
     * @return void
     */
    public function testTheAncestorIsAThrowableOfItsOwnAndNotAPlainRuntimeError(): void
    {
        $exception = new SendException('Gateway unreachable.', 7);

        self::assertInstanceOf(EuroSmsException::class, $exception);
        self::assertInstanceOf(Throwable::class, $exception);
        self::assertNotInstanceOf(RuntimeException::class, $exception);
    }

    /**
     * @return void
     */
    public function testOneCatchHoldsWhateverTheLibraryThrows(): void
    {
        $caught = null;

        try {
            throw new SendException('Gateway unreachable.', 7);
        } catch (EuroSmsException $e) {
            $caught = $e;
        }

        self::assertInstanceOf(SendException::class, $caught);
        self::assertSame('Gateway unreachable.', $caught->getMessage());
        self::assertSame(7, $caught->getCode());
    }

    /**
     * Every class the exception directory holds but the ancestor itself.
     * @return list<class-string<Throwable>>
     */
    private function exceptionClasses(): array
    {
        $files = glob(self::EXCEPTION_PATH . '/*.php');

        if (false === $files) {
            return [];
        }

        $classes = [];

        foreach ($files as $file) {
            $name = basename($file, '.php');

            if (EuroSmsException::class === self::EXCEPTION_NAMESPACE . $name) {
                continue;
            }

            /** @var class-string<Throwable> $class */
            $class = self::EXCEPTION_NAMESPACE . $name;
            $classes[] = $class;
        }

        return $classes;
    }
}
