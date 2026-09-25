<?php
declare(strict_types=1);
namespace App\Core;

/**
 * Thrown by CheckoutController::place() when the atomic stock-reservation
 * UPDATE affects zero rows -- i.e. another order (or another browser tab)
 * already took the remaining stock between add-to-cart and checkout.
 * Caught separately from generic checkout failures so the customer gets a
 * specific, actionable message instead of "Order failed, try again".
 */
final class InsufficientStockException extends \RuntimeException
{
}
