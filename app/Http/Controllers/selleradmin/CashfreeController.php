<?php

namespace App\Http\Controllers\selleradmin;

use App\Http\Controllers\Controller;
use App\Services\CashfreeKyc;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Seller onboarding verification (Cashfree Secure ID).
 *
 * Flow (same as the old panel):
 *   1. Client Service Agreement is signed with Aadhaar  -> DigiLocker (Cashfree discontinued Aadhaar OTP)
 *   2. Then KYC: PAN -> GSTIN -> Bank account + IFSC     -> SellerAuthController@completeKyc
 *
 * Verified results are kept server-side in the session so the final
 * submit cannot be faked from the browser.
 */
class CashfreeController extends Controller
{
    public function __construct(protected CashfreeKyc $kyc)
    {
    }

    public static function sessionKey(string $what): string
    {
        $id = Auth::guard('seller')->id() ?? 'guest';

        return "seller_kyc.{$id}.{$what}";
    }

    protected function seller()
    {
        return Auth::guard('seller')->user();
    }

    protected function unauthorized()
    {
        return response()->json(['ok' => false, 'error' => 'Please log in again.'], 401);
    }

    // ------------------------------------------------------------------
    // Step 1: Aadhaar via DigiLocker (used to e-sign the agreement)
    // ------------------------------------------------------------------

    public function digilockerStart(Request $request)
    {
        if (!$this->seller()) {
            return $this->unauthorized();
        }

        $verificationId = 'SX' . $this->seller()->id . '_' . Str::lower(Str::random(10));
        $res = $this->kyc->digilockerCreateUrl($verificationId, route('seller.digilocker.return'));

        if (!$res['ok'] || empty($res['data']['url'])) {
            return response()->json(['ok' => false, 'error' => $res['error'] ?? 'Could not start DigiLocker.'], $res['status'] ?: 400);
        }

        session([self::sessionKey('digilocker_vid') => $verificationId]);
        session()->forget(self::sessionKey('aadhaar'));

        return response()->json(['ok' => true, 'url' => $res['data']['url'], 'verification_id' => $verificationId]);
    }

    /**
     * Polled by the agreement modal. Once DigiLocker is AUTHENTICATED, fetch
     * the Aadhaar and store it in the session.
     */
    public function digilockerStatus(Request $request)
    {
        if (!$this->seller()) {
            return $this->unauthorized();
        }

        if ($aadhaar = session(self::sessionKey('aadhaar'))) {
            return response()->json(['ok' => true, 'status' => 'VERIFIED', 'aadhaar' => $this->publicAadhaar($aadhaar)]);
        }

        $vid = session(self::sessionKey('digilocker_vid'));
        if (!$vid) {
            return response()->json(['ok' => false, 'status' => 'NOT_STARTED', 'error' => 'Please click "Verify Aadhaar with DigiLocker" first.']);
        }

        $status = $this->kyc->digilockerStatus($vid);
        if (!$status['ok']) {
            return response()->json(['ok' => false, 'status' => 'ERROR', 'error' => $status['error']]);
        }

        $state = strtoupper($status['data']['status'] ?? 'PENDING');
        if ($state !== 'AUTHENTICATED') {
            $messages = [
                'PENDING'        => 'Waiting for you to finish DigiLocker…',
                'EXPIRED'        => 'The DigiLocker link expired. Please start again.',
                'CONSENT_DENIED' => 'You denied consent in DigiLocker. Please start again and allow access to Aadhaar.',
            ];

            return response()->json(['ok' => false, 'status' => $state, 'error' => $messages[$state] ?? 'DigiLocker status: ' . $state]);
        }

        $doc = $this->kyc->digilockerAadhaar($vid);
        if (!$doc['ok']) {
            return response()->json(['ok' => false, 'status' => 'ERROR', 'error' => $doc['error']]);
        }
        if (strtoupper($doc['data']['status'] ?? '') !== 'SUCCESS' || empty($doc['data']['name'])) {
            return response()->json(['ok' => false, 'status' => 'AADHAAR_NOT_LINKED', 'error' => 'No Aadhaar found in your DigiLocker. Please link Aadhaar in DigiLocker and try again.']);
        }

        $d = $doc['data'];
        $split = $d['split_address'] ?? [];
        $address = collect([$split['house'] ?? null, $split['street'] ?? null, $split['landmark'] ?? null, $split['vtc'] ?? null,
            $split['po'] ?? null, $split['subdist'] ?? null, $split['dist'] ?? null, $split['state'] ?? null, $split['pincode'] ?? null])
            ->filter()->implode(', ');

        $aadhaar = [
            'name'            => $d['name'],
            'care_of'         => $d['care_of'] ?? '',
            'dob'             => $d['dob'] ?? '',
            'gender'          => $d['gender'] ?? '',
            'uid_masked'      => $d['uid'] ?? '',
            'address'         => $address,
            'pincode'         => $split['pincode'] ?? '',
            'state'           => $split['state'] ?? '',
            'verification_id' => $vid,
            'reference_id'    => $d['reference_id'] ?? ($status['data']['reference_id'] ?? null),
            'verified_at'     => now()->toDateTimeString(),
        ];
        session([self::sessionKey('aadhaar') => $aadhaar]);

        return response()->json(['ok' => true, 'status' => 'VERIFIED', 'aadhaar' => $this->publicAadhaar($aadhaar)]);
    }

    /** Where DigiLocker sends the seller back (only used when the site runs on https). */
    public function digilockerReturn()
    {
        return response('<html><body style="font-family:sans-serif;text-align:center;padding:40px">'
            . '<h3>DigiLocker step complete</h3><p>You can close this tab and return to Shipxpeed.</p>'
            . '<script>setTimeout(function(){window.close()},1500)</script></body></html>');
    }

    protected function publicAadhaar(array $a): array
    {
        return array_intersect_key($a, array_flip(['name', 'care_of', 'dob', 'gender', 'uid_masked', 'address']));
    }

    // Old Aadhaar-OTP endpoints: Cashfree discontinued Aadhaar OTP; kept so old pages fail gracefully.
    public function generateOtp()
    {
        return response()->json(['ok' => false, 'error' => 'Aadhaar OTP is discontinued. Please use "Verify Aadhaar with DigiLocker".'], 410);
    }

    public function verifyOtp()
    {
        return $this->generateOtp();
    }

    // ------------------------------------------------------------------
    // Step 2: KYC checks (allowed only after the agreement is signed)
    // ------------------------------------------------------------------

    protected function agreementGate()
    {
        $seller = $this->seller();
        if (!$seller) {
            return $this->unauthorized();
        }
        if ((int) $seller->agreement_accepted !== 1) {
            return response()->json(['ok' => false, 'error' => 'Please sign the Client Service Agreement first.'], 403);
        }

        return null;
    }

    public function verifyPan(Request $request)
    {
        if ($r = $this->agreementGate()) {
            return $r;
        }
        $request->merge(['pan_number' => strtoupper(trim((string) $request->pan_number))]);
        $request->validate(['pan_number' => ['required', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/']], ['pan_number.regex' => 'Invalid PAN format']);

        $res = $this->kyc->verifyPan($request->pan_number);
        if (!$res['ok']) {
            session()->forget(self::sessionKey('pan'));
            return response()->json(['ok' => false, 'error' => $res['error']], 422);
        }

        $data = [
            'pan'             => $request->pan_number,
            'registered_name' => $res['data']['registered_name'] ?? ($res['data']['name_pan_card'] ?? ''),
            'type'            => $res['data']['type'] ?? '',
        ];
        session([self::sessionKey('pan') => $data]);

        return response()->json(['ok' => true, 'message' => 'PAN verified', 'data' => $data]);
    }

    public function verifyGST(Request $request)
    {
        if ($r = $this->agreementGate()) {
            return $r;
        }
        $request->merge(['gst_number' => strtoupper(trim((string) $request->gst_number))]);
        $request->validate(['gst_number' => ['required', 'regex:/^[0-9]{2}[A-Z0-9]{13}$/']], ['gst_number.regex' => 'Invalid GSTIN format']);

        $res = $this->kyc->verifyGstin($request->gst_number);
        if (!$res['ok']) {
            session()->forget(self::sessionKey('gst'));
            return response()->json(['ok' => false, 'error' => $res['error']], 422);
        }

        $data = [
            'gstin'                  => $request->gst_number,
            'legal_name_of_business' => $res['data']['legal_name_of_business'] ?? '',
            'trade_name_of_business' => $res['data']['trade_name_of_business'] ?? '',
            'gst_in_status'          => $res['data']['gst_in_status'] ?? '',
        ];

        // GSTIN characters 3-12 are the PAN of the business
        $pan = session(self::sessionKey('pan'));
        if ($pan && substr($request->gst_number, 2, 10) !== $pan['pan']) {
            $data['pan_mismatch'] = true;
        }

        session([self::sessionKey('gst') => $data]);

        return response()->json(['ok' => true, 'message' => 'GSTIN verified', 'data' => $data]);
    }

    public function verifyBankAccount(Request $request)
    {
        if ($r = $this->agreementGate()) {
            return $r;
        }
        $request->merge(['ifsc' => strtoupper(trim((string) $request->ifsc)), 'account_number' => preg_replace('/\s+/', '', (string) $request->account_number)]);
        $request->validate([
            'account_number' => ['required', 'regex:/^[A-Za-z0-9]{6,40}$/'],
            'ifsc'           => ['required', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
        ], ['ifsc.regex' => 'Invalid IFSC code', 'account_number.regex' => 'Invalid account number']);

        $res = $this->kyc->verifyBank($request->account_number, $request->ifsc);
        if (!$res['ok']) {
            session()->forget(self::sessionKey('bank'));
            return response()->json(['ok' => false, 'error' => $res['error']], 422);
        }

        $data = [
            'bank_account' => $request->account_number,
            'ifsc'         => $request->ifsc,
            'name_at_bank' => $res['data']['name_at_bank'] ?? '',
            'bank_name'    => $res['data']['bank_name'] ?? ($res['data']['ifsc_details']['bank'] ?? ''),
            'branch'       => $res['data']['branch'] ?? ($res['data']['ifsc_details']['branch'] ?? ''),
        ];
        session([self::sessionKey('bank') => $data]);

        return response()->json(['ok' => true, 'message' => 'Bank account verified', 'data' => $data]);
    }

    public function verifyCIN(Request $request)
    {
        if ($r = $this->agreementGate()) {
            return $r;
        }
        $request->validate(['cin_number' => 'required|string|max:21']);
        $res = $this->kyc->verifyCin($request->cin_number);

        return $res['ok']
            ? response()->json(['ok' => true, 'data' => $res['data']])
            : response()->json(['ok' => false, 'error' => $res['error']], 422);
    }

    public function submitKyc()
    {
        return redirect()->back()->with('error', 'Please use the KYC form on the dashboard.');
    }
}
