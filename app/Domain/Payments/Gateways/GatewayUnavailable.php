<?php

namespace App\Domain\Payments\Gateways;

use RuntimeException;

/** The provider could not be reached or gave an answer we cannot trust. */
class GatewayUnavailable extends RuntimeException {}
