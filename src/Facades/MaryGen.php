<?php

namespace SoysalTan\MaryGen\Facades;

use App\Models\Member;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Facade;

/**
 * @method static hi()
 */
class MaryGen extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \SoysalTan\MaryGen\MaryGen::class;
    }
}
