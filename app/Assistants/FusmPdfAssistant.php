<?php

namespace App\Assistants;

use App\GeonamesCountry;
use Carbon\Carbon;
use Illuminate\Support\Str;

class FusmPdfAssistant extends PdfClient
{
    const POSSIBLE_CURRENCIES = ["EUR", "USD", "GBP", "PLN", "ZAR"];

    const PACKAGE_TYPE_MAP = [
        "EW-Paletten" => "PALLET_OTHER",
        "Ladung" => "CARTON",
        "Stück" => "OTHER",
    ];

    public static function validateFormat(array $lines)
    {
        return $lines[0] == "Date/Time :"
            && $lines[4] == "CHARTERING CONFIRMATION";
    }

    public function processLines(array $lines, ?string $attachment_filename = null)
    {
        $attachment_filenames = [mb_strtolower($attachment_filename ?? '')];
        $truck_number = null;
        $trailer_number = null;
        $company_name = 'TRANSALLIANCE TS LTD';
        $customer = [
            'side' => 'none',
            'details' => [
                'company' => $lines[14],
                'street_address' => $lines[24] . ', ' . $lines[25]
            ]
        ];
        $cargos = [];

        $loading_li = null;
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
            }
        }
        $customer_location_data = array_slice($lines, $vat_li + 1, $ref_li - $vat_li);
        if ($customer_location_data[0] !== $company_name) {
            array_unshift($customer_location_data, $company_name);
        }
        $customer_location = $this->extractCustomerAddress($customer_location_data);

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

        $transport_numbers = join(' / ', array_filter([$truck_number, $trailer_number ?? null]));


        //     'details' => [
        //         'street_address' => 'Amerling 130',
        //         'city' => 'Kramsach',
        //         'postal_code' => '6233',
        //         'country' => 'AT',
        //         'vat_code' => 'ATU74076812',
        //         'contact_person' => $contact,
        //     ],



        // $truck_li = array_find_key($lines, fn($l) => $l == "Truck, trailer:");
        // $truck_number = $lines[$truck_li + 2];

        // $vehicle_li = array_find_key($lines, fn($l) => $l == "Vehicle type:");
        // if ($truck_li && $vehicle_li) {
        //     $trailer_li = array_find_key($lines, fn($l, $i) => $i > $truck_li && $i < $vehicle_li && preg_match('/^[A-Z]{2}[0-9]{3}( |$)/', $l));
        //     $trailer_number = explode(' ', $lines[$trailer_li], 2)[0] ?? null;
        // }

        // $transport_numbers = join(' / ', array_filter([$truck_number, $trailer_number ?? null]));

        // $freight_li = array_find_key($lines, fn($l) => $l == "Freight rate in €:");
        // $freight_price = $lines[$freight_li + 2];
        // $freight_price = preg_replace('/[^0-9,\.]/', '', $freight_price);
        // $freight_price = uncomma($freight_price);
        // $freight_currency = 'EUR';

        // $loading_li = array_find_key($lines, fn($l) => $l == "Loading sequence:");
        // $unloading_li = array_find_key($lines, fn($l) => $l == "Unloading sequence:");
        // $regards_li = array_find_key($lines, fn($l) => $l == "Best regards");

        // $loading_locations = $this->extractLocations(
        //     array_slice($lines, $loading_li + 1, $unloading_li - 1 - $loading_li)
        // );

        // $destination_locations = $this->extractLocations(
        //     array_slice($lines, $unloading_li + 1, $regards_li - 1 - $unloading_li)
        // );

        // $contact_li = array_find_key($lines, fn($l) => Str::startsWith($l, 'Contactperson: '));
        // $contact = explode(': ', $lines[$contact_li], 2)[1];

        // $customer = [
        //     'side' => 'none',
        //     'details' => [
        //         'company' => 'Access Logistic GmbH',
        //         'street_address' => 'Amerling 130',
        //         'city' => 'Kramsach',
        //         'postal_code' => '6233',
        //         'country' => 'AT',
        //         'vat_code' => 'ATU74076812',
        //         'contact_person' => $contact,
        //     ],
        // ];

        // $cargos = $this->extractCargos($lines);

        // $attachment_filenames = [mb_strtolower($attachment_filename ?? '')];

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
        );

        $this->createOrder($data);
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
            } else if ($this->isDateTimeString(($item))) {
                if (isset($time_interval['datetime_from'])) {
                    $time_interval['datetime_to'] = $item;
                } else {
                    $time_interval['datetime_from'] = $item;
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
            $country = strtoupper($m['country'] ?? '');
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
