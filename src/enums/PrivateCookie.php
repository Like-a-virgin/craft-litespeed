<?php

namespace GlueAgency\LiteSpeed\enums;

/**
 * LiteSpeed keys private cache entries on the cookies in a fixed list. Craft's session cookie isn't on it,
 * `lsc_private` is.
 */
enum PrivateCookie: string
{
    case IDENTITY = 'lsc_private';
}
