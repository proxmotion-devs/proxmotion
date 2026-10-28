<?php

namespace Tests\Unit;

use App\Domain\Apps\VersionRange;
use PHPUnit\Framework\TestCase;

class VersionRangeTest extends TestCase
{
    public function test_every_comparator_must_hold()
    {
        $range = VersionRange::parse('>=1.0.0 <1.2.0');
        $this->assertNotNull($range);

        $this->assertTrue($range->contains('1.0.0'));
        $this->assertTrue($range->contains('1.1.9'));
        $this->assertFalse($range->contains('1.2.0'));
        $this->assertFalse($range->contains('0.9.9'));
        $this->assertTrue(VersionRange::parse('=1.1.0')?->contains('1.1.0'));
        $this->assertFalse(VersionRange::parse('>1.1.0')?->contains('1.1.0'));
        $this->assertTrue(VersionRange::parse('<=1.1.0')?->contains('1.1.0'));
        $this->assertTrue(VersionRange::parse('>=1.2.0 <1.10.0')?->contains('1.9.0'), 'Parts compare as numbers.');
    }

    public function test_an_administrators_edit_counts_as_the_version_it_was_made_from()
    {
        $range = VersionRange::parse('>=1.0.0 <1.2.0');

        $this->assertTrue($range?->contains('1.1.0-local.3'));
        $this->assertFalse($range?->contains('1.2.0-local.1'));
        $this->assertSame('1.1.0', VersionRange::base('1.1.0-local.3'));
    }

    public function test_an_administrators_edit_is_newer_than_the_version_it_was_made_from()
    {
        $this->assertTrue(VersionRange::isNewer('1.1.0-local.1', '1.1.0'));
        $this->assertTrue(VersionRange::isNewer('1.1.0-local.2', '1.1.0-local.1'));
        $this->assertTrue(VersionRange::isNewer('1.2.0', '1.1.0-local.3'), 'A newer bundled release.');
        $this->assertFalse(VersionRange::isNewer('1.1.0-local.1', '1.2.0'), 'An edit of an older version.');
        $this->assertFalse(VersionRange::isNewer('1.1.0-local.1', '1.1.0-local.1'));
        $this->assertFalse(VersionRange::isNewer('1.1.0', '1.1.0'));
    }

    public function test_anything_else_is_not_a_range()
    {
        foreach (['', '1.0.0', '>=1.0', '>= 1.0.0', '^1.0.0', '>=1.0.0 || <2.0.0', '>=1.0.0;rm'] as $range) {
            $this->assertFalse(VersionRange::isValid($range), $range);
        }
    }
}
