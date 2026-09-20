<?php

$year = env('RESULTS_YEAR');

$years = array_map(
    'intval',
    explode(',', env('ELECTION_YEARS', ''))
);

$config = [
    'results_url'  => env('RESULTS_URL'),
    'results_year' => $year,
];

foreach ($years as $year) {
    $config["results_url_{$year}"] = env("RESULTS_URL_{$year}");
}

return $config;
