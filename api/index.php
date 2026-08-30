<?php
/*
 * LodgeReview Lab — single-file school project
 * ------------------------------------------------------------
 * Put your MegaPay credentials below and upload this one file.
 * Keep demo_mode true for classroom testing without real charges.
 */
$config = [
    'megapay_api_key' => 'MGPYlWU6lMpS',
    'megapay_email'   => 'denniskoskey5@gmail.com',
    'demo_mode'       => false,
    'initiate_url'    => 'https://megapay.co.ke/backend/v1/initiatestk',
    'status_url'      => 'https://megapay.co.ke/backend/v1/transactionstatus',
];

$action = $_GET['action'] ?? '';

if ($action !== '') {
    // API calls must never emit HTML warnings/notices before JSON.
    ini_set('display_errors', '0');
    ini_set('html_errors', '0');
    error_reporting(E_ALL);
    ob_start();

    set_error_handler(function ($severity, $message, $file, $line) {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        // PHP 8.4/8.5 can emit deprecation notices for harmless legacy calls.
        // Do not turn deprecations into failed API responses.
        if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
            return true;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
}

function json_response(array $data, int $status = 200): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function json_input(): array {
    $input = json_decode(file_get_contents('php://input'), true);
    return is_array($input) ? $input : [];
}

function megapay_post(string $url, array $payload): array {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'code' => 0, 'data' => [], 'error' => 'PHP cURL extension is not enabled on this server.'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    if ($body === false) return ['ok' => false, 'code' => $code, 'data' => [], 'error' => $error ?: 'Gateway connection failed.'];
    $data = json_decode($body, true);
    if (!is_array($data)) {
        $preview = trim(strip_tags((string)$body));
        $preview = preg_replace('/\s+/', ' ', $preview);
        if (strlen($preview) > 240) {
            $preview = substr($preview, 0, 240) . '...';
        }
        return [
            'ok' => false,
            'code' => $code,
            'data' => [],
            'error' => 'MegaPay returned a non-JSON response' . ($preview !== '' ? ': ' . $preview : '.'),
        ];
    }
    return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'data' => $data, 'error' => ''];
}

if ($action !== '') {
    try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['success' => false, 'completed' => false, 'failed' => true, 'message' => 'Method not allowed'], 405);
    }

    if ($action === 'initiate') {
        $input = json_input();
        $phone = preg_replace('/\\s+/', '', (string)($input['phone'] ?? ''));
        $lodgeId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($input['lodge_id'] ?? ''));
        $userId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($input['user_id'] ?? ''));

        if (!preg_match('/^(?:254|0)(?:7|1)\\d{8}$/', $phone)) {
            json_response(['success' => false, 'message' => 'Enter a valid Kenyan M-Pesa number.'], 422);
        }
        if (!$lodgeId || !$userId) {
            json_response(['success' => false, 'message' => 'Missing lodge or user reference.'], 422);
        }

        $reference = 'LRL-' . strtoupper(substr(hash('sha256', $userId.'|'.$lodgeId.'|'.microtime(true)), 0, 14));

        if (!empty($config['demo_mode'])) {
            json_response([
                'success' => true,
                'demo' => true,
                'message' => 'Demo mode: simulated KES 100 STK payment approved for classroom testing.',
                'transaction_request_id' => 'DEMO-' . time(),
                'reference' => $reference,
            ]);
        }

        if (empty($config['megapay_api_key']) || empty($config['megapay_email']) ||
            $config['megapay_api_key'] === 'PASTE_YOUR_MEGAPAY_API_KEY_HERE') {
            json_response(['success' => false, 'message' => 'MegaPay credentials are not configured in index.php.'], 500);
        }

        $payload = [
            'api_key' => $config['megapay_api_key'],
            'email' => $config['megapay_email'],
            'amount' => 100,
            'msisdn' => $phone,
            'reference' => $reference,
        ];
        $response = megapay_post($config['initiate_url'], $payload);
        $data = $response['data'];
        if ($response['ok'] && !empty($data['transaction_request_id'])) {
            json_response([
                'success' => true,
                'demo' => false,
                'transaction_request_id' => $data['transaction_request_id'],
                'reference' => $reference,
            ]);
        }
        json_response([
            'success' => false,
            'message' => $data['ResponseDescription'] ?? $data['message'] ?? $data['massage'] ?? $response['error'] ?? 'MegaPay could not initiate the STK request.',
            'gateway_response' => $data,
        ], 502);
    }

    if ($action === 'status') {
        $input = json_input();
        $tx = trim((string)($input['transaction_request_id'] ?? ''));
        if (!$tx) json_response(['completed' => false, 'failed' => true, 'message' => 'Missing transaction id'], 422);

        if (!empty($config['demo_mode'])) {
            json_response(['completed' => true, 'failed' => false, 'receipt' => 'DEMO-RECEIPT', 'status' => 'Completed']);
        }

        $payload = [
            'api_key' => $config['megapay_api_key'],
            'email' => $config['megapay_email'],
            'transaction_request_id' => $tx,
        ];
        $response = megapay_post($config['status_url'], $payload);
        $data = $response['data'];
        if (!$response['ok'] && !$data) {
            json_response(['completed' => false, 'failed' => false, 'status' => 'Pending', 'message' => $response['error'] ?: 'Could not check payment status.'], 502);
        }
        $status = strtolower((string)($data['TransactionStatus'] ?? ''));
        $txCode = (string)($data['TransactionCode'] ?? '');
        $completed = ($status === 'completed' && ($txCode === '' || $txCode === '0'));
        $failed = in_array($status, ['failed','cancelled','canceled','expired'], true) || ($txCode !== '' && $txCode !== '0' && $status !== 'pending');
        json_response([
            'completed' => $completed,
            'failed' => $failed,
            'status' => $data['TransactionStatus'] ?? 'Pending',
            'receipt' => $data['TransactionReceipt'] ?? null,
            'message' => $data['ResultDesc'] ?? ($failed ? 'Payment failed.' : 'Payment is pending.'),
        ]);
    }

    json_response(['success' => false, 'message' => 'Unknown action.'], 404);
    } catch (Throwable $e) {
        json_response([
            'success' => false,
            'completed' => false,
            'failed' => true,
            'message' => 'Server error: ' . $e->getMessage(),
        ], 500);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="description" content="LodgeReview" />
  <title>LodgeReview Lab</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
  <style>
:root{--ink:#17231d;--muted:#6b746f;--cream:#f7f4ed;--green:#174f3c;--green2:#246c53;--gold:#d6aa62;--white:#fff;--line:#e5e4de;--danger:#a63d40;--shadow:0 18px 60px rgba(23,35,29,.12)}
*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;font-family:'DM Sans',sans-serif;color:var(--ink);background:#fcfbf8}.hidden{display:none!important}.login-gated{display:none!important}.demo-banner{background:#102c22;color:#dcebe4;text-align:center;padding:8px 20px;font-size:12px;letter-spacing:.03em}.site-header{height:78px;display:flex;align-items:center;justify-content:space-between;padding:0 6vw;background:rgba(252,251,248,.93);backdrop-filter:blur(12px);position:sticky;top:0;z-index:20;border-bottom:1px solid rgba(0,0,0,.04)}.brand{display:flex;align-items:center;gap:10px;color:var(--ink);text-decoration:none;font-weight:700;font-size:20px}.brand b{color:var(--green)}.brand-mark{width:38px;height:38px;border-radius:50%;display:grid;place-items:center;background:var(--green);color:white;font-family:'Playfair Display',serif}.site-header nav{display:flex;align-items:center;gap:22px}.site-header nav>a{color:var(--ink);text-decoration:none;font-size:14px;font-weight:600}.btn{border:0;border-radius:999px;padding:11px 18px;font:inherit;font-weight:700;cursor:pointer;transition:.2s}.btn:hover{transform:translateY(-1px)}.btn-primary{background:var(--green);color:white}.btn-outline{background:transparent;border:1px solid #cfd4d1;color:var(--ink)}.btn-soft{background:#e8f0eb;color:var(--green)}.btn-lg{padding:14px 22px}.btn-full{width:100%;border-radius:12px;padding:14px}.hero{min-height:650px;padding:70px 7vw 80px;display:grid;grid-template-columns:1.05fr .95fr;align-items:center;gap:70px;background:radial-gradient(circle at 15% 20%,#eef4ee 0,transparent 35%)}.eyebrow{text-transform:uppercase;color:var(--green2);font-size:12px;letter-spacing:.16em;font-weight:800}.hero h1,.section h2,.how-section h2{font-family:'Playfair Display',serif;margin:14px 0 20px;font-size:clamp(44px,5vw,76px);line-height:1.02;letter-spacing:-.035em}.hero h1 em{font-style:normal;color:var(--green)}.hero-copy>p{max-width:620px;color:var(--muted);line-height:1.75;font-size:17px}.hero-actions{display:flex;gap:12px;margin:28px 0}.stats{display:flex;gap:38px;margin-top:40px}.stats div{display:flex;flex-direction:column}.stats strong{font-size:20px}.stats span{color:var(--muted);font-size:12px;margin-top:5px}.hero-card{position:relative;min-height:540px}.hero-photo{height:540px;border-radius:140px 140px 28px 28px;background:linear-gradient(180deg,rgba(0,0,0,.05),rgba(0,0,0,.25)),url('https://images.unsplash.com/photo-1566073771259-6a8506099945?auto=format&fit=crop&w=1200&q=85') center/cover;box-shadow:var(--shadow)}.floating-card{position:absolute;bottom:30px;left:-35px;background:white;padding:18px 22px;border-radius:16px;box-shadow:var(--shadow);display:flex;flex-direction:column;min-width:220px}.floating-card span,.floating-card small{color:var(--muted);font-size:12px}.floating-card strong{font-size:25px;color:var(--green);margin:4px 0}.section,.how-section{padding:90px 7vw}.section-head{display:flex;align-items:flex-end;justify-content:space-between;gap:25px;margin-bottom:35px}.section-head h2,.how-section h2{font-size:46px;margin-bottom:0}.filters{display:flex;gap:8px;background:#f1efe8;padding:5px;border-radius:999px}.filter{border:0;background:transparent;border-radius:999px;padding:9px 14px;cursor:pointer;font-weight:700;color:var(--muted)}.filter.active{background:white;color:var(--green);box-shadow:0 3px 10px rgba(0,0,0,.06)}.lodge-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:22px}.lodge-card{background:white;border:1px solid var(--line);border-radius:22px;overflow:hidden;transition:.22s}.lodge-card:hover{transform:translateY(-5px);box-shadow:var(--shadow)}.lodge-img{height:235px;background-size:cover;background-position:center;position:relative}.country-pill{position:absolute;top:15px;left:15px;background:rgba(255,255,255,.92);padding:7px 10px;border-radius:999px;font-size:11px;font-weight:800}.lodge-body{padding:20px}.lodge-title-row{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}.lodge-title-row h3{margin:0;font-family:'Playfair Display',serif;font-size:24px}.rating{font-size:13px;background:#f3f5f2;padding:6px 8px;border-radius:8px;white-space:nowrap}.location{color:var(--muted);font-size:13px;margin:7px 0 18px}.offer{display:flex;justify-content:space-between;align-items:end;border-top:1px solid var(--line);padding-top:16px}.offer span{display:block;font-size:11px;color:var(--muted)}.offer strong{font-size:20px;color:var(--green)}.offer button{padding:9px 13px}.how-section{background:var(--cream)}.steps{display:grid;grid-template-columns:repeat(4,1fr);gap:15px}.steps article{background:white;border-radius:18px;padding:25px;border:1px solid #e8e4da}.steps article>span{color:var(--gold);font-weight:800}.steps h3{font-size:18px}.steps p{color:var(--muted);line-height:1.6;font-size:14px}footer{padding:45px 7vw;background:#102c22;color:#d8e3dd;display:flex;justify-content:space-between;gap:30px;align-items:flex-end}footer strong{font-family:'Playfair Display';font-size:24px}footer p{max-width:520px;color:#9eb2a9;font-size:13px}.modal{position:fixed;inset:0;z-index:100;display:none;align-items:center;justify-content:center;padding:20px}.modal.open{display:flex}.modal-backdrop{position:absolute;inset:0;background:rgba(8,20,15,.68);backdrop-filter:blur(5px)}.modal-card{position:relative;z-index:1;background:white;border-radius:22px;box-shadow:var(--shadow);width:min(480px,100%);max-height:92vh;overflow:auto;padding:28px}.modal-close{position:absolute;right:16px;top:13px;width:35px;height:35px;border:0;border-radius:50%;background:#f3f3ef;font-size:22px;cursor:pointer}.auth-tabs{display:flex;background:#f4f3ee;border-radius:12px;padding:4px;margin-bottom:25px}.auth-tab{flex:1;border:0;padding:10px;border-radius:9px;background:transparent;font-weight:800;cursor:pointer}.auth-tab.active{background:white;box-shadow:0 2px 8px rgba(0,0,0,.07)}.form-panel{display:none}.form-panel.active{display:block}.form-panel h3{font-family:'Playfair Display';font-size:30px;margin:0 0 4px}.form-panel p{color:var(--muted);font-size:14px;margin-bottom:20px}.form-panel label,.payment-box label,.review-form label{display:block;font-size:13px;font-weight:700;margin:13px 0}.form-panel input,.payment-box input,.review-form input,.review-form select,.review-form textarea{width:100%;margin-top:7px;border:1px solid #d7dad7;border-radius:11px;padding:12px 13px;font:inherit;outline:none}.form-panel input:focus,.payment-box input:focus,.review-form textarea:focus{border-color:var(--green)}.lodge-modal-card{width:min(760px,100%);padding:0;overflow:hidden}.modal-lodge-image{height:270px;background-size:cover;background-position:center}.modal-lodge-body{padding:28px}.modal-lodge-body h2{font-family:'Playfair Display';font-size:35px;margin:5px 0}.modal-meta{color:var(--muted)}.reward-panel{margin:22px 0;background:#f2f6f3;border:1px solid #dbe8df;border-radius:16px;padding:18px;display:flex;align-items:center;justify-content:space-between}.reward-panel strong{font-size:24px;color:var(--green)}.payment-box{border:1px solid var(--line);border-radius:16px;padding:18px}.payment-status{padding:11px;border-radius:10px;margin-top:12px;font-size:13px;background:#f5f5f2}.payment-status.success{background:#e9f5ee;color:#205d40}.payment-status.error{background:#faecec;color:#8b3030}.review-form textarea{min-height:125px;resize:vertical}.stars-select{display:flex;gap:7px;margin:10px 0}.stars-select button{border:0;background:none;font-size:28px;color:#c6c7c4;cursor:pointer;padding:0}.stars-select button.on{color:#e0a93a}.dashboard-card{width:min(760px,100%)}.dash-top{display:flex;justify-content:space-between;gap:15px;align-items:center}.review-list{display:grid;gap:12px;margin-top:20px}.review-item{border:1px solid var(--line);border-radius:14px;padding:16px}.review-item h4{margin:0 0 6px}.review-item p{color:var(--muted);margin:8px 0;font-size:14px}.empty{padding:40px;text-align:center;color:var(--muted);background:#f7f7f3;border-radius:14px}.toast{position:fixed;right:24px;bottom:24px;z-index:200;background:#18221e;color:white;padding:13px 17px;border-radius:12px;box-shadow:var(--shadow);opacity:0;transform:translateY(10px);pointer-events:none;transition:.25s}.toast.show{opacity:1;transform:none}.spinner{display:inline-block;width:16px;height:16px;border:2px solid rgba(255,255,255,.5);border-top-color:white;border-radius:50%;animation:spin .8s linear infinite;vertical-align:-3px;margin-right:7px}@keyframes spin{to{transform:rotate(360deg)}}
@media(max-width:980px){.hero{grid-template-columns:1fr;gap:35px}.hero-card{min-height:auto}.hero-photo{height:420px}.lodge-grid{grid-template-columns:repeat(2,1fr)}.steps{grid-template-columns:repeat(2,1fr)}}
@media(max-width:720px){.site-header{height:68px;padding:0 4vw}.site-header nav>a{display:none}.site-header nav{gap:7px}.site-header .btn{padding:9px 12px;font-size:12px}.brand{font-size:16px}.brand-mark{width:32px;height:32px}.hero{padding:50px 5vw}.hero h1{font-size:46px}.hero-photo{height:360px;border-radius:80px 80px 22px 22px}.floating-card{left:10px;bottom:15px}.stats{gap:18px;justify-content:space-between}.section,.how-section{padding:65px 5vw}.section-head{align-items:flex-start;flex-direction:column}.section-head h2,.how-section h2{font-size:38px}.filters{width:100%;overflow:auto}.lodge-grid,.steps{grid-template-columns:1fr}.lodge-img{height:225px}footer{flex-direction:column;align-items:flex-start}.reward-panel{align-items:flex-start;flex-direction:column;gap:8px}}

/* Dashboard earnings + demo withdrawals */
.earnings-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:24px 0}.earning-card{border:1px solid var(--line);border-radius:16px;padding:18px;background:#fafaf7;display:flex;flex-direction:column;gap:5px}.earning-card.featured{background:#eef6f1;border-color:#d5e7db}.earning-card span{font-size:12px;color:var(--muted);font-weight:700}.earning-card strong{font-size:25px;color:var(--green)}.earning-card small{font-size:11px;color:var(--muted)}.withdraw-box{display:flex;justify-content:space-between;align-items:center;gap:18px;padding:18px;border:1px solid var(--line);border-radius:16px;margin-bottom:16px}.withdraw-box h3{margin:0 0 4px}.withdraw-box p{margin:0;color:var(--muted);font-size:13px}.withdraw-box .btn:disabled{opacity:.45;cursor:not-allowed;transform:none}.history-title{margin-top:28px}.withdraw-history{border:1px solid var(--line);border-radius:14px;overflow:hidden}.withdraw-history div{display:flex;justify-content:space-between;gap:15px;padding:13px 15px;border-bottom:1px solid var(--line);font-size:13px}.withdraw-history div:last-child{border-bottom:0}.withdraw-history span{color:var(--muted)}.withdraw-history strong{color:var(--green)}
@media(max-width:720px){.earnings-grid{grid-template-columns:1fr}.withdraw-box{align-items:stretch;flex-direction:column}.withdraw-box .btn{width:100%}}



/* Enhanced responsive/mobile layout */
@media (max-width: 720px){
  html,body{width:100%;max-width:100%;overflow-x:hidden}
  body{font-size:15px}
  .demo-banner{padding:7px 12px;font-size:11px}

  .site-header{height:auto;min-height:64px;padding:10px 14px;gap:10px}
  .brand{min-width:0;gap:8px;font-size:15px}
  .brand span:last-child{white-space:nowrap}
  .brand-mark{flex:0 0 32px}
  .site-header nav{margin-left:auto;gap:6px;min-width:0}
  .site-header nav .btn{padding:9px 10px;font-size:11px;white-space:nowrap}

  .hero{min-height:auto;padding:38px 16px 52px;gap:28px}
  .hero-copy{min-width:0}
  .hero h1{font-size:clamp(38px,12vw,50px);line-height:1.04;margin:10px 0 16px}
  .hero-copy>p{font-size:15px;line-height:1.65}
  .hero-actions{display:grid;grid-template-columns:1fr;gap:10px;margin:22px 0}
  .hero-actions .btn{width:100%;text-align:center}
  .stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:28px}
  .stats div{background:#fff;border:1px solid var(--line);border-radius:12px;padding:12px 8px;text-align:center;min-width:0}
  .stats strong{font-size:16px;line-height:1.2}
  .stats span{font-size:10px;line-height:1.25}
  .hero-card{width:100%;min-width:0}
  .hero-photo{height:330px;border-radius:62px 62px 20px 20px}
  .floating-card{left:12px;right:12px;bottom:12px;min-width:0;width:auto;padding:14px 16px}
  .floating-card strong{font-size:21px}

  .section,.how-section{padding:54px 16px}
  .section-head{gap:18px;margin-bottom:26px}
  .section-head h2,.how-section h2{font-size:clamp(32px,10vw,40px);line-height:1.08}
  .filters{max-width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch;scrollbar-width:none}
  .filters::-webkit-scrollbar{display:none}
  .filter{flex:0 0 auto}

  .lodge-grid{gap:16px}
  .lodge-card{border-radius:18px}
  .lodge-img{height:210px}
  .lodge-body{padding:16px}
  .lodge-title-row h3{font-size:22px}
  .offer{align-items:stretch;flex-direction:column;gap:12px}
  .offer button{width:100%;padding:11px 14px}

  .steps{gap:12px}
  .steps article{padding:20px}

  footer{padding:34px 16px;gap:16px}

  .modal{padding:10px;align-items:flex-end}
  .modal-card{width:100%;max-height:94dvh;border-radius:20px 20px 0 0;padding:22px 16px calc(20px + env(safe-area-inset-bottom))}
  .auth-card{padding-top:24px}
  .lodge-modal-card{width:100%;border-radius:20px 20px 0 0}
  .modal-lodge-image{height:220px}
  .modal-lodge-body{padding:20px 16px calc(22px + env(safe-area-inset-bottom))}
  .modal-lodge-body h2{font-size:30px;line-height:1.1;padding-right:34px}
  .modal-close{right:12px;top:10px;z-index:3}
  .form-panel h3{font-size:27px;padding-right:30px}
  .form-panel input,.payment-box input,.review-form input,.review-form select,.review-form textarea{font-size:16px;padding:13px}
  .payment-box{padding:16px}
  .reward-panel{padding:16px}
  .reward-panel strong{font-size:22px}
  .stars-select{gap:10px;flex-wrap:wrap}
  .stars-select button{font-size:31px;min-width:34px;min-height:40px}

  .dashboard-card{width:100%}
  .dash-top{align-items:flex-start;flex-direction:column;padding-right:38px}
  .dash-top .btn{width:100%}
  .earnings-grid{gap:10px}
  .earning-card{padding:15px}
  .earning-card strong{font-size:22px}
  .withdraw-history div{align-items:flex-start;flex-direction:column;gap:4px}

  .toast{left:12px;right:12px;bottom:calc(12px + env(safe-area-inset-bottom));text-align:center;padding:12px 14px}
}


/* Mobile lodge modal scrolling fix */
@media (max-width: 720px){
  #lodgeModal{
    align-items:stretch;
    justify-content:center;
    padding:0;
    overflow:hidden;
  }
  #lodgeModal .modal-backdrop{position:fixed}
  #lodgeModal .lodge-modal-card{
    width:100%;
    height:100dvh;
    max-height:100dvh;
    margin:0;
    border-radius:0;
    overflow-y:auto !important;
    overflow-x:hidden !important;
    -webkit-overflow-scrolling:touch;
    overscroll-behavior:contain;
    scrollbar-gutter:stable;
    touch-action:pan-y;
  }
  #lodgeModalContent{
    display:block;
    min-height:100%;
    padding-bottom:calc(28px + env(safe-area-inset-bottom));
  }
  #lodgeModal .modal-close{
    position:fixed;
    top:calc(10px + env(safe-area-inset-top));
    right:12px;
    z-index:105;
    box-shadow:0 4px 16px rgba(0,0,0,.16);
  }
  #lodgeModal .modal-lodge-image{
    height:clamp(170px,31vh,230px);
    min-height:170px;
  }
  #lodgeModal .modal-lodge-body{
    padding:18px 16px calc(42px + env(safe-area-inset-bottom));
  }
  #lodgeModal .modal-lodge-body h2{
    font-size:clamp(27px,8vw,32px);
    overflow-wrap:anywhere;
  }
  #lodgeModal .modal-lodge-body>p{
    font-size:14px;
    line-height:1.6;
  }
  #lodgeModal .reward-panel{
    margin:16px 0;
    gap:6px;
  }
  #lodgeModal .payment-box,
  #lodgeModal .review-form{
    width:100%;
    min-width:0;
  }
  #lodgeModal .payment-box h3,
  #lodgeModal .review-form h3{
    margin-top:0;
  }
  #lodgeModal .payment-box input,
  #lodgeModal .review-form input,
  #lodgeModal .review-form textarea{
    max-width:100%;
  }
  #lodgeModal .review-form textarea{
    min-height:140px;
  }
  #lodgeModal .btn-full{
    min-height:48px;
  }
}

@media (max-width: 420px){
  .brand span:last-child{max-width:125px;overflow:hidden;text-overflow:ellipsis}
  .site-header nav .btn{padding:8px 9px;font-size:10px}
  .hero{padding-left:14px;padding-right:14px}
  .hero h1{font-size:36px}
  .stats{grid-template-columns:1fr;gap:8px}
  .stats div{flex-direction:row;justify-content:space-between;align-items:center;text-align:left;padding:11px 13px}
  .stats span{margin-top:0}
  .section,.how-section{padding-left:14px;padding-right:14px}
  .lodge-img{height:195px}
  .modal{padding:0}
  .modal-card,.lodge-modal-card{border-radius:18px 18px 0 0}
  .modal-lodge-image{height:190px}
}
  </style>
</head>
<body>
  <div class="demo-banner"> · Review and get paid</div>
  <header class="site-header">
    <a class="brand" href="#home" aria-label="LodgeReview Lab home">
      <span class="brand-mark">LR</span>
      <span>LodgeReview <b>Lab</b></span>
    </a>
    <nav>
      <a href="#lodges">Lodges</a>
      <a href="#how">How it works</a>
      <button id="authBtn" class="btn btn-outline">Login</button>
      <button id="dashboardBtn" class="btn btn-primary hidden">My Dashboard</button>
    </nav>
  </header>

  <main>
    <section id="home" class="hero">
      <div class="hero-copy">
        <span class="eyebrow">Explore · Unlock · Review</span>
        <h1>Discover standout stays across the <em>United States</em> and <em>UAE.</em></h1>
        <p>Browse lodges profiles. Registered users can unlock one review submission per lodge after a KES 100 service payment.</p>
        <div class="hero-actions">
          <a href="#lodges" class="btn btn-primary btn-lg">Browse lodges</a>
          <button class="btn btn-soft btn-lg" id="joinBtn">Create account</button>
        </div>
        <div class="stats">
          <div><strong>12</strong><span> lodges Available</span></div>
          <div><strong>KES 100</strong><span>Unlock fee</span></div>
          <div><strong>2</strong><span>Destinations</span></div>
        </div>
      </div>
      <div class="hero-card">
        <div class="hero-photo"></div>
        <div class="floating-card">
          <span>Featured offer</span>
          <strong>KES 2,350</strong>
          <small>Review reward</small>
        </div>
      </div>
    </section>

    <section id="loginGate" class="section login-gate-section">
      <div style="max-width:680px;margin:0 auto;text-align:center;background:#fff;border:1px solid var(--line);border-radius:22px;padding:42px 24px;box-shadow:var(--shadow)">
        <span class="eyebrow">Members only</span>
        <h2 style="font-family:'Playfair Display',serif;font-size:clamp(32px,7vw,46px);margin:12px 0 14px">Login to view available lodges</h2>
        <p style="color:var(--muted);line-height:1.7;margin:0 auto 22px;max-width:520px">Create an account or sign in to browse the lodge offers and submit reviews.</p>
        <button class="btn btn-primary btn-lg" id="gateLoginBtn">Login / Create account</button>
      </div>
    </section>

    <section id="lodges" class="section login-gated">
      <div class="section-head">
        <div>
          <span class="eyebrow">Curated lodges directory</span>
          <h2>Choose a lodge to review</h2>
        </div>
        <div class="filters" role="group" aria-label="Filter lodges">
          <button class="filter active" data-country="all">All</button>
          <button class="filter" data-country="USA">United States</button>
          <button class="filter" data-country="UAE">UAE</button>
        </div>
      </div>
      <div id="lodgeGrid" class="lodge-grid"></div>
    </section>

    <section id="how" class="how-section">
      <div class="section-head compact">
        <div>
          <span class="eyebrow">Payment flow</span>
          <h2>How it works</h2>
        </div>
      </div>
      <div class="steps">
        <article><span>01</span><h3>Create an account</h3></article>
        <article><span>02</span><h3>Choose a lodge</h3></article>
        <article><span>03</span><h3>Pay KES 100</h3><p>Enter your M-Pesa number and trigger STK Push</p></article>
        <article><span>04</span><h3>Submit your review</h3><p>The review form unlocks only when the payment status returns completed. Happy Earning!</p></article>
      </div>
    </section>
  </main>

  <footer>
    <div><strong>LodgeReview Lab</strong></div>
    <div>© <span id="year"></span></div>
  </footer>

  <div class="modal" id="authModal" aria-hidden="true">
    <div class="modal-backdrop" data-close="authModal"></div>
    <div class="modal-card auth-card">
      <button class="modal-close" data-close="authModal">×</button>
      <div class="auth-tabs">
        <button class="auth-tab active" data-auth-tab="login">Login</button>
        <button class="auth-tab" data-auth-tab="register">Register</button>
      </div>
      <form id="loginForm" class="form-panel active">
        <h3>Welcome back</h3>
        <p>Login to unlock lodge reviews.</p>
        <label>Email<input type="email" id="loginEmail" required autocomplete="email"></label>
        <label>Password<input type="password" id="loginPassword" required autocomplete="current-password"></label>
        <button class="btn btn-primary btn-full" type="submit">Login</button>
      </form>
      <form id="registerForm" class="form-panel">
        <h3>Create your account</h3>
        <label>Full name<input type="text" id="regName" required maxlength="60"></label>
        <label>Email<input type="email" id="regEmail" required autocomplete="email"></label>
        <label>Password<input type="password" id="regPassword" required minlength="6" autocomplete="new-password"></label>
        <button class="btn btn-primary btn-full" type="submit">Create account</button>
      </form>
    </div>
  </div>

  <div class="modal" id="lodgeModal" aria-hidden="true">
    <div class="modal-backdrop" data-close="lodgeModal"></div>
    <div class="modal-card lodge-modal-card">
      <button class="modal-close" data-close="lodgeModal">×</button>
      <div id="lodgeModalContent"></div>
    </div>
  </div>

  <div class="modal" id="dashboardModal" aria-hidden="true">
    <div class="modal-backdrop" data-close="dashboardModal"></div>
    <div class="modal-card dashboard-card">
      <button class="modal-close" data-close="dashboardModal">×</button>
      <div id="dashboardContent"></div>
    </div>
  </div>

  <div id="toast" class="toast"></div>
  <script>
const lodges = [
  {id:'us-1',country:'USA',city:'Aspen, Colorado',name:'Silver Pine Lodge',rating:4.8,offer:2350,img:'https://images.unsplash.com/photo-1551882547-ff40c63fe5fa?auto=format&fit=crop&w=900&q=80',desc:'An alpine retreat surrounded by mountain scenery, fireplaces and quiet winter charm.'},
  {id:'us-2',country:'USA',city:'Sedona, Arizona',name:'Red Rock Haven',rating:4.7,offer:1850,img:'https://images.unsplash.com/photo-1564501049412-61c2a3083791?auto=format&fit=crop&w=900&q=80',desc:'A desert hideaway inspired by dramatic red-rock landscapes and wellness escapes.'},
  {id:'us-3',country:'USA',city:'Jackson, Wyoming',name:'Grand Teton Hearth',rating:4.9,offer:2500,img:'https://images.unsplash.com/photo-1549294413-26f195200c16?auto=format&fit=crop&w=900&q=80',desc:'A timber lodge concept with broad mountain views and warm, rustic interiors.'},
  {id:'us-4',country:'USA',city:'Lake Tahoe, California',name:'Tahoe Crest Retreat',rating:4.6,offer:1550,img:'https://images.unsplash.com/photo-1584132967334-10e028bd69f7?auto=format&fit=crop&w=900&q=80',desc:'A lakeside lodge.'},
  {id:'us-5',country:'USA',city:'Napa Valley, California',name:'Vineyard House Lodge',rating:4.8,offer:2100,img:'https://images.unsplash.com/photo-1568084680786-a84f91d1153c?auto=format&fit=crop&w=900&q=80',desc:'A vineyard stay with garden courtyards and relaxed countryside styling.'},
  {id:'us-6',country:'USA',city:'Maui, Hawaii',name:'Pacific Palm Lodge',rating:4.7,offer:1950,img:'https://images.unsplash.com/photo-1561501900-3701fa6a0864?auto=format&fit=crop&w=900&q=80',desc:'A tropical lodge'},
  {id:'uae-1',country:'UAE',city:'Dubai',name:'Desert Pearl Retreat',rating:4.9,offer:2450,img:'https://images.unsplash.com/photo-1542314831-068cd1dbfeeb?auto=format&fit=crop&w=900&q=80',desc:'A luxury desert retreat concept combining modern design and dune-inspired scenery.'},
  {id:'uae-2',country:'UAE',city:'Abu Dhabi',name:'Oasis Crown Lodge',rating:4.7,offer:1750,img:'https://images.unsplash.com/photo-1520250497591-112f2f40a3f4?auto=format&fit=crop&w=900&q=80',desc:'A oasis lodge with shaded courtyards, pools and tranquil desert styling.'},
  {id:'uae-3',country:'UAE',city:'Ras Al Khaimah',name:'Jebel Vista Lodge',rating:4.8,offer:2250,img:'https://images.unsplash.com/photo-1600607687939-ce8a6c25118c?auto=format&fit=crop&w=900&q=80',desc:'A mountain retreat.'},
  {id:'uae-4',country:'UAE',city:'Dubai',name:'Creek Lantern House',rating:4.5,offer:1300,img:'https://images.unsplash.com/photo-1571896349842-33c89424de2d?auto=format&fit=crop&w=900&q=80',desc:'A city lodge blending traditional-inspired details with a modern waterfront feel.'},
  {id:'uae-5',country:'UAE',city:'Sharjah',name:'Al Noor Garden Lodge',rating:4.6,offer:900,img:'https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?auto=format&fit=crop&w=900&q=80',desc:'A garden-focused lodge.'},
  {id:'uae-6',country:'UAE',city:'Fujairah',name:'Hajar Coast Lodge',rating:4.8,offer:2050,img:'https://images.unsplash.com/photo-1540541338287-41700207dee6?auto=format&fit=crop&w=900&q=80',desc:'An east-coast escape inspired by mountains, sea views and relaxed resort design.'}
];

const $=s=>document.querySelector(s), $$=s=>[...document.querySelectorAll(s)];
const KEYS={users:'lrl_users_v1',session:'lrl_session_v1',reviews:'lrl_reviews_v1',unlocks:'lrl_unlocks_v1',withdrawals:'lrl_withdrawals_v1'};
let activeLodge=null, selectedStars=5, statusTimer=null;
const read=(k,f=[])=>{try{return JSON.parse(localStorage.getItem(k))??f}catch{return f}};
const write=(k,v)=>localStorage.setItem(k,JSON.stringify(v));
const session=()=>read(KEYS.session,null);
const money=n=>`KES ${Number(n).toLocaleString()}`;

async function hashPassword(str){
  const data=new TextEncoder().encode(str); const buf=await crypto.subtle.digest('SHA-256',data);
  return [...new Uint8Array(buf)].map(b=>b.toString(16).padStart(2,'0')).join('');
}
function toast(msg){const t=$('#toast');t.textContent=msg;t.classList.add('show');setTimeout(()=>t.classList.remove('show'),2800)}
function openModal(id){$('#'+id).classList.add('open');$('#'+id).setAttribute('aria-hidden','false')}
function closeModal(id){$('#'+id).classList.remove('open');$('#'+id).setAttribute('aria-hidden','true'); if(id==='lodgeModal'&&statusTimer){clearInterval(statusTimer);statusTimer=null}}
$$('[data-close]').forEach(x=>x.addEventListener('click',()=>closeModal(x.dataset.close)));

function renderLodges(filter='all'){
  const list=filter==='all'?lodges:lodges.filter(l=>l.country===filter);
  $('#lodgeGrid').innerHTML=list.map(l=>`<article class="lodge-card">
    <div class="lodge-img" style="background-image:url('${l.img}')"><span class="country-pill">${l.country==='USA'?'🇺🇸 United States':'🇦🇪 UAE'}</span></div>
    <div class="lodge-body"><div class="lodge-title-row"><h3>${l.name}</h3><span class="rating">★ ${l.rating}</span></div><div class="location">${l.city}</div>
    <div class="offer"><div><span>Review offer</span><strong>${money(l.offer)}</strong></div><button class="btn btn-primary" onclick="viewLodge('${l.id}')">Review lodge</button></div></div>
  </article>`).join('');
}
$$('.filter').forEach(b=>b.addEventListener('click',()=>{$$('.filter').forEach(x=>x.classList.remove('active'));b.classList.add('active');renderLodges(b.dataset.country)}));

function updateNav(){
  const s=session();
  $('#authBtn').classList.toggle('hidden',!!s);
  $('#dashboardBtn').classList.toggle('hidden',!s);
  $('#joinBtn').textContent=s?'View dashboard':'Create account';
  const lodgesSection=$('#lodges'), gate=$('#loginGate');
  if(lodgesSection) lodgesSection.classList.toggle('login-gated',!s);
  if(gate) gate.classList.toggle('hidden',!!s);
}
$('#authBtn').onclick=()=>openModal('authModal');
const gateLoginBtn=$('#gateLoginBtn'); if(gateLoginBtn) gateLoginBtn.onclick=()=>openModal('authModal');
$('#joinBtn').onclick=()=>session()?showDashboard():openModal('authModal');
$('#dashboardBtn').onclick=showDashboard;

$$('.auth-tab').forEach(tab=>tab.onclick=()=>{$$('.auth-tab').forEach(t=>t.classList.remove('active'));tab.classList.add('active');$$('.form-panel').forEach(p=>p.classList.remove('active'));$('#'+tab.dataset.authTab+'Form').classList.add('active')});
$('#registerForm').onsubmit=async e=>{e.preventDefault();let users=read(KEYS.users);const email=$('#regEmail').value.trim().toLowerCase();if(users.some(u=>u.email===email))return toast('An account with that email already exists.');const u={id:'u_'+Date.now(),name:$('#regName').value.trim(),email,passwordHash:await hashPassword($('#regPassword').value),createdAt:new Date().toISOString()};users.push(u);write(KEYS.users,users);write(KEYS.session,{id:u.id,name:u.name,email:u.email});closeModal('authModal');updateNav();toast('Account created successfully.');};
$('#loginForm').onsubmit=async e=>{e.preventDefault();const email=$('#loginEmail').value.trim().toLowerCase(),hash=await hashPassword($('#loginPassword').value),u=read(KEYS.users).find(x=>x.email===email&&x.passwordHash===hash);if(!u)return toast('Invalid email or password.');write(KEYS.session,{id:u.id,name:u.name,email:u.email});closeModal('authModal');updateNav();toast(`Welcome back, ${u.name.split(' ')[0]}.`)};

window.viewLodge=function(id){if(!session()){openModal('authModal');return;}activeLodge=lodges.find(l=>l.id===id);selectedStars=5;renderLodgeModal();openModal('lodgeModal')};
function userUnlocked(lodgeId){const s=session();return !!s&&read(KEYS.unlocks).some(x=>x.userId===s.id&&x.lodgeId===lodgeId&&x.status==='completed')}
function userReviewed(lodgeId){const s=session();return !!s&&read(KEYS.reviews).some(x=>x.userId===s.id&&x.lodgeId===lodgeId)}
function renderLodgeModal(){
 const l=activeLodge,s=session(),unlocked=userUnlocked(l.id),reviewed=userReviewed(l.id);
 let action='';
 if(!s) action=`<div class="payment-box"><h3>Login required</h3><p>Sign in or create account before paying the KES 100 service charge.</p><button class="btn btn-primary" onclick="closeModal('lodgeModal');openModal('authModal')">Login / Register</button></div>`;
 else if(reviewed) action=`<div class="payment-status success">✓ You have already submitted your account review for this lodge.</div>`;
 else if(unlocked) action=reviewFormHtml();
 else action=`<div class="payment-box"><h3>Unlock review for KES 100</h3><form id="paymentForm"><label>M-Pesa phone number<input id="mpesaPhone" placeholder="07XXXXXXXX or 2547XXXXXXXX" required></label><button id="payBtn" class="btn btn-primary btn-full" type="submit">Pay KES 100 & Unlock</button></form><div id="paymentStatus" class="payment-status">No payment started.</div></div>`;
 $('#lodgeModalContent').innerHTML=`<div class="modal-lodge-image" style="background-image:url('${l.img}')"></div><div class="modal-lodge-body"><span class="eyebrow">${l.country} · ${l.city}</span><h2>${l.name}</h2><div class="modal-meta">★ ${l.rating} ·lodge profile</div><p>${l.desc}</p><div class="reward-panel"><div><span>review offer</span><br></div><strong>${money(l.offer)}</strong></div>${action}</div>`;
 const pf=$('#paymentForm'); if(pf)pf.onsubmit=startPayment; setupStars(); const rf=$('#reviewForm');if(rf)rf.onsubmit=submitReview;
}
function reviewFormHtml(){return `<form id="reviewForm" class="review-form"><h3>Submit your review</h3><p>Your KES 100 payment has been verified. You may now submit one review for this lodge.</p><label>Rating<div class="stars-select">${[1,2,3,4,5].map(n=>`<button type="button" data-star="${n}" class="${n<=selectedStars?'on':''}">★</button>`).join('')}</div></label><label>Review title<input id="reviewTitle" required maxlength="80" placeholder="Summarize your experience"></label><label>Your review<textarea id="reviewText" required minlength="20" maxlength="800" placeholder="Write at least 20 characters..."></textarea></label><button class="btn btn-primary btn-full" type="submit">Submit review</button></form>`}
function setupStars(){$$('.stars-select button').forEach(b=>b.onclick=()=>{selectedStars=Number(b.dataset.star);$$('.stars-select button').forEach(x=>x.classList.toggle('on',Number(x.dataset.star)<=selectedStars))})}
async function readJsonResponse(response){
  const raw=await response.text();
  try{
    return JSON.parse(raw);
  }catch(e){
    const clean=raw.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim();
    throw new Error(
      clean
        ? `Server returned an invalid response: ${clean.slice(0,220)}`
        : `Server returned an empty response (HTTP ${response.status}).`
    );
  }
}

async function startPayment(e){e.preventDefault();const phone=$('#mpesaPhone').value.trim();const btn=$('#payBtn'),status=$('#paymentStatus');btn.disabled=true;btn.innerHTML='<span class="spinner"></span>Sending STK Push';status.className='payment-status';status.textContent='Contacting payment server…';try{const r=await fetch('index.php?action=initiate',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({phone,lodge_id:activeLodge.id,user_id:session().id})});const d=await readJsonResponse(r);if(!r.ok||!d.success)throw new Error(d.message||'Could not initiate STK Push.');status.textContent=d.demo?d.message:'STK Push sent. Complete payment on your phone; checking status…';if(d.demo){setTimeout(()=>markUnlocked(activeLodge.id,d.transaction_request_id,'DEMO-RECEIPT'),1200);return}pollPayment(d.transaction_request_id)}catch(err){status.className='payment-status error';status.textContent=err.message;btn.disabled=false;btn.textContent='Pay KES 100 & Unlock'}}
function pollPayment(tx){let tries=0;statusTimer=setInterval(async()=>{tries++;try{const r=await fetch('index.php?action=status',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({transaction_request_id:tx})});const d=await readJsonResponse(r);const status=$('#paymentStatus');if(d.completed){clearInterval(statusTimer);statusTimer=null;markUnlocked(activeLodge.id,tx,d.receipt||'');return}if(d.failed){clearInterval(statusTimer);statusTimer=null;status.className='payment-status error';status.textContent=d.message||'Payment was not completed.';$('#payBtn').disabled=false;$('#payBtn').textContent='Try payment again';return}status.textContent='Waiting for payment confirmation…'}catch{}if(tries>=20){clearInterval(statusTimer);statusTimer=null;const status=$('#paymentStatus');if(status)status.textContent='Still pending. You can close and reopen this lodge to try again.'}},3000)}
function markUnlocked(lodgeId,tx,receipt){const s=session(),a=read(KEYS.unlocks);if(!a.some(x=>x.userId===s.id&&x.lodgeId===lodgeId))a.push({userId:s.id,lodgeId,status:'completed',amount:100,transactionId:tx,receipt,completedAt:new Date().toISOString()});write(KEYS.unlocks,a);toast('Payment verified — review unlocked.');renderLodgeModal()}
function submitReview(e){e.preventDefault();const s=session(),reviews=read(KEYS.reviews);if(userReviewed(activeLodge.id))return toast('You already reviewed this lodge.');reviews.push({id:'r_'+Date.now(),userId:s.id,userName:s.name,lodgeId:activeLodge.id,lodgeName:activeLodge.name,rating:selectedStars,title:$('#reviewTitle').value.trim(),text:$('#reviewText').value.trim(),offer:activeLodge.offer,createdAt:new Date().toISOString()});write(KEYS.reviews,reviews);toast('Review submitted successfully.');renderLodgeModal()}
function showDashboard(){
 const s=session();if(!s)return openModal('authModal');
 const reviews=read(KEYS.reviews).filter(r=>r.userId===s.id),unlocks=read(KEYS.unlocks).filter(u=>u.userId===s.id),withdrawals=read(KEYS.withdrawals).filter(w=>w.userId===s.id);
 const totalEarned=reviews.reduce((sum,r)=>sum+Number(r.offer||0),0),totalWithdrawn=withdrawals.reduce((sum,w)=>sum+Number(w.amount||0),0),available=Math.max(0,totalEarned-totalWithdrawn);
 $('#dashboardContent').innerHTML=`<div class="dash-top"><div><span class="eyebrow">My account</span><h2 style="font-family:'Playfair Display';margin:5px 0">${s.name}</h2><div class="modal-meta">${s.email}</div></div><button class="btn btn-outline" id="logoutBtn">Logout</button></div>
 <div class="earnings-grid"><div class="earning-card featured"><span>Available earnings</span><strong>${money(available)}</strong><small>From submitted reviews</small></div><div class="earning-card"><span>Total earned</span><strong>${money(totalEarned)}</strong><small>${reviews.length} completed review${reviews.length===1?'':'s'}</small></div><div class="earning-card"><span>Withdrawn</span><strong>${money(totalWithdrawn)}</strong><small>${withdrawals.length}  withdrawal${withdrawals.length===1?'':'s'}</small></div></div>
 <div class="withdraw-box"><div><h3>Withdraw earnings</h3></div><button class="btn btn-primary" id="withdrawBtn" ${available<=0?'disabled':''}>Withdraw ${money(available)}</button></div>
 <div class="reward-panel"><div><span>Verified service-charge payments</span><br><small>${unlocks.length} lodge${unlocks.length===1?'':'s'} unlocked at KES 100 each</small></div><strong>${money(unlocks.length*100)}</strong></div>
 <h3>My submitted reviews</h3><div class="review-list">${reviews.length?reviews.map(r=>`<div class="review-item"><h4>${r.lodgeName}</h4><div>★ ${r.rating}/5 · ${new Date(r.createdAt).toLocaleDateString()}</div><p><strong>${escapeHtml(r.title)}</strong></p><p>${escapeHtml(r.text)}</p><small>Earned offer: ${money(r.offer)}</small></div>`).join(''):'<div class="empty">No reviews submitted yet. Complete a paid unlock and submit a review to earn offer.</div>'}</div>
 ${withdrawals.length?`<h3 class="history-title">Withdrawal history</h3><div class="withdraw-history">${withdrawals.slice().reverse().map(w=>`<div><span>${new Date(w.createdAt).toLocaleString()}</span><strong>${money(w.amount)}</strong></div>`).join('')}</div>`:''}`;
 $('#logoutBtn').onclick=()=>{localStorage.removeItem(KEYS.session);closeModal('dashboardModal');updateNav();toast('Logged out.')};
 const wb=$('#withdrawBtn');if(wb)wb.onclick=()=>withdrawEarnings(available);
 openModal('dashboardModal')
}
function withdrawEarnings(amount){
 const s=session();if(!s||amount<=0)return toast('No earnings available to withdraw.');
 if(!confirm(`Withdraw ${money(amount)} from your earnings?`))return;
 const a=read(KEYS.withdrawals);a.push({id:'w_'+Date.now(),userId:s.id,amount,method:'payout',status:'completed',createdAt:new Date().toISOString()});write(KEYS.withdrawals,a);toast(`${money(amount)} withdrawal recorded.`);showDashboard();
}
function escapeHtml(s){return s.replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]))}
window.closeModal=closeModal;window.openModal=openModal;
renderLodges();updateNav();$('#year').textContent=new Date().getFullYear();

  </script>
</body>
</html>
