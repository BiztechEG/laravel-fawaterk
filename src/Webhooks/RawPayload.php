<?php

namespace BiztechEG\Fawaterk\Webhooks;

/**
 * A webhook body parsed from the raw request content, so no input middleware
 * (TrimStrings, ConvertEmptyStringsToNull, …) can change a signed value.
 *
 * JSON numbers keep their exact text ("150.50" stays "150.50"): the signature
 * covers the text Fawaterk sent, not PHP's idea of the number.
 */
final class RawPayload
{
    public const MAX_BYTES = 65536;

    /**
     * Fawaterk sends a handful of fields. parse_str() warns past PHP's
     * max_input_vars (1000 by default), and Laravel turns that warning into a
     * 500, so bigger form bodies are refused before parsing.
     */
    public const MAX_FORM_FIELDS = 200;

    /**
     * @param  array<array-key, mixed>  $fields
     */
    private function __construct(private readonly array $fields) {}

    /**
     * JSON when the body is a JSON object, form-encoded otherwise. Returns null
     * for anything that is not a non-empty object of fields.
     */
    public static function parse(string $body): ?self
    {
        if ($body === '' || strlen($body) > self::MAX_BYTES) {
            return null;
        }

        $trimmed = ltrim($body);

        if (str_starts_with($trimmed, '{')) {
            $quoted = self::quoteNumbers($trimmed);
            $fields = $quoted === null ? null : json_decode($quoted, true, 16);
        } elseif (substr_count($body, '&') + 1 > self::MAX_FORM_FIELDS) {
            return null;
        } else {
            parse_str($body, $fields);
        }

        return is_array($fields) && $fields !== [] && ! array_is_list($fields) ? new self($fields) : null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->fields);
    }

    /**
     * A top-level scalar value as the exact text that was sent, or null when it
     * is missing, empty, an object, a list, a boolean or null.
     */
    public function string(string $key): ?string
    {
        $value = $this->fields[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Wraps every JSON number in quotes, keeping its text. Returns null if the
     * body contains something that is not valid JSON number syntax.
     */
    private static function quoteNumbers(string $json): ?string
    {
        $out = '';
        $length = strlen($json);
        $inString = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];

            if ($inString) {
                $out .= $char;

                if ($char === '\\' && $i + 1 < $length) {
                    $out .= $json[++$i];
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
                $out .= $char;

                continue;
            }

            if ($char === '-' || ctype_digit($char)) {
                $start = $i;

                while ($i + 1 < $length && strpbrk($json[$i + 1], '0123456789+-.eE') !== false) {
                    $i++;
                }

                $number = substr($json, $start, $i - $start + 1);

                if (! preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?$/D', $number)) {
                    return null;
                }

                $out .= '"'.$number.'"';

                continue;
            }

            $out .= $char;
        }

        return $out;
    }
}
