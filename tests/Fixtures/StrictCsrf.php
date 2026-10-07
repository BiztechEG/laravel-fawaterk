<?php

namespace BiztechEG\Fawaterk\Tests\Fixtures;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;

/**
 * An app's CSRF middleware that checks tokens even in tests (Laravel's own
 * skips the check while running unit tests).
 */
class StrictCsrf extends VerifyCsrfToken
{
    protected function runningUnitTests()
    {
        return false;
    }
}
