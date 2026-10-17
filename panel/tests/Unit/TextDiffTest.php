<?php

namespace Tests\Unit;

use App\Support\TextDiff;
use PHPUnit\Framework\TestCase;

class TextDiffTest extends TestCase
{
    public function test_equal_texts_have_no_diff()
    {
        $this->assertSame('', TextDiff::unified("a\nb\n", "a\nb\n", 'install.sh'));
    }

    public function test_a_changed_line_shows_with_its_context()
    {
        $old = implode("\n", ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10']);
        $new = implode("\n", ['1', '2', '3', '4', 'five', '6', '7', '8', '9', '10']);

        $this->assertSame(
            "--- a/install.sh\n+++ b/install.sh\n@@ -2,7 +2,7 @@\n 2\n 3\n 4\n-5\n+five\n 6\n 7\n 8\n",
            TextDiff::unified($old, $new, 'install.sh'),
        );
    }

    public function test_new_and_removed_files()
    {
        $this->assertSame("--- a/lib.sh\n+++ b/lib.sh\n@@ -1,0 +1,2 @@\n+one\n+two\n", TextDiff::unified('', "one\ntwo", 'lib.sh'));
        $this->assertSame("--- a/lib.sh\n+++ b/lib.sh\n@@ -1,1 +1,0 @@\n-gone\n", TextDiff::unified('gone', '', 'lib.sh'));
    }

    public function test_changes_far_apart_are_separate_hunks()
    {
        $old = implode("\n", range(1, 30));
        $new = str_replace(["\n2\n", "\n28\n"], ["\ntwo\n", "\ntwenty-eight\n"], $old);

        $this->assertSame(2, substr_count(TextDiff::unified($old, $new, 'x.sh'), '@@ -'));
    }
}
