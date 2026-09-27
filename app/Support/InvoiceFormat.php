<?php

namespace App\Support;

use DateTimeInterface;
use IntlDateFormatter;

/**
 * Date and money formatting for the invoice PDF.
 *
 * Two things the naive `->format('d F Y')` got wrong: month names stayed
 * English on an otherwise Arabic or Kurdish invoice, and timestamps were
 * printed in the app timezone (UTC) rather than the store's, so an order paid
 * in the evening in Erbil was dated to the same day but three hours earlier —
 * and anything after 21:00 local was dated to the previous day.
 *
 * Digits are forced to Latin (`numbers=latn`) in every language. The prices,
 * quantities and the order reference next to them are Latin either way, so
 * Arabic-Indic digits in the date alone would read as an inconsistency rather
 * than a localisation.
 */
class InvoiceFormat
{
    /** Where the store is, which is the timezone its paperwork is dated in. */
    public const TIMEZONE = 'Asia/Baghdad';

    private const LOCALES = [
        'en' => 'en-GB',
        'ar' => 'ar-IQ@calendar=gregorian;numbers=latn',
        'ku' => 'ckb-IQ@calendar=gregorian;numbers=latn',
    ];

    /** "26 September 2026" / "26 أيلول 2026" / "26 ئەیلوول 2026". */
    public static function date(DateTimeInterface $moment, string $lang): string
    {
        return self::pattern($lang, 'd MMMM y')->format($moment);
    }

    /** The same, with the time appended — used for the order timeline. */
    public static function dateTime(DateTimeInterface $moment, string $lang): string
    {
        return self::pattern($lang, 'd MMMM y, HH:mm')->format($moment);
    }

    /**
     * Comma grouping and no decimals, matching the storefront's `money()` in
     * src/data.js — the dinar has no subunit in circulation, and an invoice
     * that groups differently from the page it was downloaded from reads as
     * a different number.
     */
    public static function money(int|float $amount): string
    {
        return number_format((float) $amount, 0, '.', ',');
    }

    /**
     * Whether a free-text value is written in Arabic script (Arabic proper,
     * Sorani, or the Arabic presentation forms).
     *
     * Customer-typed notes are the one field whose direction does not follow
     * the invoice language: an English note on an Arabic invoice is laid out
     * right-to-left by default, which strands its full stop at the start of
     * the line. Detecting the script lets the template mark the run as LTR.
     */
    public static function isArabicScript(string $text): bool
    {
        return preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $text) === 1;
    }

    private static function pattern(string $lang, string $pattern): IntlDateFormatter
    {
        return new IntlDateFormatter(
            self::LOCALES[$lang] ?? self::LOCALES['en'],
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            self::TIMEZONE,
            IntlDateFormatter::GREGORIAN,
            $pattern,
        );
    }
}
