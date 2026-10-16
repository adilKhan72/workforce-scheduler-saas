<?php

namespace App\Scheduling;

use DomainException;

/** A request that is valid input but not allowed in the current state (e.g. a decided swap). */
final class SchedulingError extends DomainException {}
