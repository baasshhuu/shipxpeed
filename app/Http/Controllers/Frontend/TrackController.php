<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Services\Tracking\ShipmentTracker;
use Illuminate\Http\Request;

class TrackController extends Controller
{
    /**
     * Track Order page (/order-details?awb=...).
     * Accepts an AWB number, order number or the seller's own order ID and
     * shows live tracking from whichever courier carries the shipment.
     */
    public function trackOrder(Request $request, ShipmentTracker $tracker)
    {
        $request->validate([
            'awb' => 'required|string|max:100',
        ]);

        $query = trim($request->input('awb'));
        $order = ShipmentTracker::findOrder($query);

        if (!$order) {
            return redirect()->back()
                ->withInput()
                ->withErrors(['error' => "No shipment found for \"{$query}\". Please check the AWB number or Order ID."]);
        }

        $tracking = $tracker->track($order);

        // Keep the order's status in step with what the courier just told us
        if ($tracking['ok'] && array_key_exists($tracking['status'], ShipmentTracker::STATUS_LABELS)
            && strtolower((string) $order->shipping_status) !== strtolower($tracking['status'])
            && !in_array(strtolower((string) $order->shipping_status), ShipmentTracker::FINAL_STATUSES, true)) {
            $order->shipping_status = $tracking['status'];
            if (in_array($tracking['status'], ['delivered', 'rto delivered'], true) && empty($order->delivered_date)) {
                $order->delivered_date = now()->format('Y-m-d');
            }
            $order->save();
        }

        $consignee = is_array($order->consignee) ? $order->consignee : (json_decode((string) $order->consignee, true) ?: []);
        $tracking['destination'] = trim(($consignee['city'] ?? '') . (!empty($consignee['state']) ? ', ' . $consignee['state'] : ''), ', ');
        $tracking['payment'] = strtoupper((string) $order->payment_type);
        $tracking['booked_on'] = optional($order->created_at)->format('d M Y');

        return view('frontend.website.pages.trackorder', [
            'tracking' => $tracking,
            'query'    => $query,
        ]);
    }

    /** JSON version for the seller panel / API: GET /api/orders/track?awb=... */
    public function trackJson(Request $request, ShipmentTracker $tracker)
    {
        $request->validate(['awb' => 'required|string|max:100']);

        $order = ShipmentTracker::findOrder($request->input('awb'));
        if (!$order) {
            return response()->json(['status' => false, 'message' => 'Shipment not found'], 404);
        }

        $tracking = $tracker->track($order);

        return response()->json(['status' => $tracking['ok'], 'data' => $tracking]);
    }
}
