<?php
declare(strict_types=1);

use App\Domain\Permissions;
use Tests\TestCase;

final class PermissionsTest extends TestCase
{
    public function testOwnerCanDoEverything(): void
    {
        foreach (['settings.manage', 'services.manage', 'bookings.manage', 'qualquer.coisa'] as $p) {
            $this->assertTrue(Permissions::allows('owner', $p), "dona deveria ter $p");
        }
    }

    public function testManagerCannotChangeSettings(): void
    {
        $this->assertTrue(Permissions::allows('manager', 'bookings.manage'));
        $this->assertTrue(Permissions::allows('manager', 'services.manage'));
        $this->assertFalse(Permissions::allows('manager', 'settings.manage'));
    }

    public function testAssistantIsReadOnly(): void
    {
        $this->assertTrue(Permissions::allows('assistant', 'agenda.view'));
        $this->assertTrue(Permissions::allows('assistant', 'bookings.view'));
        $this->assertFalse(Permissions::allows('assistant', 'bookings.manage'));
        $this->assertFalse(Permissions::allows('assistant', 'services.manage'));
        $this->assertFalse(Permissions::allows('assistant', 'clients.view'));
    }

    public function testArtistOnlySeesOwnAgenda(): void
    {
        $this->assertTrue(Permissions::allows('artist', 'agenda.view.own'));
        $this->assertFalse(Permissions::allows('artist', 'agenda.view'));
        $this->assertFalse(Permissions::allows('artist', 'services.manage'));
    }

    public function testUnknownRoleHasNothing(): void
    {
        $this->assertFalse(Permissions::allows('hacker', 'dashboard.view'));
        $this->assertFalse(Permissions::allows('', 'dashboard.view'));
    }
}
