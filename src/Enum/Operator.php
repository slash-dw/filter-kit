<?php

namespace SlashDw\FilterKit\Enum;

enum Operator: string
{
    case EQ = '=';
    case NEQ = '!=';    // alias: '<>'
    case GT = '>';
    case LT = '<';
    case GTE = '>=';
    case LTE = '<=';
    case LIKE = 'like';
    case ILIKE = 'ilike';
    case STARTS_WITH = 'starts_with';
    case ENDS_WITH = 'ends_with';
    case IN = 'in';
    case NOT_IN = 'not_in';
    case BETWEEN = 'between';
    case NOT_BETWEEN = 'not_between';
    case IS_NULL = 'null';
    case NOT_NULL = 'not_null';
}
