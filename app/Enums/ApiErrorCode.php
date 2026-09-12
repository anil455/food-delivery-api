<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Machine-readable error codes returned in the `code` field.
 *
 * The Next.js client switches on these — never on message strings, which are
 * translatable and may change.
 */
enum ApiErrorCode: string
{
    // Generic
    case ValidationError = 'VALIDATION_ERROR';
    case Unauthenticated = 'UNAUTHENTICATED';
    case Forbidden = 'FORBIDDEN';
    case NotFound = 'NOT_FOUND';
    case MethodNotAllowed = 'METHOD_NOT_ALLOWED';
    case TooManyRequests = 'TOO_MANY_REQUESTS';
    case ServerError = 'SERVER_ERROR';
    case Conflict = 'CONFLICT';

    // Auth / OTP
    case OtpExpired = 'OTP_EXPIRED';
    case OtpInvalid = 'OTP_INVALID';
    case OtpMaxAttempts = 'OTP_MAX_ATTEMPTS';
    case OtpResendCooldown = 'OTP_RESEND_COOLDOWN';
    case OtpNotFound = 'OTP_NOT_FOUND';
    case PhoneInvalid = 'PHONE_INVALID';
    case AccountDisabled = 'ACCOUNT_DISABLED';
    case TwoFactorRequired = 'TWO_FACTOR_REQUIRED';
    case TwoFactorInvalid = 'TWO_FACTOR_INVALID';

    // Geo
    case GeocodeFailed = 'GEOCODE_FAILED';

    // Tenancy
    case TenantContextMissing = 'TENANT_CONTEXT_MISSING';
    case RestaurantNotAccessible = 'RESTAURANT_NOT_ACCESSIBLE';

    // Store / delivery
    case RestaurantClosed = 'RESTAURANT_CLOSED';
    case RestaurantNotAcceptingOrders = 'RESTAURANT_NOT_ACCEPTING_ORDERS';
    case OutsideDeliveryRadius = 'OUTSIDE_DELIVERY_RADIUS';
    case NoStoreSelected = 'NO_STORE_SELECTED';

    // Cart / order
    case CartRestaurantMismatch = 'CART_RESTAURANT_MISMATCH';
    case CartEmpty = 'CART_EMPTY';
    case ProductUnavailable = 'PRODUCT_UNAVAILABLE';
    case MinOrderNotMet = 'MIN_ORDER_NOT_MET';
    case InvalidStatusTransition = 'INVALID_STATUS_TRANSITION';
    case CouponInvalid = 'COUPON_INVALID';
}
