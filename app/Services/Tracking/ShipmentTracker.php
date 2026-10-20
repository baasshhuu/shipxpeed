<?php

namespace App\Services\Tracking;

use App\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * One place to track a shipment for every courier the panel books with.
 *
 *   $result = app(ShipmentTracker::class)->track($order);
 *
 * Always returns the same shape (never throws):
 * [
 *   'ok'          => bool,
 *   'courier'     => 'Delhivery',          // display name
 *   'courier_key' => 'delhivery',          // internal tracker key
 *   'awb'         => '1234567890',
 *   'raw_status'  => 'In Transit',         // as the courier sent it
 *   'status'      => 'transit',            // panel value for orders.shipping_status
 *   'status_label'=> 'In Transit',         // for display
 *   'location'    => 'Delhi Hub',
 *   'updated_at'  => '2026-10-20 14:10',
 *   'edd'         => '2026-10-23' | null,  // expected delivery
 *   'events'      => [ ['time'=>..,'status'=>..,'location'=>..], ... ] newest first
 *   'error'       => null | 'message',
 * ]
 */
class ShipmentTracker
{
    /** courier_id values (lower-case) → tracker key */
    public const COURIER_MAP = [
        'delhivery'            => 'delhivery',
        'delhivery_b2c'        => 'delhivery',
        'delhivery_b2c_express'=> 'delhivery',
        'delhivery_air'        => 'delhivery',
        'xpressbees'           => 'xpressbees',
        'shadowfax'            => 'shadowfax',
        'boxd'                 => 'boxd',
        'delhivery_250gms'     => 'boxd',
        'amazon_0_5kg'         => 'boxd',
        'tekipost'             => 'tekipost',
        'dtdc'                 => 'dtdc',
        'dtdc surface 500gm'   => 'dtdc',
        'dtdc surface 1kg'     => 'dtdc',
        'dtdc air'             => 'dtdc',
        'parcelx'              => 'parcelx',
        'delhivery_500gm'      => 'parcelx',
        'amazon_500gm'         => 'parcelx',
        'shiprocket'           => 'shiprocket',
    ];

    public const COURIER_NAMES = [
        'delhivery'  => 'Delhivery',
        'xpressbees' => 'XpressBees',
        'shadowfax'  => 'Shadowfax',
        'boxd'       => 'Boxd',
        'tekipost'   => 'Tekipost',
        'dtdc'       => 'DTDC',
        'parcelx'    => 'ParcelX',
        'shiprocket' => 'Shiprocket',
    ];

    /** Couriers booked in the panel whose tracking API is not connected yet */
    public const PENDING = ['ekart', 'smartship', 'selloship', 'jiffy'];

    /** Final statuses – no further tracking needed */
    public const FINAL_STATUSES = ['delivered', 'rto delivered', 'cancelled'];

    public const STATUS_LABELS = [
        'courier Assigned' => 'Courier Assigned',
        'picked up'        => 'Picked Up',
        'transit'          => 'In Transit',
        'out for delivery' => 'Out For Delivery',
        'delivered'        => 'Delivered',
        'NDR'              => 'NDR (Delivery Attempt Failed)',
        'rto'              => 'RTO (Returning to Origin)',
        'rto delivered'    => 'RTO Delivered',
        'cancelled'        => 'Cancelled',
        'lost'             => 'Lost / Damaged',
    ];

    /* ------------------------------------------------------------------ */
    /*  Public API                                                        */
    /* ------------------------------------------------------------------ */

    /** Find an order by AWB, order number or the seller's own order id. */
    public static function findOrder(string $query, $sellerId = null): ?Order
    {
        $query = trim($query);
        if ($query === '') {
            return null;
        }

        $base = Order::query();
        if ($sellerId) {
            $base->where('seller_id', $sellerId);
        }

        foreach (['awb_number', 'order_number', 'customer_order_id', 'shopify_order_number'] as $col) {
            $order = (clone $base)->where($col, $query)->latest('id')->first();
            if ($order) {
                return $order;
            }
        }

        return null;
    }

    /** Tracker key for an order, or null when the courier is not supported. */
    public static function trackerKey(Order $order): ?string
    {
        $id = strtolower(trim((string) $order->courier_id));
        if (isset(self::COURIER_MAP[$id])) {
            return self::COURIER_MAP[$id];
        }
        // e.g. "DTDC Surface 2kg" or "delhivery_5kg" variants
        foreach (['dtdc' => 'dtdc', 'shiprocket' => 'shiprocket', 'xpressbees' => 'xpressbees', 'shadowfax' => 'shadowfax'] as $needle => $key) {
            if (str_contains($id, $needle)) {
                return $key;
            }
        }
        return null;
    }

    public function track(Order $order): array
    {
        $key = self::trackerKey($order);
        $awb = trim((string) $order->awb_number);

        $result = $this->emptyResult($order, $key);

        if ($awb === '') {
            $result['error'] = 'This order has not been shipped yet (no AWB number).';
            return $result;
        }

        if (!$key) {
            $result['error'] = in_array(strtolower((string) $order->courier_id), self::PENDING, true)
                ? 'Live tracking for ' . ucfirst((string) $order->courier_id) . ' is not connected yet (Integration Pending).'
                : 'Live tracking is not available for this courier yet.';
            return $result;
        }

        try {
            $data = match ($key) {
                'delhivery'  => $this->delhivery($awb),
                'xpressbees' => $this->xpressbees($awb),
                'shadowfax'  => $this->shadowfax($awb),
                'boxd'       => $this->boxd($awb),
                'tekipost'   => $this->tekipost($awb),
                'dtdc'       => $this->dtdc($awb),
                'parcelx'    => $this->parcelx($awb),
                'shiprocket' => $this->shiprocket($awb),
            };
        } catch (\Throwable $e) {
            Log::warning('[Tracking] ' . $key . ' failed for ' . $awb . ': ' . $e->getMessage());
            $result['error'] = 'Could not reach ' . $result['courier'] . ' right now. Please try again in a few minutes.';
            return $result;
        }

        $events = $this->sortEvents($data['events'] ?? []);
        $raw    = trim((string) ($data['raw_status'] ?? ($events[0]['status'] ?? '')));

        if ($raw === '' && empty($events)) {
            $result['error'] = $data['error'] ?? ($result['courier'] . ' has no tracking updates for this AWB yet.');
            return $result;
        }

        $status = self::normalize($raw, $key);

        return array_merge($result, [
            'ok'           => true,
            'raw_status'   => $raw,
            'status'       => $status,
            'status_label' => self::STATUS_LABELS[$status] ?? ucwords($raw),
            'location'     => $data['location'] ?? ($events[0]['location'] ?? ''),
            'updated_at'   => $data['updated_at'] ?? ($events[0]['time'] ?? ''),
            'edd'          => $data['edd'] ?? null,
            'events'       => $events,
            'error'        => null,
        ]);
    }

    /**
     * Map any courier status text to the values the panel filters use
     * (orders.shipping_status). Returns lower-case raw text when unknown.
     */
    public static function normalize(string $raw, ?string $courier = null): string
    {
        $s = strtolower(trim(preg_replace('/[\s_\-]+/', ' ', $raw)));
        if ($s === '') {
            return '';
        }

        // Courier specific meanings first
        if ($courier === 'delhivery' && $s === 'dispatched') return 'out for delivery';
        if ($courier === 'tekipost' && $s === 'dispatched') return 'out for delivery';
        if ($courier === 'tekipost' && $s === 'not picked') return 'courier Assigned';

        if (preg_match('/\b(rto|rts|return)/', $s)) {
            return str_contains($s, 'deliver') && !str_contains($s, 'undeliver') ? 'rto delivered' : 'rto';
        }
        if (str_contains($s, 'cancel')) return 'cancelled';
        if (preg_match('/lost|damage|destroy/', $s)) return 'lost';
        if (preg_match('/undeliver|not deliver|ndr|failed|attempt|refused|door ?lock|unable/', $s)) return 'NDR';
        if (preg_match('/out for delivery|^ofd$|out for del/', $s)) return 'out for delivery';
        if (preg_match('/^delivered|delivered$|^dlvd$/', $s)) return 'delivered';
        if (preg_match('/picked up|pickup done|^picked$/', $s)) return 'picked up';
        if (preg_match('/transit|shipped|in scan|reached|received at|recd at|connected|bagged|forwarded|dispatched|mis ?route|^pending$|at hub|arrived/', $s)) return 'transit';
        if (preg_match('/manifest|booked|assigned|pickup|not picked|created|new|ready|awaited|scheduled|^ofp$/', $s)) return 'courier Assigned';

        return $s;
    }

    /* ------------------------------------------------------------------ */
    /*  Couriers                                                          */
    /* ------------------------------------------------------------------ */

    protected function delhivery(string $awb): array
    {
        $token = \App\Services\DelhiveryHelper::token();
        if ($token === '') {
            return ['error' => 'Delhivery API token is missing (DELHIVERY_API_TOKEN in .env).'];
        }

        $res = Http::timeout(25)->withHeaders([
            'Authorization' => 'Token ' . $token,
            'Accept'        => 'application/json',
        ])->get(\App\Services\DelhiveryHelper::baseUrl() . '/api/v1/packages/json/', ['waybill' => $awb]);

        $s = $res->json('ShipmentData.0.Shipment');
        if (!$s) {
            return ['error' => $res->json('Error') ?: 'Delhivery has no data for this AWB.'];
        }

        $events = [];
        foreach ($s['Scans'] ?? [] as $scan) {
            $d = $scan['ScanDetail'] ?? [];
            $events[] = [
                'time'     => $d['ScanDateTime'] ?? ($d['StatusDateTime'] ?? ''),
                'status'   => trim(($d['Scan'] ?? '') . (!empty($d['Instructions']) ? ' – ' . $d['Instructions'] : '')),
                'location' => $d['ScannedLocation'] ?? '',
            ];
        }

        return [
            'raw_status' => $s['Status']['Status'] ?? '',
            'location'   => $s['Status']['StatusLocation'] ?? '',
            'updated_at' => $s['Status']['StatusDateTime'] ?? '',
            'edd'        => $s['ExpectedDeliveryDate'] ?? ($s['PromisedDeliveryDate'] ?? null),
            'events'     => $events,
        ];
    }

    protected function xpressbees(string $awb): array
    {
        $token = Cache::remember('tracking.xpressbees_token', 3000, function () {
            $cfg = config('courier_services.xpressbees', []);
            if (empty($cfg['login_url']) || empty($cfg['username'])) {
                return null;
            }
            $r = Http::timeout(20)->post($cfg['login_url'], [
                'email'    => $cfg['username'],
                'password' => $cfg['password'] ?? '',
            ]);
            return $r->successful() ? ($r->json('data') ?: null) : null;
        });

        if (!$token) {
            Cache::forget('tracking.xpressbees_token');
            return ['error' => 'XpressBees login failed – check XPB_USERNAME / XPB_PASSWORD in .env.'];
        }

        $res = Http::timeout(25)->withoutVerifying()->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ])->get(rtrim(config('tracking.xpressbees.track_url'), '/') . '/' . urlencode($awb));

        if ($res->status() === 401) {
            Cache::forget('tracking.xpressbees_token');
        }

        $d = $res->json('data');
        if (!$res->successful() || !$d) {
            return ['error' => $res->json('message') ?: 'XpressBees has no data for this AWB.'];
        }

        return [
            'raw_status' => $d['status'] ?? '',
            'edd'        => $d['edd'] ?? ($d['expected_delivery_date'] ?? null),
            'events'     => $this->genericEvents($d['history'] ?? ($d['scans'] ?? [])),
        ];
    }

    protected function shadowfax(string $awb): array
    {
        if (!config('tracking.shadowfax.token')) {
            return ['error' => 'Shadowfax tracking token is missing (SHADOWFAX_TRACK_TOKEN in .env).'];
        }
        $res = Http::timeout(25)->withHeaders([
            'Authorization' => 'Token ' . config('tracking.shadowfax.token'),
            'Accept'        => 'application/json',
        ])->get(rtrim(config('tracking.shadowfax.track_url'), '/') . '/' . urlencode($awb) . '/track/');

        $j = $res->json() ?? [];
        if (!$res->successful() || !$j) {
            return ['error' => $j['message'] ?? 'Shadowfax has no data for this AWB.'];
        }

        $d = $j['order_details'] ?? ($j['data'] ?? $j);
        $list = $j['tracking_details'] ?? ($d['tracking_details'] ?? ($d['order_tracking_details'] ?? ($j['order_tracking_details'] ?? [])));

        return [
            'raw_status' => $d['status'] ?? ($j['status'] ?? ''),
            'edd'        => $d['promised_delivery_date'] ?? ($d['edd'] ?? null),
            'events'     => $this->genericEvents($list),
        ];
    }

    protected function boxd(string $awb): array
    {
        if (!config('tracking.boxd.secret_key') || !config('tracking.boxd.customer_id')) {
            return ['error' => 'Boxd keys are missing (BOXD_SECRET_KEY / BOXD_CUSTOMER_ID in .env).'];
        }
        $res = Http::timeout(25)->asForm()->withHeaders([
            'secretkey'  => config('tracking.boxd.secret_key'),
            'customerid' => config('tracking.boxd.customer_id'),
        ])->post(config('tracking.boxd.track_url'), ['awb_number' => $awb]);

        $j = $res->json() ?? [];
        if (!$res->successful() || empty($j['status'])) {
            return ['error' => $j['message'] ?? 'Boxd has no data for this AWB.'];
        }

        $list = $j['tracking_history'] ?? ($j['shipment_history'] ?? ($j['history'] ?? ($j['data'] ?? [])));

        return [
            'raw_status' => $j['shipment_status'] ?? '',
            'events'     => $this->genericEvents(is_array($list) ? $list : []),
        ];
    }

    protected function tekipost(string $awb): array
    {
        $token = \App\Services\TekipostService::getToken();
        if (!$token) {
            return ['error' => 'Tekipost login failed.'];
        }

        $res = Http::timeout(25)->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ])->get(rtrim(config('tracking.tekipost.track_url'), '/') . '/' . urlencode($awb));

        $j = $res->json() ?? [];
        if (!$res->successful() || empty($j['success']) || empty($j['data'])) {
            return ['error' => $j['message'] ?? 'Tekipost has no data for this AWB.'];
        }

        $d = $j['data'];
        $list = $d['tracking_history'] ?? ($d['history'] ?? ($d['scans'] ?? ($d['tracking'] ?? [])));

        return [
            'raw_status' => $d['status_name'] ?? ($d['status'] ?? ''),
            'edd'        => $d['edd'] ?? null,
            'events'     => $this->genericEvents(is_array($list) ? $list : []),
        ];
    }

    protected function dtdc(string $awb): array
    {
        if (!config('tracking.dtdc.token')) {
            return ['error' => 'DTDC tracking token is missing (DTDC_TRACK_TOKEN in .env).'];
        }
        $res = Http::timeout(25)->withHeaders([
            'Content-Type'   => 'application/json',
            'x-access-token' => config('tracking.dtdc.token'),
        ])->post(config('tracking.dtdc.track_url'), [
            'trkType'   => 'cnno',
            'strcnno'   => $awb,
            'addtnlDtl' => 'Y',
        ]);

        $j = $res->json() ?? [];
        if (!$res->successful() || empty($j['trackDetails'])) {
            return [
                'raw_status' => $j['trackHeader']['strStatus'] ?? '',
                'error'      => 'DTDC has no data for this AWB.',
            ];
        }

        $events = [];
        foreach ($j['trackDetails'] as $t) {
            $events[] = [
                'time'     => $this->dtdcTime($t['strActionDate'] ?? '', $t['strActionTime'] ?? ''),
                'status'   => trim(($t['strAction'] ?? '') . (!empty($t['sTrRemarks']) ? ' – ' . $t['sTrRemarks'] : '')),
                'location' => $t['strOrigin'] ?? ($t['strDestination'] ?? ''),
            ];
        }
        $last = end($j['trackDetails']);

        return [
            'raw_status' => $last['strAction'] ?? ($j['trackHeader']['strStatus'] ?? ''),
            'edd'        => $j['trackHeader']['strExpectedDeliveryDate'] ?? null,
            'events'     => $events,
        ];
    }

    protected function parcelx(string $awb): array
    {
        if (!config('tracking.parcelx.token')) {
            return ['error' => 'ParcelX token is missing (PARCELX_ACCESS_TOKEN in .env).'];
        }
        $res = Http::timeout(25)->withHeaders([
            'access-token' => config('tracking.parcelx.token'),
            'Accept'       => 'application/json',
        ])->get(config('tracking.parcelx.track_url'), ['awb' => $awb]);

        $j = $res->json() ?? [];
        if (!$res->successful() || empty($j['status'])) {
            return ['error' => $j['message'] ?? 'ParcelX has no data for this AWB.'];
        }

        $list = $j['data'] ?? ($j['tracking'] ?? ($j['history'] ?? []));
        if (is_array($list) && isset($list['history'])) {
            $list = $list['history'];
        }

        return [
            'raw_status' => $j['current_status']['status_title'] ?? ($j['data']['current_status'] ?? ''),
            'location'   => $j['current_status']['location'] ?? '',
            'updated_at' => $j['current_status']['event_time'] ?? ($j['current_status']['date'] ?? ''),
            'edd'        => $j['edd'] ?? null,
            'events'     => $this->genericEvents(is_array($list) ? $list : []),
        ];
    }

    protected function shiprocket(string $awb): array
    {
        $token = Cache::remember('tracking.shiprocket_token', 60 * 60 * 24 * 8, function () {
            $login = (new \App\Services\ShiprocketService())->shiprocketLogin();
            return $login['token'] ?? null;
        });
        if (!$token) {
            Cache::forget('tracking.shiprocket_token');
            return ['error' => 'Shiprocket login failed.'];
        }

        $res = Http::timeout(30)->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ])->get(rtrim(config('tracking.shiprocket.track_url'), '/') . '/' . urlencode($awb));

        if ($res->status() === 401) {
            Cache::forget('tracking.shiprocket_token');
        }

        $t = $res->json('tracking_data');
        if (!$res->successful() || !$t) {
            return ['error' => 'Shiprocket has no data for this AWB.'];
        }

        $track = $t['shipment_track'][0] ?? [];
        $events = [];
        foreach ($t['shipment_track_activities'] ?? [] as $a) {
            $events[] = [
                'time'     => $a['date'] ?? '',
                'status'   => $a['activity'] ?? ($a['sr-status-label'] ?? ($a['status'] ?? '')),
                'location' => $a['location'] ?? '',
            ];
        }

        return [
            'raw_status' => $track['current_status'] ?? '',
            'edd'        => $t['etd'] ?? ($track['edd'] ?? null),
            'events'     => $events,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                           */
    /* ------------------------------------------------------------------ */

    protected function emptyResult(Order $order, ?string $key): array
    {
        return [
            'ok'           => false,
            'courier'      => $key ? self::COURIER_NAMES[$key] : ($order->all_courier_name ?: ucfirst((string) $order->courier_id) ?: 'Courier'),
            'courier_key'  => $key,
            'awb'          => (string) $order->awb_number,
            'order_number' => (string) ($order->order_number ?? ''),
            'raw_status'   => '',
            'status'       => (string) ($order->shipping_status ?? ''),
            'status_label' => self::STATUS_LABELS[$order->shipping_status ?? ''] ?? ucwords((string) ($order->shipping_status ?: 'Pending')),
            'location'     => '',
            'updated_at'   => '',
            'edd'          => null,
            'events'       => [],
            'error'        => null,
        ];
    }

    /** Turn any list of courier scan rows into [time, status, location]. */
    protected function genericEvents($list): array
    {
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $time = $this->pick($row, ['event_time', 'date', 'created_at', 'created', 'timestamp', 'scan_date_time', 'status_date', 'updated_at', 'time', 'datetime']);
            $text = $this->pick($row, ['message', 'activity', 'status_title', 'status_name', 'status', 'state', 'scan', 'remarks', 'description', 'status_description']);
            $note = $this->pick($row, ['remarks', 'remark', 'comment', 'state_description', 'instructions']);
            if ($note && $note !== $text) {
                $text = trim($text . ' – ' . $note, ' –');
            }
            $loc = $this->pick($row, ['location', 'city', 'scanned_location', 'hub', 'hub_name', 'current_location', 'branch']);

            if (is_numeric($time) && strlen((string) $time) >= 12) {
                $time = date('Y-m-d H:i', (int) ($time / 1000));
            }
            if ($text !== '' || $time !== '') {
                $out[] = ['time' => (string) $time, 'status' => (string) $text, 'location' => (string) $loc];
            }
        }
        return $out;
    }

    protected function pick(array $row, array $keys): string
    {
        foreach ($keys as $k) {
            foreach ([$k, ucfirst($k), strtoupper($k)] as $kk) {
                if (isset($row[$kk]) && is_scalar($row[$kk]) && trim((string) $row[$kk]) !== '') {
                    return trim((string) $row[$kk]);
                }
            }
        }
        return '';
    }

    /** Newest first, with readable times. */
    protected function sortEvents(array $events): array
    {
        foreach ($events as $i => $e) {
            $ts = $this->toTimestamp($e['time'] ?? '');
            $events[$i]['_ts'] = $ts;
            if ($ts) {
                $events[$i]['time'] = date('d M Y, h:i A', $ts);
            }
        }
        usort($events, fn ($a, $b) => ($b['_ts'] ?? 0) <=> ($a['_ts'] ?? 0));
        return array_map(function ($e) {
            unset($e['_ts']);
            return $e;
        }, $events);
    }

    protected function toTimestamp(string $t): int
    {
        $t = trim($t);
        if ($t === '') {
            return 0;
        }
        $ts = strtotime(str_replace('/', '-', $t));
        return $ts ?: 0;
    }

    protected function dtdcTime(string $date, string $time): string
    {
        // DTDC sends ddmmyyyy and hhmm
        if (preg_match('/^(\d{2})(\d{2})(\d{4})$/', $date, $d)) {
            $date = "{$d[3]}-{$d[2]}-{$d[1]}";
        }
        if (preg_match('/^(\d{2})(\d{2})$/', $time, $m)) {
            $time = "{$m[1]}:{$m[2]}";
        }
        return trim($date . ' ' . $time);
    }
}
