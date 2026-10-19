{{-- Step 1 of onboarding: sign the Client Service Agreement with Aadhaar (DigiLocker via Cashfree Secure ID) --}}
@php
    $sxAadhaar = session(\App\Http\Controllers\selleradmin\CashfreeController::sessionKey('aadhaar'));
@endphp
<div id="sxSign" class="sx-sign mt-4">
    <h5 class="mb-1">Sign this agreement with Aadhaar</h5>
    <p class="text-muted small mb-3">
        Step 1 of 2. Verify your Aadhaar through DigiLocker; your Aadhaar name and address are used as your e-signature on this agreement.
        After signing you will complete KYC (PAN, GST, bank account).
    </p>

    <div id="sxAadhaarStart" class="{{ $sxAadhaar ? 'd-none' : '' }}">
        <button type="button" class="btn btn-success" id="sxDigilockerBtn">
            Verify Aadhaar with DigiLocker
        </button>
        <div class="small text-muted mt-2">A DigiLocker window will open. Log in, allow access to Aadhaar, then come back to this tab.</div>
    </div>

    <div id="sxAadhaarStatus" class="mt-2"></div>

    <div id="sxAadhaarDetails" class="border rounded p-3 mt-3 {{ $sxAadhaar ? '' : 'd-none' }}">
        <div class="text-success fw-bold mb-2">&#10003; Aadhaar verified</div>
        <div class="row g-2 small">
            <div class="col-md-6"><span class="text-muted">Name:</span> <strong id="sxAName">{{ $sxAadhaar['name'] ?? '' }}</strong></div>
            <div class="col-md-6"><span class="text-muted">Aadhaar:</span> <span id="sxAUid">{{ $sxAadhaar['uid_masked'] ?? '' }}</span></div>
            <div class="col-md-6"><span class="text-muted">Care of:</span> <span id="sxACareOf">{{ $sxAadhaar['care_of'] ?? '' }}</span></div>
            <div class="col-md-6"><span class="text-muted">DOB:</span> <span id="sxADob">{{ $sxAadhaar['dob'] ?? '' }}</span></div>
            <div class="col-12"><span class="text-muted">Address:</span> <span id="sxAAddress">{{ $sxAadhaar['address'] ?? '' }}</span></div>
        </div>

        <div class="mt-3">
            <label class="form-label small mb-1" for="sxClientPan">Business PAN (optional, printed on the agreement)</label>
            <input type="text" class="form-control text-uppercase" id="sxClientPan" maxlength="10" placeholder="ABCDE1234F">
        </div>

        <div class="form-check mt-3">
            <input class="form-check-input" type="checkbox" id="sxAgree">
            <label class="form-check-label" for="sxAgree">
                I have read and agree to all terms of this Client Service Agreement and sign it electronically with my Aadhaar.
            </label>
        </div>

        <button type="button" class="btn btn-primary w-100 mt-3" id="sxSignBtn" disabled>Sign Agreement</button>
    </div>
</div>

<script>
(function () {
    var csrf = '{{ csrf_token() }}';
    var startUrl = '{{ route('seller.digilocker.start') }}';
    var statusUrl = '{{ route('seller.digilocker.status') }}';
    var signUrl = '{{ route('seller.agreement.accept') }}';
    var pollTimer = null, pollCount = 0;

    function $(id) { return document.getElementById(id); }
    function setStatus(html, cls) { $('sxAadhaarStatus').innerHTML = html ? '<div class="small ' + (cls || '') + '">' + html + '</div>' : ''; }
    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

    function showAadhaar(a) {
        $('sxAName').textContent = a.name || '';
        $('sxAUid').textContent = a.uid_masked || '';
        $('sxACareOf').textContent = a.care_of || '';
        $('sxADob').textContent = a.dob || '';
        $('sxAAddress').textContent = a.address || '';
        $('sxAadhaarStart').classList.add('d-none');
        $('sxAadhaarDetails').classList.remove('d-none');
        setStatus('');
    }

    function poll() {
        pollCount++;
        fetch(statusUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.ok && d.aadhaar) { stopPoll(); showAadhaar(d.aadhaar); return; }
                if (d.status === 'PENDING' && pollCount < 150) { setStatus('<span class="spinner-border spinner-border-sm me-1"></span>' + esc(d.error), 'text-muted'); return; }
                stopPoll();
                setStatus(esc(d.error || 'Aadhaar verification failed.'), 'text-danger');
                $('sxDigilockerBtn').disabled = false;
            })
            .catch(function () { if (pollCount >= 150) stopPoll(); });
    }
    function stopPoll() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }

    var dlBtn = $('sxDigilockerBtn');
    if (dlBtn) dlBtn.addEventListener('click', function () {
        // open the window synchronously so pop-up blockers allow it
        var win = window.open('', '_blank');
        dlBtn.disabled = true;
        setStatus('Starting DigiLocker…', 'text-muted');

        fetch(startUrl, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) { if (win) win.close(); dlBtn.disabled = false; setStatus(esc(d.error || 'Could not start DigiLocker.'), 'text-danger'); return; }
                if (win) { win.location.href = d.url; } else { window.location.href = d.url; return; }
                pollCount = 0; stopPoll();
                pollTimer = setInterval(poll, 4000);
                setStatus('<span class="spinner-border spinner-border-sm me-1"></span>Complete the steps in the DigiLocker tab…', 'text-muted');
            })
            .catch(function () { if (win) win.close(); dlBtn.disabled = false; setStatus('Network error. Please try again.', 'text-danger'); });
    });

    // If the seller comes back to this tab, check immediately
    document.addEventListener('visibilitychange', function () { if (!document.hidden && pollTimer) poll(); });

    var agree = $('sxAgree');
    if (agree) agree.addEventListener('change', function () { $('sxSignBtn').disabled = !this.checked; });

    var signBtn = $('sxSignBtn');
    if (signBtn) signBtn.addEventListener('click', function () {
        if (!$('sxAgree').checked) return;
        signBtn.disabled = true; signBtn.textContent = 'Signing…';
        var body = new FormData();
        body.append('_token', csrf);
        body.append('agree', '1');
        body.append('clientPAN', ($('sxClientPan').value || '').toUpperCase().trim());

        fetch(signUrl, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf } })
            .then(function (r) { return r.json().catch(function () { return { success: false, message: 'Unexpected server response (' + r.status + ').' }; }); })
            .then(function (d) {
                if (d.success) {
                    setStatus(esc(d.message), 'text-success');
                    setTimeout(function () { location.reload(); }, 900);
                } else {
                    signBtn.disabled = false; signBtn.textContent = 'Sign Agreement';
                    setStatus(esc(d.message || 'Could not sign the agreement.'), 'text-danger');
                }
            })
            .catch(function () { signBtn.disabled = false; signBtn.textContent = 'Sign Agreement'; setStatus('Network error. Please try again.', 'text-danger'); });
    });

    // Show the agreement modal automatically (cannot be dismissed until signed)
    window.addEventListener('load', function () {
        var el = document.getElementById('agreementModal');
        if (el && window.bootstrap) bootstrap.Modal.getOrCreateInstance(el, { backdrop: 'static', keyboard: false }).show();
        var dateEl = document.getElementById('current-date');
        if (dateEl) dateEl.textContent = new Date().toLocaleDateString('en-IN', { year: 'numeric', month: 'long', day: 'numeric' });
    });
})();
</script>
