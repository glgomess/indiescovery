<?php

namespace App\Services;

use RuntimeException;

/** Thrown when an upstream API is rate limiting or down, as opposed to answering "no data for this app". */
class UpstreamUnavailable extends RuntimeException {}
