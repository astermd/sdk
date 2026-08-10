<?php

declare(strict_types=1);

namespace AsterMD\Sdk\Enum;

/**
 * Journey events recorded alongside intake-submission records.
 *
 * Every call to {@see \AsterMD\Sdk\Resource\IntakeSubmissions::create()} or
 * {@see \AsterMD\Sdk\Resource\IntakeSubmissions::update()} must include one of these
 * events to advance the server-side order-flow state machine.
 *
 * Two separate flows exist, each with three events:
 *
 * Pre-qualifying flow — emitted while the prospect answers eligibility questions:
 *  - `PreQualifyingInitiated` → first form step (use with `create()`)
 *  - `PreQualifyingInProgress` → intermediate steps (use with `update()`)
 *  - `PreQualifyingCompleted` → final step / form submitted (use with `update()`)
 *
 * Intake flow — emitted while the prospect fills in the full intake questionnaire:
 *  - `IntakeInitiated` → first form step (use with `create()`)
 *  - `IntakeInProgress` → intermediate steps (use with `update()`)
 *  - `IntakeCompleted` → final step / form submitted (use with `update()`)
 *
 * In both flows the `*Initiated` event is always passed to `create()` (the first
 * call that creates the server-side record), and `*InProgress` / `*Completed`
 * events are passed to subsequent `update()` calls.
 */
enum Event: string
{
    /** Emitted when the prospect starts the pre-qualifying questionnaire for the first time. Pass to `IntakeSubmissions::create()`. */
    case PreQualifyingInitiated = 'pre_qualifying_initiated';

    /** Emitted as the prospect progresses through intermediate steps of the pre-qualifying form. Pass to `IntakeSubmissions::update()`. */
    case PreQualifyingInProgress = 'pre_qualifying_inprogress';

    /** Emitted when the prospect completes and submits the pre-qualifying form. Pass to `IntakeSubmissions::update()`. */
    case PreQualifyingCompleted = 'pre_qualifying_completed';

    /** Emitted when the prospect starts the intake questionnaire for the first time. Pass to `IntakeSubmissions::create()`. */
    case IntakeInitiated = 'intake_initiated';

    /** Emitted as the prospect progresses through intermediate steps of the intake form. Pass to `IntakeSubmissions::update()`. */
    case IntakeInProgress = 'intake_inprogress';

    /** Emitted when the prospect completes and submits the intake form. Pass to `IntakeSubmissions::update()`. */
    case IntakeCompleted = 'intake_completed';
}
