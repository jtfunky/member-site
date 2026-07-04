<?php
/**
 * Country list (ISO code, name, international dial code) for the mobile-number
 * field. The flag emoji is derived from the ISO code at render time via
 * flagEmoji(), so it isn't stored here.
 */

function getCountries(): array {
    return [
        ['iso' => 'AF', 'name' => 'Afghanistan',          'dial' => '+93'],
        ['iso' => 'AL', 'name' => 'Albania',              'dial' => '+355'],
        ['iso' => 'DZ', 'name' => 'Algeria',              'dial' => '+213'],
        ['iso' => 'AR', 'name' => 'Argentina',            'dial' => '+54'],
        ['iso' => 'AU', 'name' => 'Australia',            'dial' => '+61'],
        ['iso' => 'AT', 'name' => 'Austria',              'dial' => '+43'],
        ['iso' => 'BH', 'name' => 'Bahrain',              'dial' => '+973'],
        ['iso' => 'BD', 'name' => 'Bangladesh',           'dial' => '+880'],
        ['iso' => 'BE', 'name' => 'Belgium',              'dial' => '+32'],
        ['iso' => 'BR', 'name' => 'Brazil',               'dial' => '+55'],
        ['iso' => 'BN', 'name' => 'Brunei',               'dial' => '+673'],
        ['iso' => 'KH', 'name' => 'Cambodia',             'dial' => '+855'],
        ['iso' => 'CA', 'name' => 'Canada',               'dial' => '+1'],
        ['iso' => 'CL', 'name' => 'Chile',                'dial' => '+56'],
        ['iso' => 'CN', 'name' => 'China',                'dial' => '+86'],
        ['iso' => 'CO', 'name' => 'Colombia',             'dial' => '+57'],
        ['iso' => 'CZ', 'name' => 'Czech Republic',       'dial' => '+420'],
        ['iso' => 'DK', 'name' => 'Denmark',              'dial' => '+45'],
        ['iso' => 'EC', 'name' => 'Ecuador',              'dial' => '+593'],
        ['iso' => 'EG', 'name' => 'Egypt',                'dial' => '+20'],
        ['iso' => 'FI', 'name' => 'Finland',              'dial' => '+358'],
        ['iso' => 'FR', 'name' => 'France',               'dial' => '+33'],
        ['iso' => 'DE', 'name' => 'Germany',              'dial' => '+49'],
        ['iso' => 'GR', 'name' => 'Greece',               'dial' => '+30'],
        ['iso' => 'HK', 'name' => 'Hong Kong',            'dial' => '+852'],
        ['iso' => 'HU', 'name' => 'Hungary',              'dial' => '+36'],
        ['iso' => 'IN', 'name' => 'India',                'dial' => '+91'],
        ['iso' => 'ID', 'name' => 'Indonesia',            'dial' => '+62'],
        ['iso' => 'IR', 'name' => 'Iran',                 'dial' => '+98'],
        ['iso' => 'IQ', 'name' => 'Iraq',                 'dial' => '+964'],
        ['iso' => 'IE', 'name' => 'Ireland',              'dial' => '+353'],
        ['iso' => 'IL', 'name' => 'Israel',               'dial' => '+972'],
        ['iso' => 'IT', 'name' => 'Italy',                'dial' => '+39'],
        ['iso' => 'JP', 'name' => 'Japan',                'dial' => '+81'],
        ['iso' => 'JO', 'name' => 'Jordan',               'dial' => '+962'],
        ['iso' => 'KE', 'name' => 'Kenya',                'dial' => '+254'],
        ['iso' => 'KW', 'name' => 'Kuwait',               'dial' => '+965'],
        ['iso' => 'LA', 'name' => 'Laos',                 'dial' => '+856'],
        ['iso' => 'LB', 'name' => 'Lebanon',              'dial' => '+961'],
        ['iso' => 'MY', 'name' => 'Malaysia',             'dial' => '+60'],
        ['iso' => 'MV', 'name' => 'Maldives',             'dial' => '+960'],
        ['iso' => 'MX', 'name' => 'Mexico',               'dial' => '+52'],
        ['iso' => 'MM', 'name' => 'Myanmar',              'dial' => '+95'],
        ['iso' => 'NP', 'name' => 'Nepal',                'dial' => '+977'],
        ['iso' => 'NL', 'name' => 'Netherlands',          'dial' => '+31'],
        ['iso' => 'NZ', 'name' => 'New Zealand',          'dial' => '+64'],
        ['iso' => 'NG', 'name' => 'Nigeria',              'dial' => '+234'],
        ['iso' => 'NO', 'name' => 'Norway',               'dial' => '+47'],
        ['iso' => 'OM', 'name' => 'Oman',                 'dial' => '+968'],
        ['iso' => 'PK', 'name' => 'Pakistan',             'dial' => '+92'],
        ['iso' => 'PH', 'name' => 'Philippines',          'dial' => '+63'],
        ['iso' => 'PL', 'name' => 'Poland',               'dial' => '+48'],
        ['iso' => 'PT', 'name' => 'Portugal',             'dial' => '+351'],
        ['iso' => 'QA', 'name' => 'Qatar',                'dial' => '+974'],
        ['iso' => 'RO', 'name' => 'Romania',              'dial' => '+40'],
        ['iso' => 'RU', 'name' => 'Russia',               'dial' => '+7'],
        ['iso' => 'SA', 'name' => 'Saudi Arabia',         'dial' => '+966'],
        ['iso' => 'SG', 'name' => 'Singapore',            'dial' => '+65'],
        ['iso' => 'ZA', 'name' => 'South Africa',         'dial' => '+27'],
        ['iso' => 'KR', 'name' => 'South Korea',          'dial' => '+82'],
        ['iso' => 'ES', 'name' => 'Spain',                'dial' => '+34'],
        ['iso' => 'LK', 'name' => 'Sri Lanka',            'dial' => '+94'],
        ['iso' => 'SE', 'name' => 'Sweden',               'dial' => '+46'],
        ['iso' => 'CH', 'name' => 'Switzerland',          'dial' => '+41'],
        ['iso' => 'TW', 'name' => 'Taiwan',               'dial' => '+886'],
        ['iso' => 'TH', 'name' => 'Thailand',             'dial' => '+66'],
        ['iso' => 'TR', 'name' => 'Turkey',               'dial' => '+90'],
        ['iso' => 'UA', 'name' => 'Ukraine',              'dial' => '+380'],
        ['iso' => 'AE', 'name' => 'United Arab Emirates', 'dial' => '+971'],
        ['iso' => 'GB', 'name' => 'United Kingdom',       'dial' => '+44'],
        ['iso' => 'US', 'name' => 'United States',        'dial' => '+1'],
        ['iso' => 'VN', 'name' => 'Vietnam',              'dial' => '+84'],
    ];
}

// Unicode regional-indicator flag from a 2-letter ISO code (e.g. PH -> 🇵🇭).
function flagEmoji(string $iso): string {
    $iso = strtoupper(trim($iso));
    if (strlen($iso) !== 2 || !ctype_alpha($iso) || !function_exists('mb_chr')) return '';
    return mb_chr(0x1F1E6 + ord($iso[0]) - 65) . mb_chr(0x1F1E6 + ord($iso[1]) - 65);
}

// Dial code for an ISO country code, or '' if unknown.
function dialByIso(string $iso): string {
    $iso = strtoupper($iso);
    foreach (getCountries() as $c) {
        if ($c['iso'] === $iso) return $c['dial'];
    }
    return '';
}

// Country name for an ISO country code, or '' if unknown.
function nameByIso(string $iso): string {
    $iso = strtoupper($iso);
    foreach (getCountries() as $c) {
        if ($c['iso'] === $iso) return $c['name'];
    }
    return '';
}
