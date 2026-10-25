<?php

namespace App\Exceptions;

use RuntimeException;

/** Biznes qoidasi buzilganda (masalan, qoldiq yetarli emas). Controller buni 422 javobga aylantiradi. */
class BusinessRuleException extends RuntimeException
{
}
