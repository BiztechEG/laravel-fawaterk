<?php

namespace BiztechEG\Fawaterk\Tests\MySql\Fixtures;

enum OrderKind: string
{
    case Standard = 'standard';
    case Rush = 'rush';
}
