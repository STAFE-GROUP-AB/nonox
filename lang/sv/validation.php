<?php

declare(strict_types=1);

/*
 * Swedish validation messages. Rules missing here fall back to English
 * (app.fallback_locale). Add a rule here when the app starts using it.
 */

return [
    'accepted' => 'Du behöver godkänna :attribute.',
    'after' => ':attribute måste vara ett datum efter :date.',
    'after_or_equal' => ':attribute måste vara :date eller senare.',
    'alpha_dash' => ':attribute får bara innehålla bokstäver, siffror, bindestreck och understreck.',
    'array' => ':attribute måste vara en lista.',
    'before' => ':attribute måste vara ett datum före :date.',
    'before_or_equal' => ':attribute måste vara :date eller tidigare.',
    'between' => [
        'array' => ':attribute måste ha mellan :min och :max delar.',
        'file' => ':attribute måste vara mellan :min och :max kilobyte.',
        'numeric' => ':attribute måste vara mellan :min och :max.',
        'string' => ':attribute måste vara mellan :min och :max tecken.',
    ],
    'boolean' => ':attribute måste vara ja eller nej.',
    'confirmed' => ':attribute stämmer inte med bekräftelsen.',
    'current_password' => 'Lösenordet är fel.',
    'date' => ':attribute är inte ett giltigt datum.',
    'date_format' => ':attribute måste ha formatet :format.',
    'decimal' => ':attribute måste ha :decimal decimaler.',
    'different' => ':attribute och :other måste vara olika.',
    'digits' => ':attribute måste vara :digits siffror.',
    'digits_between' => ':attribute måste vara mellan :min och :max siffror.',
    'email' => ':attribute måste vara en giltig e-postadress.',
    'enum' => 'Välj ett giltigt alternativ för :attribute.',
    'exists' => 'Det valda värdet för :attribute finns inte.',
    'file' => ':attribute måste vara en fil.',
    'filled' => ':attribute måste fyllas i.',
    'gt' => [
        'numeric' => ':attribute måste vara större än :value.',
    ],
    'gte' => [
        'numeric' => ':attribute måste vara :value eller större.',
    ],
    'image' => ':attribute måste vara en bild.',
    'in' => 'Välj ett giltigt alternativ för :attribute.',
    'integer' => ':attribute måste vara ett heltal.',
    'lt' => [
        'numeric' => ':attribute måste vara mindre än :value.',
    ],
    'lte' => [
        'numeric' => ':attribute måste vara :value eller mindre.',
    ],
    'max' => [
        'array' => ':attribute får ha högst :max delar.',
        'file' => ':attribute får vara högst :max kilobyte.',
        'numeric' => ':attribute får vara högst :max.',
        'string' => ':attribute får vara högst :max tecken.',
    ],
    'mimes' => ':attribute måste vara en fil av typen :values.',
    'min' => [
        'array' => ':attribute måste ha minst :min delar.',
        'file' => ':attribute måste vara minst :min kilobyte.',
        'numeric' => ':attribute måste vara minst :min.',
        'string' => ':attribute måste vara minst :min tecken.',
    ],
    'not_in' => 'Välj ett giltigt alternativ för :attribute.',
    'numeric' => ':attribute måste vara ett tal.',
    'regex' => ':attribute har fel format.',
    'required' => 'Fyll i :attribute.',
    'required_if' => 'Fyll i :attribute när :other är :value.',
    'required_with' => 'Fyll i :attribute när :values är ifyllt.',
    'same' => ':attribute och :other måste vara lika.',
    'size' => [
        'numeric' => ':attribute måste vara :size.',
        'string' => ':attribute måste vara :size tecken.',
    ],
    'string' => ':attribute måste vara text.',
    'unique' => ':attribute används redan.',
    'url' => ':attribute måste vara en giltig webbadress.',

    'custom' => [],

    'attributes' => [
        'name' => 'namn',
        'email' => 'e-postadress',
        'role' => 'roll',
        'city' => 'ort',
        'postal_code' => 'postnummer',
        'address_line1' => 'adress',
        'phone' => 'telefonnummer',
    ],
];
