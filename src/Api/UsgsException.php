<?php
/**
 * USGS API error.
 *
 * @package RiverConditions
 */

declare(strict_types=1);

namespace RiverConditions\Api;

/**
 * Thrown on network failures, non-2xx responses and invalid JSON.
 * The code is the HTTP status, or 0 for network errors.
 */
final class UsgsException extends \RuntimeException {}
