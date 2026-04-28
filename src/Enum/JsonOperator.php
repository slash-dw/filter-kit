<?php

namespace SlashDw\FilterKit\Enum;

/**
 * JSON column filtering operators.
 *
 * Special operators for PostgreSQL JSON/JSONB columns.
 * These operators are used with the JsonFilterable attribute.
 */
enum JsonOperator: string
{
    case EQ = 'json_eq';
    case NEQ = 'json_neq';
    case GT = 'json_gt';
    case LT = 'json_lt';
    case GTE = 'json_gte';
    case LTE = 'json_lte';
    case LIKE = 'json_like';
    case ILIKE = 'json_ilike';
    case IN = 'json_in';
    case NOT_IN = 'json_not_in';
    case IS_NULL = 'json_is_null';
    case NOT_NULL = 'json_not_null';
    case STARTS_WITH = 'json_starts_with';
    case ENDS_WITH = 'json_ends_with';
}
