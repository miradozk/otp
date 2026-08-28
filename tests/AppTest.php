<?php

declare(strict_types=1);

namespace Tests;

use App\App;
use App\Store;
use PHPUnit\Framework\TestCase;

final class AppTest extends TestCase
{
    private const SECRET = 'JBSWY3DPEHPK3PXP';

    private Store $store;
    private App $app;

    protected function setUp(): void
    {
        $this->store = new Store('sqlite::memory:');
        $this->app = new App($this->store);
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function post(string $path, array $data): array
    {
        return $this->app->handle('POST', $path, json_encode($data, JSON_THROW_ON_ERROR), 0);
    }

    public function testListIsEmptyInitially(): void
    {
        self::assertSame([200, ['names' => []]], $this->app->handle('GET', '/secrets', '', 0));
    }

    public function testAddSecretReturns201AndStoresIt(): void
    {
        $response = $this->post('/secrets', ['name' => 'github', 'secret' => self::SECRET]);

        self::assertSame([201, ['name' => 'github']], $response);
        self::assertSame(['name' => 'github', 'secret' => self::SECRET, 'digits' => 6, 'period' => 30], $this->store->find('github'));
    }

    public function testAddSecretNormalizesSecret(): void
    {
        $this->post('/secrets', ['name' => 'github', 'secret' => 'jbsw y3dp ehpk 3pxp']);

        self::assertSame(self::SECRET, $this->store->find('github')['secret']);
    }

    public function testAddSecretAcceptsDigitsAndPeriod(): void
    {
        $this->post('/secrets', ['name' => 'github', 'secret' => self::SECRET, 'digits' => 8, 'period' => 60]);

        $row = $this->store->find('github');
        self::assertSame(8, $row['digits']);
        self::assertSame(60, $row['period']);
    }

    public function testListNeverExposesSecrets(): void
    {
        $this->post('/secrets', ['name' => 'github', 'secret' => self::SECRET]);
        $this->post('/secrets', ['name' => 'aws', 'secret' => self::SECRET]);

        [$status, $body] = $this->app->handle('GET', '/secrets', '', 0);

        self::assertSame(200, $status);
        self::assertSame(['names' => ['aws', 'github']], $body);
        self::assertStringNotContainsString(self::SECRET, json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function testAddSecretRejectsInvalidJson(): void
    {
        [$status, $body] = $this->app->handle('POST', '/secrets', '{not json', 0);

        self::assertSame(400, $status);
        self::assertArrayHasKey('error', $body);
    }

    public function testAddSecretRejectsMissingName(): void
    {
        [$status, $body] = $this->post('/secrets', ['secret' => self::SECRET]);

        self::assertSame(400, $status);
        self::assertStringContainsString('name', $body['error']);
    }

    public function testAddSecretRejectsInvalidName(): void
    {
        [$status] = $this->post('/secrets', ['name' => 'git hub/évil', 'secret' => self::SECRET]);

        self::assertSame(400, $status);
    }

    public function testAddSecretRejectsMissingSecret(): void
    {
        [$status, $body] = $this->post('/secrets', ['name' => 'github']);

        self::assertSame(400, $status);
        self::assertStringContainsString('secret', $body['error']);
    }

    public function testAddSecretRejectsInvalidBase32(): void
    {
        [$status, $body] = $this->post('/secrets', ['name' => 'github', 'secret' => 'not-base32!']);

        self::assertSame(400, $status);
        self::assertStringContainsString('secret', $body['error']);
    }

    public function testAddSecretRejectsBadDigits(): void
    {
        [$status] = $this->post('/secrets', ['name' => 'github', 'secret' => self::SECRET, 'digits' => 7]);

        self::assertSame(400, $status);
    }

    public function testAddSecretRejectsBadPeriod(): void
    {
        [$status] = $this->post('/secrets', ['name' => 'github', 'secret' => self::SECRET, 'period' => 0]);

        self::assertSame(400, $status);
    }

    public function testAddSecretDuplicateReturns409(): void
    {
        $this->post('/secrets', ['name' => 'github', 'secret' => self::SECRET]);

        [$status, $body] = $this->post('/secrets', ['name' => 'github', 'secret' => self::SECRET]);

        self::assertSame(409, $status);
        self::assertArrayHasKey('error', $body);
    }

    public function testSecretsRejectsOtherMethods(): void
    {
        [$status] = $this->app->handle('PUT', '/secrets', '', 0);

        self::assertSame(405, $status);
    }

    public function testUnknownRouteReturns404(): void
    {
        [$status] = $this->app->handle('GET', '/nope', '', 0);

        self::assertSame(404, $status);
    }
}
