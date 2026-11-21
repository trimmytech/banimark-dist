<?php

namespace Banimark\Http;

/**
 * What can be known about a visitor from their own browser and request,
 * turned into short, human lines for the staff Visitor card.
 *
 * Everything here is derived ON THIS SERVER from signals the visitor's own
 * client already reveals - the User-Agent header, and the page URL, referrer,
 * language and timezone the widget reports. Nothing is looked up from an
 * outside service, so a self-hosted desk's promise ("your data never leaves
 * your server") holds: country, for instance, comes from the visitor's
 * timezone via PHP's built-in zone database, not from an IP geolocation call.
 *
 * All methods are pure and static so they are trivially testable and carry no
 * request state of their own.
 */
final class Context
{
    /** Longest we keep any single captured string. */
    public const URL_MAX = 500;
    public const SHORT_MAX = 255;

    /**
     * Clean the raw context the widget sent into exactly the columns we store.
     * Unknown keys are dropped; every value is length-capped; a URL that is not
     * http(s) is discarded rather than stored. Returns only non-empty fields.
     *
     * @param array $ctx   ['url','title','referrer','locale','tz'] from the client
     * @param string $ua   the User-Agent header (server-side)
     * @return array<string,string> column => value, ready for PdoStore::setContext
     */
    public static function clean(array $ctx, string $ua = ''): array
    {
        $out = [];
        if (($u = self::url((string) ($ctx['url'] ?? ''))) !== '') {
            $out['visitor_page_url'] = $u;
        }
        if (($t = self::text((string) ($ctx['title'] ?? ''), self::SHORT_MAX)) !== '') {
            $out['visitor_page_title'] = $t;
        }
        if (($r = self::url((string) ($ctx['referrer'] ?? ''))) !== '') {
            $out['visitor_referrer'] = $r;
        }
        if (($l = self::locale((string) ($ctx['locale'] ?? ''))) !== '') {
            $out['visitor_locale'] = $l;
        }
        if (($z = self::tz((string) ($ctx['tz'] ?? ''))) !== '') {
            $out['visitor_tz'] = $z;
        }
        if (($a = self::text($ua, self::SHORT_MAX)) !== '') {
            $out['visitor_ua'] = $a;
        }
        return $out;
    }

    /** A plain string, stripped of tags and control characters, length-capped. */
    public static function text(string $s, int $max): string
    {
        $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($s)) ?? '';
        return mb_substr(trim($s), 0, $max);
    }

    /** Keep only an http(s) URL; anything else becomes ''. */
    public static function url(string $s): string
    {
        $s = trim($s);
        if ($s === '' || !preg_match('#^https?://#i', $s)) {
            return '';
        }
        return mb_substr($s, 0, self::URL_MAX);
    }

    /** A BCP-47-ish language tag: letters, digits and hyphens only. */
    public static function locale(string $s): string
    {
        $s = trim($s);
        return preg_match('/^[A-Za-z0-9\-]{2,40}$/', $s) ? $s : '';
    }

    /** An IANA timezone we can actually resolve; '' otherwise. */
    public static function tz(string $s): string
    {
        $s = trim($s);
        if (!preg_match('#^[A-Za-z0-9_+\-/]{3,64}$#', $s)) {
            return '';
        }
        try {
            new \DateTimeZone($s);
            return $s;
        } catch (\Throwable $e) {
            return '';
        }
    }

    // ---- display: turn the stored raw signals into the staff-facing lines ----

    /**
     * A short device line from the User-Agent, e.g. "iPhone · Safari",
     * "Windows · Chrome", "Android · Samsung Internet". '' when we cannot tell.
     */
    public static function device(string $ua): string
    {
        $ua = trim($ua);
        if ($ua === '') {
            return '';
        }

        // operating system / hardware, most specific first
        $os = '';
        if (preg_match('/iPhone/i', $ua)) {
            $os = 'iPhone';
        } elseif (preg_match('/iPad/i', $ua)) {
            $os = 'iPad';
        } elseif (preg_match('/Android/i', $ua)) {
            $os = 'Android';
        } elseif (preg_match('/CrOS/i', $ua)) {
            $os = 'ChromeOS';
        } elseif (preg_match('/Windows NT/i', $ua)) {
            $os = 'Windows';
        } elseif (preg_match('/Mac OS X|Macintosh/i', $ua)) {
            $os = 'Mac';
        } elseif (preg_match('/Linux/i', $ua)) {
            $os = 'Linux';
        }

        // browser - order matters: Edge/Samsung/Opera masquerade as Chrome,
        // and Chrome's token also appears in Safari's UA
        $browser = '';
        if (preg_match('/Edg(?:A|iOS)?\//i', $ua)) {
            $browser = 'Edge';
        } elseif (preg_match('/SamsungBrowser\//i', $ua)) {
            $browser = 'Samsung Internet';
        } elseif (preg_match('/OPR\/|Opera/i', $ua)) {
            $browser = 'Opera';
        } elseif (preg_match('/Firefox\/|FxiOS/i', $ua)) {
            $browser = 'Firefox';
        } elseif (preg_match('/Chrome\/|CriOS/i', $ua)) {
            $browser = 'Chrome';
        } elseif (preg_match('/Safari\//i', $ua)) {
            $browser = 'Safari';
        }

        $parts = array_values(array_filter([$os, $browser]));
        return $parts === [] ? '' : implode(' · ', $parts);
    }

    /** The visitor's language in words, e.g. "en-US" -> "English (United States)". */
    public static function language(string $locale): string
    {
        $locale = trim($locale);
        if ($locale === '') {
            return '';
        }
        $bits = preg_split('/[-_]/', $locale);
        $lang = strtolower($bits[0] ?? '');
        $region = isset($bits[1]) ? strtoupper($bits[1]) : '';
        $name = self::LANGUAGES[$lang] ?? ($lang !== '' ? strtoupper($lang) : '');
        if ($name === '') {
            return $locale;
        }
        $country = $region !== '' ? (self::COUNTRIES[$region] ?? $region) : '';
        return $country !== '' ? $name.' ('.$country.')' : $name;
    }

    /** Country name inferred from the visitor's timezone, with a locale fallback. */
    public static function country(string $tz, string $locale = ''): string
    {
        $code = '';
        $tz = trim($tz);
        if ($tz !== '') {
            try {
                $loc = (new \DateTimeZone($tz))->getLocation();
                $code = is_array($loc) ? strtoupper((string) ($loc['country_code'] ?? '')) : '';
            } catch (\Throwable $e) {
                $code = '';
            }
        }
        if (($code === '' || $code === '??') && $locale !== '') {
            $bits = preg_split('/[-_]/', trim($locale));
            if (isset($bits[1]) && preg_match('/^[A-Za-z]{2}$/', $bits[1])) {
                $code = strtoupper($bits[1]);
            }
        }
        if ($code === '' || $code === '??') {
            return '';
        }
        return self::COUNTRIES[$code] ?? $code;
    }

    /**
     * The visitor's current local time from their timezone, e.g. "14:32, Fri".
     * '' when we have no usable zone. Computed fresh each view, never stored.
     */
    public static function localTime(string $tz, ?int $now = null): string
    {
        $tz = trim($tz);
        if ($tz === '') {
            return '';
        }
        try {
            $dt = new \DateTime('@'.($now ?? time()));
            $dt->setTimezone(new \DateTimeZone($tz));
            return $dt->format('H:i, D');
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** The bare host of a referrer, e.g. "https://www.google.com/x" -> "google.com". */
    public static function host(string $url): string
    {
        $h = (string) (parse_url(trim($url), PHP_URL_HOST) ?? '');
        return $h === '' ? '' : preg_replace('/^www\./i', '', $h);
    }

    /** ISO-3166 alpha-2 -> English short name. Offline, so no intl dependency. */
    private const COUNTRIES = [
        'AD' => 'Andorra', 'AE' => 'United Arab Emirates', 'AF' => 'Afghanistan', 'AG' => 'Antigua & Barbuda',
        'AI' => 'Anguilla', 'AL' => 'Albania', 'AM' => 'Armenia', 'AO' => 'Angola', 'AR' => 'Argentina',
        'AS' => 'American Samoa', 'AT' => 'Austria', 'AU' => 'Australia', 'AW' => 'Aruba', 'AZ' => 'Azerbaijan',
        'BA' => 'Bosnia & Herzegovina', 'BB' => 'Barbados', 'BD' => 'Bangladesh', 'BE' => 'Belgium',
        'BF' => 'Burkina Faso', 'BG' => 'Bulgaria', 'BH' => 'Bahrain', 'BI' => 'Burundi', 'BJ' => 'Benin',
        'BM' => 'Bermuda', 'BN' => 'Brunei', 'BO' => 'Bolivia', 'BR' => 'Brazil', 'BS' => 'Bahamas',
        'BT' => 'Bhutan', 'BW' => 'Botswana', 'BY' => 'Belarus', 'BZ' => 'Belize', 'CA' => 'Canada',
        'CD' => 'DR Congo', 'CF' => 'Central African Republic', 'CG' => 'Congo', 'CH' => 'Switzerland',
        'CI' => "Côte d'Ivoire", 'CL' => 'Chile', 'CM' => 'Cameroon', 'CN' => 'China', 'CO' => 'Colombia',
        'CR' => 'Costa Rica', 'CU' => 'Cuba', 'CV' => 'Cape Verde', 'CY' => 'Cyprus', 'CZ' => 'Czechia',
        'DE' => 'Germany', 'DJ' => 'Djibouti', 'DK' => 'Denmark', 'DM' => 'Dominica', 'DO' => 'Dominican Republic',
        'DZ' => 'Algeria', 'EC' => 'Ecuador', 'EE' => 'Estonia', 'EG' => 'Egypt', 'ER' => 'Eritrea',
        'ES' => 'Spain', 'ET' => 'Ethiopia', 'FI' => 'Finland', 'FJ' => 'Fiji', 'FM' => 'Micronesia',
        'FO' => 'Faroe Islands', 'FR' => 'France', 'GA' => 'Gabon', 'GB' => 'United Kingdom', 'GD' => 'Grenada',
        'GE' => 'Georgia', 'GF' => 'French Guiana', 'GG' => 'Guernsey', 'GH' => 'Ghana', 'GI' => 'Gibraltar',
        'GL' => 'Greenland', 'GM' => 'Gambia', 'GN' => 'Guinea', 'GP' => 'Guadeloupe', 'GQ' => 'Equatorial Guinea',
        'GR' => 'Greece', 'GT' => 'Guatemala', 'GU' => 'Guam', 'GW' => 'Guinea-Bissau', 'GY' => 'Guyana',
        'HK' => 'Hong Kong', 'HN' => 'Honduras', 'HR' => 'Croatia', 'HT' => 'Haiti', 'HU' => 'Hungary',
        'ID' => 'Indonesia', 'IE' => 'Ireland', 'IL' => 'Israel', 'IM' => 'Isle of Man', 'IN' => 'India',
        'IQ' => 'Iraq', 'IR' => 'Iran', 'IS' => 'Iceland', 'IT' => 'Italy', 'JE' => 'Jersey', 'JM' => 'Jamaica',
        'JO' => 'Jordan', 'JP' => 'Japan', 'KE' => 'Kenya', 'KG' => 'Kyrgyzstan', 'KH' => 'Cambodia',
        'KI' => 'Kiribati', 'KM' => 'Comoros', 'KN' => 'St Kitts & Nevis', 'KP' => 'North Korea',
        'KR' => 'South Korea', 'KW' => 'Kuwait', 'KY' => 'Cayman Islands', 'KZ' => 'Kazakhstan', 'LA' => 'Laos',
        'LB' => 'Lebanon', 'LC' => 'St Lucia', 'LI' => 'Liechtenstein', 'LK' => 'Sri Lanka', 'LR' => 'Liberia',
        'LS' => 'Lesotho', 'LT' => 'Lithuania', 'LU' => 'Luxembourg', 'LV' => 'Latvia', 'LY' => 'Libya',
        'MA' => 'Morocco', 'MC' => 'Monaco', 'MD' => 'Moldova', 'ME' => 'Montenegro', 'MG' => 'Madagascar',
        'MH' => 'Marshall Islands', 'MK' => 'North Macedonia', 'ML' => 'Mali', 'MM' => 'Myanmar',
        'MN' => 'Mongolia', 'MO' => 'Macao', 'MP' => 'Northern Mariana Islands', 'MQ' => 'Martinique',
        'MR' => 'Mauritania', 'MS' => 'Montserrat', 'MT' => 'Malta', 'MU' => 'Mauritius', 'MV' => 'Maldives',
        'MW' => 'Malawi', 'MX' => 'Mexico', 'MY' => 'Malaysia', 'MZ' => 'Mozambique', 'NA' => 'Namibia',
        'NC' => 'New Caledonia', 'NE' => 'Niger', 'NG' => 'Nigeria', 'NI' => 'Nicaragua', 'NL' => 'Netherlands',
        'NO' => 'Norway', 'NP' => 'Nepal', 'NR' => 'Nauru', 'NZ' => 'New Zealand', 'OM' => 'Oman',
        'PA' => 'Panama', 'PE' => 'Peru', 'PF' => 'French Polynesia', 'PG' => 'Papua New Guinea',
        'PH' => 'Philippines', 'PK' => 'Pakistan', 'PL' => 'Poland', 'PR' => 'Puerto Rico', 'PS' => 'Palestine',
        'PT' => 'Portugal', 'PW' => 'Palau', 'PY' => 'Paraguay', 'QA' => 'Qatar', 'RE' => 'Réunion',
        'RO' => 'Romania', 'RS' => 'Serbia', 'RU' => 'Russia', 'RW' => 'Rwanda', 'SA' => 'Saudi Arabia',
        'SB' => 'Solomon Islands', 'SC' => 'Seychelles', 'SD' => 'Sudan', 'SE' => 'Sweden', 'SG' => 'Singapore',
        'SI' => 'Slovenia', 'SK' => 'Slovakia', 'SL' => 'Sierra Leone', 'SM' => 'San Marino', 'SN' => 'Senegal',
        'SO' => 'Somalia', 'SR' => 'Suriname', 'SS' => 'South Sudan', 'ST' => 'São Tomé & Príncipe',
        'SV' => 'El Salvador', 'SY' => 'Syria', 'SZ' => 'Eswatini', 'TC' => 'Turks & Caicos Islands',
        'TD' => 'Chad', 'TG' => 'Togo', 'TH' => 'Thailand', 'TJ' => 'Tajikistan', 'TL' => 'Timor-Leste',
        'TM' => 'Turkmenistan', 'TN' => 'Tunisia', 'TO' => 'Tonga', 'TR' => 'Türkiye', 'TT' => 'Trinidad & Tobago',
        'TW' => 'Taiwan', 'TZ' => 'Tanzania', 'UA' => 'Ukraine', 'UG' => 'Uganda', 'US' => 'United States',
        'UY' => 'Uruguay', 'UZ' => 'Uzbekistan', 'VA' => 'Vatican City', 'VC' => 'St Vincent & Grenadines',
        'VE' => 'Venezuela', 'VG' => 'British Virgin Islands', 'VI' => 'US Virgin Islands', 'VN' => 'Vietnam',
        'VU' => 'Vanuatu', 'WS' => 'Samoa', 'XK' => 'Kosovo', 'YE' => 'Yemen', 'YT' => 'Mayotte',
        'ZA' => 'South Africa', 'ZM' => 'Zambia', 'ZW' => 'Zimbabwe',
    ];

    /** Primary language subtag -> English name. Common tags; unknown falls back to the tag. */
    private const LANGUAGES = [
        'ar' => 'Arabic', 'bg' => 'Bulgarian', 'bn' => 'Bengali', 'cs' => 'Czech', 'da' => 'Danish',
        'de' => 'German', 'el' => 'Greek', 'en' => 'English', 'es' => 'Spanish', 'et' => 'Estonian',
        'fa' => 'Persian', 'fi' => 'Finnish', 'fil' => 'Filipino', 'fr' => 'French', 'ha' => 'Hausa',
        'he' => 'Hebrew', 'hi' => 'Hindi', 'hr' => 'Croatian', 'hu' => 'Hungarian', 'id' => 'Indonesian',
        'ig' => 'Igbo', 'it' => 'Italian', 'ja' => 'Japanese', 'ko' => 'Korean', 'lt' => 'Lithuanian',
        'lv' => 'Latvian', 'ms' => 'Malay', 'nl' => 'Dutch', 'no' => 'Norwegian', 'pl' => 'Polish',
        'pt' => 'Portuguese', 'ro' => 'Romanian', 'ru' => 'Russian', 'sk' => 'Slovak', 'sl' => 'Slovenian',
        'sr' => 'Serbian', 'sv' => 'Swedish', 'sw' => 'Swahili', 'ta' => 'Tamil', 'th' => 'Thai',
        'tr' => 'Turkish', 'uk' => 'Ukrainian', 'ur' => 'Urdu', 'vi' => 'Vietnamese', 'yo' => 'Yoruba',
        'zh' => 'Chinese', 'zu' => 'Zulu',
    ];
}
