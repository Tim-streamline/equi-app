<?php

return [
    /*
     * Questions shown through grouped visibility rules ($all, $any, $not, gte) are
     * hidden by app versions released before 27 October 2026. While such versions
     * are still in use, the server does not let these questions block submission;
     * the current app still requires them. Enable once every client is updated.
     */
    'require_grouped_conditions' => (bool) env('INTAKE_REQUIRE_GROUPED_CONDITIONS', false),
];
