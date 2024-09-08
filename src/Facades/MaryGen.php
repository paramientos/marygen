<?php

namespace SoysalTan\MaryGen\Facades;

use Illuminate\Support\Facades\Facade;

class MaryGen extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'marygen';
    }
}
