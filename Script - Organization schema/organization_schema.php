<?php

add_filter(
    'wpseo_schema_organization',
    static function ($data) {
        if (! is_array($data)) {
            return $data;
        }

        $data['@type'] = 'PlaceOfWorship';
        $data['telephone'] = '+421 902 446 688';
        $data['email'] = 'fara@mamateyka.sk';
        $data['address'] = array(
            '@type'           => 'PostalAddress',
            'streetAddress'   => 'Mamateyova 4',
            'postalCode'      => '851 04',
            'addressLocality' => 'Bratislava',
            'addressCountry'  => 'SK',
        );

        return $data;
    }
);
