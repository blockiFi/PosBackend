<?php

namespace App\Exceptions;

use RuntimeException;

/** A return the server refuses, with a message for the cashier. */
class ReturnRefused extends RuntimeException {}
