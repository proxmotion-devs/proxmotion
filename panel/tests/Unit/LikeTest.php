<?php

namespace Tests\Unit;

use App\Support\Database\Like;
use PHPUnit\Framework\TestCase;

class LikeTest extends TestCase
{
    public function test_wildcards_and_the_escape_character_match_only_themselves()
    {
        $this->assertSame('%203.0.113.5%', Like::contains('203.0.113.5'));
        $this->assertSame('%50!%!_off!!%', Like::contains('50%_off!'));
        $this->assertSame('%%', Like::contains(''));
    }
}
