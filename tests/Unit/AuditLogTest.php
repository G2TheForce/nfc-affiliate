<?php

namespace Tests\Unit;

use App\Domain\Audit\AuditLogger;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_entries_cannot_be_updated(): void
    {
        $log = app(AuditLogger::class)->log('test.created');

        $this->expectException(LogicException::class);
        $log->update(['action' => 'tampered']);
    }

    public function test_entries_cannot_be_deleted(): void
    {
        $log = app(AuditLogger::class)->log('test.created');

        $this->expectException(LogicException::class);
        $log->delete();
    }

    public function test_sensitive_keys_are_redacted_recursively(): void
    {
        $redacted = app(AuditLogger::class)->redact([
            'password' => 'x',
            'nested' => ['api_key' => 'y', 'account_number' => '123', 'amount_minor' => 5000],
            'access_token' => 'z',
            'name' => 'kept',
        ]);

        $this->assertSame('[redacted]', $redacted['password']);
        $this->assertSame('[redacted]', $redacted['nested']['api_key']);
        $this->assertSame('[redacted]', $redacted['nested']['account_number']);
        $this->assertSame(5000, $redacted['nested']['amount_minor']);
        $this->assertSame('[redacted]', $redacted['access_token']);
        $this->assertSame('kept', $redacted['name']);
    }

    public function test_ip_addresses_are_stored_as_keyed_hashes(): void
    {
        $this->get('/up', ['REMOTE_ADDR' => '203.0.113.7']);
        $hash = app(AuditLogger::class)->hashIp('203.0.113.7');

        $this->assertSame(64, strlen($hash));
        $this->assertNotSame(hash('sha256', '203.0.113.7'), $hash);
        $this->assertSame(0, AuditLog::where('ip_hash', '203.0.113.7')->count());
    }
}
