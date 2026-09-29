<?php

namespace Database\Seeders;

use App\Enums\PricingType;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Region;
use App\Models\Region as RegionModel;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo data matching the client walkthrough in spec section 29.
 *
 * Every mobile number is a fake Indian number in the 90000xxxxx range.
 * With SMS_DRIVER=log the OTP for each account is written to
 * storage/logs/laravel.log, so the whole flow can be demoed without a
 * TextBee gateway phone.
 */
class DemoSeeder extends Seeder
{
    /**
     * The platform Super Admin. No business_id: they sit above the
     * tenants rather than inside one.
     */
    public const SUPER_ADMIN_MOBILE = '+919000000001';

    public function run(): void
    {
        DB::transaction(function () {
            $this->seedSuperAdmin();
            $this->seedBusiness(
                name: 'ABC Laundry',
                slug: 'ABC',
                owner: 'Anil Sharma',
                ownerMobile: '+919000000010',
                branchLimit: 7,
                charges: ['pickup' => 50, 'delivery' => 40],
                regions: ['Kothrud', 'Karve Nagar', 'Bavdhan', 'Baner', 'Aundh'],
            );

            // A second client with a tighter limit, so cross-tenant
            // isolation and limit enforcement can both be demonstrated.
            $this->seedBusiness(
                name: 'XYZ Laundry',
                slug: 'XYZ',
                owner: 'Priya Nair',
                ownerMobile: '+919000000020',
                branchLimit: 2,
                charges: ['pickup' => 30, 'delivery' => 25],
                regions: ['Camp', 'Koregaon Park'],
            );
        });
    }

    private function seedSuperAdmin(): void
    {
        User::query()->updateOrCreate(
            ['mobile' => self::SUPER_ADMIN_MOBILE],
            [
                'business_id' => null,
                'branch_id' => null,
                'name' => 'Platform Super Admin',
                'role' => Role::SUPER_ADMIN,
                'status' => 'ACTIVE',
            ]
        );
    }

    /**
     * @param  array{pickup: int, delivery: int}  $charges
     * @param  list<string>  $regions
     */
    private function seedBusiness(
        string $name,
        string $slug,
        string $owner,
        string $ownerMobile,
        int $branchLimit,
        array $charges,
        array $regions,
    ): Business {
        // Keyed on the slug so re-running the seeder updates the demo
        // laundry instead of creating a duplicate tenant.
        $business = Business::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'business_name' => $name,
                'owner_name' => $owner,
                'mobile' => $ownerMobile,
                'branch_limit' => $branchLimit,
                'status' => 'ACTIVE',
                'pickup_charge' => $charges['pickup'],
                'delivery_charge' => $charges['delivery'],
                'email' => strtolower(str_replace(' ', '', $name)).'@example.test',
                'address' => 'Pune, Maharashtra',
                'working_hours_start' => '08:00:00',
                'working_hours_end' => '21:00:00',
                'notification_templates' => Business::defaultTemplates(),
            ]
        );

        $this->seedOwner($business, $owner, $ownerMobile);
        $this->seedServices($business);
        $this->seedRegions($business, $regions);

        // Two branches for the first client, one for the second, so the
        // branch limit is visibly partly used in the demo.
        $branches = $this->seedBranches($business, min(2, max(1, $branchLimit)));
        $this->seedStaff($business, $branches, $regions);
        $this->seedCustomers($business);

        return $business;
    }

    private function seedOwner(Business $business, string $owner, string $mobile): User
    {
        return User::query()->create([
            'business_id' => $business->id,
            'branch_id' => null,
            'name' => $owner,
            'mobile' => $mobile,
            'role' => Role::ADMIN,
            'status' => 'ACTIVE',
        ]);
    }

    /**
     * @return list<Branch>
     */
    private function seedBranches(Business $business, int $count): array
    {
        $names = ['Main Branch', 'Kothrud Branch', 'Baner Branch', 'Camp Branch'];
        $branches = [];

        for ($i = 0; $i < $count; $i++) {
            $branches[] = Branch::query()->create([
                'business_id' => $business->id,
                'name' => $names[$i] ?? 'Branch '.($i + 1),
                'code' => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $business->business_name), 0, 2)).($i + 1),
                'phone' => '+9191000000'.($i + 1),
                'address' => $names[$i] ?? 'Branch '.($i + 1).', Pune',
                'status' => 'ACTIVE',
            ]);
        }

        return $branches;
    }

    private function seedRegions(Business $business, array $names): void
    {
        foreach ($names as $name) {
            RegionModel::query()->create([
                'business_id' => $business->id,
                'name' => $name,
                'status' => 'ACTIVE',
            ]);
        }
    }

    /**
     * @param  list<Branch>  $branches
     * @param  list<string>  $regionNames
     */
    private function seedStaff(Business $business, array $branches, array $regionNames): void
    {
        $first = $branches[0];

        User::query()->create([
            'business_id' => $business->id,
            'branch_id' => $first->id,
            'name' => 'Branch Manager',
            'mobile' => '+9192000000'.(string) $business->id.'1',
            'role' => Role::BRANCH,
            'status' => 'ACTIVE',
        ]);

        $pickup = User::query()->create([
            'business_id' => $business->id,
            'branch_id' => $first->id,
            'name' => 'Ravi Pickup',
            'mobile' => '+9193000000'.(string) $business->id.'1',
            'role' => Role::PICKUP_BOY,
            'status' => 'ACTIVE',
        ]);

        $delivery = User::query()->create([
            'business_id' => $business->id,
            'branch_id' => $first->id,
            'name' => 'Suresh Delivery',
            'mobile' => '+9194000000'.(string) $business->id.'1',
            'role' => Role::DELIVERY_BOY,
            'status' => 'ACTIVE',
        ]);

        // Map the field staff onto the first three zones, demonstrating the
        // many-to-many region assignment from spec section 17.
        $pickup->regions()->sync(
            RegionModel::query()
                ->where('business_id', $business->id)
                ->orderBy('id')
                ->limit(3)
                ->pluck('id')
        );

        $delivery->regions()->sync(
            RegionModel::query()
                ->where('business_id', $business->id)
                ->orderBy('id')
                ->limit(2)
                ->pluck('id')
        );
    }

    private function seedServices(Business $business): void
    {
        $services = [
            ['Washing & Folding', PricingType::KG, 90.00],
            ['Dry Cleaning', PricingType::KG, 220.00],
            ['Wash & Iron', PricingType::KG, 110.00],
            ['Blanket Cleaning', PricingType::KG, 180.00],
            ['Shoe Cleaning', PricingType::PIECE, 350.00],
            ['Bag Cleaning', PricingType::PIECE, 250.00],
            ['Comforter Cleaning', PricingType::PIECE, 450.00],
            ['Ironing Only', PricingType::PIECE, 25.00],
        ];

        foreach ($services as [$name, $type, $rate]) {
            Service::query()->create([
                'business_id' => $business->id,
                'name' => $name,
                'pricing_type' => $type,
                'rate' => $rate,
                'status' => 'ACTIVE',
            ]);
        }
    }

    private function seedCustomers(Business $business): void
    {
        $base = (int) $business->id * 100;

        $people = [
            ['Meera Joshi', '+9151000000'.$base.'1', 'Kothrud, Pune', '411038'],
            ['Aditya Desai', '+9151000000'.$base.'2', 'Karve Nagar, Pune', '411052'],
            ['Fatima Sheikh', '+9151000000'.$base.'3', 'Bavdhan, Pune', '411045'],
        ];

        foreach ($people as [$name, $mobile, $line, $pincode]) {
            $customer = Customer::query()->create([
                'business_id' => $business->id,
                'name' => $name,
                'mobile' => $mobile,
                'status' => 'ACTIVE',
            ]);

            $customer->addresses()->create([
                'label' => 'Home',
                'address_line' => $line,
                'city' => 'Pune',
                'pincode' => $pincode,
                'is_default' => true,
            ]);
        }
    }
}
