<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A notebook source could not be read; the message is written for the student.
 */
class NotebookSourceException extends RuntimeException {}
