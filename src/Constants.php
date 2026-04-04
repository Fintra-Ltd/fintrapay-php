<?php

declare(strict_types=1);

namespace FintraPay;

/**
 * Constants for the FintraPay SDK.
 *
 * Supported blockchains, tokens, token availability per chain,
 * invoice statuses, payout reasons, and Earn durations.
 */
final class Constants
{
    /** Supported blockchains. */
    public const CHAINS = [
        'tron',
        'bsc',
        'ethereum',
        'solana',
        'base',
        'arbitrum',
        'polygon',
    ];

    /** Supported stablecoin tokens. */
    public const TOKENS = [
        'USDT',
        'USDC',
        'DAI',
        'FDUSD',
        'TUSD',
        'PYUSD',
    ];

    /** Token availability per chain. */
    public const TOKEN_CHAINS = [
        'USDT' => ['tron', 'bsc', 'ethereum', 'solana', 'base', 'arbitrum', 'polygon'],
        'USDC' => ['bsc', 'ethereum', 'solana', 'base', 'arbitrum', 'polygon'],
        'DAI'  => ['bsc', 'ethereum', 'base', 'arbitrum', 'polygon'],
        'FDUSD' => ['bsc', 'ethereum'],
        'TUSD' => ['tron', 'bsc', 'ethereum'],
        'PYUSD' => ['ethereum', 'solana'],
    ];

    /** Invoice statuses. */
    public const INVOICE_PENDING        = 'pending';
    public const INVOICE_AWAITING       = 'awaiting_selection';
    public const INVOICE_PAID           = 'paid';
    public const INVOICE_CONFIRMED      = 'confirmed';
    public const INVOICE_EXPIRED        = 'expired';
    public const INVOICE_PARTIALLY_PAID = 'partially_paid';

    public const INVOICE_STATUSES = [
        self::INVOICE_PENDING,
        self::INVOICE_AWAITING,
        self::INVOICE_PAID,
        self::INVOICE_CONFIRMED,
        self::INVOICE_EXPIRED,
        self::INVOICE_PARTIALLY_PAID,
    ];

    /** Valid payout reason values. */
    public const PAYOUT_REASONS = [
        'payment',
        'refund',
        'reward',
        'airdrop',
        'salary',
        'other',
    ];

    /** Earn durations: months => APY%. */
    public const EARN_DURATIONS = [
        1  => 3.0,
        3  => 5.0,
        6  => 7.0,
        12 => 10.0,
    ];

    /** @codeCoverageIgnore */
    private function __construct()
    {
        // Prevent instantiation.
    }
}
