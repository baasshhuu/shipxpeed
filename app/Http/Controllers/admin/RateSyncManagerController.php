<?php

namespace App\Http\Controllers\admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Models\SellerList;
use App\Models\ZonePriceSetting;
use App\Models\ActicvSleb;

/**
 * RateSyncManagerController
 * -------------------------
 * One simple tab to manage zone-wise rates + active/inactive status for ALL couriers
 * of a given client (seller) in a single screen.
 *
 * IMPORTANT (why this "syncs everywhere"):
 * It writes to the SAME two tables the pricing engine already reads:
 *   - zone_price_Settings (ZonePriceSetting) -> the zone-wise rate card
 *   - zone_price          (ActicvSleb)       -> which couriers are active for a seller
 * Because the rate card, rate calculator (RateCardController::checkRate),
 * serviceability and the order-shipping charge calc (ServiceabilityController)
 * all read these tables, anything saved here goes live instantly to the client
 * panel, the rate card, the rate calculator and while shipping an order.
 * No extra sync step is required.
 *
 * This deliberately does NOT use courier_slabs / courier_slab_rates
 * (the old "Courier Rate Manager"), because nothing else in the app reads those
 * tables -- rates saved there never reached the calculator or orders.
 */
class RateSyncManagerController extends Controller
{
    /**
     * The canonical courier / service list.
     * Kept identical to ZonePriceSettingController::add() so rate rows and active
     * rows stay keyed to exactly the same LogisticProvider codes the engine matches on.
     */
    private function couriers(): array
    {
        return [
            // Delhivery
            'Delhivery',
            'Delhivery_Air',
            // Shadowfax
            'Shadowfax',
            // selloship
            'Ekart2KG_selloship',
            // DTDC
            'DTDC_Surface_500gm',
            'DTDC_Surface_1kg',
            'DTDC_Air',
            // tekipost
            'tekipost_Delhivery_5kg',
            'tekipost_Delhivery_10kg',
            'tekipost_Delhivery_1_KG',
            'tekipost_Ekart_2_KG_Fixed',
            'tekipost_Amazon_2_kg',
            'tekipost_Amazon_500_GM',
            // parcelx
            'Parcel_X_Delhivery',
            'Parcel_X_Amazon',
            'Parcel_X_Amazon_1KG',
            'Parcel_X_Amazon_2KG',
            'Parcel_X_Delhivery_250gm',
            // Shiprocket
            'Shiprocket_Xpressbee',
            'Shiprocket_Delhivery',
            'Shiprocket_Bluedart',
            'shiprocket_Bluedart_1kg',
            // misc
            'Ekart500gm',
            'Ekart500gm_boxd',
            'boxd_bluedart_500gm',
            'parcel_x_Xpressbee',
            'parcel_x_Shreemaruti',
        ];
    }

    /** Zones used across the rate card. */
    private function zones(): array
    {
        return ['A', 'B', 'C', 'D', 'E', 'F'];
    }

    /**
     * Render the tab. The grid itself is loaded via AJAX (getRates) once a
     * client is chosen, so the page stays light.
     */
    public function index()
    {
        $SellerList = SellerList::all();

        return view('rate-sync-manager.index', [
            'SellerList' => $SellerList,
            'couriers'   => $this->couriers(),
            'zones'      => $this->zones(),
        ]);
    }

    /**
     * Return, for one seller, every courier's active flag and its per-zone rates,
     * so the grid pre-fills with whatever is already live.
     */
    public function getRates(Request $request)
    {
        $request->validate(['seller_id' => ['required']]);
        $sellerId = $request->seller_id;

        // Active flags: LogisticProvider => status (0/1)
        $active = ActicvSleb::where('seller_id', $sellerId)
            ->get()
            ->keyBy('LogisticProvider');

        // Existing rates: LogisticProvider => [ zone => row ]
        $rows = ZonePriceSetting::where('seller_id', $sellerId)->get();
        $rates = [];
        foreach ($rows as $r) {
            $rates[$r->LogisticProvider][$r->zone] = [
                'prepaid_price'      => (string) $r->prepaid_price,
                'prepaid_fix_price'  => (string) $r->prepaid_fix_price,
                'cod_price'          => (string) $r->cod_price,
                'cod_fix_price'      => (string) $r->cod_fix_price,
                'cod_charge_parsent' => (string) $r->cod_charge_parsent,
                'rto_credit'         => (string) ($r->rto_credit ?? 0),
            ];
        }

        $out = [];
        foreach ($this->couriers() as $code) {
            $out[] = [
                'code'   => $code,
                'active' => isset($active[$code]) ? (int) $active[$code]->status : 0,
                'zones'  => $rates[$code] ?? (object) [],
            ];
        }

        return response()->json([
            'success'  => true,
            'zones'    => $this->zones(),
            'couriers' => $out,
        ]);
    }

    /**
     * Bulk save. Accepts:
     *   seller_id
     *   couriers[CODE][active]           = 0|1
     *   couriers[CODE][zones][ZONE][...] = numeric rate fields
     *
     * For each courier we upsert the ActicvSleb active flag, and for each zone that
     * has any rate entered we upsert the ZonePriceSetting row (same match keys the
     * engine uses: seller_id + LogisticProvider + zone). Everything is wrapped in a
     * transaction so a client's rate card is never left half-written.
     */
    public function save(Request $request)
    {
        $request->validate([
            'seller_id' => ['required'],
            'couriers'  => ['required', 'array'],
        ]);

        $sellerId      = $request->seller_id;
        $couriers      = $request->input('couriers', []);
        $validCouriers = $this->couriers();
        $validZones    = $this->zones();

        $num = function ($v) {
            return (is_numeric($v)) ? round((float) $v, 2) : 0;
        };

        $ratesTouched   = 0;
        $couriersTouched = 0;

        DB::transaction(function () use (
            $couriers, $sellerId, $validCouriers, $validZones, $num,
            &$ratesTouched, &$couriersTouched
        ) {
            foreach ($couriers as $code => $payload) {
                if (!in_array($code, $validCouriers, true)) {
                    continue; // ignore anything not in the canonical list
                }
                $couriersTouched++;

                // 1) Active / inactive for this client
                $status = (isset($payload['active']) && (string) $payload['active'] === '1') ? 1 : 0;
                $activeRow = ActicvSleb::where('seller_id', $sellerId)
                    ->where('LogisticProvider', $code)
                    ->first();
                if ($activeRow) {
                    $activeRow->update(['status' => $status]);
                } else {
                    ActicvSleb::create([
                        'seller_id'        => $sellerId,
                        'LogisticProvider' => $code,
                        'status'           => $status,
                    ]);
                }

                // 2) Zone-wise rates
                $zones = $payload['zones'] ?? [];
                foreach ($zones as $zone => $vals) {
                    if (!in_array($zone, $validZones, true)) {
                        continue;
                    }

                    // Only write a zone that actually has at least one value entered,
                    // so we never overwrite a live rate with a blank row.
                    $hasValue = false;
                    foreach (['prepaid_price', 'prepaid_fix_price', 'cod_price', 'cod_fix_price', 'cod_charge_parsent', 'rto_credit'] as $k) {
                        if (isset($vals[$k]) && $vals[$k] !== '' && $vals[$k] !== null) {
                            $hasValue = true;
                            break;
                        }
                    }
                    if (!$hasValue) {
                        continue;
                    }

                    $data = [
                        'seller_id'          => $sellerId,
                        'zone'               => $zone,
                        'LogisticProvider'   => $code,
                        'prepaid_price'      => $num($vals['prepaid_price'] ?? 0),
                        'prepaid_fix_price'  => $num($vals['prepaid_fix_price'] ?? 0),
                        'cod_price'          => $num($vals['cod_price'] ?? 0),
                        'cod_fix_price'      => $num($vals['cod_fix_price'] ?? 0),
                        'cod_charge_parsent' => min(100, $num($vals['cod_charge_parsent'] ?? 0)),
                        'rto_credit'         => $num($vals['rto_credit'] ?? 0),
                        'status'             => 1,
                    ];

                    $existing = ZonePriceSetting::where('seller_id', $sellerId)
                        ->where('zone', $zone)
                        ->where('LogisticProvider', $code)
                        ->first();

                    if ($existing) {
                        $existing->update($data);
                    } else {
                        ZonePriceSetting::create($data);
                    }
                    $ratesTouched++;
                }
            }
        });

        return response()->json([
            'success'          => true,
            'message'          => "Saved & synced live. {$couriersTouched} couriers updated, {$ratesTouched} zone rates written.",
            'couriers_touched' => $couriersTouched,
            'rates_touched'    => $ratesTouched,
        ]);
    }
}
