<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../services/IdGenerator.php';

class UserPublicIdTest extends TestCase
{
    public function test_every_account_gets_the_same_usr_format(): void
    {
        $this->assertSame('USR-0001', generatePublicId('USR', 1));
        $this->assertSame('USR-0042', generatePublicId('USR', 42));
    }

    public function test_sign_up_does_not_label_people_as_surveyors(): void
    {
        $src = (string)file_get_contents(__DIR__ . '/../auth/register.php');
        $this->assertStringNotContainsString("'SURV-'", $src);
        $this->assertStringContainsString("generatePublicId('USR'", $src);
    }
}
