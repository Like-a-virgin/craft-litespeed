<?php

namespace GlueAgency\LiteSpeed\enums;

/**
 * What LiteSpeed puts in `$_SERVER`.
 */
enum ServerVariable: string
{
    /**
     * The cache features the server supports, e.g. `on,crawler,esi`.
     */
    case LSCACHE = 'X-LSCACHE';
}
