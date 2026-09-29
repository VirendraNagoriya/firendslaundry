<?php

namespace App\Enums;

use App\Enums\Concerns\EnumeratesValues;

enum Role: string
{
    use EnumeratesValues;

    case SUPER_ADMIN = 'SUPER_ADMIN';
    case ADMIN = 'ADMIN';
    case BRANCH = 'BRANCH';
    case PICKUP_BOY = 'PICKUP_BOY';
    case DELIVERY_BOY = 'DELIVERY_BOY';
    case CUSTOMER = 'CUSTOMER';

    public function label(): string
    {
        return match ($this) {
            self::SUPER_ADMIN => 'Super Admin',
            self::ADMIN => 'Admin / Owner',
            self::BRANCH => 'Branch',
            self::PICKUP_BOY => 'Pickup Boy',
            self::DELIVERY_BOY => 'Delivery Boy',
            self::CUSTOMER => 'Customer',
        };
    }

    /**
     * Roles that belong to a tenant business (everything except the
     * platform-level Super Admin, whose business_id is null).
     */
    public function isTenantRole(): bool
    {
        return $this !== self::SUPER_ADMIN;
    }

    /**
     * Roles that operate out of a single branch.
     */
    public function isBranchScoped(): bool
    {
        return in_array($this, [self::BRANCH, self::PICKUP_BOY, self::DELIVERY_BOY], true);
    }

    /**
     * Roles permitted to move an order through its lifecycle.
     */
    public function canChangeOrderStatus(): bool
    {
        return in_array($this, [
            self::SUPER_ADMIN,
            self::ADMIN,
            self::BRANCH,
            self::PICKUP_BOY,
            self::DELIVERY_BOY,
        ], true);
    }

    /**
     * @return list<self>
     */
    public static function tenantRoles(): array
    {
        return array_values(array_filter(self::cases(), fn (self $r) => $r->isTenantRole()));
    }

    /**
     * @return list<self>
     */
    public static function employeeRoles(): array
    {
        return [self::BRANCH, self::PICKUP_BOY, self::DELIVERY_BOY];
    }
}
