<?php

declare(strict_types=1);

namespace Innis\Hubstr\Relay\Tests\Unit\Infrastructure\Persistence;

use Innis\Hubstr\Relay\Infrastructure\Persistence\PolicyState;
use Innis\Hubstr\Relay\Infrastructure\Persistence\WriteThroughPolicyManagement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionNamedType;
use SplFileInfo;

final class PolicyStateDesignTest extends TestCase
{
    private const string SOURCE_ROOT = __DIR__.'/../../../../src';

    private const string SOURCE_NAMESPACE = 'Innis\\Hubstr\\Relay\\';

    public function testPolicyStateStaysAMutableReadModelNotAnImmutableValueObject(): void
    {
        self::assertFalse(
            new ReflectionClass(PolicyState::class)->isReadOnly(),
            'PolicyState must stay mutable: a readonly class cannot serve in-place hot-path policy updates (ADR-0006).',
        );
    }

    #[DataProvider('mutators')]
    public function testMutatorsMutateInPlaceRatherThanReturningANewInstance(string $mutator): void
    {
        $returnType = new ReflectionClass(PolicyState::class)->getMethod($mutator)->getReturnType();

        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame(
            'void',
            $returnType->getName(),
            sprintf('%s() must mutate in place; a non-void return signals a drift toward immutable transformation (ADR-0006).', $mutator),
        );
    }

    public function testOnlyTheWriteThroughCoordinatorIsInjectedWithTheMutableProjection(): void
    {
        $injectionSites = [];

        foreach (self::productionClasses() as $class) {
            if (WriteThroughPolicyManagement::class === $class) {
                continue;
            }

            $constructor = new ReflectionClass($class)->getConstructor();

            foreach ($constructor?->getParameters() ?? [] as $parameter) {
                $type = $parameter->getType();

                if ($type instanceof ReflectionNamedType && PolicyState::class === $type->getName()) {
                    $injectionSites[] = sprintf('%s::$%s', $class, $parameter->getName());
                }
            }
        }

        self::assertSame(
            [],
            $injectionSites,
            'Only WriteThroughPolicyManagement may hold the concrete PolicyState; everything else depends on '
            .'PolicyStateInterface so the persist-then-mutate invariant cannot be bypassed (ADR-0006).',
        );
    }

    /**
     * @return iterable<class-string>
     */
    private static function productionClasses(): iterable
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::SOURCE_ROOT));

        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }

            $relative = substr((string) $file->getRealPath(), strlen((string) realpath(self::SOURCE_ROOT)) + 1);
            $class = self::SOURCE_NAMESPACE.str_replace(['/', '.php'], ['\\', ''], $relative);

            if (class_exists($class)) {
                yield $class;
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mutators(): iterable
    {
        yield 'addTenant' => ['addTenant'];
        yield 'removeTenant' => ['removeTenant'];
        yield 'setGuestPolicy' => ['setGuestPolicy'];
        yield 'setRateLimits' => ['setRateLimits'];
        yield 'banPubkey' => ['banPubkey'];
        yield 'unbanPubkey' => ['unbanPubkey'];
        yield 'banWord' => ['banWord'];
        yield 'unbanWord' => ['unbanWord'];
        yield 'banHashtag' => ['banHashtag'];
        yield 'unbanHashtag' => ['unbanHashtag'];
        yield 'blockIp' => ['blockIp'];
        yield 'unblockIp' => ['unblockIp'];
        yield 'setMetadata' => ['setMetadata'];
    }
}
