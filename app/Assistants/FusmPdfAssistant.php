<?php

namespace App\Assistants;

use App\GeonamesCountry;
use Illuminate\Support\Str;

class FusmPdfAssistant extends PdfClient
{
    private const COMPANY_NAME = 'TRANSALLIANCE TS LTD';

    const POSSIBLE_CURRENCIES = ["EUR", "USD", "GBP", "PLN", "ZAR"];

    public static function validateFormat(array $lines)
    {
        return $lines[0] == "Date/Time :"
            && ($lines[4] == "CHARTERING CONFIRMATION" || $lines[6] == "CHARTERING CONFIRMATION")
            && array_find_key($lines, fn($l) => $l === self::COMPANY_NAME);
    }

    public function processLines(array $lines, ?string $attachment_filename = null)
    {
        $attachment_filenames = $attachment_filename ? [mb_strtolower($attachment_filename)] : [];
        $truck_number = null;
        $trailer_number = null;
        $customer = [
            'side' => 'none',
        ];
        $cargos = [];

        $loading_li = null;
        $instruction_li = [];
        foreach ($lines as $index => $item) {

            if (Str::startsWith($item, 'REF.:')) {
                $ref_li = $index;
                $order_reference = trim(str_replace('REF.:', '', $item));
            } else if (Str::startsWith($item, 'VAT NUM:') && !isset($vat_li)) {
                $vat_li = $index + 2;

            } else if (Str::startsWith($item, 'Tract.registr.:')) {
                $truck_number = trim(str_replace('Tract.registr.:', '', $item));

            } else if (Str::startsWith($item, 'Trail.registr.:')) {
                $trailer_number = trim(str_replace('Trail.registr.:', '', $item));

            } else if ($item === 'SHIPPING PRICE') {
                $freight_price = preg_replace('/[^0-9,\.]/', '', $lines[$index + 1]);
                $freight_price = uncomma($freight_price);
                $freight_currency = $this->getCurrency($lines[$index + 2]);

            } else if ($item === 'Loading') {
                $loading_li = $index;
            } else if ($item === 'Delivery') {
                $destination_li = $index;
            } else if ($item === 'Observations :') {
                $observation_li = $index;
            } else if ($item === 'Instructions') {
                $instruction_li[] = $index;
            }
        }
        $customer_location_data = array_slice($lines, $vat_li, $ref_li - $vat_li);
        if ($customer_location_data[0] !== self::COMPANY_NAME) {
            array_unshift($customer_location_data, self::COMPANY_NAME);
        }
        $customer_details = $this->extractCustomerAddress($customer_location_data);
        $customer['details'] = $customer_details['company_address'];

        $loading_locations = $this->extractLocations(
            array_slice($lines, $loading_li + 1, $destination_li - $loading_li)
        );
        $destination_location_data = array_slice($lines, $destination_li + 1, $observation_li - $destination_li);
        $destination_locations = $this->extractLocations(
            $destination_location_data
        );

        $cargos[] = $this->getCargoData(
            $destination_location_data
        );
        $comment = '';
        foreach ($instruction_li as $li) {

            if ($destination_li > $li) {
                $commentData = array_filter(array_slice($lines, $li + 1, $destination_li - $li - 1), fn($l) => $l != "");
                $comment = $this->formatCommentLines($commentData);
            } elseif (isset($observation_li) && $observation_li > $li) {
                $commentData = array_filter(array_slice($lines, $li + 1, $observation_li - $li - 1), fn($l) => $l != "");
                $comment .= ' ' . $this->formatCommentLines($commentData);
            }

        }
        $transport_numbers = join(' / ', array_filter([$truck_number, $trailer_number ?? null]));

        $data = compact(
            'customer',
            'loading_locations',
            'destination_locations',
            'attachment_filenames',
            'cargos',
            'order_reference',
            'transport_numbers',
            'freight_price',
            'freight_currency',
            'comment'
        );

        $this->createOrder($data);
    }

    private function getCargoData(array $cargoData): array
    {
        $cargos = ['package_type' => 'other'];
        $cargo_raw_data = [];
        foreach ($cargoData as $index => $item) {
            if ($item === 'LM . . . :') {
                $start_item_li = $index;
            } else if ($item === 'Pal. nb. :') {
                array_splice($cargoData, $index, 1);
            } else if ($item === 'M. nature:') {
                $end_item_li = $index;
            } else if ($item === 'OT :') {
                array_splice($cargoData, $index - 1, 4);
            }
        }

        $total_key = $end_item_li - $start_item_li;
        for ($i = $start_item_li; $i <= $end_item_li; $i++) {

            $cargo_raw_data[$cargoData[$i]] = $cargoData[$i + $total_key + 1];
        }
        foreach ($cargo_raw_data as $index => $item) {
            if ($index === 'M. nature:') {
                $cargos['title'] = $item;
            } else if ($index === 'Weight . :') {
                $cargos['weight'] = uncomma($item);
            } else if ($index === 'LM . . . :') {
                $cargos['ldm'] = uncomma($item);
            } else if ($index === 'Parc. nb :') {
                $cargos['package_count'] = empty($item) ? 1 : uncomma($item);
            } else if ($index === 'Type :') {
                $cargos['package_type'] = $item;
            }
        }

        return $cargos;
    }

    private function extractCustomerAddress(array $customerData): array
    {
        $time_interval = [];
        foreach ($customerData as $index => $item) {

            if (Str::startsWith($item, 'Contact:')) {
                $contact_person = trim(str_replace('Contact:', '', $item));
            } else if (Str::startsWith($item, 'Tel :')) {

                $telephone_li = $index;
            } else if (Str::startsWith($item, 'VAT NUM:')) {

                $vat_code = trim(str_replace('VAT NUM:', '', $item));
            } else if ($item === 'E-mail :') {

                $email = $customerData[$index + 1];
            } else if ($this->isDateTimeString(($item))) {
                if (isset($time_interval['datetime_from'])) {
                    $time_interval['datetime_to'] = $item;
                } else {
                    $time_interval['datetime_from'] = $item;
                }

            }

        }
        $onBlock = array_slice($customerData, 0, $telephone_li);
        $company_address = $this->parseAddressBlock($onBlock);
        $company_address['contact_person'] = $contact_person;
        $company_address['email'] = $email;
        $company_address['vat_code'] = $vat_code;
        $loading_locations = [
            'company_address' => $company_address,
            'time_interval' => $time_interval
        ];
        return $loading_locations;
    }
    private function extractLocations(array $loadingData): array
    {
        $company_address = [];
        $time_interval = [];
        foreach ($loadingData as $index => $item) {
            if ($item === 'ON:') {
                $on_li = $index;
            } else if (Str::startsWith($item, 'Contact:')) {
                $contact_li = $index;

                $contact_person = trim(str_replace('Contact:', '', $item));
            } else if ($this->isDateTimeString($item)) {
                // Handle date line
                if (!isset($time_interval['datetime_from'])) {
                    $time_interval['datetime_from'] = $this->parseDateTime($item);
                } else {
                    $time_interval['datetime_to'] = $this->parseDateTime($item);
                }
            } else if ($this->isTimeRangeString($item)) {
                // Handle time range line (e.g., "8h00 - 15h00")
                $times = $this->parseTimeRange($item);

                if (isset($time_interval['datetime_from'])) {
                    // If datetime_from is date-only → merge time
                    if (preg_match('/T00:00:00$/', $time_interval['datetime_from'])) {
                        $time_interval['datetime_from'] = substr($time_interval['datetime_from'], 0, 10) . 'T' . $times['from'];
                    }
                }

                if (isset($time_interval['datetime_to'])) {
                    if (preg_match('/T00:00:00$/', $time_interval['datetime_to'])) {
                        $time_interval['datetime_to'] = substr($time_interval['datetime_to'], 0, 10) . 'T' . $times['to'];
                    }
                }
                // If no datetime_to exists yet, set it
                else if (isset($time_interval['datetime_from'])) {
                    $time_interval['datetime_to'] = substr($time_interval['datetime_from'], 0, 10) . 'T' . $times['to'];
                }
            }

        }
        $onBlock = array_slice($loadingData, $on_li + 2, $contact_li - $on_li - 2);
        $company_address = $this->parseAddressBlock($onBlock);
        $company_address['contact_person'] = $contact_person;
        $loading_locations = [
            [
                'company_address' => $company_address,
                'time_interval' => $time_interval
            ]
        ];
        return $loading_locations;
    }

    protected function getCurrency(string $value): string|null
    {
        foreach (self::POSSIBLE_CURRENCIES as $currency) {
            if (stripos($value, $currency) !== false) {
                return $currency;
            }
        }
        return null;
    }

    private function parseDateTime(string $value): string
    {
        $value = trim($value);

        // Replace h/H with ":" for consistent time parsing
        $value = preg_replace('/([0-9])[hH]([0-9]{2})/', '$1:$2', $value);

        // Normalize delimiters
        $value = str_replace(['-', '.'], '/', $value);

        // Supported formats
        $formats = [
            'd/m/Y H:i',
            'd/m/Y',
            'd/m/y H:i',
            'd/m/y',
        ];

        foreach ($formats as $format) {
            $dt = \DateTime::createFromFormat($format, $value);
            if ($dt) {
                // Fix 2-digit year issue (e.g., 0025 → 2025)
                $year = (int) $dt->format('Y');
                if ($year < 100) {
                    $year += 2000;
                    $dt->setDate($year, (int) $dt->format('m'), (int) $dt->format('d'));
                }

                // If no explicit time was provided, default to midnight
                $timePart = strpos($value, ':') !== false ? '' : 'T00:00:00';
                return $dt->format('Y-m-d\T') . ($timePart ? '00:00:00' : $dt->format('H:i:s'));
            }
        }

        // Fallback: try native parsing
        try {
            $dt = new \DateTime($value);
            return $dt->format('Y-m-d\TH:i:s');
        } catch (\Exception $e) {
            return $value; // fallback raw if not parseable
        }
    }


    private function isDateTimeString(string $value): bool
    {
        $value = trim($value);

        // Pattern matches dd/mm/yyyy or dd/mm/yy optionally followed by time
        $pattern = '/^
        (0[1-9]|[12][0-9]|3[01])    # day
        [\/\-\.]                     # separator
        (0[1-9]|1[0-2])              # month
        [\/\-\.]
        (\d{2}|\d{4})                # year (2 or 4 digits)
        (?:\s+                       # optional space before time
            ([01]?[0-9]|2[0-3])      # hour
            (:[0-5][0-9]){1,2}       # minutes[:seconds]
        )?
        $/x';

        if (!preg_match($pattern, $value)) {
            return false;
        }

        // Try parsing with PHP's DateTime
        $formats = [
            'd/m/Y H:i:s',
            'd/m/Y H:i',
            'd/m/y H:i:s',
            'd/m/y H:i',
            'd/m/Y',
            'd/m/y'
        ];

        foreach ($formats as $format) {
            $dt = \DateTime::createFromFormat($format, $value);
            if ($dt && $dt->format($format) === $value) {
                return true;
            }
        }

        return false;
    }

    private function parseAddressBlock(array $lines): array
    {
        $company = trim($lines[0] ?? '');
        $streetParts = [];
        $country = $postal = $city = null;

        // Determine structure based on number of lines
        if (count($lines) === 4) {
            // 4 line format , then 1,2 index will combine and make a address
            $streetParts = array_filter([
                trim($lines[1] ?? ''),
                trim($lines[2] ?? '')
            ]);
            $lastPart = trim($lines[3] ?? '');
        } elseif (count($lines) === 3) {
            // 3-line format
            $streetParts = [trim($lines[1] ?? '')];
            $lastPart = trim($lines[2] ?? '');
        } else {
            // Fallback: treat everything except first as address
            $streetParts = array_slice($lines, 1, -1);
            $lastPart = end($lines);
        }

        // Detect patterns like "country, zip and city"
        if (
            preg_match(
                '/^(?:(?<country>[A-Z]{2})[- ]?)?(?<postal>[A-Z0-9\- ]{3,10})\s+(?<city>[A-ZÀ-ÿ\-\s]+)$/iu',
                ltrim($lastPart, '- '),
                $m
            )
        ) {
            $country = strtoupper($m['country'] ?? null);
            if (!$country && str_starts_with($lastPart, '-')) {
                $country = preg_replace('/[^A-Z]/ui', '', $country);
                $country = GeonamesCountry::getIso($country);
            }

            $postal = trim($m['postal']);
            $city = ucwords(strtolower(trim($m['city'])));
        }

        $res = [
            'company' => $company,
            'title' => $company,
            'street_address' => implode(', ', $streetParts),
            'city' => $city,
            'postal_code' => $postal,
        ];
        if ($country) {
            $res['country'] = $country;
        } else {
            foreach ($lines as $item) {
                $words = explode(' ', $item);

                foreach ($words as $index => $word) {
                    if (GeonamesCountry::getIso(strtoupper($word)) !== null) {
                        $country = GeonamesCountry::getIso(strtoupper($word));
                        $res['country'] = $country;

                        break;
                    }
                }
                if (!is_null($country)) {
                    break;
                }
            }
        }
        return $res;
    }

    private function isTimeRangeString(string $value): bool
    {
        return (bool) preg_match('/\d{1,2}\s*[hH:]?\s*\d{0,2}\s*-\s*\d{1,2}\s*[hH:]?\s*\d{0,2}/', trim($value));
    }

    private function parseTimeRange(string $value): ?array
    {
        $value = str_replace(['H', 'h'], ':', $value);
        $value = preg_replace('/\s+/', '', $value); // remove spaces

        if (preg_match('/(\d{1,2})(?::(\d{2}))?-(\d{1,2})(?::(\d{2}))?/', $value, $m)) {
            $fromHour = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $fromMin = $m[2] ?? '00';
            $toHour = str_pad($m[3], 2, '0', STR_PAD_LEFT);
            $toMin = $m[4] ?? '00';

            return [
                'from' => "{$fromHour}:{$fromMin}:00",
                'to' => "{$toHour}:{$toMin}:00",
            ];
        }
        return null;
    }

    private function formatCommentLines(array $lines): string
    {
        $lines = array_map(function ($line) {
            // Remove only leading '-' and surrounding spaces
            return preg_replace('/^\s*-\s*/', '', trim($line));
        }, $lines);

        foreach ($lines as $i => &$line) {
            $next = $lines[$i + 1] ?? null;

            // If current line ends with '.', keep as is
            if (str_ends_with($line, '.'))
                continue;

            // If next line ends with '.', skip adding '.'
            if ($next && str_ends_with($next, '.'))
                continue;

            // Otherwise, append '.'
            $line .= '.';
        }
        unset($line);

        // Join all lines into one clean string
        return preg_replace('/\s+/', ' ', trim(implode(' ', $lines)));
    }
}
