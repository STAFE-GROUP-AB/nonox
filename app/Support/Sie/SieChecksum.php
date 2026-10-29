<?php

declare(strict_types=1);

namespace App\Support\Sie;

/**
 * The outcome of the #KSUMMA control sum (SIE 4C §10): CRC-32 with
 * polynomial EDB88320, preset to FFFFFFFF and inverted at the end, which is
 * exactly PHP's crc32b. It covers every record label and field, in
 * codepage 437, between the opening and closing #KSUMMA; not the
 * whitespace, the quotes around fields, the braces or the backslash in \".
 */
enum SieChecksum: string
{
    /** The file has no control sum, which is allowed (§10.3). */
    case Absent = 'absent';
    case Valid = 'valid';
    case Invalid = 'invalid';
    /** An opening #KSUMMA without a closing one: the file is cut short (§10.6). */
    case Unterminated = 'unterminated';
}
