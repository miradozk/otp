<?php

declare(strict_types=1);

namespace Tests;

use App\DuplicateNameException;
use App\Store;
use PHPUnit\Framework\TestCase;

final class StoreTest extends TestCase
{
    private Store $store;

    protected function setUp(): void
    {
        $this->store = new Store('sqlite::memory:');
    }

    public function testNamesIsEmptyInitially(): void
    {
        self::assertSame([], $this->store->names());
    }

    public function testAddThenFind(): void
    {
        $this->store->add('github', 'JBSWY3DPEHPK3PXP', 8, 60);

        self::assertSame(
            ['name' => 'github', 'secret' => 'JBSWY3DPEHPK3PXP', 'digits' => 8, 'period' => 60],
            $this->store->find('github'),
        );
    }

    public function testAddUsesDefaults(): void
    {
        $this->store->add('github', 'JBSWY3DPEHPK3PXP');

        $row = $this->store->find('github');
        self::assertSame(6, $row['digits']);
        self::assertSame(30, $row['period']);
    }

    public function testNamesAreSorted(): void
    {
        $this->store->add('zed', 'JBSWY3DPEHPK3PXP');
        $this->store->add('alpha', 'JBSWY3DPEHPK3PXP');

        self::assertSame(['alpha', 'zed'], $this->store->names());
    }

    public function testFindUnknownReturnsNull(): void
    {
        self::assertNull($this->store->find('nope'));
    }

    public function testAddDuplicateThrows(): void
    {
        $this->store->add('github', 'JBSWY3DPEHPK3PXP');

        $this->expectException(DuplicateNameException::class);
        $this->store->add('github', 'JBSWY3DPEHPK3PXP');
    }

    public function testDelete(): void
    {
        $this->store->add('github', 'JBSWY3DPEHPK3PXP');

        self::assertTrue($this->store->delete('github'));
        self::assertNull($this->store->find('github'));
        self::assertFalse($this->store->delete('github'));
    }
}
