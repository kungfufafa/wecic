<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\PortalOfflineAccess;
use App\Services\PortalSessionManager;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Horizon (/horizon) and Log Viewer (/log-viewer) open, outside the local
 * environment, only for a student signed in with a NIM listed in
 * `portal.maintainers` (PortalMaintainers).
 */
class OperatorDashboardAccessTest extends TestCase
{
    private const MAINTAINER = '20990000001';

    private const STUDENT = '20990000002';

    /**
     * @var list<string>
     */
    private const DASHBOARDS = ['/horizon', '/log-viewer'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['portal.maintainers' => [self::MAINTAINER]]);
    }

    public function test_a_listed_maintainer_opens_both_dashboards(): void
    {
        $this->signInAs(self::MAINTAINER);

        foreach (self::DASHBOARDS as $dashboard) {
            $this->get($dashboard)->assertOk();
        }
    }

    public function test_another_student_is_refused(): void
    {
        $this->signInAs(self::STUDENT);

        foreach (self::DASHBOARDS as $dashboard) {
            $this->get($dashboard)->assertForbidden();
        }
    }

    public function test_a_visitor_without_a_campus_session_is_refused(): void
    {
        foreach (self::DASHBOARDS as $dashboard) {
            $this->get($dashboard)->assertForbidden();
        }
    }

    public function test_a_campus_session_that_has_ended_is_refused(): void
    {
        $this->signInAs(self::MAINTAINER, usable: false);

        foreach (self::DASHBOARDS as $dashboard) {
            $this->get($dashboard)->assertForbidden();
        }
    }

    public function test_a_maintainer_signed_in_offline_is_refused(): void
    {
        $this->signInAs(self::MAINTAINER, offline: true);

        foreach (self::DASHBOARDS as $dashboard) {
            $this->get($dashboard)->assertForbidden();
        }
    }

    public function test_no_one_gets_in_while_the_list_is_empty(): void
    {
        config(['portal.maintainers' => []]);
        $this->signInAs(self::MAINTAINER);

        foreach (self::DASHBOARDS as $dashboard) {
            $this->get($dashboard)->assertForbidden();
        }
    }

    public function test_the_log_viewer_api_follows_the_same_rule(): void
    {
        $this->signInAs(self::STUDENT);
        $this->get('/log-viewer/api/files', ['Referer' => config('app.url').'/log-viewer'])->assertForbidden();

        $this->signInAs(self::MAINTAINER);
        $this->get('/log-viewer/api/files', ['Referer' => config('app.url').'/log-viewer'])->assertOk();
    }

    public function test_the_local_environment_lets_everyone_in(): void
    {
        $this->app['env'] = 'local';

        foreach (self::DASHBOARDS as $dashboard) {
            $this->get($dashboard)->assertOk();
        }
    }

    /**
     * A MyCIC session whose campus session was opened with the given NIM:
     * usable or ended (inactive or idle-expired), online or offline.
     */
    private function signInAs(string $username, bool $usable = true, bool $offline = false): void
    {
        $this->withSession(['campus_session_token' => 'campus-token-dashboards']);
        $this->partialMock(PortalSessionManager::class, function (MockInterface $mock) use ($username, $usable): void {
            $mock->shouldReceive('isSessionUsable')->with('campus-token-dashboards')->andReturn($usable);
            $mock->shouldReceive('getSession')->with('campus-token-dashboards')->andReturn(['username' => $username]);
        });
        $this->partialMock(PortalOfflineAccess::class, function (MockInterface $mock) use ($offline): void {
            $mock->shouldReceive('isOfflineSession')->with('campus-token-dashboards')->andReturn($offline);
        });
    }
}
