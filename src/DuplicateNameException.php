<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/** Levée par Store::add quand le nom existe déjà. */
final class DuplicateNameException extends RuntimeException
{
}
