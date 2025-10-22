<?php

namespace App\Assistants;

use App\GeonamesCountry;
use Carbon\Carbon;
use Illuminate\Support\Str;

class ToluPdfAssistant extends PdfClient
{

    private const COMPANY_NAME = 'ZIEGLER UK LTD';
    const POSSIBLE_CURRENCIES = ["EUR", "USD", "GBP", "PLN", "ZAR"];

    const PACKAGE_TYPE_MAP = [
        "EW-Paletten" => "PALLET_OTHER",
        "Ladung" => "CARTON",
        "Stück" => "OTHER",
    ];

    public static function validateFormat(array $lines)
    {
        return $lines[0] == self::COMPANY_NAME;
    }

    public function processLines(array $lines, ?string $attachment_filename = null)
    {
        $attachment_filenames = [mb_strtolower($attachment_filename ?? '')];
        $truck_number = null;
        $trailer_number = null;
        $company_name = self::COMPANY_NAME;
        $customer = [
            'side' => 'none',
            'details' => [
                'company' => $company_name,
            ]
        ];

        $cargos = [];
        $loading_locations = [];
        $destination_locations = [];
        $loading_li = [];
        $destination_li = [];
        foreach ($lines as $index => $item) {


            if (Str::startsWith($item, 'Ziegler Ref')) {
                $ref_li = $index;

                $order_reference = trim($lines[$index + 2]);
            } else if ($item === 'Rate') {

                $line = $lines[$index + 2];

                $currency = $this->getCurrency($line); // e.g. "EUR" or "€"
                // Remove the currency part and extract only the number

                $amountPart = str_ireplace(
                    [$currency, '€', '$', '£', 'zł', 'R'],
                    '',
                    $line
                );

                $freight_price = uncomma($amountPart);
                $freight_currency = $currency;

            } else if ($item === 'Collection') {
                $loading_li[] = $index;
            } else if ($item === 'Clearance' || $item === 'Delivery') {
                $destination_li[] = $index;
            } else if (Str::startsWith($item, '- Payment')) {
                $destination_end_li = $index;
            }
        }
        // dd($lines);
        // dd($order_reference);
        // dd($freight_currency);
        // dd($freight_price);
        $customer_location_data = array_slice($lines, 0, $ref_li);
        if ($customer_location_data[0] !== $company_name) {
            array_unshift($customer_location_data, $company_name);
        }
        $customer_location = $this->extractCustomerAddress($customer_location_data);

        foreach ($loading_li as $index => $loadingLi) {

            $end_li = $loading_li[$index + 1] ?? $destination_li[0];
            $loading_locations[] = $this->extractLocations(
                array_slice($lines, $loadingLi + 2, $end_li - $loadingLi - 3, true)
            );
        }
        foreach ($destination_li as $index => $destinationLi) {

            $end_li = $destination_li[$index + 1] ?? $destination_end_li;
            $destination_locations[] = $this->extractLocations(
                array_slice($lines, $destinationLi + 1, $end_li - $destinationLi - 2, false)
            );
        }

        foreach ($loading_locations as $loadingData) {
            if (
                isset($loadingData['company_address']['comment']) &&
                Str::contains(strtolower($loadingData['company_address']['comment']), 'pallets')
            ) {
                $parts = preg_split('/[,]+/', $loadingData['company_address']['comment'], -1, PREG_SPLIT_NO_EMPTY);
                $parts = array_map('trim', $parts);
                $palletCount = 0;
                foreach ($parts as $part) {
                    if (Str::contains(strtolower($part), 'pallets')) {
                        $palletCount += (int) $part;
                    }

                }
                $cargos[] = [
                    'title' => $palletCount . ' Pallets',
                    'package_count' => $palletCount,
                    'palletized' => true,
                    'package_type' => 'EPAL'
                ];
            }
        }


        // dd($customer_location);
        // dd($loading_locations);
        // dd($destination_locations);
        // dd($cargos);

        // $transport_numbers = join(' / ', array_filter([$truck_number, $trailer_number ?? null]));

        $data = compact(
            'customer',
            'loading_locations',
            'destination_locations',
            'attachment_filenames',
            'cargos',
            'order_reference',
            // 'transport_numbers',
            'freight_price',
            'freight_currency',
        );

        $this->createOrder($data);
    }

    private function extractCustomerAddress(array $customerData): array
    {
        $company_address = [];
        $time_interval = [];

        $contact_person = null;
        $email = null;
        $vat_code = null;
        $telephone_li = null;

        foreach ($customerData as $index => $item) {
            $item = trim($item);

            if (Str::startsWith($item, 'Contact:')) {
                $contact_person = trim(Str::after($item, 'Contact:'));
            } elseif (Str::startsWith($item, 'Telephone')) {
                $telephone_li = $index;
                $contact_person = trim(Str::replace('Telephone:', '', $item));
                $contact_person = trim(Str::replace(' ', '', $contact_person));
            }
        }
        $addressLines = array_slice($customerData, 0, $telephone_li);

        $company_address = $this->parseAddressBlock($addressLines);

        $company_address['contact_person'] = $contact_person;
        $company_address['email'] = $email;
        $company_address['vat_code'] = $vat_code;
        $company_address['vat_code'] = $vat_code;

        return [
            'company_address' => $company_address,
            'time_interval' => $time_interval
        ];
    }

    private function extractLocations(array $loadingData, bool $loading = true): array
    {
        $company_address = [];
        $time_interval = [];
        // 1️⃣ Identify key lines
        $ref_li = null;
        foreach ($loadingData as $index => $item) {
            $item = trim($item);

            if ($item === '')
                continue;

            // Detect REF line
            elseif (
                ($item === 'REF')
            ) {
                $loadingData[$index] = ''; // prevent re-processing
            } elseif (
                ($item !== 'REF') &&
                (Str::startsWith(strtoupper($item), 'REF')
                    || Str::contains(strtolower($item), 'pick up t1')
                    || Str::contains(strtolower($item), 'delivery slot will be provided soon')
                )
            ) {
                if (!isset($ref_li)) {
                    $ref_li = $index;
                }

                $comment = isset($comment)
                    ? $comment . ', ' . $item
                    : $item;

                $loadingData[$index] = ''; // prevent re-processing
            } else if (Str::contains(strtolower($item), 'pallets')) {
                if (isset($comment)) {
                    $comment .= ', ' . $item;
                } else {
                    $comment = $item;
                }
                $loadingData[$index] = ''; // prevent re-processing

            }

            // 1) Time range or single time with colon/dot or am/pm (e.g., 09:00-17:00, 9am-2pm, BOOKED-06:00 AM)
            else if (
                preg_match(
                    '/\b(\d{1,2}[:.]\d{2}\s*(?:am|pm|a\.m\.|p\.m\.)?|\d{1,2}\s*(?:am|pm|a\.m\.|p\.m\.))' .
                    '(?:\s*-\s*(\d{1,2}[:.]\d{2}\s*(?:am|pm|a\.m\.|p\.m\.)?|\d{1,2}\s*(?:am|pm|a\.m\.|p\.m\.)))?\b/i',
                    $item,
                    $matches
                )
            ) {
                // skip address-like lines
                if (preg_match('/^\d+\s+[A-Za-zÀ-ÿ]/u', $item) || preg_match('/^\d{4,5}\s+[A-Za-zÀ-ÿ]/u', $item)) {
                    // looks like "166 Chem. ..." or "95150 TAVERNY"
                } else {
                    if (!empty($matches[2])) {
                        $time_interval['datetime_from'] = $this->parseTimeRange($item, $loadingData, 'from');
                        $time_interval['datetime_to'] = $this->parseTimeRange($item, $loadingData, 'to');
                    } else {
                        $time_interval['datetime_from'] = $this->parseTimeRange($item, $loadingData, 'from');
                        $time_interval['datetime_to'] = '23:59:59';
                    }

                    $loadingData[$index] = '';
                }
            }

            // 2) Compact numeric time range (e.g., 0900-1700, 900-1730)
            else if (
                preg_match('/\b(\d{3,4})\s*-\s*(\d{3,4})\b/', $item, $m)
            ) {
                // guard: don’t touch address-like lines (though these typically won’t match because need "digits - digits")
                if (preg_match('/^\d+\s+[A-Za-zÀ-ÿ]/u', $item)) {
                    // do nothing
                } else {
                    // validate and normalize both tokens
                    $from = $this->normalizeCompactTime($m[1]); // returns "HH:MM:SS" or null
                    $to = $this->normalizeCompactTime($m[2]);

                    if ($from && $to) {
                        $time_li = $index;
                        $time_interval['datetime_from'] = $from;
                        $time_interval['datetime_to'] = $to;
                        $loadingData[$index] = '';
                    }
                    // else: ignore invalid (e.g., 2560-9999)
                }
            }

            // 3) Single time token without dash (e.g., "06:00 AM")
            else if (preg_match('/\b\d{1,2}[:.]\d{2}\s*(?:am|pm|a\.m\.|p\.m\.)\b/i', $item, $m)) {
                if (!preg_match('/^\d+\s+[A-Za-zÀ-ÿ]/u', $item)) {
                    $time_li = $index;
                    $time_interval['datetime_from'] = $this->parseTimeRange($item, $loadingData, 'from'); // will pick single time
                    $time_interval['datetime_to'] = '23:59:59';
                    $loadingData[$index] = '';
                }
            }
            // Detect "BOOKED FOR 27/06" or "BOOKED FOR 27/06/2025"
            else if (preg_match('/\bBOOKED\s*FOR\s*(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?/i', $item, $bookedMatch)) {
                // Extract parts
                $day = str_pad($bookedMatch[1], 2, '0', STR_PAD_LEFT);
                $month = str_pad($bookedMatch[2], 2, '0', STR_PAD_LEFT);
                $year = !empty($bookedMatch[3])
                    ? (strlen($bookedMatch[3]) === 2 ? ('20' . $bookedMatch[3]) : $bookedMatch[3])
                    : date('Y'); // default current year if not provided

                // Build final date
                $date = "{$year}-{$month}-{$day}";

                // Apply to time interval
                if (isset($time_interval['datetime_from'])) {
                    $time_interval['datetime_from'] = $date . 'T' . $time_interval['datetime_from'];
                } else {
                    $time_interval['datetime_from'] = $date . 'T00:00:00';
                }

                if (isset($time_interval['datetime_to'])) {
                    $time_interval['datetime_to'] = $date . 'T' . $time_interval['datetime_to'];
                } else {
                    $time_interval['datetime_to'] = $date . 'T23:59:59';
                }

                // Remove this line since it's already processed
                $loadingData[$index] = '';
            }


            // Detect date line (e.g. 12/03/2025)
            else if (preg_match('/\d{2}\/\d{2}\/\d{4}/', $item)) {
                $date = Carbon::createFromFormat('d/m/Y', $item)->format('Y-m-d');

                if (isset($time_interval['datetime_from'])) {
                    $time_interval['datetime_from'] = $date . 'T' . $time_interval['datetime_from'];
                } else {
                    $time_interval['datetime_from'] = $date . 'T00:00:00';
                }

                if (isset($time_interval['datetime_to'])) {
                    $time_interval['datetime_to'] = $date . 'T' . $time_interval['datetime_to'];
                } else {
                    $time_interval['datetime_to'] = $date . 'T23:59:59';
                }

                $loadingData[$index] = '';
            }

        }

        //  Extract address block — start from company name to before postal
        $onBlock = array_filter($loadingData, fn($v) => (trim($v) !== ''));
        $company_address = $this->parseAddressBlock($onBlock);

        //  Merge REF/comment if found
        if (isset($comment)) {
            $company_address['comment'] = $comment;
        }

        // 4️⃣ Build the location output
        $loading_locations = [

            'company_address' => $company_address,
            'time_interval' => $time_interval

        ];
        return $loading_locations;
    }

    private function normalizeCompactTime(string $t): ?string
    {
        // 900 -> 09:00, 1730 -> 17:30
        $len = strlen($t);
        if ($len === 3) {
            $h = (int) substr($t, 0, 1);
            $m = (int) substr($t, 1, 2);
        } elseif ($len === 4) {
            $h = (int) substr($t, 0, 2);
            $m = (int) substr($t, 2, 2);
        } else {
            return null;
        }

        if ($h < 0 || $h > 23 || $m < 0 || $m > 59) {
            return null;
        }

        return sprintf('%02d:%02d:00', $h, $m);
    }


    /**
     * Helper to parse time range like "0900-1700" into ISO times
     */
    private function parseTimeRange(string $timeRange, array $data, string $part): ?string
    {
        $matches = [];
        // Match any valid time range (with or without AM/PM, with or without colon)
        if (
            preg_match(
                '/\b(\d{1,4}(?:[:.]?\d{2})?\s*(?:am|pm|a\.m\.|p\.m\.)?)\s*-\s*(\d{1,4}(?:[:.]?\d{2})?\s*(?:am|pm|a\.m\.|p\.m\.)?)\b/i',
                $timeRange,
                $matches
            )
        ) {
            // Extract correct part
            $timeStr = strtolower($part === 'from' ? $matches[1] : $matches[2]);
            $timeStr = trim(str_replace('.', ':', $timeStr)); // normalize . to :

            // Handle formats like 0900 or 900 → convert to 09:00
            if (preg_match('/^\d{3,4}$/', $timeStr)) {
                if (strlen($timeStr) === 3) {
                    $timeStr = '0' . substr($timeStr, 0, 1) . ':' . substr($timeStr, 1);
                } else {
                    $timeStr = substr($timeStr, 0, 2) . ':' . substr($timeStr, 2);
                }
            }

            // Append missing minutes if only hour is given (like 9am)
            if (preg_match('/^\d{1,2}(am|pm)$/', $timeStr)) {
                $timeStr = preg_replace('/(am|pm)/', ':00$1', $timeStr);
            }

            try {
                // Parse using Carbon (automatically handles am/pm and 24-hour)
                $time = Carbon::parse($timeStr)->format('H:i:s');
                return $time;
            } catch (\Exception $e) {
                info('⚠️ Time parse failed', [
                    'input' => $timeStr,
                    'range' => $timeRange,
                    'error' => $e->getMessage()
                ]);
                return null;
            }
        }

        return null;
    }

    protected function getCurrency(string $value): ?string
    {
        // Check by ISO code first
        foreach (self::POSSIBLE_CURRENCIES as $currency) {
            if (stripos($value, $currency) !== false) {
                return $currency;
            }
        }

        // Then handle common symbols
        if (strpos($value, '€') !== false)
            return 'EUR';
        if (strpos($value, '$') !== false)
            return 'USD';
        if (strpos($value, '£') !== false)
            return 'GBP';
        if (strpos($value, 'zł') !== false)
            return 'PLN';
        if (stripos($value, 'R') !== false && preg_match('/R\s*\d/', $value))
            return 'ZAR';

        return null;
    }
    private function parseAddressBlock(array $lines): array
    {
        $lines = array_values(array_filter($lines, fn($l) => (trim($l) !== '') && ($l !== 'BOOKING') && ($l !== 'INSTRUCTION')));
        $company = trim($lines[0] ?? '');
        $postal = $city = $country = null;
        $streetParts = [];

        // Detect line containing postal pattern (ZIP)
        $postalIndex = collect($lines)->search(
            fn($line) =>
            preg_match('/[A-Z]{1,2}\d{1,2}[A-Z]?\s*\d[A-Z]{2}|[0-9]{4,6}|[A-Z]{2}-[0-9]{4,6}/i', $line)
        );
        if ($postalIndex !== false) {
            $postalLine = trim($lines[$postalIndex]);

            // 🟢 Case 1: City before postal ("Leighton Buzzard, LU7 4UH")
            if (preg_match('/^(.*?)[, ]+([A-Z]{1,2}\d{1,2}[A-Z]?\s*\d[A-Z]{2})$/i', $postalLine, $matches)) {
                $city = trim($matches[1]);
                $postal = trim($matches[2]);
            }
            // 🟢 Case 2: Postal before city ("TN25 6GE Ashford")
            else if (preg_match('/^([A-Z]{1,2}\d{1,2}[A-Z]?\s*\d[A-Z]{2})[\s,]+([A-Za-z].*)$/i', $postalLine, $matches)) {
                $postal = trim($matches[1]);
                $city = trim($matches[2]);
            }
            // 🆕 Case 3: Country-prefixed postal ("ENNERY, FR-57365")
            else if (preg_match('/^(.*?)[, ]+([A-Z]{2})[-\s]?(\d{4,6})$/i', $postalLine, $matches)) {
                $city = trim($matches[1]);
                $country = strtoupper(trim($matches[2]));
                $postal = trim($matches[3]);
            }
            // Fallback
            else {
                $city = $lines[$postalIndex - 1] ?? null;
                $postal = $postalLine;
            }
        }

        // Country detection
        foreach ($lines as $item) {
            foreach (explode(' ', $item) as $word) {
                $word = strtoupper(trim($word));

                // ✅ Match and extract prefix if in form FR-57365 or FR57365
                if (preg_match('/^([A-Z]{2})[-]?\d{4,6}$/', $word, $matches)) {
                    $code = $matches[1];
                    if (GeonamesCountry::getIso($code) !== null) {
                        $country = GeonamesCountry::getIso($code);
                        break 2; // break both loops
                    }
                }

                // ✅ Normal direct code like "FR" or "GB"
                if (GeonamesCountry::getIso($word) !== null) {
                    $country = GeonamesCountry::getIso($word);
                    break 2;
                }
            }
        }

        // Collect street lines (excluding company and city/postal)
        if (count($lines) > 3) {
            $streetParts = array_slice($lines, 1, ($postalIndex > 1 ? $postalIndex - 2 : 2));

        } else {
            if ($postalIndex === 2) {
                $streetParts = [$lines[1]];
            }
        }
        $res = [
            'company' => $company,
            'title' => $company,
            'street_address' => implode(', ', array_filter($streetParts)),
            'city' => $city ? ucwords(strtolower(trim($city))) : null,
            'postal_code' => $postal,
        ];

        if ($country !== null) {
            $res['country'] = $country;
        }

        return $res;
    }

    public function mapPackageType(string $type)
    {
        $package_type = static::PACKAGE_TYPE_MAP[$type] ?? "PALLET_OTHER";
        return trans("package_type.{$package_type}");
    }
}
