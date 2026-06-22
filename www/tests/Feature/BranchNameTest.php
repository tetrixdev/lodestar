<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Deliverable;
use App\Models\Task;
use Tests\TestCase;

/**
 * branchName() must return the RECORDED branch when set — the branch that
 * actually exists in git — and only compute the D{id}-slug default before one is
 * recorded. Guards against the drift where Str::slug("v0.5") = "v05" disagrees
 * with the real `D000001-v0-5` branch.
 */
class BranchNameTest extends TestCase
{
    public function test_deliverable_prefers_the_recorded_branch(): void
    {
        $d = new Deliverable();
        $d->id = 1;
        $d->title = 'v0.5';

        // Before a branch is recorded: computed fallback (note slug strips the dot).
        $this->assertSame('D000001-v05', $d->branchName());

        // Once recorded, the real branch wins — no recompute drift.
        $d->branch = 'D000001-v0-5';
        $this->assertSame('D000001-v0-5', $d->branchName());
    }

    public function test_task_prefers_the_recorded_branch(): void
    {
        $t = new Task();
        $t->deliverable_id = 1;
        $t->sub_id = 73;
        $t->title = 'Some Task';

        $this->assertSame('D000001/T73-some-task', $t->branchName());

        $t->branch = 'D000001/T73-renamed-earlier';
        $this->assertSame('D000001/T73-renamed-earlier', $t->branchName());
    }
}
