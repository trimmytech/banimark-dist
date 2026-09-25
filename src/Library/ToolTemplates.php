<?php

namespace Banimark\Library;

/**
 * Ready-made tools the owner can add from the Tools page, all calling FREE
 * PUBLIC APIs with no key - so they work the moment they are added and show
 * how a tool is put together (an address with {placeholders}, the path to the
 * rows, the fields the AI may see, identity scoping). No database tools: we
 * cannot know the owner's tables.
 *
 * Each installed template is an ordinary HTTP tool the owner can open and
 * edit, marked with its template slug (`tools.template`) so the licence rule
 * for templates can count it: templates may use at most HALF of the plan's
 * tool allowance (Licensing\Entitlements::templateAllowance).
 *
 * Every definition here is proven against the live API by the test suite's
 * fixtures and must pass HttpTool::fromDefinition unchanged.
 */
final class ToolTemplates
{
    /** A polite identity: Wikipedia, Open Food Facts and CoinGecko ask every client to send one (CoinGecko answers 403 without it). */
    private const UA = ['User-Agent' => 'BanimarkDesk/1.0 (+https://banimark.com)'];

    /**
     * @return array<string, array{group:string, title:string, about:string, api:string, terms:string, try:array, tool:array}>
     */
    public static function all(): array
    {
        return [
            'weather_find_place' => [
                'group' => 'Weather (two tools that work together)',
                'title' => 'Find a place',
                'about' => 'Turns a town name into coordinates. The weather tool needs them - the AI calls this first, then the forecast. A good example of two tools working as a pair.',
                'api' => 'Open-Meteo geocoding',
                'terms' => 'Free, no key. Non-commercial use under 10,000 calls a day; a commercial site needs their paid plan.',
                'try' => ['place' => 'Lagos'],
                'tool' => [
                    'name' => 'find_place',
                    'description' => 'Find a town or city by name and get its coordinates (latitude and longitude), country and time zone. Use it before get_weather.',
                    'parameters' => ['place' => ['type' => 'string', 'description' => 'The town or city name, e.g. Lagos', 'required' => true]],
                    'max_rows' => 3,
                    'config' => [
                        'method' => 'GET',
                        'url' => 'https://geocoding-api.open-meteo.com/v1/search?name={place}&count=3',
                        'path' => 'results',
                        'fields' => ['name', 'admin1', 'country', 'latitude', 'longitude', 'timezone'],
                    ],
                ],
            ],
            'weather_now' => [
                'group' => 'Weather (two tools that work together)',
                'title' => 'Weather and 3-day forecast',
                'about' => 'Current temperature, humidity and wind, and the next three days, for a latitude and longitude. Shows how nested answers become fields like "current.temperature_2m".',
                'api' => 'Open-Meteo forecast',
                'terms' => 'Free, no key. Non-commercial use under 10,000 calls a day; a commercial site needs their paid plan.',
                'try' => ['latitude' => '6.45', 'longitude' => '3.39'],
                'tool' => [
                    'name' => 'get_weather',
                    'description' => 'Current weather and a 3-day forecast for a place, given its latitude and longitude (get them with find_place). Temperatures are in °C, wind in km/h.',
                    'parameters' => [
                        'latitude' => ['type' => 'number', 'description' => 'Latitude from find_place', 'required' => true],
                        'longitude' => ['type' => 'number', 'description' => 'Longitude from find_place', 'required' => true],
                    ],
                    'max_rows' => 1,
                    'config' => [
                        'method' => 'GET',
                        'url' => 'https://api.open-meteo.com/v1/forecast?latitude={latitude}&longitude={longitude}&current=temperature_2m,relative_humidity_2m,wind_speed_10m,weather_code&daily=temperature_2m_max,temperature_2m_min,precipitation_probability_max&forecast_days=3&timezone=auto',
                        'path' => '',
                        'fields' => [
                            'timezone', 'current.time', 'current.temperature_2m', 'current.relative_humidity_2m', 'current.wind_speed_10m', 'current.weather_code',
                            'daily.time.0', 'daily.temperature_2m_max.0', 'daily.temperature_2m_min.0', 'daily.precipitation_probability_max.0',
                            'daily.time.1', 'daily.temperature_2m_max.1', 'daily.temperature_2m_min.1', 'daily.precipitation_probability_max.1',
                            'daily.time.2', 'daily.temperature_2m_max.2', 'daily.temperature_2m_min.2', 'daily.precipitation_probability_max.2',
                        ],
                    ],
                ],
            ],
            'exchange_rate' => [
                'group' => 'Money',
                'title' => 'Exchange rates',
                'about' => 'Today\'s rates from one currency to the major ones, Naira included. Shows the field list at work: the API returns 160 currencies, the AI sees only the ones listed.',
                'api' => 'ExchangeRate-API (open access)',
                'terms' => 'Free, no key, updated once a day. Their terms ask for a link back to exchangerate-api.com where rates are shown.',
                'try' => ['currency' => 'USD'],
                'tool' => [
                    'name' => 'exchange_rates',
                    'description' => 'Today\'s exchange rates from one currency (a 3-letter code such as USD, GBP, EUR or NGN) to NGN, USD, EUR, GBP, GHS, KES, ZAR, CAD, CNY and AED. Rates are indicative and updated daily.',
                    'parameters' => ['currency' => ['type' => 'string', 'description' => 'The 3-letter code of the currency to convert FROM, e.g. USD', 'required' => true]],
                    'max_rows' => 1,
                    'config' => [
                        'method' => 'GET',
                        'url' => 'https://open.er-api.com/v6/latest/{currency}',
                        'path' => '',
                        'fields' => ['base_code', 'time_last_update_utc', 'rates.NGN', 'rates.USD', 'rates.EUR', 'rates.GBP', 'rates.GHS', 'rates.KES', 'rates.ZAR', 'rates.CAD', 'rates.CNY', 'rates.AED'],
                    ],
                ],
            ],
            'crypto_price' => [
                'group' => 'Money',
                'title' => 'Cryptocurrency price',
                'about' => 'The price of a coin in Naira, with its 24-hour change.',
                'api' => 'CoinGecko',
                'terms' => 'Free public API with a low rate limit (a few calls a minute). Their terms ask for attribution to CoinGecko.',
                'try' => ['coin' => 'bitcoin'],
                'tool' => [
                    'name' => 'crypto_price',
                    'description' => 'Current price in Nigerian Naira of a cryptocurrency, by its CoinGecko id (bitcoin, ethereum, tether, solana, binancecoin...), with the 24-hour change in percent. Prices move constantly - say they are indicative.',
                    'parameters' => ['coin' => ['type' => 'string', 'description' => 'CoinGecko coin id in lowercase, e.g. bitcoin', 'required' => true]],
                    'max_rows' => 1,
                    'config' => [
                        'method' => 'GET',
                        'url' => 'https://api.coingecko.com/api/v3/coins/markets?vs_currency=ngn&ids={coin}',
                        'headers' => self::UA, // CoinGecko refuses a request that does not say who it is
                        'path' => '',
                        'fields' => ['name', 'symbol', 'current_price', 'price_change_percentage_24h', 'high_24h', 'low_24h', 'last_updated'],
                    ],
                ],
            ],
            'public_holidays' => [
                'group' => 'Calendar',
                'title' => 'Public holidays',
                'about' => 'Public holidays for a country and year - useful for "are you open on…" and delivery questions.',
                'api' => 'Nager.Date',
                'terms' => 'Free and open source, no key. Covers over 100 countries, Nigeria included.',
                'try' => ['year' => '2026', 'country' => 'NG'],
                'tool' => [
                    'name' => 'public_holidays',
                    'description' => 'The public holidays of a country in a given year. The country is a 2-letter code (NG for Nigeria, GH for Ghana, GB for the UK, US...).',
                    'parameters' => [
                        'year' => ['type' => 'integer', 'description' => 'The year, e.g. 2026', 'required' => true],
                        'country' => ['type' => 'string', 'description' => '2-letter country code, e.g. NG', 'required' => true],
                    ],
                    'max_rows' => 25,
                    'config' => [
                        'method' => 'GET',
                        'url' => 'https://date.nager.at/api/v3/PublicHolidays/{year}/{country}',
                        'path' => '',
                        'fields' => ['date', 'name', 'localName'],
                    ],
                ],
            ],
            'demo_products' => [
                'group' => 'Practice shop (fake data)',
                'title' => 'Search products',
                'about' => 'Searches a pretend shop. Use it to practise: this is exactly how a tool on YOUR shop\'s API would look.',
                'api' => 'DummyJSON',
                'terms' => 'Free test data for learning - not a real shop. Remove this tool before going live.',
                'try' => ['query' => 'phone'],
                'tool' => [
                    'name' => 'demo_search_products',
                    'description' => 'PRACTICE DATA: search the demo shop\'s products by keyword, with price (USD), stock and rating. Tell the customer this is a demo catalogue.',
                    'parameters' => ['query' => ['type' => 'string', 'description' => 'What to search for, e.g. phone', 'required' => true]],
                    'max_rows' => 5,
                    'config' => [
                        'method' => 'GET',
                        'url' => 'https://dummyjson.com/products/search?q={query}&limit=5&select=title,price,stock,rating,category',
                        'path' => 'products',
                        'fields' => ['id', 'title', 'price', 'stock', 'rating', 'category'],
                    ],
                ],
            ],
            'demo_order' => [
                'group' => 'Practice shop (fake data)',
                'title' => 'Look up an order by number',
                'about' => 'Looks up one pretend order (numbers 1 to 50). Shows fields inside a list: "products.0.title" is the first item.',
                'api' => 'DummyJSON',
                'terms' => 'Free test data for learning - not a real shop. Remove this tool before going live.',
                'try' => ['order' => '1'],
                'tool' => [
                    'name' => 'demo_order_lookup',
                    'description' => 'PRACTICE DATA: look up a demo order by its number (1 to 50) - totals and the first items. Tell the customer this is demo data.',
                    'parameters' => ['order' => ['type' => 'integer', 'description' => 'The order number, 1 to 50', 'required' => true]],
                    'max_rows' => 1,
                    'config' => [
                        'method' => 'GET',
                        'url' => 'https://dummyjson.com/carts/{order}',
                        'path' => '',
                        'fields' => ['id', 'totalProducts', 'totalQuantity', 'total', 'discountedTotal',
                            'products.0.title', 'products.0.quantity', 'products.0.price',
                            'products.1.title', 'products.1.quantity', 'products.1.price',
                            'products.2.title', 'products.2.quantity', 'products.2.price'],
                    ],
                ],
            ],
            'demo_my_orders' => [
                'group' => 'Practice shop (fake data)',
                'title' => 'My orders (signed-in customer)',
                'about' => 'The important one: it only answers for a SIGNED-IN visitor, using the user_id your site signs into their token - the AI cannot ask for someone else\'s orders. For a guest it politely refuses.',
                'api' => 'DummyJSON',
                'terms' => 'Free test data for learning - not a real shop. Remove this tool before going live.',
                'try' => [],
                'tool' => [
                    'name' => 'demo_my_orders',
                    'description' => 'PRACTICE DATA: the signed-in customer\'s own demo orders. Takes no input - who the customer is comes from their login.',
                    'parameters' => [],
                    'context' => ['user_id'],
                    'max_rows' => 5,
                    'config' => [
                        'method' => 'GET',
                        'url' => 'https://dummyjson.com/carts/user/{_user_id}',
                        'path' => 'carts',
                        'fields' => ['id', 'totalProducts', 'total', 'discountedTotal'],
                    ],
                ],
            ],
            'wikipedia' => [
                'group' => 'Knowledge',
                'title' => 'Wikipedia summary',
                'about' => 'A short summary of a topic from Wikipedia. Shows a custom header: Wikipedia asks every client to say who it is.',
                'api' => 'Wikipedia REST API',
                'terms' => 'Free, no key. Text is CC BY-SA; the answer includes the page link for attribution.',
                'try' => ['topic' => 'Lagos'],
                'tool' => [
                    'name' => 'wikipedia_summary',
                    'description' => 'A short Wikipedia summary of a topic (a person, place or thing). Use the exact page title where possible, with underscores for spaces.',
                    'parameters' => ['topic' => ['type' => 'string', 'description' => 'The Wikipedia page title, e.g. Lagos or Nollywood', 'required' => true]],
                    'max_rows' => 1,
                    'config' => [
                        'method' => 'GET',
                        'url' => 'https://en.wikipedia.org/api/rest_v1/page/summary/{topic}',
                        'headers' => self::UA,
                        'path' => '',
                        'fields' => ['title', 'description', 'extract', 'content_urls.desktop.page'],
                    ],
                ],
            ],
            'food_barcode' => [
                'group' => 'Knowledge',
                'title' => 'Food product by barcode',
                'about' => 'Name, brand, allergens and size of a packaged food from its barcode.',
                'api' => 'Open Food Facts',
                'terms' => 'Free, open data (ODbL), no key. Coverage of local products varies.',
                'try' => ['barcode' => '3017624010701'],
                'tool' => [
                    'name' => 'food_by_barcode',
                    'description' => 'Look up a packaged food by its barcode: name, brand, size, allergens and Nutri-Score. Do not give medical or allergy advice from it - tell the customer to check the label.',
                    'parameters' => ['barcode' => ['type' => 'string', 'description' => 'The barcode digits', 'required' => true]],
                    'max_rows' => 1,
                    'config' => [
                        'method' => 'GET',
                        'url' => 'https://world.openfoodfacts.org/api/v2/product/{barcode}?fields=product_name,brands,quantity,allergens,nutriscore_grade',
                        'headers' => self::UA,
                        'path' => 'product',
                        'fields' => ['product_name', 'brands', 'quantity', 'allergens', 'nutriscore_grade'],
                    ],
                ],
            ],
        ];
    }

    public static function get(string $slug): ?array
    {
        return self::all()[$slug] ?? null;
    }

    /** The definition HttpTool / ToolFactory take - one place builds it. */
    public static function definition(string $slug): ?array
    {
        $t = self::get($slug);
        if ($t === null) {
            return null;
        }
        $tool = $t['tool'];
        return [
            'name' => $tool['name'],
            'description' => $tool['description'],
            'parameters' => $tool['parameters'],
            'context' => $tool['context'] ?? [],
            'max_rows' => $tool['max_rows'] ?? 10,
            'kind' => 'http',
            'config' => $tool['config'] + ['method' => 'GET', 'headers' => [], 'auth' => ['type' => 'none'], 'body' => '', 'path' => '', 'fields' => []],
        ];
    }
}
