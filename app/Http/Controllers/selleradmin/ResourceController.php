<?php

namespace App\Http\Controllers\selleradmin;

use App\Http\Controllers\Controller;
use App\Models\Recharge;
use App\Models\Order;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Pagination\LengthAwarePaginator;
use Carbon\Carbon;

class ResourceController extends Controller
{

    public function index()
    {
        $seller = Auth::guard('seller')->user();

        $totalAmount = 0;
        if ($seller && $seller->status == 1) {
            $totalAmount = Recharge::where('seller_id', $seller->id)->sum('amount');
        }
        return view('sellerdashboard.ndr.index', [
            'seller' => $seller,
            'totalAmount' => $totalAmount
        ]);
    }


    public function passbook()
{
    $seller = Auth::guard('seller')->user();

    $totalAmount = 0;
    $totalCredit = 0;
    $totalDebit = 0;
    // Initialize transactions as an empty paginated collection
    $transactions = Recharge::where('id', '<', 0)->paginate(10);

    if ($seller && $seller->status == 1) {
        $totalCredit = Recharge::where('seller_id', $seller->id)
            ->where('status', 1)
            ->where('type', 'Credit')
            ->sum('amount');

        $totalDebit = Recharge::where('seller_id', $seller->id)
            ->where('type', 'Debit')
            ->sum('amount');
         // dd($totalDebit);
        $transactions = Recharge::where('seller_id', $seller->id)
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        $totalAmount = $totalCredit - $totalDebit;
    }

    return view('sellerdashboard.passbook.index', [
        'seller' => $seller,
        'totalAmount' => $totalAmount,
        'totalCredit' => $totalCredit,
        'totalDebit' => $totalDebit,
        'transactions' => $transactions
    ]);
}

//    public function passbook()
// {
//     $seller = Auth::guard('seller')->user();

//     $totalAmount = 0;
//     $totalCredit = 0;
//     $totalDebit = 0;

//     if ($seller && $seller->status == 1) {
//         $totalCredit = Recharge::where('seller_id', $seller->id)
//             ->where('status', 1)
//             ->where('type', 'Credit')
//             ->sum('amount');

//         $totalDebit = Recharge::where('seller_id', $seller->id)
//             ->where('type', 'Debit')
//             ->sum('amount');
//         $transactions = Recharge::where('seller_id', $seller->id)
//     ->orderBy('created_at', 'desc')
//     ->paginate(10); // Show 10 per page


//         $totalAmount = $totalCredit - $totalDebit;
//     }

//     return view('sellerdashboard.passbook.index', [
//         'seller' => $seller,
//         'totalAmount' => $totalAmount,
//         'totalCredit' => $totalCredit,
//         'totalDebit' => $totalDebit,
//         'transactions'=>  $transactions
//     ]);
// }

/**
 * Wallet balance (credits - debits) for the logged-in seller.
 */
private function walletBalance($seller)
{
    if (!$seller) {
        return 0;
    }
    $credit = Recharge::where('seller_id', $seller->id)
        ->where('status', 1)
        ->where('type', 'Credit')
        ->sum('amount');
    $debit = Recharge::where('seller_id', $seller->id)
        ->where('type', 'Debit')
        ->sum('amount');
    return $credit - $debit;
}

/**
 * Cache of which optional columns exist on the orders table. Older databases
 * imported for this panel do not always have payment_status / delivered_date /
 * all_courier_name, and querying a missing column throws a 500.
 */
private function orderColumns()
{
    static $cols = null;
    if ($cols === null) {
        $cols = [];
        foreach (['payment_status', 'delivered_date', 'all_courier_name', 'shipping_status', 'courier_id'] as $c) {
            $cols[$c] = Schema::hasColumn('orders', $c);
        }
    }
    return $cols;
}

/**
 * Base query: delivered COD orders for a seller.
 */
private function deliveredCodQuery($sellerId)
{
    $q = Order::where('seller_id', $sellerId)->where('payment_type', 'cod');
    if ($this->orderColumns()['shipping_status']) {
        $q->whereRaw('LOWER(shipping_status) = ?', ['delivered']);
    }
    return $q;
}

public function cod()
{
    $seller = Auth::guard('seller')->user();

    $codOrders = new LengthAwarePaginator([], 0, 10);
    $totalAmount = 0;
    $totalPay = 0;
    $totalCodRemittance = 0;
    $remittanceDate = 'N/A';

    try {
        $totalAmount = $this->walletBalance($seller);

        if ($seller && $seller->status == 1) {
            $cols = $this->orderColumns();

            $unpaid = function ($q) use ($cols) {
                if ($cols['payment_status']) {
                    $q->where(function ($w) {
                        $w->whereNull('payment_status')->orWhere('payment_status', '!=', 'Paid');
                    });
                }
                return $q;
            };

            $select = ['id', 'order_number', 'collectable_amount', 'awb_number', 'created_at'];
            foreach (['shipping_status', 'courier_id', 'payment_status', 'delivered_date', 'all_courier_name'] as $c) {
                if ($cols[$c]) {
                    $select[] = $c;
                }
            }

            $codOrders = $unpaid($this->deliveredCodQuery($seller->id))
                ->select($select)
                ->orderBy('created_at', 'desc')
                ->paginate(10);

            $totalPay = $unpaid($this->deliveredCodQuery($seller->id))->sum('collectable_amount');

            if ($cols['payment_status']) {
                $totalCodRemittance = $this->deliveredCodQuery($seller->id)
                    ->where('payment_status', 'Paid')
                    ->sum('collectable_amount');
            }

            if ($cols['delivered_date']) {
                $latest = Order::where('seller_id', $seller->id)
                    ->where('payment_type', 'cod')
                    ->whereNotNull('delivered_date')
                    ->max('delivered_date');
                if ($latest) {
                    $remittanceDate = Carbon::parse($latest)->addDays(7)->format('d/m/y');
                }
            }
        }
    } catch (\Throwable $e) {
        report($e);
    }

    return view('sellerdashboard.passbook.cod', [
        'seller' => $seller,
        'totalAmount' => $totalAmount,
        'codOrders' => $codOrders,
        'totalPay' => $totalPay,
        'totalCodRemittance' => $totalCodRemittance,
        'remittanceDate' => $remittanceDate,
    ]);
}

/**
 * Wallet recharge history (credits added to the wallet).
 */
public function recharge()
{
    $seller = Auth::guard('seller')->user();

    $recharges = new LengthAwarePaginator([], 0, 10);
    $totalAmount = 0;
    $totalRecharged = 0;
    $pendingAmount = 0;
    $lastRecharge = null;

    try {
        $totalAmount = $this->walletBalance($seller);

        if ($seller) {
            $base = fn () => Recharge::where('seller_id', $seller->id)->where('type', 'Credit');

            $recharges = $base()->orderBy('created_at', 'desc')->paginate(10);
            $totalRecharged = $base()->where('status', 1)->sum('amount');
            $pendingAmount = $base()->where('status', '!=', 1)->sum('amount');
            $lastRecharge = $base()->where('status', 1)->latest('created_at')->first();
        }
    } catch (\Throwable $e) {
        report($e);
    }

    return view('sellerdashboard.passbook.recharge', [
        'seller' => $seller,
        'totalAmount' => $totalAmount,
        'recharges' => $recharges,
        'totalRecharged' => $totalRecharged,
        'pendingAmount' => $pendingAmount,
        'lastRecharge' => $lastRecharge,
    ]);
}

/**
 * Static help / support page - no order data needed.
 */
public function help()
{
    $seller = Auth::guard('seller')->user();
    $totalAmount = 0;
    try {
        $totalAmount = $this->walletBalance($seller);
    } catch (\Throwable $e) {
        report($e);
    }

    return view('sellerdashboard.help', compact('seller', 'totalAmount'));
}

/**
 * Static API documentation page - no order data needed.
 */
public function api()
{
    $seller = Auth::guard('seller')->user();
    $totalAmount = 0;
    try {
        $totalAmount = $this->walletBalance($seller);
    } catch (\Throwable $e) {
        report($e);
    }

    return view('sellerdashboard.api', compact('seller', 'totalAmount'));
}
//     public function cod()
// {
//     $seller = Auth::guard('seller')->user();

//     $totalAmount = 0;
//     $codOrders = [];

//     if ($seller && $seller->status == 1) {
//         // $totalAmount = Recharge::where('seller_id', $seller->id)->sum('amount');

        
//          $sellerRechargeAmount = Recharge::where('seller_id', $seller->id)
//         ->where('status', 1)
//         ->where('type', 'Credit')
//         ->sum('amount');

//     $sellerUsedAmount = Recharge::where('seller_id', $seller->id)
//         ->where('type', 'Debit')
//         ->sum('amount');

//     $totalAmount = $sellerRechargeAmount - $sellerUsedAmount;

//         $codOrders = Order::where('seller_id', $seller->id)
//             ->where('payment_type', 'cod')
//             ->where('payment_status', '!=', 'Paid')
//             ->where('shipping_status', 'delivered') // ✅ only delivered COD orders
//             ->select('order_number', 'shipping_status', 'collectable_amount', 'courier_id', 'awb_number', 'created_at','payment_status')
//             ->orderBy('created_at', 'desc')
//             ->paginate(10);

//                 // 🟢 Total Pay (sum of collectable amounts)
//     $totalPay = Order::where('seller_id', $seller->id)
//         ->where('payment_type', 'cod')
//         ->where('shipping_status', 'delivered')
//         ->where('payment_status', '!=', 'Paid')
//         ->sum('collectable_amount');

//     // 🟢 Total COD Remittance (count of orders)
//     $totalCodRemittance = Order::where('seller_id', $seller->id)
//         ->where('payment_type', 'cod')
//         ->where('shipping_status', 'delivered')
//         ->where('payment_status', 'Paid')
//         ->sum('collectable_amount');

//     // 🟢 Remittance Date (7 days after most recent delivered date)
//     $latestDeliveredDate = Order::where('seller_id', $seller->id)
//         ->where('payment_type', 'cod')
//         ->whereNotNull('delivered_date')
//         ->orderBy('delivered_date', 'desc')
//         ->value('delivered_date');

//     $remittanceDate = $latestDeliveredDate
//         ? \Carbon\Carbon::parse($latestDeliveredDate)->addDays(7)->format('d/m/y')
//         : 'N/A';

//     }

//     return view('sellerdashboard.passbook.cod', [
//         'seller' => $seller,
//         'totalAmount' => $totalAmount,
//         'codOrders' => $codOrders,
//         'totalPay' => $totalPay,
//         'totalCodRemittance' => $totalCodRemittance,
//         'remittanceDate' => $remittanceDate

//     ]);
// }




public function shippingcharge()
{
    $seller = Auth::guard('seller')->user();

    // Initialize as paginated empty collection
    $orders = Order::where('id', '<', 0)->paginate(10); // Empty paginated collection
    $recharges = [];
    $totalAmount = 0;

    if ($seller && $seller->status == 1) {
        $orders = Order::where('seller_id', $seller->id)
                      ->where('order_status', '!=', 'cancelled')
                      ->latest()
                      ->paginate(10);
                      
        $recharges = Recharge::where([
            'seller_id' => $seller->id,
            'status' => '1',
            'type' => 'Debit'
        ])->get();

        $sellerRechargeAmount = Recharge::where('seller_id', $seller->id)
            ->where('status', 1)
            ->where('type', 'Credit')
            ->sum('amount');

        $sellerUsedAmount = Recharge::where('seller_id', $seller->id)
            ->where('type', 'Debit')
            ->sum('amount');

        $totalAmount = $sellerRechargeAmount - $sellerUsedAmount;
    }

    return view('sellerdashboard.passbook.shippingcharge', compact('seller', 'orders', 'recharges', 'totalAmount'));
}


    // public function shippingcharge()
    // {
    //     $seller = Auth::guard('seller')->user();

    //     $orders = [];
    //     $recharges = [];
    //     $totalAmount = 0;

    //     if ($seller && $seller->status == 1) {
    //         $orders = Order::where('seller_id', $seller->id)->where('order_status', '!=', 'cancelled')->latest()->paginate(10); // adjust limit as needed
                
    //         $recharges = Recharge::where(['seller_id' => $seller->id,'status'=>'1','type' => 'Debit'])->get();
    //         // $totalAmount = $recharges->sum('amount');


    //             $sellerRechargeAmount = Recharge::where('seller_id', $seller->id)
    //                 ->where('status', 1)
    //                 ->where('type', 'Credit')
    //                 ->sum('amount');

    //             $sellerUsedAmount = Recharge::where('seller_id', $seller->id)
    //                 ->where('type', 'Debit')
    //                 ->sum('amount');

    //             $totalAmount = $sellerRechargeAmount - $sellerUsedAmount;

    //     }

    //     return view('sellerdashboard.passbook.shippingcharge', compact('seller', 'orders', 'recharges', 'totalAmount'));
    // }

    public function allcharges()
    {
        $seller = Auth::guard('seller')->user();

        $totalAmount = 0;
        if ($seller && $seller->status == 1) {
            // $totalAmount = Recharge::where('seller_id', $seller->id)->sum('amount');

            $recharges = Recharge::where(['seller_id' => $seller->id,'status'=>'1'])->get();
            // dd($recharges);
            // $totalAmount = $recharges->sum('amount');


                $sellerRechargeAmount = Recharge::where('seller_id', $seller->id)
                    ->where('status', 1)
                    ->where('type', 'Credit')
                    ->sum('amount');

                $sellerUsedAmount = Recharge::where('seller_id', $seller->id)
                    ->where('type', 'Debit')
                    ->sum('amount');

                $totalAmount = $sellerRechargeAmount - $sellerUsedAmount;

        }
        return view('sellerdashboard.passbook.allcharges', [
            'seller' => $seller,
            'totalAmount' => $totalAmount,
            'recharges' => $recharges

        ]);
    }

    public function invoice()
    {
        $seller = Auth::guard('seller')->user();

        $totalAmount = 0;
        if ($seller && $seller->status == 1) {
            $totalAmount = Recharge::where('seller_id', $seller->id)->sum('amount');
        }
        return view('sellerdashboard.passbook.invoice', [
            'seller' => $seller,
            'totalAmount' => $totalAmount
        ]);
    }

    public function creditnote()
    {
        $seller = Auth::guard('seller')->user();

        $totalAmount = 0;
        if ($seller && $seller->status == 1) {

                 $recharges = Recharge::where(['seller_id' => $seller->id,'status'=>'1','type'=>'Credit'])->get();
            // dd($recharges);
            // $totalAmount = $recharges->sum('amount');


                $sellerRechargeAmount = Recharge::where('seller_id', $seller->id)
                    ->where('status', 1)
                    ->where('type', 'Credit')
                    ->sum('amount');

                $sellerUsedAmount = Recharge::where('seller_id', $seller->id)
                    ->where('type', 'Debit')
                    ->sum('amount');

                $totalAmount = $sellerRechargeAmount - $sellerUsedAmount;
        }
        return view('sellerdashboard.passbook.creditnote', [
            'seller' => $seller,
            'totalAmount' => $totalAmount,
            'recharges' => $recharges
        ]);
    }

    public function trackorder()
    {
        $seller = Auth::guard('seller')->user();

        $totalAmount = 0;
        if ($seller && $seller->status == 1) {
            // $totalAmount = Recharge::where('seller_id', $seller->id)->sum('amount');

            $sellerRechargeAmount = Recharge::where('seller_id', $seller->id)
                ->where('status', 1)
                ->where('type', 'Credit')
                ->sum('amount');
            $sellerUsedAmount = Recharge::where('seller_id', $seller->id)
                ->where('type', 'Debit')
                ->sum('amount');
            $totalAmount = $sellerRechargeAmount - $sellerUsedAmount;

        }
        return view('sellerdashboard.order.trackorder', [
            'seller' => $seller,
            'totalAmount' => $totalAmount
        ]);
    }




    public function trackorderin()
    {
        $seller = Auth::guard('seller')->user();

        $totalAmount = 0;
        if ($seller && $seller->status == 1) {
            // $totalAmount = Recharge::where('seller_id', $seller->id)->sum('amount');

            $sellerRechargeAmount = Recharge::where('seller_id', $seller->id)
                ->where('status', 1)
                ->where('type', 'Credit')
                ->sum('amount');
            $sellerUsedAmount = Recharge::where('seller_id', $seller->id)
                ->where('type', 'Debit')
                ->sum('amount');
            $totalAmount = $sellerRechargeAmount - $sellerUsedAmount;

        }
        return view('sellerdashboard.order.trackorderin', [
            'seller' => $seller,
            'totalAmount' => $totalAmount
        ]);
    }

}
