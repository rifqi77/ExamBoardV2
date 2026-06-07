<?php

namespace Tests\Feature;

use App\Services\Alerts;
use Tests\TestCase;

class AlertsTest extends TestCase
{
    public function test_only_server_faults_are_alertable(): void
    {
        // Server faults → alert.
        $this->assertTrue(Alerts::isAlertable(new \RuntimeException('boom')));
        $this->assertTrue(Alerts::isAlertable(new \Symfony\Component\HttpKernel\Exception\HttpException(500)));
        $this->assertTrue(Alerts::isAlertable(new \Symfony\Component\HttpKernel\Exception\HttpException(503)));

        // Client / expected conditions → no alert (avoids noise).
        $this->assertFalse(Alerts::isAlertable(new \Symfony\Component\HttpKernel\Exception\HttpException(404)));
        $this->assertFalse(Alerts::isAlertable(new \Symfony\Component\HttpKernel\Exception\HttpException(403)));
        $this->assertFalse(Alerts::isAlertable(new \Illuminate\Auth\AuthenticationException));
        $this->assertFalse(Alerts::isAlertable(new \Illuminate\Database\Eloquent\ModelNotFoundException));
    }

    public function test_send_is_safe_with_no_channel_configured(): void
    {
        // No webhook/email set + array cache + log mailer → must not throw.
        Alerts::send('unit test alert', 'body');
        Alerts::send('unit test alert', 'body'); // deduped second call
        $this->assertTrue(true);
    }
}
