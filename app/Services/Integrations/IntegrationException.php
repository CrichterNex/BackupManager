<?php

namespace App\Services\Integrations;

use RuntimeException;

/** A problem talking to an external platform. The message is safe to show: it never contains credentials. */
class IntegrationException extends RuntimeException {}
