<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class AttendanceShortcutApiTest extends TestCase
{
    public function test_root_redirects_to_login_when_campus_session_is_missing(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }
}
