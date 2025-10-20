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
                // $order_reference = trim($item[$index + 2]);
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

            } else if ($item === 'Collection') {
                $loading_li[] = $index;
            } else if ($item === 'Clearance' || $item === 'Delivery') {
                $destination_li[] = $index;
            } else if (Str::startsWith($item, '- Payment')) {
                $destination_end_li = $index;
            }
        }
        $customer_location_data = array_slice($lines, 0, $ref_li);
        if ($customer_location_data[0] !== $company_name) {
            array_unshift($customer_location_data, $company_name);
        }
        // $customer_location = $this->extractCustomerAddress($customer_location_data);

        // foreach ($loading_li as $index => $loadingLi) {

        //     $end_li = $loading_li[$index + 1] ?? $destination_li[0];
        //     $loading_locations[] = $this->extractLocations(
        //         array_slice($lines, $loadingLi + 2, $end_li - $loadingLi - 3)
        //     );
        // }
        foreach ($destination_li as $index => $destinationLi) {

            $end_li = $destination_li[$index + 1] ?? $destination_end_li;
            $destination_locations[] = $this->extractLocations(
                array_slice($lines, $destinationLi + 1, $end_li - $destinationLi - 2)
            );
        }

        dd($destination_locations);
        // $destination_location_data = array_slice($lines, $destination_li + 1, $observation_li - $destination_li);
        // $destination_locations = $this->extractLocations(
        //     $destination_location_data
        // );

        // $cargos[] = $this->getCargoData(
        //     $destination_location_data
        // );

        // $transport_numbers = join(' / ', array_filter([$truck_number, $trailer_number ?? null]));

        // $data = compact(
        //     'customer',
        //     'loading_locations',
        //     'destination_locations',
        //     'attachment_filenames',
        //     'cargos',
        //     'order_reference',
        //     'transport_numbers',
        //     'freight_price',
        //     'freight_currency',
        // );

        // $this->createOrder($data);
    }

    private function getCargoData(array $cargoData): array
    {
        $cargos = ['package_type' => 'EPAL'];
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
            }
        }
        // If we didn’t find a Telephone line, take all non-empty lines
        $addressLines = array_slice($customerData, 0, $telephone_li);

        $company_address = $this->parseAddressBlock($addressLines);

        $company_address['contact_person'] = $contact_person;
        $company_address['email'] = $email;
        $company_address['vat_code'] = $vat_code;

        return [
            'company_address' => $company_address,
            'time_interval' => $time_interval
        ];
    }

    private function extractLocations(array $loadingData): array
    {
        $company_address = [];
        $time_interval = [];
        // 1️⃣ Identify key lines
        $ref_li = null;
        $date_li = null;
        $time_li = null;
        foreach ($loadingData as $index => $item) {
            $item = trim($item);

            if ($item === '')
                continue;

            // Detect REF line
            elseif (
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
            }

            // Detect time range (e.g. 0900-1700)
            else if (preg_match('/\d{2}[:.]?\d{2}\s*-\s*\d{2}[:.]?\d{2}/', $item)) {
                $time_li = $index;
                $time_interval['datetime_from'] = $this->parseTimeRange($item, $loadingData, 'from');
                $time_interval['datetime_to'] = $this->parseTimeRange($item, $loadingData, 'to');
                $loadingData[$index] = '';
            }

            // Detect date line
            else if (preg_match('/\d{2}\/\d{2}\/\d{4}/', $item)) {
                $date_li = $index;
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
        // 2️⃣ Extract address block — start from company name to before postal
        $onBlock = array_filter($loadingData, fn($v) => (trim($v) !== '' && trim($v) !== 'REF'));
        $company_address = $this->parseAddressBlock($onBlock);

        // 3️⃣ Merge REF/comment if found
        if (isset($comment)) {
            $company_address['comment'] = $comment;
        }

        // 4️⃣ Build the location output
        $loading_locations = [
            [
                'company_address' => $company_address,
                'time_interval' => $time_interval
            ]
        ];
        return $loading_locations;
    }

    /**
     * Helper to parse time range like "0900-1700" into ISO times
     */
    private function parseTimeRange(string $timeRange, array $data, string $part): ?string
    {
        $matches = [];
        if (preg_match('/(\d{2}[:.]?\d{2})\s*-\s*(\d{2}[:.]?\d{2})/', $timeRange, $matches)) {
            $from = $matches[1];
            $to = $matches[2];

            $from = strlen($from) === 4 ? substr($from, 0, 2) . ':' . substr($from, 2, 2) : $from;
            $to = strlen($to) === 4 ? substr($to, 0, 2) . ':' . substr($to, 2, 2) : $to;

            return $part === 'from' ? $from . ':00' : $to . ':00';
        }
        return null;
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

    // public function extractLocations(array $lines)
    // {
    //     $index = 0;
    //     $location_size = 6;
    //     $output = [];
    //     while ($index < count($lines)) {
    //         $location_lines = array_slice($lines, $index, $location_size);
    //         $output[] = $this->extractLocation($location_lines);
    //         $index += $location_size;
    //     }
    //     return $output;
    // }

    public function extractLocation(array $lines)
    {
        $datetime = $lines[2];
        $location = $lines[4];

        return [
            'company_address' => $this->parseAddressBlock($location),
            'time' => $this->parseDateTime($datetime),
        ];
    }

    public function parseDateTime(string $datetime)
    {
        preg_match('/^([0-9\.]+) ?([0-9:]+)?-?([0-9:]+)?$/', $datetime, $matches);
        if ($matches) {
            $date_start = $matches[1];
            if ($matches[2] ?? null) {
                $date_start .= " " . $matches[2];
            }
            $date_start = Carbon::parse($date_start)->toIsoString();

            $date_end = $matches[1];
            if ($matches[3] ?? null) {
                $date_end .= " " . $matches[3];
            }
            $date_end = Carbon::parse($date_end)->toIsoString();
        }

        $output = [
            'datetime_from' => $date_start ?? null,
            'datetime_to' => $date_end ?? null,
        ];

        if ($output['datetime_from'] == $output['datetime_to']) {
            unset($output['datetime_to']);
        }

        return $output;
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

    public function parseCompanyAddress(string $location): array
    {
        // Split and clean
        $parts = array_map('trim', explode(',', $location));
        $company = $parts[0] ?? null;
        $street = $parts[1] ?? null;
        $region = count($parts) > 3 ? $parts[2] : null;
        $lastPart = end($parts);

        $country = $postal = $city = null;

        // 🇬🇧 UK-style (GB-SS17 9DY STANFORD)
        if (preg_match('/^(?<country>[A-Z]{2})[- ](?<postal>[A-Z0-9 ]+)\s+(?<city>[A-Z][A-Z\s]+)$/i', trim($lastPart), $m)) {
            $country = preg_replace('/[^A-Z]/ui', '', strtoupper($m['country']));
            $postal = trim($m['postal']);
            $city = ucwords(strtolower(trim($m['city'])));
        }
        // 🇫🇷 France-style (-37530 POCE-SUR-CISSE)
        elseif (preg_match('/^-?(?<postal>\d{4,6})\s+(?<city>[A-ZÀ-ÿ\- ]+)$/i', trim($lastPart), $m)) {
            $country = 'FR';
            $postal = $m['postal'];
            $city = ucwords(strtolower(trim($m['city'])));
        }

        // Merge region if relevant
        if ($region && $city && strcasecmp($region, $city) !== 0) {
            $city = "{$region}, {$city}";
        }

        return [
            'company' => $company,
            'title' => $company,
            'street_address' => $street,
            'city' => $city,
            'postal_code' => $postal,
            'country' => $country,
            'company_code' => null,
            'vat_code' => null,
            'email' => null,
            'contact_person' => null,
        ];
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

        dd($city);
        // Country detection
        foreach ($lines as $item) {
            foreach (explode(' ', $item) as $word) {
                if (GeonamesCountry::getIso(strtoupper($word)) !== null) {
                    $country = GeonamesCountry::getIso(strtoupper($word));
                    break;
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
    public function extractCargos(array $lines)
    {
        $load_li = array_find_key($lines, fn($l) => $l == "Load:");
        $title = $lines[$load_li + 1];

        $amount_li = array_find_key($lines, fn($l) => $l == "Amount:");
        $package_count = $lines[$amount_li + 1]
            ? uncomma($lines[$amount_li + 1])
            : null;

        $unit_li = array_find_key($lines, fn($l) => $l == "Unit:");
        $package_type = $this->mapPackageType($lines[$unit_li + 1]);

        $weight_li = array_find_key($lines, fn($l) => $l == "Weight:");
        $weight = $lines[$weight_li + 1]
            ? uncomma($lines[$weight_li + 1])
            : null;

        $ldm_li = array_find_key($lines, callback: fn($l) => $l == "Loadingmeter:");
        $ldm = $lines[$ldm_li + 1]
            ? uncomma($lines[$ldm_li + 1])
            : null;

        $load_ref_li = array_find_key($lines, fn($l) => Str::startsWith($l, "Loading reference:"));
        $load_ref = $load_ref_li
            ? explode(': ', $lines[$load_ref_li], 2)[1] ?? null
            : null;

        $unload_ref_li = array_find_key($lines, fn($l) => Str::startsWith($l, "Unloading reference:"));
        $unload_ref = $unload_ref_li
            ? explode(': ', $lines[$unload_ref_li], 2)[1] ?? null
            : null;

        $number = join('; ', array_filter([$load_ref, $unload_ref]));

        return [
            [
                'title' => $title,
                'number' => $number,
                'package_count' => $package_count ?? 1,
                'package_type' => $package_type,
                'ldm' => $ldm,
                'weight' => $weight,
            ]
        ];
    }

    public function mapPackageType(string $type)
    {
        $package_type = static::PACKAGE_TYPE_MAP[$type] ?? "PALLET_OTHER";
        return trans("package_type.{$package_type}");
    }
}
