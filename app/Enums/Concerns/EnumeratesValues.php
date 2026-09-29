<?php

namespace App\Enums\Concerns;

/**
 * Backed enums do not have a `values()` helper of their own, but every
 * validation rule in this app needs the list of legal backing values
 * (`Rule::in(FulfilmentType::values())`). Rather than repeat the
 * array_column call in each enum, it lives here once.
 *
 * @phpstan-require-extends \BackedEnum
 */
trait EnumeratesValues
{
    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The legal values plus their human labels, for populating a select.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = method_exists($case, 'label')
                ? $case->label()
                : $case->value;
        }

        return $options;
    }
}
