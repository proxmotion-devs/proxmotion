# 32. Staged provisioning tasks

- **Status:** Accepted
- **Date:** 2026-10-20

## Context

Creating and reinstalling a server ran as one queue job (`RunServerTask`, one try, a 1800-second timeout) that did everything in a row and slept while it waited: the clone (up to 30 minutes), the start, the guest agent (10 minutes), Windows setup (15 minutes) or cloud-init (10 more minutes), the restart of a Windows server named from its location's template with another agent wait (ADR 0031), the guest scripts and the ordered app.

- **The waits add up past the job's timeout.** A slow clone followed by a slow Windows setup is killed by the worker; `RunServerTask::failed()` then ends the task and puts the server in `error`, although the VM is most likely fine and still setting itself up.
- **Each provisioning holds a worker for its whole length,** most of it sleeping. With two workers, a third order waits in the queue until one of the first two has finished.
- **The queue's `retry_after` was 90 seconds**, far below the job's timeout. On the database and Redis queues a job still running after 90 seconds is handed to a second worker; with one try that copy is failed at once ("has been attempted too many times") and `failed()` ends the task that is still running. (Found by reading the code; not reproduced on a real queue.)
- **A stuck task could be ended** by an administrator 1800 seconds after it started, whatever it was doing, which is wrong for a task that legitimately runs longer.

## Decision

### 1. Stages

Create and reinstall tasks run in **stages** (`App\Provisioning\Stages`). A pipeline that implements `StagedPipeline` lists its stages in order and runs one stage at a time; a stage either finishes or asks to be run again after a number of seconds. Every stage stays idempotent, as before.

| Pipeline | Stages |
|---|---|
| Create | `provision`, `clone`, `configure`, `resize`, `firewall`, `start`, `wait_agent`, `name_guest`, `name_guest_wait`, `guest_scripts`, `finish` |
| Reinstall | `prepare`, `destroy`, then the same as create from `clone` |

The stage a task is in and what the stage needs to remember (the UPID of a running Proxmox task, when a wait began) are stored on the task (`tasks.stage`, `tasks.stage_state`, cleared when the next stage begins). What later stages need, such as the `net0` of the deleted VM whose MAC address a reinstall keeps, goes into `tasks.input`. Task steps (`completeStep()`) stay as they were: stages are how the work is split up, steps are what administrators and billing systems see.

### 2. Waiting without holding a worker

`StageRunner::advance()` runs stages until one asks to wait. `RunServerTask` then queues itself again with that delay and returns, so the worker is free in the meantime:

- **Proxmox tasks** (clone, resize, start, destroy) are started once, their UPID is kept, and each run of the stage checks their status once (`TaskWaiter::check()`); their timeouts are unchanged.
- **The guest agent, Windows setup and cloud-init** are checked once per run (`GuestScripts::checkReady()`), every 5 seconds, with the same timeouts and notes as before. Unsuspends keep waiting in place (`waitForAgentStep()`, now a loop around the same check).
- **Guest scripts and the ordered app's upload** still run inside one stage: they take seconds to a few minutes.

A single run also stops after about 5 minutes of work and queues the rest at once, so no job comes near its timeout. On the `sync` queue (tests, and installs without a worker) the job sleeps and goes on in place instead of queuing itself, which keeps waits testable with `Sleep::fake()`.

### 3. One chain per task

Every time the task is queued again, `tasks.dispatch` is incremented and the job carries that number. A job whose number is not the task's current one, or that finds the task no longer `running` (an administrator marked it as failed), returns without doing anything; its `failed()` does not touch the task either. Before a task is marked as succeeded, its status is read again, so a task an administrator ended stays failed.

### 4. Time limits

- A staged task has a **deadline** (`tasks.deadline_at`, `PROXMOTION_PROVISIONING_DEADLINE_MINUTES`, default 120 minutes from its start). A task past it fails with *The task did not finish within :minutes minutes.* and the server is in `error`, retryable as before.
- Each run of a task records a **heartbeat** (`tasks.heartbeat_at`), also after each stage. *Mark as failed* is offered for a running task without a heartbeat for longer than a job may run (`RunServerTask::TIMEOUT`), or past its deadline. Tasks that are not staged record their heartbeat when they start, which keeps the old rule for them.
- The queue's **`retry_after`** defaults to 1860 seconds for the database, Redis and Beanstalkd connections (`DB_QUEUE_RETRY_AFTER`, `REDIS_QUEUE_RETRY_AFTER`, `BEANSTALKD_QUEUE_RETRY_AFTER`), longer than `RunServerTask::TIMEOUT`, as Laravel requires. Jobs whose worker died are therefore retried only after 31 minutes; none of the panel's jobs depends on a quicker retry.

### 5. What does not change

Other task types still run as one job. The API, its task resource and the webhooks are unchanged: a staged task is `running` from its first stage to its last, and its steps look as before. Retry after a failure still starts a new task that continues from what Proxmox shows.

## Consequences

- A worker is busy only while a stage works, so more servers can be created at the same time with the same workers.
- A long clone or a slow Windows setup no longer puts a healthy server in `error`; the deadline is the one limit for the whole task.
- Delayed jobs need a queue that supports delays (database, Redis); the `sync` queue sleeps instead.
- Nothing of this has run on a real queue or against a real Proxmox server yet: delayed dispatches on Redis with Horizon and on the database queue, and a Windows server whose setup outlasts one job, are on the verification list in `AGENTS.md`.
