{{-- Step 2 of onboarding: KYC (PAN, GSTIN, Bank) via Cashfree Secure ID. Shown only after the agreement is signed. --}}
@php
    $sxKey = fn ($w) => \App\Http\Controllers\selleradmin\CashfreeController::sessionKey($w);
    $sxPan = session($sxKey('pan'));
    $sxGst = session($sxKey('gst'));
    $sxBank = session($sxKey('bank'));
@endphp
<meta name="csrf-token" content="{{ csrf_token() }}">

@if ($seller->kyc_status == 0)
    @if ($seller->agreement_accepted != 1)
        <div class="kyc-profile">
            <button type="button" class="btn btn-warning d-flex justify-content-center" data-bs-toggle="modal" data-bs-target="#agreementModal">
                <div class="alert alert-warning mb-0" role="alert">
                    <strong>Step 1 of 2:</strong> Please sign the Client Service Agreement with Aadhaar to continue.
                </div>
            </button>
        </div>
    @else
        <div class="kyc-profile">
            <button type="button" class="btn btn-warning d-flex justify-content-center" data-bs-toggle="modal" data-bs-target="#kycModal">
                <div class="alert alert-warning mb-0" role="alert">
                    <strong>Step 2 of 2:</strong> Agreement signed. Please complete your KYC (PAN, GST, bank account) to start shipping.
                </div>
            </button>
        </div>

        <div class="modal fade" id="kycModal" tabindex="-1" aria-labelledby="kycModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header d-flex justify-content-between align-items-center">
                        <h5 class="modal-title" id="kycModalLabel">Complete Your KYC</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <form action="{{ route('seller.kyc.complete') }}" method="POST" id="kycForm">
                        @csrf
                        <div class="modal-body">

                            <!-- PAN -->
                            <div class="mb-3">
                                <label for="sxPan" class="form-label">1. PAN Card Number <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="text" class="form-control text-uppercase" id="sxPan" maxlength="10" placeholder="ABCDE1234F" value="{{ $sxPan['pan'] ?? '' }}">
                                    <button type="button" class="btn btn-warning" id="sxPanBtn">Verify</button>
                                </div>
                                <div id="sxPanStatus" class="mt-1 small">
                                    @if ($sxPan)<span class="text-success">&#10003; PAN verified: {{ $sxPan['registered_name'] }}</span>@endif
                                </div>
                            </div>
                            <hr class="my-3">

                            <!-- GSTIN -->
                            <div class="mb-3">
                                <label for="sxGst" class="form-label">2. GSTIN <span class="text-muted small">(if GST registered)</span></label>
                                <div class="input-group">
                                    <input type="text" class="form-control text-uppercase" id="sxGst" maxlength="15" placeholder="07ABCDE1234F1Z5" value="{{ $sxGst['gstin'] ?? '' }}">
                                    <button type="button" class="btn btn-info" id="sxGstBtn">Verify</button>
                                </div>
                                <div id="sxGstStatus" class="mt-1 small">
                                    @if ($sxGst)<span class="text-success">&#10003; GST verified: {{ $sxGst['legal_name_of_business'] }}</span>@endif
                                </div>
                            </div>
                            <hr class="my-3">

                            <!-- Bank -->
                            <div class="mb-2">
                                <label class="form-label" for="sxAcc">3. Bank Account Number <span class="text-danger">*</span></label>
                                <input type="text" id="sxAcc" class="form-control" placeholder="Enter bank account number" value="{{ $sxBank['bank_account'] ?? '' }}">
                            </div>
                            <div class="mb-2">
                                <label class="form-label" for="sxIfsc">IFSC Code <span class="text-danger">*</span></label>
                                <input type="text" id="sxIfsc" class="form-control text-uppercase" maxlength="11" placeholder="HDFC0000001" value="{{ $sxBank['ifsc'] ?? '' }}">
                            </div>
                            <button type="button" id="sxBankBtn" class="btn btn-primary w-100">Verify Bank Account</button>
                            <div id="sxBankStatus" class="mt-1 small">
                                @if ($sxBank)<span class="text-success">&#10003; Verified: {{ $sxBank['name_at_bank'] }} ({{ $sxBank['bank_name'] }})</span>@endif
                            </div>

                            <hr class="my-3">
                            <button type="submit" class="btn btn-success w-100" id="sxKycSubmit" {{ ($sxPan && $sxBank) ? '' : 'disabled' }}>Complete Your KYC</button>
                            <div class="small text-muted mt-2 text-center">PAN and bank account must be verified. GST is verified if entered.</div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
        (function () {
            var csrf = '{{ csrf_token() }}';
            var done = { pan: {{ $sxPan ? 'true' : 'false' }}, bank: {{ $sxBank ? 'true' : 'false' }} };
            function $(id) { return document.getElementById(id); }
            function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
            function refresh() { $('sxKycSubmit').disabled = !(done.pan && done.bank); }

            function call(url, payload, btn, statusEl, onOk) {
                var label = btn.textContent;
                btn.disabled = true; btn.textContent = 'Verifying…';
                statusEl.innerHTML = '';
                fetch(url, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                    body: JSON.stringify(payload)
                })
                .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Unexpected server response (' + r.status + ')' }; }); })
                .then(function (d) {
                    if (d.ok) { statusEl.innerHTML = '<span class="text-success">&#10003; ' + onOk(d.data) + '</span>'; }
                    else {
                        var msg = d.error || d.message || 'Verification failed';
                        if (d.errors) { msg = Object.values(d.errors).flat().join(' '); }
                        statusEl.innerHTML = '<span class="text-danger">' + esc(msg) + '</span>';
                    }
                    return d.ok;
                })
                .catch(function () { statusEl.innerHTML = '<span class="text-danger">Network error. Please try again.</span>'; return false; })
                .then(function (ok) { btn.disabled = false; btn.textContent = label; return ok; })
                .then(function (ok) { if (payload._k) { done[payload._k] = !!ok; refresh(); } });
            }

            $('sxPanBtn').addEventListener('click', function () {
                var pan = $('sxPan').value.trim().toUpperCase();
                if (!/^[A-Z]{5}[0-9]{4}[A-Z]$/.test(pan)) { $('sxPanStatus').innerHTML = '<span class="text-danger">Invalid PAN format.</span>'; return; }
                call('{{ route('pan.verify') }}', { pan_number: pan, _k: 'pan' }, this, $('sxPanStatus'), function (d) { return 'PAN verified: ' + esc(d.registered_name); });
            });

            $('sxGstBtn').addEventListener('click', function () {
                var gst = $('sxGst').value.trim().toUpperCase();
                if (!/^[0-9]{2}[A-Z0-9]{13}$/.test(gst)) { $('sxGstStatus').innerHTML = '<span class="text-danger">Invalid GSTIN format.</span>'; return; }
                call('{{ route('gst.verify') }}', { gst_number: gst }, this, $('sxGstStatus'), function (d) {
                    return 'GST verified: ' + esc(d.legal_name_of_business) + (d.gst_in_status ? ' (' + esc(d.gst_in_status) + ')' : '')
                        + (d.pan_mismatch ? ' <span class="text-warning">— note: GSTIN does not belong to the PAN entered above</span>' : '');
                });
            });

            $('sxBankBtn').addEventListener('click', function () {
                var acc = $('sxAcc').value.replace(/\s+/g, ''), ifsc = $('sxIfsc').value.trim().toUpperCase();
                if (!acc || !/^[A-Z]{4}0[A-Z0-9]{6}$/.test(ifsc)) { $('sxBankStatus').innerHTML = '<span class="text-danger">Enter a valid account number and IFSC.</span>'; return; }
                call('{{ route('bank.verify') }}', { account_number: acc, ifsc: ifsc, _k: 'bank' }, this, $('sxBankStatus'), function (d) {
                    return 'Verified: ' + esc(d.name_at_bank) + (d.bank_name ? ' (' + esc(d.bank_name) + ')' : '');
                });
            });

            // Re-entering a value means it must be verified again
            ['sxPan', 'sxAcc', 'sxIfsc'].forEach(function (id) {
                $(id).addEventListener('input', function () { done[id === 'sxPan' ? 'pan' : 'bank'] = false; refresh(); });
            });
        })();
        </script>
    @endif
@endif
